<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Withdrawal extends Model
{
    use HasUuids;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID     = 'paid';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Dibuat lewat Wallet::requestWithdrawal(). Status dan data peninjauan
     * sengaja TIDAK bisa diisi massal.
     */
    protected $fillable = [
        'wallet_id',
        'store_id',
        'requested_by',
        'amount',
        'bank_code',
        'bank_name',
        'account_name',
        'account_number_encrypted',
        'account_number_last4',
    ];

    protected $hidden = ['account_number_encrypted'];

    protected function casts(): array
    {
        return [
            'amount'                   => 'integer',
            'account_number_encrypted' => 'encrypted',
            'reviewed_at'              => 'datetime',
            'paid_at'                  => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Admin menyetujui: pending -> approved. */
    public function approve(User $admin): void
    {
        $this->moveTo([self::STATUS_PENDING], self::STATUS_APPROVED, [
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
    }

    /** Admin sudah mentransfer: approved -> paid. Nomor referensi transfer wajib diisi. */
    public function markPaid(User $admin, string $transferReference): void
    {
        if (trim($transferReference) === '') {
            throw new DomainException('Nomor referensi transfer wajib diisi.');
        }

        $this->moveTo([self::STATUS_APPROVED], self::STATUS_PAID, [
            'reviewed_by'        => $admin->id,
            'paid_at'            => now(),
            'transfer_reference' => $transferReference,
        ]);
    }

    /**
     * Admin menolak: pending atau approved -> rejected, dan dana dikembalikan
     * ke saldo toko (tepat sekali). Alasan wajib diisi.
     */
    public function reject(User $admin, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($admin, $reason) {
            $withdrawal = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! in_array($withdrawal->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
                throw new DomainException("Penarikan berstatus {$withdrawal->status} tidak bisa ditolak.");
            }

            $withdrawal->forceFill([
                'status'           => self::STATUS_REJECTED,
                'reviewed_by'      => $admin->id,
                'reviewed_at'      => now(),
                'rejection_reason' => $reason,
            ])->save();

            $withdrawal->wallet->creditWithdrawalReversal($withdrawal->amount, $withdrawal);
        });

        $this->refresh();
    }

    /**
     * @param  array<int, string>  $allowedFrom
     * @param  array<string, mixed>  $attributes
     */
    private function moveTo(array $allowedFrom, string $to, array $attributes): void
    {
        DB::transaction(function () use ($allowedFrom, $to, $attributes) {
            $withdrawal = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! in_array($withdrawal->status, $allowedFrom, true)) {
                throw new DomainException("Penarikan berstatus {$withdrawal->status} tidak bisa diubah menjadi {$to}.");
            }

            $withdrawal->forceFill(array_merge($attributes, ['status' => $to]))->save();
        });

        $this->refresh();
    }
}
