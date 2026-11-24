<?php

namespace App\Services;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Checkout: pratinjau rincian dan pembuatan pesanan per toko dalam satu transaksi.
 * Harga, ongkir, voucher, dan biaya layanan selalu dihitung ulang di server.
 */
class CheckoutService
{
    public const PESAN_KERANJANG_BERUBAH = 'Isi keranjang atau harga berubah saat checkout. Periksa ulang lalu coba lagi.';
    public const PESAN_LAYANAN_TIDAK_ADA = 'Layanan pengiriman yang dipilih tidak tersedia lagi. Pilih ulang layanan pengiriman.';
    public const PESAN_POTONGAN_PENUH    = 'Voucher platform tidak dapat dipakai karena potongan lain sudah menutup seluruh belanja.';

    private const FIELD_OPSI = [
        'courier_code',
        'courier_name',
        'service_code',
        'service_name',
        'description',
        'duration',
        'service_type',
        'shipping_type',
        'price',
    ];

    public function __construct(
        private readonly CartService $cart,
        private readonly ShippingQuoteService $shipping,
        private readonly VoucherService $vouchers,
    ) {}

    // ------------------------------------------------------------- PUBLIK

    /** Menghitung rincian checkout tanpa menyimpan apa pun. */
    public function preview(User $user, array $data): array
    {
        $plan = $this->plan($user, $data);

        return [
            'stores' => array_values(array_map(fn(array $p) => [
                'store'          => ['id' => $p['uuid'], 'name' => $p['model']->name],
                'summary'        => ['items' => $p['items'], 'quantity' => $p['quantity'], 'weight_gram' => $p['weight'], 'subtotal' => $p['subtotal']],
                'shipping'       => array_intersect_key($p['option'], array_flip(self::FIELD_OPSI)),
                'store_voucher'  => $p['store_voucher'] === null ? null : ['code' => $p['store_voucher']->code, 'discount' => $p['store_discount']],
                'discount_total' => $p['discount_total'],
                'grand_total'    => $p['grand_total'],
            ], $plan['stores'])),
            'platform_voucher' => $plan['platform_voucher'] === null ? null : ['code' => $plan['platform_voucher']->code, 'discount' => $plan['platform_discount']],
            'totals'           => $plan['totals'],
        ];
    }

    /**
     * Membuat checkout. Kunci yang sama mengembalikan checkout yang sudah ada.
     *
     * @return array{group: CheckoutGroup, created: bool}
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): array
    {
        if (($ada = $this->cariIdempoten($user, $data['idempotency_key'])) !== null) {
            return ['group' => $ada, 'created' => false];
        }

        $plan = $this->plan($user, $data);

        try {
            $grup = DB::transaction(fn() => $this->simpan($user, $data, $plan));
        } catch (UniqueConstraintViolationException $e) {
            // Dua permintaan dengan kunci sama berlomba: yang kalah mengambil milik pemenang.
            $ada = $this->cariIdempoten($user, $data['idempotency_key']);

            if ($ada === null) {
                throw $e;
            }

            return ['group' => $ada, 'created' => false];
        }

        return ['group' => $grup, 'created' => true];
    }

    /** Checkout milik pengguna ini. Milik orang lain: 404. */
    public function find(User $user, string $uuid): CheckoutGroup
    {
        return CheckoutGroup::query()->where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function present(CheckoutGroup $g): array
    {
        $g->loadMissing(['orders.store', 'orders.items']);

        $disk     = Storage::disk('public');
        $menunggu = $g->status === CheckoutGroup::STATUS_PENDING_PAYMENT && $g->payment_expires_at !== null;

        return [
            'id'                 => $g->uuid,
            'number'             => $g->number,
            'status'             => $g->status,
            'subtotal'           => (int) $g->subtotal,
            'shipping_total'     => (int) $g->shipping_total,
            'discount_total'     => (int) $g->discount_total,
            'service_fee'        => (int) $g->service_fee,
            'grand_total'        => (int) $g->grand_total,
            'payment_expires_at' => $g->payment_expires_at?->toIso8601String(),
            'expires_in_seconds' => $menunggu ? max(0, (int) floor(now()->diffInSeconds($g->payment_expires_at, false))) : 0,
            'orders'             => $g->orders->sortBy('id')->values()->map(fn(Order $o) => [
                'id'                => $o->uuid,
                'number'            => $o->number,
                'status'            => $o->status,
                'store'             => ['id' => $o->store?->uuid, 'name' => $o->store?->name],
                'shipping_address'  => $o->shipping_address,
                'courier_company'   => $o->courier_company,
                'courier_type'      => $o->courier_type,
                'shipping_etd'      => $o->shipping_etd,
                'total_weight_gram' => (int) $o->total_weight_gram,
                'subtotal'          => (int) $o->subtotal,
                'shipping_cost'     => (int) $o->shipping_cost,
                'discount_total'    => (int) $o->discount_total,
                'grand_total'       => (int) $o->grand_total,
                'buyer_note'        => $o->buyer_note,
                'items'             => $o->items->map(fn($i) => [
                    'product_name' => $i->product_name,
                    'variant_name' => $i->variant_name,
                    'options'      => $i->options,
                    'image_url'    => $i->image_path ? $disk->url($i->image_path) : null,
                    'price'        => (int) $i->price,
                    'unit_price'   => (int) $i->unit_price,
                    'quantity'     => (int) $i->quantity,
                    'subtotal'     => (int) $i->subtotal,
                ])->values()->all(),
            ])->all(),
        ];
    }

    // ---------------------------------------------------------- PERENCANAAN

    /**
     * Menghitung seluruh angka checkout (ongkir segar, voucher, biaya layanan, komisi).
     * Tidak menulis apa pun ke database.
     *
     * @throws ValidationException
     */
    private function plan(User $user, array $data): array
    {
        $alamat = Address::query()->where('user_id', $user->id)->where('uuid', $data['address_id'])->firstOrFail();

        $entri        = [];
        $subtotalToko = [];

        foreach (array_values($data['stores']) as $i => $s) {
            try {
                $q = $this->shipping->quote($user, $s['store_id'], $data['address_id']);
            } catch (ValidationException $e) {
                $this->petakan($e, $i);
            }

            $kurir   = strtolower($s['courier_code']);
            $layanan = strtolower($s['service_code']);
            $opsi    = collect($q['options'])->first(
                fn(array $o) => strtolower($o['courier_code']) === $kurir && strtolower($o['service_code']) === $layanan
            );

            if ($opsi === null) {
                $this->reject("stores.{$i}.courier_code", self::PESAN_LAYANAN_TIDAK_ADA);
            }

            $subtotalToko[$s['store_id']] = (int) $q['summary']['subtotal'];

            $entri[$i] = [
                'uuid'           => $s['store_id'],
                'model'          => Store::query()->where('uuid', $s['store_id'])->firstOrFail(),
                'items'          => (int) $q['summary']['items'],
                'quantity'       => (int) $q['summary']['quantity'],
                'weight'         => (int) $q['summary']['weight_gram'],
                'subtotal'       => (int) $q['summary']['subtotal'],
                'option'         => $opsi,
                'shipping_cost'  => (int) $opsi['price'],
                'note'           => $s['note'] ?? null,
                'voucher_code'   => $s['voucher_code'] ?? null,
                'store_voucher'  => null,
                'store_discount' => 0,
            ];
        }

        // Voucher toko: dihitung dari subtotal tokonya sendiri.
        foreach ($entri as $i => $p) {
            if (filled($p['voucher_code'])) {
                $a = $this->vouchers->assess($user, $p['voucher_code'], "stores.{$i}.voucher_code", $p['uuid'], $subtotalToko);

                $entri[$i]['store_voucher']  = $a['voucher'];
                $entri[$i]['store_discount'] = min($a['discount'], $p['subtotal']);
            }
        }

        // Voucher platform: dihitung dari seluruh subtotal, lalu dibagi ke pesanan menurut sisa tagihan barangnya.
        $sisa            = array_map(fn(array $p) => $p['subtotal'] - $p['store_discount'], $entri);
        $platformVoucher = null;
        $platformDiskon  = 0;

        if (filled($data['voucher_code'] ?? null)) {
            $a = $this->vouchers->assess($user, $data['voucher_code'], 'voucher_code', null, $subtotalToko);

            $platformVoucher = $a['voucher'];
            $platformDiskon  = min($a['discount'], array_sum($sisa));

            if ($platformDiskon <= 0) {
                $this->reject('voucher_code', self::PESAN_POTONGAN_PENUH);
            }
        }

        $alokasi = $this->bagi($platformDiskon, $sisa);
        $tarifUmum = Setting::getValue('marketplace.commission_rate', 5);

        foreach ($entri as $i => $p) {
            $diskon = $p['store_discount'] + $alokasi[$i];
            $bp     = (int) round(((float) ($p['model']->commission_rate ?? $tarifUmum)) * 100);

            $entri[$i]['platform_discount'] = $alokasi[$i];
            $entri[$i]['discount_total']    = $diskon;
            $entri[$i]['grand_total']       = $p['subtotal'] + $p['shipping_cost'] - $diskon;
            $entri[$i]['commission_rate']   = $bp / 100;
            $entri[$i]['commission_amount'] = intdiv($p['subtotal'] * $bp, 10000);
        }

        $subtotal = array_sum(array_column($entri, 'subtotal'));
        $ongkir   = array_sum(array_column($entri, 'shipping_cost'));
        $diskonT  = array_sum(array_column($entri, 'discount_total'));
        $biaya    = max(0, (int) Setting::getValue('marketplace.service_fee', 0));

        return [
            'address'          => $alamat,
            'stores'           => $entri,
            'platform_voucher' => $platformVoucher,
            'platform_discount' => $platformDiskon,
            'totals'           => [
                'subtotal'       => $subtotal,
                'shipping_total' => $ongkir,
                'discount_total' => $diskonT,
                'service_fee'    => $biaya,
                'grand_total'    => $subtotal + $ongkir + $biaya - $diskonT,
            ],
        ];
    }

    // ------------------------------------------------------------ PENYIMPANAN

    /** Seluruh penulisan dalam satu transaksi: kegagalan di mana pun membatalkan semuanya. */
    private function simpan(User $user, array $data, array $plan): CheckoutGroup
    {
        // Satu pengguna, satu checkout pada satu waktu.
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        $total = $plan['totals'];

        $grup = new CheckoutGroup([
            'user_id'            => $user->id,
            'number'             => CheckoutGroup::generateNumber(),
            'subtotal'           => $total['subtotal'],
            'shipping_total'     => $total['shipping_total'],
            'discount_total'     => $total['discount_total'],
            'service_fee'        => $total['service_fee'],
            'grand_total'        => $total['grand_total'],
            'payment_expires_at' => now()->addHours(max(1, (int) Setting::getValue('payment.expiry_hours', 24))),
        ]);
        $grup->idempotency_key = $data['idempotency_key'];
        $grup->save();

        $isiKeranjang = collect($this->cart->view($user)['groups'])->keyBy('store.id');
        $snapshot     = $this->snapshotAlamat($plan['address']);
        $dibeli       = [];

        foreach (array_values($plan['stores']) as $i => $p) {
            $item = collect($isiKeranjang->get($p['uuid'])['items'] ?? [])
                ->filter(fn(array $x) => $x['is_selected'] && $x['status'] === CartService::STATUS_AVAILABLE)
                ->values();

            $subtotal = (int) $item->sum('line_total');
            $berat    = (int) $item->sum(fn(array $x) => max(1, (int) $x['variant']['weight_gram']) * (int) $x['quantity']);

            // Keranjang berubah sejak ongkir dihitung: batalkan, jangan menagih angka yang salah.
            if ($subtotal !== $p['subtotal'] || $berat !== $p['weight']) {
                $this->reject('cart', self::PESAN_KERANJANG_BERUBAH);
            }

            $varian = ProductVariant::query()
                ->whereIn('uuid', $item->pluck('variant_id'))
                ->with('product.images')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $opsi  = $p['option'];
            $order = new Order([
                'checkout_group_id' => $grup->id,
                'user_id'           => $user->id,
                'store_id'          => $p['model']->id,
                'number'            => Order::generateNumber($grup, $i + 1),
                'shipping_address'  => $snapshot,
                'courier_company'   => $opsi['courier_code'],
                'courier_type'      => $opsi['service_code'],
                'shipping_etd'      => $opsi['duration'] ?? null,
                'total_weight_gram' => $p['weight'],
                'subtotal'          => $p['subtotal'],
                'shipping_cost'     => $p['shipping_cost'],
                'discount_total'    => $p['discount_total'],
                'grand_total'       => $p['grand_total'],
                'commission_rate'   => $p['commission_rate'],
                'commission_amount' => $p['commission_amount'],
                'buyer_note'        => $p['note'],
            ]);
            $order->save();

            foreach ($item->sortBy(fn(array $x) => $varian->get($x['variant_id'])?->id) as $x) {
                $v = $varian->get($x['variant_id']);

                if ($v === null) {
                    $this->reject('cart', self::PESAN_KERANJANG_BERUBAH);
                }

                $order->items()->create([
                    'product_id'   => $v->product_id,
                    'variant_id'   => $v->id,
                    'product_name' => $x['product']['name'],
                    'variant_name' => $x['variant']['name'],
                    'sku'          => $v->sku,
                    'image_path'   => $v->product->images->sortBy('sort_order')->first()?->path,
                    'options'      => $x['variant']['options'],
                    'price'        => (int) $x['price'],
                    'unit_price'   => (int) $x['final_price'],
                    'quantity'     => (int) $x['quantity'],
                    'subtotal'     => (int) $x['line_total'],
                    'weight_gram'  => (int) $x['variant']['weight_gram'],
                ]);

                if (! $v->adjustStock(-(int) $x['quantity'], 'order', $order, $user->id, 'Checkout ' . $grup->number)) {
                    $this->reject('cart', "Stok {$x['product']['name']} - {$x['variant']['name']} di {$p['model']->name} tidak mencukupi. Checkout dibatalkan, periksa keranjang Anda.");
                }

                $dibeli[] = $v->id;
            }
        }

        if ($plan['platform_voucher'] !== null) {
            $this->tebus($plan['platform_voucher'], $user, $grup, $plan['totals']['subtotal'], $plan['platform_discount'], 'voucher_code');
        }

        foreach (array_values($plan['stores']) as $i => $p) {
            if ($p['store_voucher'] !== null) {
                $this->tebus($p['store_voucher'], $user, $grup, $p['subtotal'], $p['store_discount'], "stores.{$i}.voucher_code");
            }
        }

        // Hanya item yang benar-benar dibeli yang keluar dari keranjang.
        CartItem::query()->where('user_id', $user->id)->whereIn('variant_id', $dibeli)->delete();

        return $grup->fresh();
    }

    private function tebus(Voucher $voucher, User $user, CheckoutGroup $grup, int $subtotal, int $diskon, string $field): void
    {
        try {
            $pakai = $voucher->redeem($user, $grup, $subtotal);
        } catch (DomainException $e) {
            $this->reject($field, $e->getMessage());
        }

        // Potongan yang tercatat selalu sama dengan yang dipakai pada tagihan.
        if ((int) $pakai->discount_amount !== $diskon) {
            $pakai->forceFill(['discount_amount' => $diskon])->save();
        }
    }

    // ---------------------------------------------------------------- PRIVAT

    private function cariIdempoten(User $user, string $kunci): ?CheckoutGroup
    {
        return CheckoutGroup::query()->where('user_id', $user->id)->where('idempotency_key', $kunci)->first();
    }

    /**
     * Membagi potongan ke beberapa pesanan menurut sisa tagihannya, dalam bilangan bulat.
     * Sisa pembulatan diberikan ke pecahan terbesar, dan tidak ada pesanan yang kelebihan potongan.
     *
     * @param  array<int, int>  $sisa
     * @return array<int, int>
     */
    private function bagi(int $total, array $sisa): array
    {
        $hasil  = array_fill_keys(array_keys($sisa), 0);
        $jumlah = array_sum($sisa);

        if ($total <= 0 || $jumlah <= 0) {
            return $hasil;
        }

        $pecahan = [];
        $terbagi = 0;

        foreach ($sisa as $kunci => $nilai) {
            $kali           = $total * $nilai;
            $hasil[$kunci]  = intdiv($kali, $jumlah);
            $pecahan[$kunci] = $kali % $jumlah;
            $terbagi       += $hasil[$kunci];
        }

        $lebih = $total - $terbagi;
        arsort($pecahan);

        foreach (array_keys($pecahan) as $kunci) {
            if ($lebih <= 0) {
                break;
            }

            if ($pecahan[$kunci] > 0) {
                $hasil[$kunci]++;
                $lebih--;
            }
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function snapshotAlamat(Address $a): array
    {
        return [
            'label'            => $a->label,
            'recipient_name'   => $a->recipient_name,
            'phone'            => $a->phone,
            'province'         => $a->province,
            'city'             => $a->city,
            'district'         => $a->district,
            'village'          => $a->village,
            'postal_code'      => $a->postal_code,
            'street'           => $a->street,
            'latitude'         => $a->latitude,
            'longitude'        => $a->longitude,
            'biteship_area_id' => $a->biteship_area_id,
        ];
    }

    /** Kesalahan cek ongkir dari satu toko dipindah ke kolom daftar toko yang bersangkutan. */
    private function petakan(ValidationException $e, int $i): never
    {
        $pesan = [];

        foreach ($e->errors() as $kolom => $daftar) {
            $pesan[$kolom === 'store_id' ? "stores.{$i}.store_id" : $kolom] = $daftar;
        }

        throw ValidationException::withMessages($pesan);
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
