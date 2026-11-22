<?php

namespace App\Services;

use App\Models\Address;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menghitung pilihan ongkos kirim untuk item terpilih dari satu toko di keranjang pembeli.
 */
class ShippingQuoteService
{
    public const PESAN_ALAMAT_TANPA_AREA = 'Alamat belum memiliki area pengiriman. Pilih area alamat terlebih dahulu.';
    public const PESAN_TIDAK_ADA_ITEM    = 'Tidak ada produk terpilih dari toko ini di keranjang.';
    public const PESAN_TOKO_LIBUR        = 'Toko sedang libur. Produk tetap tersimpan di keranjang sampai toko buka kembali.';
    public const PESAN_TOKO_TANPA_AREA   = 'Toko ini belum dapat mengirim pesanan saat ini.';
    public const PESAN_TANPA_KURIR       = 'Toko ini belum mengaktifkan layanan kurir.';

    public function __construct(
        private readonly CartService $cart,
        private readonly BiteshipService $biteship,
    ) {}

    /**
     * @return array{store: array, address: array, summary: array, options: array}
     *
     * @throws ValidationException
     */
    public function quote(User $user, string $storeUuid, string $addressUuid): array
    {
        // Alamat orang lain atau yang sudah dihapus: 404.
        $alamat = Address::query()->where('user_id', $user->id)->where('uuid', $addressUuid)->firstOrFail();

        if (blank($alamat->biteship_area_id)) {
            $this->reject('address_id', self::PESAN_ALAMAT_TANPA_AREA);
        }

        // Aturan "terpilih dan tersedia" sama dengan keranjang karena memakai tampilan keranjang.
        $grup    = collect($this->cart->view($user)['groups'])->firstWhere('store.id', $storeUuid);
        $dipilih = collect($grup['items'] ?? [])
            ->filter(fn(array $i) => $i['is_selected'] && $i['status'] === CartService::STATUS_AVAILABLE)
            ->values();

        if ($grup === null || $dipilih->isEmpty()) {
            $this->reject('store_id', self::PESAN_TIDAK_ADA_ITEM);
        }

        if (! $grup['store']['is_open']) {
            $this->reject('store_id', self::PESAN_TOKO_LIBUR);
        }

        $toko = Store::query()->where('uuid', $storeUuid)->firstOrFail();

        if (blank($toko->biteship_area_id)) {
            $this->reject('store_id', self::PESAN_TOKO_TANPA_AREA);
        }

        $kurir = $this->kurirToko($toko);

        if ($kurir === []) {
            $this->reject('store_id', self::PESAN_TANPA_KURIR);
        }

        $varian = ProductVariant::query()->whereIn('uuid', $dipilih->pluck('variant_id'))->get()->keyBy('uuid');

        $items = $dipilih->map(function (array $i) use ($varian) {
            $v = $varian->get($i['variant_id']);

            return [
                'name'     => Str::limit($i['product']['name'] . ' - ' . $i['variant']['name'], 100, ''),
                'value'    => (int) $i['final_price'],
                'length'   => (int) $v?->length_cm,
                'width'    => (int) $v?->width_cm,
                'height'   => (int) $v?->height_cm,
                'weight'   => max(1, (int) $i['variant']['weight_gram']),
                'quantity' => (int) $i['quantity'],
            ];
        })->all();

        $opsi = $this->biteship->rates((string) $toko->biteship_area_id, (string) $alamat->biteship_area_id, $items, $kurir);

        usort($opsi, fn(array $a, array $b) => [$a['price'], $a['courier_code'], $a['service_code']]
            <=> [$b['price'], $b['courier_code'], $b['service_code']]);

        return [
            'store'   => ['id' => $toko->uuid, 'name' => $toko->name],
            'address' => ['id' => $alamat->uuid, 'label' => $alamat->label],
            'summary' => [
                'items'       => count($items),
                'quantity'    => array_sum(array_column($items, 'quantity')),
                'weight_gram' => array_sum(array_map(fn(array $i) => $i['weight'] * $i['quantity'], $items)),
                'subtotal'    => (int) $grup['subtotal'],
            ],
            'options' => $opsi,
        ];
    }

    /**
     * Kode kurir yang dipakai toko. Belum pernah mengatur: daftar bawaan.
     * Sudah mengatur tetapi semuanya nonaktif: kosong.
     *
     * @return list<string>
     */
    private function kurirToko(Store $toko): array
    {
        $baris = $toko->courierSettings()->get();

        $kode = $baris->isEmpty()
            ? collect(config('biteship.default_couriers', []))
            : $baris->where('is_active', true)->pluck('courier_code');

        return $kode->map(fn($c) => strtolower(trim((string) $c)))->filter()->unique()->sort()->values()->all();
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
