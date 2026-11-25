<?php

namespace App\Services;

use App\Exceptions\BiteshipException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Penghubung ke Biteship untuk membuat dan membatalkan order pengiriman. */
class BiteshipShipmentClient
{
    private const PESAN_TIDAK_TERSEDIA = 'Layanan pengiriman sedang tidak tersedia.';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string, price: int, tracking_id: ?string, waybill_id: ?string, raw: array<string, mixed>}
     *
     * @throws BiteshipException
     */
    public function createOrder(array $payload): array
    {
        $res   = $this->kirim('/v1/orders', $payload);
        $kurir = (array) ($res['courier'] ?? []);
        $id    = (string) ($res['id'] ?? '');

        if ($id === '') {
            throw new BiteshipException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable');
        }

        $status = (string) ($res['status'] ?? 'confirmed');
        $harga  = (int) ($res['price'] ?? 0);

        return [
            'id'          => $id,
            'status'      => $status,
            'price'       => $harga,
            'tracking_id' => $kurir['tracking_id'] ?? null,
            'waybill_id'  => $kurir['waybill_id'] ?? null,
            // Hanya kolom yang dibutuhkan yang disimpan: tanpa kontak dan alamat.
            'raw'         => [
                'id'      => $id,
                'status'  => $status,
                'price'   => $harga,
                'courier' => array_intersect_key($kurir, array_flip(['company', 'type', 'tracking_id', 'waybill_id', 'link'])),
            ],
        ];
    }

    /** @throws BiteshipException */
    public function cancelOrder(string $biteshipOrderId, string $alasan): void
    {
        $this->kirim('/v1/orders/' . rawurlencode($biteshipOrderId) . '/cancel', [
            'cancellation_reason_code' => 'others',
            'cancellation_reason'      => Str::limit($alasan, 200, ''),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function kirim(string $path, array $data): array
    {
        $key = config('biteship.key');

        if (blank($key)) {
            Log::warning('Biteship belum dikonfigurasi: BITESHIP_API_KEY kosong.');

            throw new BiteshipException(self::PESAN_TIDAK_TERSEDIA, 503, 'not_configured');
        }

        try {
            $res = Http::baseUrl(rtrim((string) config('biteship.base_url'), '/'))
                ->withHeaders(['Authorization' => $key])
                ->acceptJson()
                ->timeout((int) config('biteship.timeout'))
                ->post($path, $data);
        } catch (ConnectionException) {
            throw new BiteshipException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable');
        }

        $body = $res->json();
        $body = is_array($body) ? $body : [];
        $code = (int) ($body['code'] ?? 0);

        if (! $res->failed() && ($body['success'] ?? true) !== false) {
            return $body;
        }

        // Hanya jalur, status, dan kode yang dicatat: tanpa key, alamat, atau isi permintaan.
        Log::warning('Biteship order gagal', ['path' => $path, 'status' => $res->status(), 'code' => $code]);

        throw match (true) {
            in_array($code, [40200000, 42200000], true)
            => new BiteshipException(self::PESAN_TIDAK_TERSEDIA, 503, 'quota'),
            $res->status() === 429 || $code === 42900000
            => new BiteshipException('Terlalu banyak permintaan ke layanan pengiriman, coba lagi sebentar.', 429, 'rate_limited'),
            in_array($res->status(), [400, 422], true)
            => new BiteshipException('Data pengiriman ditolak oleh layanan kurir. Periksa alamat, nomor telepon, dan layanan kurir, lalu coba lagi.', 422, 'invalid_request'),
            default
            => new BiteshipException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable'),
        };
    }
}
