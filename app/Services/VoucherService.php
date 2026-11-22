<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan voucher (admin dan seller) dan pengecekan kode oleh pembeli.
 * Pemakaian sebenarnya (kuota naik) terjadi saat checkout lewat Voucher::redeem().
 */
class VoucherService
{
    public const PESAN_TIDAK_VALID       = 'Kode voucher tidak valid atau tidak dapat digunakan.';
    public const PESAN_KUOTA_HABIS       = 'Kuota voucher telah habis.';
    public const PESAN_BATAS_PENGGUNA    = 'Anda sudah mencapai batas pemakaian voucher ini.';
    public const PESAN_KERANJANG_KOSONG  = 'Pilih produk di keranjang terlebih dahulu untuk memakai voucher.';
    public const PESAN_TOKO_TIDAK_COCOK  = 'Voucher ini hanya berlaku untuk produk dari toko tertentu. Pilih produk dari toko tersebut di keranjang.';

    private const FIELD_BOLEH = [
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_purchase',
        'quota',
        'per_user_limit',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    public function __construct(private readonly CartService $cart) {}

    // ------------------------------------------------------------- PEMBELI

    /**
     * Memeriksa kode voucher terhadap item terpilih di keranjang dan menghitung potongannya.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function check(User $user, string $kode): array
    {
        $voucher = Voucher::query()->with('store')->where('code', strtoupper(trim($kode)))->first();

        if (
            $voucher === null
            || ($voucher->store_id !== null && $voucher->store === null)
            || in_array($this->status($voucher), ['inactive', 'scheduled', 'expired'], true)
        ) {
            $this->reject('code', self::PESAN_TIDAK_VALID);
        }

        if ($this->status($voucher) === 'exhausted') {
            $this->reject('code', self::PESAN_KUOTA_HABIS);
        }

        $dipakai = VoucherUsage::query()
            ->where('voucher_id', $voucher->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['reserved', 'used'])
            ->count();

        if ($dipakai >= (int) $voucher->per_user_limit) {
            $this->reject('code', self::PESAN_BATAS_PENGGUNA);
        }

        // Subtotal hanya menghitung item yang dipilih dan tersedia (aturan yang sama dengan keranjang).
        $grup = collect($this->cart->view($user)['groups']);

        if ($voucher->store_id === null) {
            $subtotal = (int) $grup->sum('subtotal');
        } else {
            $subtotal = (int) (($grup->firstWhere('store.id', $voucher->store->uuid)['subtotal'] ?? 0));
        }

        if ($subtotal <= 0) {
            $this->reject('code', $voucher->store_id === null ? self::PESAN_KERANJANG_KOSONG : self::PESAN_TOKO_TIDAK_COCOK);
        }

        if ($subtotal < (int) $voucher->min_purchase) {
            $this->reject('code', 'Minimal belanja Rp' . number_format((int) $voucher->min_purchase, 0, ',', '.') . ' untuk memakai voucher ini.');
        }

        $diskon = min($subtotal, max(0, (int) $voucher->calculateDiscount($subtotal)));

        return [
            'voucher' => [
                'code'           => $voucher->code,
                'name'           => $voucher->name,
                'description'    => $voucher->description,
                'discount_type'  => $voucher->discount_type,
                'discount_value' => (int) $voucher->discount_value,
                'max_discount'   => $voucher->max_discount === null ? null : (int) $voucher->max_discount,
                'min_purchase'   => (int) $voucher->min_purchase,
                'ends_at'        => $this->iso($voucher->ends_at),
                'scope'          => $voucher->store_id === null ? 'platform' : 'store',
                'store'          => $voucher->store_id === null ? null : ['id' => $voucher->store->uuid, 'name' => $voucher->store->name],
            ],
            'eligible_subtotal' => $subtotal,
            'discount_amount'   => $diskon,
        ];
    }

    // ------------------------------------------------------------ PENGELOLA

    /** Voucher milik pengelola: platform (storeId null) atau satu toko. Bukan miliknya: 404. */
    public function find(?int $storeId, string $uuid): Voucher
    {
        return Voucher::query()
            ->where('uuid', $uuid)
            ->when($storeId === null, fn($q) => $q->whereNull('store_id'), fn($q) => $q->where('store_id', $storeId))
            ->firstOrFail();
    }

    /**
     * Daftar voucher dengan pencarian, filter status, dan paginasi.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(?int $storeId, array $filter): array
    {
        $sekarang = now();

        $q = Voucher::query()
            ->when($storeId === null, fn($w) => $w->whereNull('store_id'), fn($w) => $w->where('store_id', $storeId))
            ->orderByDesc('id');

        if (filled($filter['q'] ?? null)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filter['q']))) . '%';

            $q->where(function ($w) use ($like) {
                $w->whereRaw("lower(code) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(name) like ? escape '!'", [$like]);
            });
        }

        $dalamMasaTayang = function ($w) use ($sekarang) {
            $w->where(fn($x) => $x->whereNull('starts_at')->orWhere('starts_at', '<=', $sekarang))
                ->where(fn($x) => $x->whereNull('ends_at')->orWhere('ends_at', '>=', $sekarang));
        };

        switch ($filter['status'] ?? null) {
            case 'active':
                $q->where('is_active', true);
                $dalamMasaTayang($q);
                $q->where(fn($x) => $x->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'));
                break;
            case 'inactive':
                $q->where('is_active', false);
                break;
            case 'scheduled':
                $q->where('is_active', true)->where('starts_at', '>', $sekarang);
                break;
            case 'expired':
                $q->where('is_active', true)
                    ->where(fn($x) => $x->whereNull('starts_at')->orWhere('starts_at', '<=', $sekarang))
                    ->where('ends_at', '<', $sekarang);
                break;
            case 'exhausted':
                $q->where('is_active', true);
                $dalamMasaTayang($q);
                $q->whereNotNull('quota')->whereColumn('used_count', '>=', 'quota');
                break;
        }

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(Voucher $v) => $this->present($v))->values()->all(),
            'meta'  => [
                'current_page' => $hal->currentPage(),
                'per_page'     => $hal->perPage(),
                'total'        => $hal->total(),
                'last_page'    => $hal->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Sudah divalidasi.
     *
     * @throws ValidationException
     */
    public function create(array $data, ?Store $store, User $pembuat): Voucher
    {
        $mulai = $this->waktu($data['starts_at'] ?? null);
        $akhir = $this->waktu($data['ends_at'] ?? null);

        $this->assertPeriode($mulai, $akhir);

        $voucher = new Voucher;
        $voucher->forceFill([
            'store_id'       => $store?->id,
            'code'           => strtoupper(trim($data['code'])),
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'discount_type'  => $data['discount_type'],
            'discount_value' => (int) $data['discount_value'],
            'max_discount'   => $data['discount_type'] === Voucher::TYPE_PERCENTAGE ? ($data['max_discount'] ?? null) : null,
            'min_purchase'   => (int) ($data['min_purchase'] ?? 0),
            'quota'          => $data['quota'] ?? null,
            'per_user_limit' => (int) ($data['per_user_limit'] ?? 1),
            'starts_at'      => $mulai,
            'ends_at'        => $akhir,
            'is_active'      => (bool) ($data['is_active'] ?? true),
            'created_by'     => $pembuat->id,
        ])->save();

        return $voucher->fresh();
    }

    /**
     * Mengubah sebagian kolom. Kode, kepemilikan, dan jumlah pemakaian tidak pernah berubah.
     *
     * @param  array<string, mixed>  $data  Sudah divalidasi.
     *
     * @throws ValidationException
     */
    public function update(Voucher $voucher, array $data): Voucher
    {
        $data = array_intersect_key($data, array_flip(self::FIELD_BOLEH));

        $tipe  = $data['discount_type'] ?? $voucher->discount_type;
        $nilai = (int) ($data['discount_value'] ?? $voucher->discount_value);

        if ($tipe === Voucher::TYPE_PERCENTAGE && $nilai > 100) {
            $this->reject('discount_value', 'Diskon persen maksimal 100.');
        }

        if (array_key_exists('quota', $data) && $data['quota'] !== null && (int) $data['quota'] < (int) $voucher->used_count) {
            $this->reject('quota', 'Kuota tidak boleh lebih kecil dari jumlah pemakaian (' . (int) $voucher->used_count . ').');
        }

        if (array_key_exists('starts_at', $data)) {
            $data['starts_at'] = $this->waktu($data['starts_at']);
        }

        if (array_key_exists('ends_at', $data)) {
            $data['ends_at'] = $this->waktu($data['ends_at']);
        }

        $this->assertPeriode(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : $this->waktu($voucher->starts_at),
            array_key_exists('ends_at', $data) ? $data['ends_at'] : $this->waktu($voucher->ends_at),
        );

        if ($tipe === Voucher::TYPE_FIXED) {
            $data['max_discount'] = null;
        }

        $voucher->forceFill($data)->save();

        return $voucher->fresh();
    }

    /**
     * Menghapus voucher yang belum pernah dipakai (permanen, sehingga kodenya bebas lagi).
     *
     * @throws ValidationException
     */
    public function delete(Voucher $voucher): void
    {
        if ($voucher->usages()->exists()) {
            $this->reject('voucher', 'Voucher ini sudah pernah dipakai dan tidak dapat dihapus. Nonaktifkan saja.');
        }

        $voucher->forceDelete();
    }

    /** @return array<string, mixed> */
    public function present(Voucher $v): array
    {
        return [
            'id'             => $v->uuid,
            'code'           => $v->code,
            'name'           => $v->name,
            'description'    => $v->description,
            'discount_type'  => $v->discount_type,
            'discount_value' => (int) $v->discount_value,
            'max_discount'   => $v->max_discount === null ? null : (int) $v->max_discount,
            'min_purchase'   => (int) $v->min_purchase,
            'quota'          => $v->quota === null ? null : (int) $v->quota,
            'used_count'     => (int) $v->used_count,
            'per_user_limit' => (int) $v->per_user_limit,
            'starts_at'      => $this->iso($v->starts_at),
            'ends_at'        => $this->iso($v->ends_at),
            'is_active'      => (bool) $v->is_active,
            'status'         => $this->status($v),
            'scope'          => $v->store_id === null ? 'platform' : 'store',
            'created_at'     => $this->iso($v->created_at),
        ];
    }

    /** active, inactive, scheduled, expired, atau exhausted (kuota habis). */
    public function status(Voucher $v): string
    {
        $sekarang = now();
        $mulai    = $this->waktu($v->starts_at);
        $akhir    = $this->waktu($v->ends_at);

        return match (true) {
            ! $v->is_active                                                     => 'inactive',
            $mulai !== null && $mulai->gt($sekarang)                            => 'scheduled',
            $akhir !== null && $akhir->lt($sekarang)                            => 'expired',
            $v->quota !== null && (int) $v->used_count >= (int) $v->quota       => 'exhausted',
            default                                                             => 'active',
        };
    }

    // ---------------------------------------------------------------- PRIVAT

    /** Waktu dari klien (zona waktu apa pun) disamakan ke zona waktu aplikasi sebelum disimpan. */
    private function waktu(mixed $nilai): ?Carbon
    {
        return filled($nilai) ? Carbon::parse($nilai)->setTimezone(config('app.timezone')) : null;
    }

    private function iso(mixed $nilai): ?string
    {
        return $this->waktu($nilai)?->toIso8601String();
    }

    /** @throws ValidationException */
    private function assertPeriode(?Carbon $mulai, ?Carbon $akhir): void
    {
        if ($mulai !== null && $akhir !== null && $akhir->lte($mulai)) {
            $this->reject('ends_at', 'Waktu berakhir harus setelah waktu mulai.');
        }
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
