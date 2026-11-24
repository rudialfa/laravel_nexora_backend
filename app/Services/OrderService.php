<?php

namespace App\Services;

use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alur pesanan: tampilan untuk pembeli dan seller, pemrosesan, pembatalan, konfirmasi terima, dan faktur.
 * Perpindahan status mengikuti Order::TRANSITIONS, jadi aturan siapa boleh apa tidak ditulis ulang di sini.
 */
class OrderService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly MidtransService $midtrans,
    ) {}

    // -------------------------------------------------------------- TAMPILAN

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function listForBuyer(User $user, array $filter): array
    {
        return $this->paginate(Order::query()->where('user_id', $user->id), $filter);
    }

    /** Seller hanya melihat pesanan yang sudah dibayar. */
    public function listForStore(Store $store, array $filter): array
    {
        return $this->paginate(Order::query()->where('store_id', $store->id)->whereNotNull('paid_at'), $filter);
    }

    /** Pesanan milik pembeli ini. Milik orang lain atau tidak ada: 404. */
    public function findForBuyer(User $user, string $uuid): Order
    {
        return Order::query()->where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** Pesanan toko ini yang sudah dibayar. Selain itu: 404. */
    public function findForStore(Store $store, string $uuid): Order
    {
        return Order::query()->where('store_id', $store->id)->whereNotNull('paid_at')->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> $peran: buyer atau seller */
    public function present(Order $o, string $peran): array
    {
        $o->loadMissing(['store', 'items', 'statusHistories', 'shipment']);

        $disk = Storage::disk('public');

        return [
            'id'                => $o->uuid,
            'number'            => $o->number,
            'status'            => $o->status,
            'store'             => ['id' => $o->store?->uuid, 'name' => $o->store?->name, 'slug' => $o->store?->slug],
            'shipping_address'  => Arr::except((array) $o->shipping_address, ['biteship_area_id']),
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
            'timeline'          => $o->statusHistories->sortBy('id')->values()->map(fn($h) => [
                'from_status' => $h->from_status,
                'to_status'   => $h->to_status,
                'actor_type'  => $h->actor_type,
                'note'        => $h->note,
                'created_at'  => $this->iso($h->created_at),
            ])->all(),
            'shipment'          => $o->shipment === null ? null : [
                'status'          => $o->shipment->status,
                'courier_company' => $o->shipment->courier_company,
                'courier_type'    => $o->shipment->courier_type,
                'waybill_id'      => $o->shipment->waybill_id,
                'tracking_id'     => $o->shipment->tracking_id,
            ],
            'created_at'        => $this->iso($o->created_at),
            'paid_at'           => $this->iso($o->paid_at),
            'shipped_at'        => $this->iso($o->shipped_at),
            'delivered_at'      => $this->iso($o->delivered_at),
            'auto_complete_at'  => $this->iso($o->auto_complete_at),
            'completed_at'      => $this->iso($o->completed_at),
            'cancelled_at'      => $this->iso($o->cancelled_at),
            'actions'           => $this->aksi($o, $peran),
        ];
    }

    /**
     * Faktur pesanan (JSON). Tersedia setelah pesanan dibayar.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function invoice(User $user, string $uuid): array
    {
        $o = $this->findForBuyer($user, $uuid);

        if ($o->paid_at === null) {
            $this->reject('order', 'Faktur tersedia setelah pesanan dibayar.');
        }

        $o->loadMissing(['store', 'items', 'checkoutGroup']);

        $g     = $o->checkoutGroup;
        $bayar = Payment::query()->where('checkout_group_id', $g->id)->where('status', Payment::STATUS_PAID)->orderByDesc('id')->first();

        return [
            'invoice_number'   => 'INV/' . $o->number,
            'order_number'     => $o->number,
            'checkout_number'  => $g->number,
            'issued_at'        => $this->iso($o->paid_at),
            'status'           => $o->status,
            'seller'           => ['name' => $o->store?->name],
            'buyer'            => ['name' => $user->name],
            'shipping_address' => Arr::except((array) $o->shipping_address, ['biteship_area_id']),
            'courier'          => ['company' => $o->courier_company, 'type' => $o->courier_type, 'etd' => $o->shipping_etd],
            'items'            => $o->items->map(fn($i) => [
                'product_name' => $i->product_name,
                'variant_name' => $i->variant_name,
                'quantity'     => (int) $i->quantity,
                'unit_price'   => (int) $i->unit_price,
                'subtotal'     => (int) $i->subtotal,
            ])->values()->all(),
            'totals'           => [
                'subtotal'    => (int) $o->subtotal,
                'shipping'    => (int) $o->shipping_cost,
                'discount'    => (int) $o->discount_total,
                'order_total' => (int) $o->grand_total,
            ],
            // Biaya layanan dibayar sekali per checkout, bukan per pesanan.
            'checkout'         => ['service_fee' => (int) $g->service_fee, 'grand_total' => (int) $g->grand_total],
            'payment'          => $bayar === null ? null : ['method' => $bayar->payment_type, 'bank' => $bayar->bank, 'paid_at' => $this->iso($bayar->paid_at)],
        ];
    }

    // ---------------------------------------------------------------- SELLER

    public function process(Store $store, string $uuid, User $actor): Order
    {
        return $this->pindahSeller($store, $uuid, $actor, Order::STATUS_PROCESSING, 'diproses');
    }

    public function pack(Store $store, string $uuid, User $actor): Order
    {
        return $this->pindahSeller($store, $uuid, $actor, Order::STATUS_PACKED, 'ditandai sudah dikemas');
    }

    /**
     * Seller membatalkan pesanan yang sudah dibayar: stok kembali dan pengembalian dana dicatat.
     * Biaya layanan ikut dikembalikan jika setelah ini seluruh pesanan dalam checkout menjadi batal.
     *
     * @throws ValidationException
     */
    public function sellerCancel(Store $store, string $uuid, User $actor, string $alasan): Order
    {
        return DB::transaction(function () use ($store, $uuid, $actor, $alasan) {
            $o = Order::query()
                ->where('store_id', $store->id)
                ->whereNotNull('paid_at')
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $o->canTransitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SELLER)) {
                $this->reject('order', "Pesanan berstatus {$o->status} tidak dapat dibatalkan.");
            }

            $o->transitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SELLER, $actor->id, Str::limit('Dibatalkan penjual: ' . $alasan, 500, ''));

            foreach ($o->items()->get() as $item) {
                ProductVariant::query()->find($item->variant_id)?->adjustStock((int) $item->quantity, 'order_cancel', $o, $actor->id, 'Dibatalkan penjual');
            }

            $semuaBatal = ! Order::query()
                ->where('checkout_group_id', $o->checkout_group_id)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->exists();

            $biaya = $semuaBatal ? (int) CheckoutGroup::query()->whereKey($o->checkout_group_id)->value('service_fee') : 0;

            $refund = new Refund([
                'order_id' => $o->id,
                'amount'   => (int) $o->grand_total + $biaya,
                'method'   => Refund::METHOD_MANUAL,
                'reason'   => Str::limit('Dibatalkan penjual: ' . $alasan, 250, ''),
            ]);
            $refund->save();

            return $o->fresh();
        });
    }

    // --------------------------------------------------------------- PEMBELI

    /**
     * Pembeli membatalkan pesanan yang belum dibayar. Seluruh checkout ikut ditutup.
     *
     * @throws ValidationException
     */
    public function buyerCancel(User $user, string $uuid): Order
    {
        $o = $this->findForBuyer($user, $uuid);

        if ($o->status !== Order::STATUS_PENDING_PAYMENT) {
            $this->reject('order', 'Pesanan yang sudah dibayar tidak dapat dibatalkan oleh pembeli. Hubungi penjual atau ajukan komplain.');
        }

        $grup = CheckoutGroup::query()->findOrFail($o->checkout_group_id);

        $menunggu = Payment::query()
            ->where('checkout_group_id', $grup->id)
            ->where('status', Payment::STATUS_PENDING)
            ->pluck('midtrans_order_id')
            ->all();

        if (! $this->payments->closeGroup($grup, CheckoutGroup::STATUS_CANCELLED, 'Dibatalkan pembeli')) {
            $this->reject('order', 'Checkout ini sudah tidak menunggu pembayaran.');
        }

        foreach ($menunggu as $orderId) {
            $this->midtrans->expire($orderId);
        }

        return $o->fresh();
    }

    /** Pembeli mengonfirmasi pesanan sudah diterima. */
    public function buyerComplete(User $user, string $uuid): Order
    {
        return DB::transaction(function () use ($user, $uuid) {
            $o = Order::query()->where('user_id', $user->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if (! $o->canTransitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER)) {
                $this->reject('order', 'Pesanan hanya dapat dikonfirmasi setelah sampai di tujuan dan belum dikonfirmasi.');
            }

            $o->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER, $user->id, 'Diterima pembeli');

            return $o->fresh();
        });
    }

    // ---------------------------------------------------------------- PRIVAT

    private function pindahSeller(Store $store, string $uuid, User $actor, string $ke, string $kata): Order
    {
        return DB::transaction(function () use ($store, $uuid, $actor, $ke, $kata) {
            $o = Order::query()
                ->where('store_id', $store->id)
                ->whereNotNull('paid_at')
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $o->canTransitionTo($ke, Order::ACTOR_SELLER)) {
                $this->reject('order', "Pesanan berstatus {$o->status} tidak dapat {$kata}.");
            }

            $o->transitionTo($ke, Order::ACTOR_SELLER, $actor->id);

            return $o->fresh();
        });
    }

    /** @return array<string, bool> */
    private function aksi(Order $o, string $peran): array
    {
        if ($peran === 'buyer') {
            return [
                'cancel'   => $o->status === Order::STATUS_PENDING_PAYMENT,
                'complete' => $o->canTransitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER),
            ];
        }

        return [
            'process' => $o->canTransitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER),
            'pack'    => $o->canTransitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER),
            'cancel'  => $o->canTransitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SELLER),
        ];
    }

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    private function paginate(Builder $query, array $filter): array
    {
        $query->with(['store', 'items'])->orderByDesc('id');

        if (filled($filter['status'] ?? null)) {
            $query->where('status', $filter['status']);
        }

        if (filled($filter['q'] ?? null)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filter['q']))) . '%';

            $query->whereRaw("lower(number) like ? escape '!'", [$like]);
        }

        $hal = $query->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(Order $o) => $this->presentList($o))->values()->all(),
            'meta'  => [
                'current_page' => $hal->currentPage(),
                'per_page'     => $hal->perPage(),
                'total'        => $hal->total(),
                'last_page'    => $hal->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentList(Order $o): array
    {
        $pertama = $o->items->first();

        return [
            'id'             => $o->uuid,
            'number'         => $o->number,
            'status'         => $o->status,
            'store'          => ['id' => $o->store?->uuid, 'name' => $o->store?->name],
            'items_count'    => (int) $o->items->sum('quantity'),
            'first_item'     => $pertama === null ? null : [
                'product_name' => $pertama->product_name,
                'variant_name' => $pertama->variant_name,
                'image_url'    => $pertama->image_path ? Storage::disk('public')->url($pertama->image_path) : null,
            ],
            'subtotal'       => (int) $o->subtotal,
            'shipping_cost'  => (int) $o->shipping_cost,
            'discount_total' => (int) $o->discount_total,
            'grand_total'    => (int) $o->grand_total,
            'created_at'     => $this->iso($o->created_at),
            'paid_at'        => $this->iso($o->paid_at),
        ];
    }

    private function iso(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
