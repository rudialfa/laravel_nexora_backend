<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Bantuan bersama untuk tes komplain: pesanan sampai atau selesai dengan dompet toko, dan pengajuan komplain. */
trait SiapkanSengketa
{
    use SiapkanKeuangan;

    protected function aturSengketa(): void
    {
        $this->aturPengiriman();
        Storage::fake('public');
    }

    /** Permintaan multipart (untuk unggah foto). */
    protected function kirimForm(string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    /** @return array<int, UploadedFile> */
    protected function fotoUji(int $jumlah = 1): array
    {
        $daftar = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $daftar[] = UploadedFile::fake()->image("bukti{$i}.jpg", 800, 600);
        }

        return $daftar;
    }

    /** Dari dibayar sampai paket sampai (delivered). */
    protected function sampaikan(Order $o): Order
    {
        $o->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SYSTEM);
        $o->transitionTo(Order::STATUS_DELIVERED, Order::ACTOR_SYSTEM);

        return $o->fresh();
    }

    /**
     * Pesanan milik pembeli yang sudah sampai. Komisi 5% (Rp10.000), jadi Rp190.000 ditahan di dompet toko.
     * Total pesanan Rp210.000, biaya layanan checkout Rp1.000.
     */
    protected function sampaiUntuk(User $user): Order
    {
        $grup  = $this->checkout($user);
        $order = Order::where('checkout_group_id', $grup->id)->first();

        $order->forceFill(['commission_rate' => 5, 'commission_amount' => 10000])->save();
        Wallet::firstOrCreate(['store_id' => $order->store_id]);

        $this->lunas($grup);

        return $this->sampaikan($order->fresh());
    }

    /** @return array{0: User, 1: string, 2: Order, 3: Wallet} */
    protected function pesananSampai(): array
    {
        [$user, $token] = $this->pembeli();

        $o = $this->sampaiUntuk($user);

        return [$user, $token, $o, Wallet::where('store_id', $o->store_id)->first()];
    }

    /** Pesanan yang sudah dikonfirmasi pembeli: dana 190.000 sudah di saldo yang bisa ditarik. */
    protected function pesananSelesai(): array
    {
        [$user, $token, $o] = $this->pesananSampai();

        $o->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER);

        return [$user, $token, $o->fresh(), Wallet::where('store_id', $o->store_id)->first()];
    }

    protected function ajukan(Order $o, string $token, array $data = [], array $foto = [])
    {
        Cache::flush();   // penghitung laju dibersihkan: banyak tes mengirim lebih dari 5 permintaan

        $data += ['reason_code' => 'damaged', 'description' => 'Sepatu sobek pada bagian samping saat dibuka'];

        if ($foto !== []) {
            $data['photos'] = $foto;
        }

        return $this->kirimForm("/api/v1/orders/{$o->uuid}/dispute", $data, $token);
    }
}
