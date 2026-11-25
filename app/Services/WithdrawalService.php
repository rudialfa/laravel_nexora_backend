<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Penarikan dana: pengajuan oleh seller dan peninjauan oleh admin. */
class WithdrawalService
{
    public function __construct(private readonly FinanceService $finance) {}

    // ---------------------------------------------------------------- SELLER

    /**
     * Mengajukan penarikan. Saldo langsung berkurang dan rekening disimpan sebagai salinan.
     *
     * @throws ValidationException
     */
    public function request(Store $store, User $by, string $accountUuid, int $amount): Withdrawal
    {
        // Rekening toko lain atau yang tidak ada: 404.
        $akun = StoreBankAccount::query()->where('store_id', $store->id)->where('uuid', $accountUuid)->firstOrFail();

        $minimal = $this->finance->minimalPenarikan();

        if ($amount < $minimal) {
            $this->tolakInput('amount', 'Penarikan minimal Rp' . number_format($minimal, 0, ',', '.') . '.');
        }

        return DB::transaction(function () use ($store, $by, $akun, $amount) {
            $wallet = $this->finance->walletFor($store);

            // Dompet dikunci agar dua pengajuan bersamaan tidak melewati batas pengajuan.
            Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();

            $menunggu = Withdrawal::query()->where('store_id', $store->id)->where('status', Withdrawal::STATUS_PENDING)->count();

            if ($menunggu >= FinanceService::MAKS_PENGAJUAN_MENUNGGU) {
                $this->tolakInput('amount', 'Anda masih memiliki ' . FinanceService::MAKS_PENGAJUAN_MENUNGGU . ' pengajuan penarikan yang menunggu. Tunggu peninjauan admin.');
            }

            try {
                return $wallet->requestWithdrawal($by, $akun, $amount);
            } catch (DomainException $e) {
                $this->tolakInput('amount', $e->getMessage());
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForStore(Store $store, array $filter): array
    {
        return $this->paginate(Withdrawal::query()->where('store_id', $store->id), $filter, false);
    }

    /** Milik toko lain atau tidak ada: 404. */
    public function findForStore(Store $store, string $uuid): Withdrawal
    {
        return Withdrawal::query()->where('store_id', $store->id)->where('uuid', $uuid)->firstOrFail();
    }

    // ----------------------------------------------------------------- ADMIN

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listAll(array $filter): array
    {
        return $this->paginate(Withdrawal::query(), $filter, true);
    }

    public function find(string $uuid): Withdrawal
    {
        return Withdrawal::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @throws ValidationException */
    public function approve(string $uuid, User $admin): Withdrawal
    {
        $w = $this->find($uuid);

        try {
            $w->approve($admin);
        } catch (DomainException $e) {
            $this->tolakInput('withdrawal', $e->getMessage());
        }

        return $w->fresh();
    }

    /** @throws ValidationException */
    public function markPaid(string $uuid, User $admin, string $referensi): Withdrawal
    {
        $w = $this->find($uuid);

        try {
            $w->markPaid($admin, $referensi);
        } catch (DomainException $e) {
            $this->tolakInput('withdrawal', $e->getMessage());
        }

        return $w->fresh();
    }

    /** @throws ValidationException */
    public function reject(string $uuid, User $admin, string $alasan): Withdrawal
    {
        $w = $this->find($uuid);

        try {
            $w->reject($admin, $alasan);
        } catch (DomainException $e) {
            $this->tolakInput('withdrawal', $e->getMessage());
        }

        return $w->fresh();
    }

    // ------------------------------------------------------------- TAMPILAN

    /**
     * Nomor rekening lengkap hanya untuk admin pada tampilan detail. Seller hanya melihat empat digit terakhir.
     *
     * @return array<string, mixed>
     */
    public function present(Withdrawal $w, bool $admin = false, bool $detail = false): array
    {
        $data = [
            'id'                   => $w->uuid,
            'amount'               => (int) $w->amount,
            'status'               => $w->status,
            'bank_code'            => $w->bank_code,
            'bank_name'            => $w->bank_name,
            'account_name'         => $w->account_name,
            'account_number_last4' => $w->account_number_last4,
            'transfer_reference'   => $w->transfer_reference,
            'rejection_reason'     => $w->rejection_reason,
            'requested_at'         => $this->iso($w->created_at),
            'reviewed_at'          => $this->iso($w->reviewed_at),
            'paid_at'              => $this->iso($w->paid_at),
        ];

        if (! $admin) {
            return $data;
        }

        $w->loadMissing(['store', 'requester', 'reviewer']);

        $data['store']        = ['id' => $w->store?->uuid, 'name' => $w->store?->name];
        $data['requested_by'] = $w->requester?->name;
        $data['reviewed_by']  = $w->reviewer?->name;

        if ($detail) {
            $data['account_number'] = $w->account_number_encrypted;
        }

        return $data;
    }

    // ---------------------------------------------------------------- PRIVAT

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    private function paginate($query, array $filter, bool $admin): array
    {
        $query->orderByDesc('id');

        if ($admin) {
            $query->with(['store', 'requester', 'reviewer']);
        }

        if (filled($filter['status'] ?? null)) {
            $query->where('status', $filter['status']);
        }

        if ($admin && filled($filter['q'] ?? null)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filter['q']))) . '%';

            $query->whereHas('store', fn($s) => $s->whereRaw("lower(name) like ? escape '!'", [$like]));
        }

        $hal = $query->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(Withdrawal $w) => $this->present($w, $admin))->values()->all(),
            'meta'  => [
                'current_page' => $hal->currentPage(),
                'per_page'     => $hal->perPage(),
                'total'        => $hal->total(),
                'last_page'    => $hal->lastPage(),
            ],
        ];
    }

    private function iso(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }

    /** @throws ValidationException */
    private function tolakInput(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
