<?php

namespace Tests\Concerns;

use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Bantuan bersama untuk tes pengiriman: pesanan dikemas, seller, Biteship palsu, dan webhook. */
trait SiapkanPengiriman
{
    use SiapkanPembayaran;

    protected function aturPengiriman(): void
    {
        config([
            'biteship.key'           => 'test-key',
            'biteship.base_url'      => 'https://api.biteship.com',
            'biteship.webhook_token' => 'token-rahasia-uji',
        ]);

        $this->seed(RoleSeeder::class);
        $this->aturMidtrans();
    }

    /** Menandai seluruh pesanan checkout sebagai lunas. Mengembalikan pesanan pertama. */
    protected function lunas(CheckoutGroup $grup): Order
    {
        foreach (Order::where('checkout_group_id', $grup->id)->orderBy('id')->get() as $order) {
            $order->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
        }

        $grup->forceFill(['status' => CheckoutGroup::STATUS_PAID, 'paid_at' => now()])->save();

        return Order::where('checkout_group_id', $grup->id)->orderBy('id')->first();
    }

    /** Pesanan yang sudah dibayar, diproses, dan dikemas, dengan alamat tujuan lengkap. */
    protected function dikemas(User $user): Order
    {
        $o = $this->lunas($this->checkout($user));

        $o->forceFill(['shipping_address' => [
            'label'            => 'Rumah',
            'recipient_name'   => 'Budi Penerima',
            'phone'            => '628123456780',
            'province'         => 'DKI Jakarta',
            'city'             => 'Jakarta Selatan',
            'district'         => 'Cilandak',
            'postal_code'      => '12430',
            'street'           => 'Jl. Contoh Pembeli No. 2',
            'latitude'         => null,
            'longitude'        => null,
            'biteship_area_id' => 'AREA-TUJUAN',
        ]])->save();

        $o->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);

        return $o->fresh();
    }

    /** Token seller pemilik toko pesanan ini. */
    protected function penjual(Order $order): string
    {
        $toko = $order->store;
        $toko->forceFill(['verified_at' => now()])->save();

        $pemilik = $toko->owner;

        if (! $pemilik->hasRole('seller')) {
            $pemilik->assignRole('seller');
        }

        return $pemilik->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }

    /** Biteship palsu: order berhasil dibuat (dengan ID unik) dan pembatalan diterima. */
    protected function palsuBiteship(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders' => fn(Request $r) => Http::response([
                'success' => true,
                'id'      => 'BTS-' . strtolower(Str::random(10)),
                'status'  => 'confirmed',
                'price'   => 24000,
                'courier' => ['tracking_id' => 'TRK-' . strtolower(Str::random(6)), 'waybill_id' => null, 'company' => 'jne', 'type' => 'reg', 'link' => 'https://track.example.com/abc'],
            ], 200),
            'api.biteship.com/v1/orders/*/cancel' => Http::response(['success' => true, 'status' => 'cancelled'], 200),
        ]);
    }

    protected function urlKirim(Order $order): string
    {
        return "/api/v1/seller/orders/{$order->uuid}/ship";
    }

    /** Webhook Biteship dengan token (null: tanpa token). */
    protected function webhook(array $payload, ?string $token = 'token-rahasia-uji')
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withHeader('X-Webhook-Token', $token);
        }

        return $this->postJson('/api/v1/webhooks/biteship', $payload);
    }

    protected function statusPengiriman(string $biteshipId, string $status, array $ubah = []): array
    {
        return array_merge([
            'event'                       => 'order.status',
            'courier_tracking_id'         => 'TRK-1',
            'courier_waybill_id'          => 'WB-123',
            'courier_company'             => 'JNE',
            'courier_type'                => 'REG',
            'courier_driver_name'         => 'Pak Kurir',
            'courier_driver_phone'        => '088888888888',
            'courier_driver_plate_number' => 'B 1234 AAA',
            'courier_link'                => 'https://track.example.com/abc',
            'order_id'                    => $biteshipId,
            'order_price'                 => 24000,
            'status'                      => $status,
        ], $ubah);
    }
}
