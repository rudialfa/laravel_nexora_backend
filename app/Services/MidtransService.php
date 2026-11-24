<?php

namespace App\Services;

use App\Exceptions\MidtransException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Penghubung ke Midtrans Core API: membuat tagihan dan meminta tagihan kedaluwarsa.
 * Otentikasi memakai Basic Auth dengan Server Key sebagai nama pengguna.
 */
class MidtransService
{
    private const PESAN_TIDAK_TERSEDIA = 'Layanan pembayaran sedang tidak tersedia. Coba lagi beberapa saat.';

    /** @param  array<string, mixed>  $payload */
    public function charge(array $payload): array
    {
        return $this->kirim('/v2/charge', $payload);
    }

    /** Meminta tagihan kedaluwarsa. Gagal (sudah kedaluwarsa, sudah dibayar, tidak ada) tidak dianggap masalah. */
    public function expire(string $orderId): void
    {
        try {
            $this->kirim('/v2/' . rawurlencode($orderId) . '/expire');
        } catch (MidtransException) {
            // Best effort: Midtrans tetap mengakhiri tagihan sendiri pada batas waktunya.
        }
    }

    /** @param  array<string, mixed>  $data */
    private function kirim(string $path, array $data = []): array
    {
        $key = config('midtrans.server_key');

        if (blank($key)) {
            Log::warning('Midtrans belum dikonfigurasi: MIDTRANS_SERVER_KEY kosong.');

            throw new MidtransException(self::PESAN_TIDAK_TERSEDIA, 503, 'not_configured');
        }

        try {
            $req = Http::baseUrl(rtrim((string) config('midtrans.base_url'), '/'))
                ->withBasicAuth((string) $key, '')
                ->acceptJson()
                ->timeout((int) config('midtrans.timeout'));

            $res = $data === [] ? $req->post($path) : $req->post($path, $data);
        } catch (ConnectionException) {
            throw new MidtransException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable');
        }

        $body = $res->json();
        $body = is_array($body) ? $body : [];

        // Midtrans bisa menjawab HTTP 200 dengan kode kegagalan di dalam isi, jadi isi diperiksa lebih dulu.
        $kode = (int) ($body['status_code'] ?? $res->status());

        if (in_array($kode, [200, 201], true) && ! $res->serverError()) {
            return $body;
        }

        // Hanya jalur, status, dan kode yang dicatat: tanpa kunci, alamat, atau isi permintaan.
        Log::warning('Midtrans gagal', ['path' => $path, 'http' => $res->status(), 'code' => $kode]);

        throw match (true) {
            $kode === 406
            => new MidtransException('Tagihan untuk percobaan ini sudah pernah dibuat. Muat ulang halaman pembayaran.', 409, 'duplicate'),
            $kode === 429 || $res->status() === 429
            => new MidtransException('Terlalu banyak permintaan pembayaran, coba lagi sebentar.', 429, 'rate_limited'),
            default
            => new MidtransException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable'),
        };
    }
}
