<?php

namespace App\Services;

use App\Models\CheckoutGroup;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\MaskedName;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Komplain, tanggapan seller, keputusan admin, dan pengembalian dana (refund).
 * Perpindahan status pesanan tetap lewat Order::transitionTo() dan saldo toko lewat method Wallet.
 */
class DisputeService
{
    public const WINDOW_DAYS  = 7;
    public const RESPOND_DAYS = 3;

    public const PESAN_BELUM_SAMPAI       = 'Komplain hanya dapat diajukan untuk pesanan yang sudah sampai.';
    public const PESAN_LEWAT_BATAS        = 'Batas waktu komplain (7 hari sejak pesanan sampai) sudah lewat.';
    public const PESAN_SUDAH_ADA          = 'Pesanan ini sudah pernah diajukan komplain.';
    public const PESAN_SUDAH_MENANGGAPI   = 'Anda sudah menanggapi komplain ini.';
    public const PESAN_SUDAH_DIPUTUSKAN   = 'Komplain ini sudah diputuskan.';
    public const PESAN_FOTO_GAGAL         = 'Foto bukti tidak dapat diproses.';

    public function __construct(private readonly FinanceService $finance) {}

    // ---------------------------------------------------------------- PEMBELI

    /**
     * Mengajukan komplain. Foto diproses ulang menjadi JPEG sebelum disimpan.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $foto
     *
     * @throws ValidationException
     */
    public function create(User $user, string $orderUuid, array $data, array $foto): Dispute
    {
        $order = Order::query()->where('user_id', $user->id)->where('uuid', $orderUuid)->firstOrFail();

        $this->periksaSyarat($order);

        $diminta = isset($data['requested_amount']) ? (int) $data['requested_amount'] : null;

        if ($diminta !== null && $diminta > (int) $order->grand_total) {
            $this->tolak('requested_amount', 'Jumlah yang diminta melebihi total pesanan.');
        }

        $binari  = array_map(fn(UploadedFile $f) => $this->prosesFoto($f), array_values($foto));
        $ditulis = [];

        try {
            $d = DB::transaction(function () use ($user, $order, $data, $diminta, $binari, &$ditulis) {
                // Pesanan dikunci agar dua pengajuan bersamaan tidak lolos bersamaan.
                Order::query()->whereKey($order->id)->lockForUpdate()->first();

                if (Dispute::query()->where('order_id', $order->id)->exists()) {
                    $this->tolak('order', self::PESAN_SUDAH_ADA);
                }

                $d = new Dispute([
                    'type'             => Dispute::TYPE_COMPLAINT,
                    'reason_code'      => $data['reason_code'],
                    'description'      => trim((string) $data['description']),
                    'requested_amount' => $diminta,
                ]);
                $d->forceFill(['order_id' => $order->id, 'user_id' => $user->id, 'store_id' => $order->store_id]);
                $d->save();

                $this->simpanBukti($d, $user, 'buyer', $binari, $ditulis);

                return $d;
            });
        } catch (Throwable $e) {
            $this->hapusBerkas($ditulis);

            throw $e;
        }

        return $this->muat($d->id);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForBuyer(User $user, array $filter): array
    {
        return $this->paginasi(Dispute::query()->where('user_id', $user->id), $filter, 'buyer');
    }

    /** Milik pembeli ini. Milik orang lain atau tidak ada: 404. */
    public function findForBuyer(User $user, string $uuid): Dispute
    {
        return $this->lengkapi(Dispute::query()->where('user_id', $user->id)->where('uuid', $uuid))->firstOrFail();
    }

    // ----------------------------------------------------------------- SELLER

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForStore(Store $store, array $filter): array
    {
        return $this->paginasi(Dispute::query()->where('store_id', $store->id), $filter, 'seller');
    }

    public function findForStore(Store $store, string $uuid): Dispute
    {
        return $this->lengkapi(Dispute::query()->where('store_id', $store->id)->where('uuid', $uuid))->firstOrFail();
    }

    /**
     * Seller menanggapi komplain satu kali (sebelum diputuskan).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $foto
     *
     * @throws ValidationException
     */
    public function respond(Store $store, string $uuid, User $actor, array $data, array $foto): Dispute
    {
        $binari  = array_map(fn(UploadedFile $f) => $this->prosesFoto($f), array_values($foto));
        $ditulis = [];

        try {
            $id = DB::transaction(function () use ($store, $uuid, $actor, $data, $binari, &$ditulis) {
                $d = Dispute::query()->where('store_id', $store->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

                if (! in_array($d->status, [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW], true)) {
                    $this->tolak('dispute', self::PESAN_SUDAH_DIPUTUSKAN);
                }

                if ($d->seller_decision !== null) {
                    $this->tolak('response', self::PESAN_SUDAH_MENANGGAPI);
                }

                $d->forceFill([
                    'seller_decision'     => $data['decision'],
                    'seller_response'     => trim((string) $data['response']),
                    'seller_responded_at' => now(),
                ])->save();

                $this->simpanBukti($d, $actor, 'seller', $binari, $ditulis);

                return $d->id;
            });
        } catch (Throwable $e) {
            $this->hapusBerkas($ditulis);

            throw $e;
        }

        return $this->muat($id);
    }

    // ------------------------------------------------------------------ ADMIN

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listAll(array $filter): array
    {
        return $this->paginasi(Dispute::query(), $filter, 'admin');
    }

    public function find(string $uuid): Dispute
    {
        return $this->lengkapi(Dispute::query()->where('uuid', $uuid))->firstOrFail();
    }

    /** @throws ValidationException */
    public function review(string $uuid, User $admin): Dispute
    {
        $d = $this->find($uuid);

        try {
            $d->startReview($admin);
        } catch (DomainException $e) {
            $this->tolakDomain($e);
        }

        return $this->muat($d->id);
    }

    /**
     * Menyetujui komplain: refund dibuat, dana seller dibalik (atau ditanggung platform), dan pesanan menjadi "refunded".
     * Semuanya dalam satu transaksi: kegagalan di mana pun membatalkan seluruhnya.
     *
     * @param  array<string, mixed>  $data  amount, note, bearer (seller atau platform), include_service_fee
     *
     * @throws ValidationException
     */
    public function approve(string $uuid, User $admin, array $data): Dispute
    {
        $id = $this->find($uuid)->id;

        DB::transaction(function () use ($id, $admin, $data) {
            $d     = Dispute::query()->lockForUpdate()->findOrFail($id);
            $order = Order::query()->lockForUpdate()->findOrFail($d->order_id);

            if (! in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED], true)) {
                $this->tolak('order', 'Pesanan tidak lagi berstatus sampai atau selesai, sehingga dananya tidak dapat dikembalikan.');
            }

            $jumlah      = (int) ($data['amount'] ?? $d->requested_amount ?? $order->grand_total);
            $penanggung  = $data['bearer'] ?? 'seller';
            $denganBiaya = (bool) ($data['include_service_fee'] ?? false);

            if ($jumlah < 1) {
                $this->tolak('amount', 'Jumlah pengembalian minimal Rp1.');
            }

            $biaya = $denganBiaya ? $this->biayaLayanan($order) : 0;

            try {
                if ($d->status === Dispute::STATUS_OPEN) {
                    $d->startReview($admin);
                }

                $d->approve($admin, $jumlah, isset($data['note']) ? trim((string) $data['note']) : null);
            } catch (DomainException $e) {
                $this->tolakDomain($e);
            }

            // Dana toko diselesaikan SEBELUM status pesanan berubah, supaya pengait keuangan tidak membalik dua kali.
            $hasil = $this->bebankan($order, $jumlah, $penanggung === 'seller');

            $refund = new Refund([
                'order_id'   => $order->id,
                'dispute_id' => $d->id,
                'amount'     => $jumlah + $biaya,
                'method'     => Refund::METHOD_MANUAL,
                'reason'     => Str::limit('Komplain disetujui: ' . $d->reason_code, 250, ''),
            ]);
            $refund->save();

            $d->forceFill([
                'bearer'               => $penanggung,
                'service_fee_refunded' => $biaya,
                'seller_charged'       => $hasil['charged'],
                'shortfall'            => $hasil['shortfall'],
            ])->save();

            try {
                $order->transitionTo(Order::STATUS_REFUNDED, Order::ACTOR_ADMIN, $admin->id, 'Komplain disetujui');
            } catch (DomainException $e) {
                $this->tolak('order', $e->getMessage());
            }
        });

        return $this->muat($id);
    }

    /** @throws ValidationException */
    public function reject(string $uuid, User $admin, string $alasan): Dispute
    {
        $id = $this->find($uuid)->id;

        DB::transaction(function () use ($id, $admin, $alasan) {
            $d = Dispute::query()->lockForUpdate()->findOrFail($id);

            try {
                if ($d->status === Dispute::STATUS_OPEN) {
                    $d->startReview($admin);
                }

                $d->reject($admin, trim($alasan));
            } catch (DomainException $e) {
                $this->tolakDomain($e);
            }
        });

        return $this->muat($id);
    }

    /**
     * Membuat refund pengganti untuk komplain yang sudah disetujui tetapi refund sebelumnya gagal.
     *
     * @throws ValidationException
     */
    public function retryRefund(string $uuid): Dispute
    {
        $d = $this->find($uuid);

        if ($d->status !== Dispute::STATUS_APPROVED) {
            $this->tolak('dispute', 'Hanya komplain yang disetujui yang dapat dibuatkan pengembalian dana.');
        }

        if (Refund::query()->where('dispute_id', $d->id)->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_COMPLETED])->exists()) {
            $this->tolak('dispute', 'Pengembalian dana untuk komplain ini sudah ada atau sedang diproses.');
        }

        $r = new Refund([
            'order_id'   => $d->order_id,
            'dispute_id' => $d->id,
            'amount'     => (int) $d->approved_amount + (int) $d->service_fee_refunded,
            'method'     => Refund::METHOD_MANUAL,
            'reason'     => Str::limit('Komplain disetujui (diulang): ' . $d->reason_code, 250, ''),
        ]);
        $r->save();

        return $this->muat($d->id);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listRefunds(array $filter): array
    {
        $q = Refund::query()->with(['order.store', 'order.buyer', 'dispute'])->orderByDesc('id');

        if (filled($filter['status'] ?? null)) {
            $q->where('status', $filter['status']);
        }

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(Refund $r) => $this->presentRefund($r, true))->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /** Pengembalian dana selesai. Jika berasal dari komplain, komplainnya ikut selesai. */
    public function completeRefund(string $uuid, User $admin, string $referensi): Refund
    {
        $r = Refund::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $r->complete($admin, trim($referensi));

            if ($r->dispute_id !== null) {
                $d = Dispute::query()->find($r->dispute_id);

                if ($d !== null && $d->status === Dispute::STATUS_APPROVED) {
                    $d->resolve($admin);
                }
            }
        } catch (DomainException $e) {
            $this->tolak('refund', $e->getMessage());
        }

        return $r->fresh(['order.store', 'order.buyer', 'dispute']);
    }

    public function failRefund(string $uuid, User $admin, string $alasan): Refund
    {
        $r = Refund::query()->where('uuid', $uuid)->firstOrFail();

        try {
            $r->fail($admin, trim($alasan));
        } catch (DomainException $e) {
            $this->tolak('refund', $e->getMessage());
        }

        return $r->fresh(['order.store', 'order.buyer', 'dispute']);
    }

    /** Pesanan masih punya komplain yang belum diputuskan. Dipakai penjadwal konfirmasi otomatis nanti. */
    public function hasActive(Order $order): bool
    {
        return Dispute::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW])
            ->exists();
    }

    // ------------------------------------------------------------- TAMPILAN

    /** @return array<string, mixed> */
    public function present(Dispute $d, string $peran): array
    {
        $d->loadMissing(['order', 'store', 'buyer', 'evidences', 'refunds']);

        $disk   = Storage::disk('public');
        $putus  = in_array($d->status, [Dispute::STATUS_APPROVED, Dispute::STATUS_REJECTED, Dispute::STATUS_RESOLVED], true);
        $respon = $d->seller_decision === null ? null : [
            'decision'     => $d->seller_decision,
            'text'         => $d->seller_response,
            'responded_at' => $this->iso($d->seller_responded_at),
        ];

        $data = [
            'id'                  => $d->uuid,
            'status'              => $d->status,
            'reason_code'         => $d->reason_code,
            'description'         => $d->description,
            'requested_amount'    => $d->requested_amount === null ? null : (int) $d->requested_amount,
            'approved_amount'     => $d->approved_amount === null ? null : (int) $d->approved_amount,
            'order'               => [
                'id'          => $d->order?->uuid,
                'number'      => $d->order?->number,
                'status'      => $d->order?->status,
                'grand_total' => (int) $d->order?->grand_total,
            ],
            'store'               => ['id' => $d->store?->uuid, 'name' => $d->store?->name],
            'seller_response'     => $respon,
            'seller_respond_until' => $respon === null ? $this->iso(Carbon::parse($d->created_at)->addDays(self::RESPOND_DAYS)) : null,
            'can_respond'         => $peran === 'seller' && $respon === null && in_array($d->status, [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW], true),
            'admin_note'          => ($putus || $peran === 'admin') ? $d->admin_note : null,
            'evidences'           => $d->evidences->sortBy('id')->map(fn($e) => [
                'url'        => $disk->url($e->path),
                'by'         => $e->note,
                'created_at' => $this->iso($e->created_at),
            ])->values()->all(),
            'refunds'             => $d->refunds->sortBy('id')->map(fn(Refund $r) => $this->presentRefund($r, false))->values()->all(),
            'created_at'          => $this->iso($d->created_at),
            'reviewed_at'         => $this->iso($d->reviewed_at),
            'resolved_at'         => $this->iso($d->resolved_at),
        ];

        if ($peran !== 'buyer') {
            $data['buyer'] = ['name' => $peran === 'admin' ? $d->buyer?->name : MaskedName::of($d->buyer?->name)];
        }

        if ($peran === 'seller') {
            $data['seller_charged'] = (int) $d->seller_charged;
        }

        if ($peran === 'admin') {
            $data['bearer']               = $d->bearer;
            $data['seller_charged']       = (int) $d->seller_charged;
            $data['shortfall']            = (int) $d->shortfall;
            $data['service_fee_refunded'] = (int) $d->service_fee_refunded;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function presentRefund(Refund $r, bool $admin): array
    {
        $data = [
            'id'           => $r->uuid,
            'amount'       => (int) $r->amount,
            'status'       => $r->status,
            'reason'       => $r->reason,
            'reference'    => $r->reference,
            'processed_at' => $this->iso($r->processed_at),
            'created_at'   => $this->iso($r->created_at),
        ];

        if ($admin) {
            $r->loadMissing(['order.store', 'order.buyer', 'dispute']);

            $data['failure_reason'] = $r->failure_reason;
            $data['order']          = ['id' => $r->order?->uuid, 'number' => $r->order?->number];
            $data['store']          = ['name' => $r->order?->store?->name];
            $data['buyer']          = ['name' => $r->order?->buyer?->name];
            $data['dispute_id']     = $r->dispute?->uuid;
        }

        return $data;
    }

    // ---------------------------------------------------------------- PRIVAT

    /** @throws ValidationException */
    private function periksaSyarat(Order $o): void
    {
        if (! in_array($o->status, [Order::STATUS_DELIVERED, Order::STATUS_COMPLETED], true)) {
            $this->tolak('order', self::PESAN_BELUM_SAMPAI);
        }

        $sejak = $o->delivered_at ?? $o->completed_at;

        if ($sejak === null || Carbon::parse($sejak)->addDays(self::WINDOW_DAYS)->lt(now())) {
            $this->tolak('order', self::PESAN_LEWAT_BATAS);
        }

        if (Dispute::query()->where('order_id', $o->id)->exists()) {
            $this->tolak('order', self::PESAN_SUDAH_ADA);
        }
    }

    /**
     * Membalik dana penjualan dari dompet toko. Yang dibebankan ke seller paling banyak penghasilan bersih pesanan ini
     * dan paling banyak saldo yang ada; selebihnya dicatat sebagai kekurangan. Sisa dana yang masih tertahan dicairkan.
     *
     * @return array{charged: int, shortfall: int}
     */
    private function bebankan(Order $order, int $jumlah, bool $penjualMenanggung): array
    {
        $target = $penjualMenanggung ? min($jumlah, $this->finance->penghasilan($order)) : 0;
        $wallet = Wallet::query()->where('store_id', $order->store_id)->first();

        if ($wallet === null) {
            return ['charged' => 0, 'shortfall' => $target];
        }

        if ($order->status === Order::STATUS_DELIVERED) {
            $ditahan = (int) WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', Wallet::TYPE_SALE_HOLD)
                ->where('reference_type', $order->getMorphClass())
                ->where('reference_id', $order->getKey())
                ->value('amount');

            if ($ditahan <= 0) {
                return ['charged' => 0, 'shortfall' => $target];
            }

            $dibebankan = min($target, $ditahan);

            if ($dibebankan > 0) {
                $wallet->reverseSale($dibebankan, $order, Wallet::BUCKET_PENDING);
            }

            if ($ditahan - $dibebankan > 0) {
                $wallet->releaseSale($ditahan - $dibebankan, $order);
            }

            return ['charged' => $dibebankan, 'shortfall' => $target - $dibebankan];
        }

        // Pesanan sudah selesai: dananya ada di saldo yang bisa ditarik.
        $dibebankan = min($target, (int) $wallet->fresh()->balance);

        if ($dibebankan > 0) {
            $wallet->reverseSale($dibebankan, $order, Wallet::BUCKET_AVAILABLE);
        }

        return ['charged' => $dibebankan, 'shortfall' => $target - $dibebankan];
    }

    /** Biaya layanan checkout, hanya jika belum pernah dikembalikan lewat komplain lain. */
    private function biayaLayanan(Order $order): int
    {
        $sudah = (int) Dispute::query()
            ->whereIn('order_id', Order::query()->where('checkout_group_id', $order->checkout_group_id)->select('id'))
            ->sum('service_fee_refunded');

        if ($sudah > 0) {
            $this->tolak('include_service_fee', 'Biaya layanan checkout ini sudah pernah dikembalikan.');
        }

        return (int) CheckoutGroup::query()->whereKey($order->checkout_group_id)->value('service_fee');
    }

    private function lengkapi(Builder $q): Builder
    {
        return $q->with(['order', 'store', 'buyer', 'evidences', 'refunds']);
    }

    private function muat(int $id): Dispute
    {
        return $this->lengkapi(Dispute::query()->whereKey($id))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function paginasi(Builder $q, array $filter, string $peran): array
    {
        $this->lengkapi($q)->orderByDesc('id');

        if (filled($filter['status'] ?? null)) {
            $q->where('status', $filter['status']);
        }

        if (filled($filter['q'] ?? null)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filter['q']))) . '%';

            $q->whereHas('order', fn($o) => $o->whereRaw("lower(number) like ? escape '!'", [$like]));
        }

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(Dispute $d) => $this->present($d, $peran))->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /**
     * @param  array<int, string>  $binari
     * @param  array<int, string>  $ditulis
     */
    private function simpanBukti(Dispute $d, User $oleh, string $pihak, array $binari, array &$ditulis): void
    {
        foreach ($binari as $isi) {
            $path = "disputes/{$d->uuid}/" . Str::random(40) . '.jpg';

            Storage::disk('public')->put($path, $isi);
            $ditulis[] = $path;

            DisputeEvidence::create([
                'dispute_id' => $d->id,
                'user_id'    => $oleh->id,
                'path'       => $path,
                'mime_type'  => 'image/jpeg',
                'size_bytes' => strlen($isi),
                'note'       => $pihak,
            ]);
        }
    }

    /**
     * Foto diubah menjadi JPEG baru (maksimal 1600 px). Metadata dan muatan tersembunyi tidak ikut terbawa.
     *
     * @throws ValidationException
     */
    private function prosesFoto(UploadedFile $f): string
    {
        $isi    = @file_get_contents((string) $f->getRealPath());
        $gambar = $isi === false ? false : @imagecreatefromstring($isi);

        if ($gambar === false) {
            $this->tolak('photos', self::PESAN_FOTO_GAGAL);
        }

        $w     = imagesx($gambar);
        $h     = imagesy($gambar);
        $skala = min(1, 1600 / max($w, $h));
        $lw    = max(1, (int) round($w * $skala));
        $lh    = max(1, (int) round($h * $skala));

        $kanvas = imagecreatetruecolor($lw, $lh);
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $gambar, 0, 0, 0, 0, $lw, $lh, $w, $h);

        ob_start();
        imagejpeg($kanvas, null, 85);

        return (string) ob_get_clean();
    }

    /** @param  array<int, string>  $path */
    private function hapusBerkas(array $path): void
    {
        if ($path !== []) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return array<string, int> */
    private function meta(LengthAwarePaginator $hal): array
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
    private function tolakDomain(DomainException $e): never
    {
        $pesan = $e->getMessage();
        $kolom = str_contains($pesan, 'melebihi') || str_contains($pesan, 'negatif') ? 'amount' : 'dispute';

        $this->tolak($kolom, $pesan);
    }

    /** @throws ValidationException */
    private function tolak(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
