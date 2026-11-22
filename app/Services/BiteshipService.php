<?php

namespace App\Services;

use App\Exceptions\BiteshipException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Penghubung ke Biteship: pencarian area dan cek ongkos kirim.
 * Alamat diidentifikasi dengan area ID (tingkat kecamatan).
 */
class BiteshipService
{
    private const KODE_TIDAK_ADA_KURIR = 40001010;

    /** Mencari area (kecamatan) berdasarkan teks. Hasil disimpan 24 jam untuk menghemat kuota. */
    public function searchAreas(string $input): array
    {
        $input = trim((string) preg_replace('/\s+/', ' ', $input));

        if (mb_strlen($input) < 3) {
            return [];
        }

        return Cache::remember('biteship:areas:' . sha1(mb_strtolower($input)), now()->addDay(), function () use ($input) {
            $json = $this->kirim('GET', '/v1/maps/areas', ['countries' => 'ID', 'input' => $input, 'type' => 'single']);

            return collect($json['areas'] ?? [])->map(fn(array $a) => [
                'id'          => $a['id'],
                'name'        => $a['name'] ?? null,
                'province'    => $a['administrative_division_level_1_name'] ?? null,
                'city'        => $a['administrative_division_level_2_name'] ?? null,
                'district'    => $a['administrative_division_level_3_name'] ?? null,
                'postal_code' => isset($a['postal_code']) ? (string) $a['postal_code'] : null,
            ])->values()->all();
        });
    }

    /**
     * Menghitung pilihan ongkos kirim. Item: name, value, length, width, height, weight (gram), quantity.
     *
     * @param  list<string>  $couriers
     */
    public function rates(string $originAreaId, string $destinationAreaId, array $items, array $couriers): array
    {
        if ($couriers === [] || $items === []) {
            return [];
        }

        $json = $this->kirim('POST', '/v1/rates/couriers', [
            'origin_area_id'      => $originAreaId,
            'destination_area_id' => $destinationAreaId,
            'couriers'            => implode(',', $couriers),
            'items'               => array_map(fn(array $i) => [
                'name'     => (string) $i['name'],
                'value'    => (int) $i['value'],
                'length'   => (int) $i['length'],
                'width'    => (int) $i['width'],
                'height'   => (int) $i['height'],
                'weight'   => (int) $i['weight'],
                'quantity' => (int) $i['quantity'],
            ], array_values($items)),
        ], [self::KODE_TIDAK_ADA_KURIR]);

        return collect($json['pricing'] ?? [])->map(fn(array $p) => [
            'courier_code'  => $p['courier_code'],
            'courier_name'  => $p['courier_name'] ?? null,
            'service_code'  => $p['courier_service_code'],
            'service_name'  => $p['courier_service_name'] ?? null,
            'description'   => $p['description'] ?? null,
            'duration'      => $p['duration'] ?? null,
            'service_type'  => $p['service_type'] ?? null,
            'shipping_type' => $p['shipping_type'] ?? null,
            'price'         => (int) $p['price'],
        ])->values()->all();
    }

    /** @param  list<int>  $kodeDiabaikan  Kode respons yang berarti "hasil kosong", bukan kegagalan. */
    private function kirim(string $method, string $path, array $data = [], array $kodeDiabaikan = []): array
    {
        $key = config('biteship.key');

        if (blank($key)) {
            Log::warning('Biteship belum dikonfigurasi: BITESHIP_API_KEY kosong.');

            throw new BiteshipException('Layanan pengiriman sedang tidak tersedia.', 503, 'not_configured');
        }

        try {
            $req = Http::baseUrl(rtrim((string) config('biteship.base_url'), '/'))
                ->withHeaders(['Authorization' => $key])
                ->acceptJson()
                ->timeout((int) config('biteship.timeout'));

            $res = $method === 'GET' ? $req->get($path, $data) : $req->post($path, $data);
        } catch (ConnectionException) {
            throw new BiteshipException('Layanan pengiriman sedang tidak tersedia.', 503, 'unavailable');
        }

        $body = $res->json();
        $body = is_array($body) ? $body : [];
        $code = (int) ($body['code'] ?? 0);

        if (in_array($code, $kodeDiabaikan, true)) {
            return [];
        }

        if ($res->failed()) {
            // Hanya status dan kode yang dicatat: tanpa key dan tanpa isi alamat.
            Log::warning('Biteship gagal', ['path' => $path, 'status' => $res->status(), 'code' => $code]);

            throw match (true) {
                in_array($code, [40200000, 42200000], true)
                => new BiteshipException('Layanan pengiriman sedang tidak tersedia.', 503, 'quota'),
                $res->status() === 429 || $code === 42900000
                => new BiteshipException('Terlalu banyak permintaan ke layanan pengiriman, coba lagi sebentar.', 429, 'rate_limited'),
                in_array($code, [40001001, 40001002], true)
                => new BiteshipException('Alamat tidak valid untuk cek ongkos kirim.', 422, 'invalid_area'),
                default
                => new BiteshipException('Layanan pengiriman sedang tidak tersedia.', 503, 'unavailable'),
            };
        }

        return $body;
    }
}
