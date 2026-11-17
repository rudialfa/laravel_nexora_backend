<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    public const BUCKET_AVAILABLE = 'available';
    public const BUCKET_PENDING   = 'pending';

    public const TYPE_SALE_HOLD           = 'sale_hold';
    public const TYPE_SALE_RELEASE        = 'sale_release';
    public const TYPE_SALE_REVERSAL       = 'sale_reversal';
    public const TYPE_WITHDRAWAL          = 'withdrawal';
    public const TYPE_WITHDRAWAL_REVERSAL = 'withdrawal_reversal';
    public const TYPE_ADJUSTMENT          = 'adjustment';

    /** Nilai sementara. Nanti dipindah ke tabel pengaturan marketplace. */
    public const MIN_WITHDRAWAL = 50000;

    /** Saldo sengaja TIDAK bisa diisi massal: hanya berubah lewat method di bawah. */
    protected $fillable = ['store_id'];

    protected function casts(): array
    {
        return [
            'balance'         => 'integer',
            'pending_balance' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderBy('id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /** Pesanan dibayar: dana penjual ditahan (saldo pending). */
    public function holdSale(int $amount, Model $order, ?string $description = null): bool
    {
        $this->requirePositive($amount);

        return $this->mutate([[self::BUCKET_PENDING, $amount]], self::TYPE_SALE_HOLD, $order, null, $description);
    }

    /** Pesanan selesai: dana pindah dari pending ke saldo yang bisa ditarik. */
    public function releaseSale(int $amount, Model $order): bool
    {
        $this->requirePositive($amount);

        return $this->mutate(
            [[self::BUCKET_PENDING, -$amount], [self::BUCKET_AVAILABLE, $amount]],
            self::TYPE_SALE_RELEASE,
            $order
        );
    }

    /** Pesanan dibatalkan/direfund: dana ditarik kembali dari pending atau available. */
    public function reverseSale(int $amount, Model $order, string $bucket): bool
    {
        $this->requirePositive($amount);
        $this->requireBucket($bucket);

        return $this->mutate([[$bucket, -$amount]], self::TYPE_SALE_REVERSAL, $order);
    }

    /** Koreksi manual oleh admin (positif menambah, negatif mengurangi). Alasan wajib. */
    public function adjust(int $delta, User $admin, string $description, string $bucket = self::BUCKET_AVAILABLE): bool
    {
        if ($delta === 0) {
            throw new DomainException('Jumlah koreksi tidak boleh nol.');
        }

        if (trim($description) === '') {
            throw new DomainException('Keterangan koreksi wajib diisi.');
        }

        $this->requireBucket($bucket);

        return $this->mutate([[$bucket, $delta]], self::TYPE_ADJUSTMENT, null, $admin->id, $description);
    }

    /**
     * Mengajukan penarikan: saldo available langsung dikurangi dan permintaan dibuat.
     * Jika saldo tidak cukup, tidak ada yang tersimpan.
     *
     * @throws DomainException
     */
    public function requestWithdrawal(User $by, StoreBankAccount $account, int $amount): Withdrawal
    {
        if ((int) $account->store_id !== (int) $this->store_id) {
            throw new DomainException('Rekening bukan milik toko ini.');
        }

        if ($amount < self::MIN_WITHDRAWAL) {
            throw new DomainException('Penarikan minimal Rp ' . number_format(self::MIN_WITHDRAWAL, 0, ',', '.') . '.');
        }

        return DB::transaction(function () use ($by, $account, $amount) {
            $withdrawal = Withdrawal::create([
                'wallet_id'                => $this->id,
                'store_id'                 => $this->store_id,
                'requested_by'             => $by->id,
                'amount'                   => $amount,
                'bank_code'                => $account->bank_code,
                'bank_name'                => $account->bank_name,
                'account_name'             => $account->account_name,
                'account_number_encrypted' => $account->account_number_encrypted,
                'account_number_last4'     => $account->account_number_last4,
            ]);

            // Melempar DomainException jika saldo tidak cukup; seluruh transaksi dibatalkan.
            $this->mutate(
                [[self::BUCKET_AVAILABLE, -$amount]],
                self::TYPE_WITHDRAWAL,
                $withdrawal,
                $by->id,
                'Penarikan dana'
            );

            return $withdrawal->fresh();
        });
    }

    /** Penarikan ditolak: dana dikembalikan ke saldo available (sekali saja). */
    public function creditWithdrawalReversal(int $amount, Withdrawal $withdrawal): bool
    {
        $this->requirePositive($amount);

        return $this->mutate(
            [[self::BUCKET_AVAILABLE, $amount]],
            self::TYPE_WITHDRAWAL_REVERSAL,
            $withdrawal,
            null,
            'Penarikan ditolak, dana dikembalikan'
        );
    }

    /**
     * Satu-satunya jalan mengubah saldo.
     *
     * - Baris dompet dikunci, sehingga dua proses bersamaan tidak saling menimpa.
     * - Jika perubahan dengan tipe dan referensi yang sama sudah pernah tercatat,
     *   tidak ada yang dilakukan (mengembalikan false). Ini menjaga agar webhook
     *   atau scheduler yang berjalan dua kali tidak menggandakan uang.
     * - Saldo tidak boleh minus (DomainException, tidak ada yang berubah).
     * - Setiap perubahan ditulis ke ledger bersama saldo sesudahnya.
     *
     * @param  array<int, array{0: string, 1: int}>  $moves  daftar [bucket, perubahan bertanda]
     */
    private function mutate(array $moves, string $type, ?Model $reference, ?int $userId = null, ?string $description = null): bool
    {
        $diterapkan = DB::transaction(function () use ($moves, $type, $reference, $userId, $description) {
            $wallet = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($reference !== null) {
                $sudahAda = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('type', $type)
                    ->where('reference_type', $reference->getMorphClass())
                    ->where('reference_id', $reference->getKey())
                    ->exists();

                if ($sudahAda) {
                    return false;
                }
            }

            $saldo = [
                self::BUCKET_AVAILABLE => (int) $wallet->balance,
                self::BUCKET_PENDING   => (int) $wallet->pending_balance,
            ];

            foreach ($moves as [$bucket, $delta]) {
                $saldo[$bucket] += $delta;

                if ($saldo[$bucket] < 0) {
                    throw new DomainException('Saldo tidak cukup.');
                }
            }

            foreach ($moves as [$bucket, $delta]) {
                WalletTransaction::create([
                    'wallet_id'      => $wallet->id,
                    'bucket'         => $bucket,
                    'type'           => $type,
                    'amount'         => $delta,
                    'balance_after'  => $saldo[$bucket],
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id'   => $reference?->getKey(),
                    'description'    => $description,
                    'created_by'     => $userId,
                ]);
            }

            $wallet->forceFill([
                'balance'         => $saldo[self::BUCKET_AVAILABLE],
                'pending_balance' => $saldo[self::BUCKET_PENDING],
            ])->save();

            return true;
        });

        $this->refresh();

        return $diterapkan;
    }

    private function requirePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new DomainException('Jumlah harus lebih dari nol.');
        }
    }

    private function requireBucket(string $bucket): void
    {
        if (! in_array($bucket, [self::BUCKET_AVAILABLE, self::BUCKET_PENDING], true)) {
            throw new DomainException("Jenis saldo tidak dikenal: {$bucket}.");
        }
    }
}
