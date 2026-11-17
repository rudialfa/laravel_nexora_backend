<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Refund extends Model
{
    use HasUuids;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const METHOD_MANUAL = 'manual';

    /** Status dan data pemrosesan hanya berubah lewat complete() dan fail(). */
    protected $fillable = ['order_id', 'dispute_id', 'amount', 'method', 'reason'];

    protected function casts(): array
    {
        return [
            'amount'       => 'integer',
            'processed_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Pengembalian dana berhasil dilakukan admin: pending -> completed. */
    public function complete(User $admin, ?string $reference = null): void
    {
        $this->moveTo(self::STATUS_COMPLETED, [
            'reference'    => $reference,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
    }

    /** Pengembalian dana gagal: pending -> failed. Alasan wajib diisi. */
    public function fail(User $admin, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Alasan kegagalan wajib diisi.');
        }

        $this->moveTo(self::STATUS_FAILED, [
            'failure_reason' => $reason,
            'processed_by'   => $admin->id,
            'processed_at'   => now(),
        ]);
    }

    private function moveTo(string $to, array $attributes): void
    {
        DB::transaction(function () use ($to, $attributes) {
            $refund = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($refund->status !== self::STATUS_PENDING) {
                throw new DomainException("Refund berstatus {$refund->status} tidak bisa diubah menjadi {$to}.");
            }

            $refund->forceFill(array_merge($attributes, ['status' => $to]))->save();
        });

        $this->refresh();
    }
}
