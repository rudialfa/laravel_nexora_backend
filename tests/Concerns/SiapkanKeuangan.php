<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\User;
use App\Models\Wallet;

/** Bantuan bersama untuk tes keuangan: pesanan dengan dompet, penyelesaian pesanan, rekening, dan admin. */
trait SiapkanKeuangan
{
    use SiapkanPengiriman;

    /** @return array{0: User, 1: string} */
    protected function akunAdmin(): array
    {
        $this->urut++;

        $u = User::create(['name' => "Admin {$this->urut}", 'email' => "admin{$this->urut}@nexora.test", 'password' => 'Rahasia123']);
        $u->assignRole('admin');

        return [$u, $u->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    /**
     * Checkout belum dibayar dengan dompet toko dan komisi 5% (Rp10.000 dari subtotal Rp200.000).
     * Penghasilan bersih pesanan ini Rp190.000. Voucher platform Rp10.000 tidak mengurangi penghasilan.
     *
     * @return array{0: User, 1: string, 2: \App\Models\CheckoutGroup, 3: Order, 4: Wallet}
     */
    protected function pesananBelumBayar(): array
    {
        [$user, $token] = $this->pembeli();

        $grup  = $this->checkout($user);
        $order = Order::where('checkout_group_id', $grup->id)->first();
        $order->forceFill(['commission_rate' => 5, 'commission_amount' => 10000])->save();

        $wallet = Wallet::firstOrCreate(['store_id' => $order->store_id])->fresh();

        return [$user, $token, $grup, $order->fresh(), $wallet];
    }

    /** Dari dibayar sampai diterima pembeli, dengan aktor yang sah di tiap langkah. */
    protected function selesaikan(Order $o): void
    {
        $o->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SYSTEM);
        $o->transitionTo(Order::STATUS_DELIVERED, Order::ACTOR_SYSTEM);
        $o->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER);
    }

    protected function rekening(Store $toko, string $nomor = '1234567890'): StoreBankAccount
    {
        $a = new StoreBankAccount(['bank_code' => 'bca', 'bank_name' => 'Bank Central Asia', 'account_name' => 'Budi Santoso', 'is_default' => true]);
        $a->store_id = $toko->id;
        $a->setAccountNumber($nomor);
        $a->save();

        return $a->fresh();
    }

    /**
     * Seller dengan saldo tersedia sebesar $saldo (0 berarti tanpa koreksi).
     *
     * @return array{0: string, 1: Store, 2: Wallet, 3: User, 4: Order} token seller, toko, dompet, admin, pesanan
     */
    protected function penjualBerdana(int $saldo): array
    {
        [,,, $o, $w] = $this->pesananBelumBayar();
        [$admin]       = $this->akunAdmin();

        if ($saldo > 0) {
            $w->adjust($saldo, $admin, 'Saldo uji');
        }

        return [$this->penjual($o), $o->store, $w->fresh(), $admin, $o];
    }
}
