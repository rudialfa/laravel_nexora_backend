<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\VoucherUsage;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;

/**
 * Keuangan toko: menggerakkan dompet mengikuti status pesanan, ringkasan saldo, mutasi, dan penghasilan per pesanan.
 * Seluruh perubahan saldo tetap lewat method Wallet (idempoten per referensi, tidak pernah minus).
 */
class FinanceService
{
    public const MAKS_PENGAJUAN_MENUNGGU = 3;

    // --------------------------------------------------------------- PEMICU

    /**
     * Dipanggil setiap kali riwayat status pesanan tercatat (di dalam transaksi perpindahan status).
     * Toko yang belum punya dompet dilewati: dompet tidak dibuat sendiri di sini.
     */
    public function handleStatusChange(OrderStatusHistory $riwayat): void
    {
        $ke = $riwayat->to_status;

        if (! in_array($ke, [Order::STATUS_PAID, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            return;
        }

        $order  = Order::query()->find($riwayat->order_id);
        $wallet = $order === null ? null : Wallet::query()->where('store_id', $order->store_id)->first();

        if ($wallet === null) {
            return;
        }

        $sebelumDikirim = [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_PACKED];

        match (true) {
            $ke === Order::STATUS_PAID      => $this->tahan($wallet, $order),
            $ke === Order::STATUS_COMPLETED => $this->cairkan($wallet, $order),
            $ke === Order::STATUS_CANCELLED && in_array($riwayat->from_status, $sebelumDikirim, true),
            $ke === Order::STATUS_REFUNDED && $riwayat->from_status === Order::STATUS_DELIVERED
            => $this->balikkan($wallet, $order),
            default                         => null,
        };
    }

    // -------------------------------------------------------------- TAMPILAN

    public function walletFor(Store $store): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate(['store_id' => $store->id]);

        return $wallet->wasRecentlyCreated ? $wallet->fresh() : $wallet;
    }

    /** Minimal penarikan: nilai terbesar antara konstanta model dan pengaturan marketplace. */
    public function minimalPenarikan(): int
    {
        return max(Wallet::MIN_WITHDRAWAL, (int) Setting::getValue('withdrawal.min_amount', Wallet::MIN_WITHDRAWAL));
    }

    /** @return array<string, int> */
    public function summary(Store $store): array
    {
        $w = $this->walletFor($store);

        $menunggu = Withdrawal::query()->where('store_id', $store->id)->where('status', Withdrawal::STATUS_PENDING);

        return [
            'available'                  => (int) $w->balance,
            'pending'                    => (int) $w->pending_balance,
            'total'                      => (int) $w->balance + (int) $w->pending_balance,
            'min_withdrawal'             => $this->minimalPenarikan(),
            'withdrawals_pending_count'  => (clone $menunggu)->count(),
            'withdrawals_pending_amount' => (int) (clone $menunggu)->sum('amount'),
            'max_pending_withdrawals'    => self::MAKS_PENGAJUAN_MENUNGGU,
        ];
    }

    /**
     * Mutasi dompet, terbaru lebih dulu.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function ledger(Store $store, array $filter): array
    {
        $w = $this->walletFor($store);
        $q = WalletTransaction::query()->where('wallet_id', $w->id)->orderByDesc('id');

        if (filled($filter['bucket'] ?? null)) {
            $q->where('bucket', $filter['bucket']);
        }

        if (filled($filter['type'] ?? null)) {
            $q->where('type', $filter['type']);
        }

        $hal   = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));
        $baris = $hal->getCollection();
        $baris->load('reference');

        return [
            'items' => $baris->map(fn(WalletTransaction $t) => [
                'id'            => $t->id,
                'type'          => $t->type,
                'bucket'        => $t->bucket,
                'amount'        => (int) $t->amount,
                'balance_after' => (int) $t->balance_after,
                'description'   => $t->description,
                'reference'     => $this->referensi($t),
                'created_at'    => $this->iso($t->created_at),
            ])->values()->all(),
            'meta'  => [
                'current_page' => $hal->currentPage(),
                'per_page'     => $hal->perPage(),
                'total'        => $hal->total(),
                'last_page'    => $hal->lastPage(),
            ],
        ];
    }

    /**
     * Rincian penghasilan seller dari satu pesanan dan status dananya.
     *
     * @return array<string, mixed>
     */
    public function orderEarnings(Order $o): array
    {
        return [
            'order'                  => ['id' => $o->uuid, 'number' => $o->number, 'status' => $o->status],
            'subtotal'               => (int) $o->subtotal,
            'store_voucher_discount' => $this->diskonVoucherToko($o),
            'commission_rate'        => (float) $o->commission_rate,
            'commission_amount'      => (int) $o->commission_amount,
            'net_income'             => $this->penghasilan($o),
            'shipping_charge'        => $this->selisihOngkirSeller($o),
            'funds'                  => $this->statusDana($o),
        ];
    }

    // ------------------------------------------------------------- PERHITUNGAN

    /** Subtotal barang dikurangi voucher toko dan komisi. Ongkir, biaya layanan, dan voucher platform tidak ikut. */
    public function penghasilan(Order $o): int
    {
        return max(0, (int) $o->subtotal - $this->diskonVoucherToko($o) - (int) $o->commission_amount);
    }

    /**
     * Selisih ongkir yang ditanggung seller: kelebihan di atas batas yang ditanggung platform.
     * Rumusnya sama dengan yang ditampilkan di pelacakan seller.
     */
    public function selisihOngkirSeller(Order $o): int
    {
        $s = Shipment::query()->where('order_id', $o->id)->first();

        if ($s === null || $s->courier_price === null) {
            return 0;
        }

        $batas = max(0, (int) Setting::getValue('shipping.platform_absorb_limit', 5000));

        return max(0, (int) $s->courier_price - (int) $o->shipping_cost - $batas);
    }

    // ---------------------------------------------------------------- PRIVAT

    private function tahan(Wallet $wallet, Order $order): void
    {
        $net = $this->penghasilan($order);

        if ($net > 0) {
            $wallet->holdSale($net, $order, 'Penjualan ' . $order->number);
        }
    }

    private function cairkan(Wallet $wallet, Order $order): void
    {
        $ditahan = $this->jumlahTertahan($wallet, $order);

        // Pesanan yang lunas sebelum dompet ada: ditahan dulu supaya buku besar tetap lengkap.
        if ($ditahan === null) {
            $ditahan = $this->penghasilan($order);

            if ($ditahan <= 0) {
                return;
            }

            $wallet->holdSale($ditahan, $order, 'Penjualan ' . $order->number);
        }

        if ($ditahan <= 0) {
            return;
        }

        $wallet->releaseSale($ditahan, $order);

        // Potongan tidak pernah melebihi penghasilan pesanan ini, jadi saldo tidak mungkin minus.
        $potongan = min($this->selisihOngkirSeller($order), $ditahan);

        if ($potongan > 0) {
            $wallet->chargeShippingDifference($potongan, $order);
        }
    }

    private function balikkan(Wallet $wallet, Order $order): void
    {
        $ditahan = $this->jumlahTertahan($wallet, $order);

        if ($ditahan !== null && $ditahan > 0) {
            $wallet->reverseSale($ditahan, $order, Wallet::BUCKET_PENDING);
        }
    }

    private function jumlahTertahan(Wallet $wallet, Order $order): ?int
    {
        $nilai = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', Wallet::TYPE_SALE_HOLD)
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->value('amount');

        return $nilai === null ? null : (int) $nilai;
    }

    /** Potongan dari voucher milik toko ini saja. Voucher platform ditanggung platform. */
    private function diskonVoucherToko(Order $o): int
    {
        return (int) VoucherUsage::query()
            ->where('checkout_group_id', $o->checkout_group_id)
            ->whereIn('status', [VoucherUsage::STATUS_RESERVED, VoucherUsage::STATUS_USED])
            ->whereHas('voucher', fn($q) => $q->withTrashed()->where('store_id', $o->store_id))
            ->sum('discount_amount');
    }

    private function statusDana(Order $o): string
    {
        if ($o->paid_at === null) {
            return 'unpaid';
        }

        $wallet = Wallet::query()->where('store_id', $o->store_id)->first();

        if ($wallet === null) {
            return 'none';
        }

        $jenis = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', $o->getMorphClass())
            ->where('reference_id', $o->getKey())
            ->pluck('type')
            ->unique();

        return match (true) {
            $jenis->contains(Wallet::TYPE_SALE_REVERSAL) => 'reversed',
            $jenis->contains(Wallet::TYPE_SALE_RELEASE)  => 'released',
            $jenis->contains(Wallet::TYPE_SALE_HOLD)     => 'held',
            default                                      => 'none',
        };
    }

    /** @return array<string, mixed>|null */
    private function referensi(WalletTransaction $t): ?array
    {
        $r = $t->reference;

        return match (true) {
            $r instanceof Order      => ['type' => 'order', 'number' => $r->number],
            $r instanceof Withdrawal => ['type' => 'withdrawal', 'id' => $r->uuid],
            default                  => null,
        };
    }

    private function iso(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }
}
