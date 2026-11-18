<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public const MAX_PER_STORE = 5;

    public function __construct(private readonly AuthService $auth) {}

    /** @return Collection<int, StoreBankAccount> rekening utama dulu, lalu yang terbaru */
    public function list(Store $store): Collection
    {
        return $store->bankAccounts()->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    /** Mencari rekening HANYA di antara milik toko ini. Milik toko lain atau yang tidak ada sama-sama 404. */
    public function find(Store $store, string $uuid): StoreBankAccount
    {
        return $store->bankAccounts()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Menambah rekening. Baris toko dikunci agar dua permintaan bersamaan tidak bisa
     * melewati batas jumlah rekening atau menghasilkan dua rekening utama.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Store $store, User $user, array $data, Request $request): StoreBankAccount
    {
        return DB::transaction(function () use ($store, $user, $data, $request) {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $ada = $store->bankAccounts()->get();

            if ($ada->count() >= self::MAX_PER_STORE) {
                throw ValidationException::withMessages([
                    'bank_account' => ['Maksimal ' . self::MAX_PER_STORE . ' rekening per toko. Hapus salah satu rekening lama terlebih dahulu.'],
                ]);
            }

            $last4 = substr($data['account_number'], -4);

            $kembar = $ada
                ->where('bank_code', $data['bank_code'])
                ->where('account_number_last4', $last4)
                ->contains(fn(StoreBankAccount $akun) => $akun->account_number_encrypted === $data['account_number']);

            if ($kembar) {
                throw ValidationException::withMessages([
                    'account_number' => ['Rekening ini sudah terdaftar.'],
                ]);
            }

            $rekening = new StoreBankAccount(Arr::only($data, ['bank_code', 'bank_name', 'account_name']));
            $rekening->store_id = $store->id;
            $rekening->setAccountNumber($data['account_number']);
            $rekening->save();

            if ((bool) ($data['is_default'] ?? false) || $ada->isEmpty()) {
                $this->promote($store, $rekening);
            }

            $this->auth->record($user->id, 'store.bank_account_added', $request, [
                'account' => $rekening->uuid,
                'bank'    => $rekening->bank_code,
            ]);

            return $rekening->fresh();
        });
    }

    public function makeDefault(Store $store, User $user, StoreBankAccount $rekening, Request $request): StoreBankAccount
    {
        DB::transaction(function () use ($store, $user, $rekening, $request) {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $this->promote($store, $rekening);

            $this->auth->record($user->id, 'store.bank_account_default_changed', $request, ['account' => $rekening->uuid]);
        });

        return $rekening->fresh();
    }

    /** Menghapus rekening. Jika yang dihapus rekening utama, rekening terbaru yang tersisa dinaikkan. */
    public function delete(Store $store, User $user, StoreBankAccount $rekening, Request $request): void
    {
        DB::transaction(function () use ($store, $user, $rekening, $request) {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            $adalahUtama = (bool) $rekening->is_default;
            $uuid        = $rekening->uuid;

            $rekening->delete();

            if ($adalahUtama) {
                $store->bankAccounts()->orderByDesc('id')->first()?->forceFill(['is_default' => true])->save();
            }

            $this->auth->record($user->id, 'store.bank_account_removed', $request, ['account' => $uuid]);
        });
    }

    /** Menjadikan rekening ini satu-satunya rekening utama toko. */
    private function promote(Store $store, StoreBankAccount $rekening): void
    {
        $store->bankAccounts()
            ->where('is_default', true)
            ->where('id', '!=', $rekening->id)
            ->update(['is_default' => false]);

        if (! $rekening->is_default) {
            $rekening->forceFill(['is_default' => true])->save();
        }
    }
}
