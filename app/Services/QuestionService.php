<?php

namespace App\Services;

use App\Models\ProductQuestion;
use App\Models\Store;
use App\Models\User;
use App\Support\MaskedName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tanya jawab produk: pertanyaan pembeli dan jawaban seller. */
class QuestionService
{
    public const MAKS_PER_HARI = 5;

    public const PESAN_SUDAH_DIJAWAB = 'Pertanyaan ini sudah dijawab.';
    public const PESAN_TOKO_SENDIRI  = 'Anda tidak dapat bertanya pada produk toko sendiri.';

    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * @throws ValidationException
     */
    public function ask(User $user, string $slug, string $teks): ProductQuestion
    {
        $produk = $this->reviews->publicProduct($slug);

        if ((int) $produk->store->owner_id === (int) $user->id) {
            $this->reject('question', self::PESAN_TOKO_SENDIRI);
        }

        return DB::transaction(function () use ($user, $produk, $teks) {
            // Satu pengguna diproses satu per satu agar dua permintaan tidak melewati batas harian.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $baru = ProductQuestion::query()
                ->where('product_id', $produk->id)
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($baru >= self::MAKS_PER_HARI) {
                $this->reject('question', 'Batas ' . self::MAKS_PER_HARI . ' pertanyaan per produk dalam 24 jam tercapai.');
            }

            $q = new ProductQuestion(['question' => trim($teks)]);
            $q->forceFill(['product_id' => $produk->id, 'user_id' => $user->id])->save();

            return $q->fresh();
        });
    }

    /**
     * Pertanyaan yang tayang pada produk. Filter: answered (sudah dijawab atau belum).
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listPublic(string $slug, array $filter): array
    {
        $produk = $this->reviews->publicProduct($slug);

        $q = ProductQuestion::query()->where('product_id', $produk->id)->where('status', 'published')->with('user')->orderByDesc('id');

        $this->saring($q, $filter);

        $hal = $q->paginate((int) ($filter['per_page'] ?? 10), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(ProductQuestion $x) => $this->present($x, $produk->store->name))->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /**
     * Pertanyaan pada produk toko ini yang tayang.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForSeller(Store $store, array $filter): array
    {
        $q = ProductQuestion::query()
            ->where('status', 'published')
            ->whereHas('product', fn($p) => $p->withTrashed()->where('store_id', $store->id))
            ->with(['user', 'product' => fn($p) => $p->withTrashed()])
            ->orderByDesc('id');

        $this->saring($q, $filter);

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(ProductQuestion $x) => $this->present($x, $store->name, true))->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /**
     * Seller menjawab pertanyaan pada produk tokonya satu kali.
     *
     * @throws ValidationException
     */
    public function answer(Store $store, string $uuid, User $actor, string $teks): ProductQuestion
    {
        return DB::transaction(function () use ($store, $uuid, $actor, $teks) {
            $q = ProductQuestion::query()
                ->where('uuid', $uuid)
                ->where('status', 'published')
                ->whereHas('product', fn($p) => $p->withTrashed()->where('store_id', $store->id))
                ->lockForUpdate()
                ->firstOrFail();

            if ($q->answer !== null) {
                $this->reject('answer', self::PESAN_SUDAH_DIJAWAB);
            }

            $q->answer($actor, trim($teks));

            return $q->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function present(ProductQuestion $q, ?string $namaToko = null, bool $denganProduk = false): array
    {
        $q->loadMissing('user');

        $data = [
            'id'         => $q->uuid,
            'question'   => $q->question,
            'asker'      => MaskedName::of($q->user?->name),
            'created_at' => $this->iso($q->created_at),
            'answer'     => $q->answer === null ? null : ['text' => $q->answer, 'answered_at' => $this->iso($q->answered_at), 'by' => $namaToko],
        ];

        if ($denganProduk) {
            $data['product'] = ['name' => $q->product?->name, 'slug' => $q->product?->slug];
        }

        return $data;
    }

    // ---------------------------------------------------------------- PRIVAT

    /** @param  array<string, mixed>  $filter */
    private function saring($query, array $filter): void
    {
        if (filled($filter['answered'] ?? null)) {
            filter_var($filter['answered'], FILTER_VALIDATE_BOOLEAN) ? $query->whereNotNull('answer') : $query->whereNull('answer');
        }
    }

    /** @return array<string, int> */
    private function meta($hal): array
    {
        return [
            'current_page' => $hal->currentPage(),
            'per_page'     => $hal->perPage(),
            'total'        => $hal->total(),
            'last_page'    => $hal->lastPage(),
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
