<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Dispute extends Model
{
    use HasUuids;

    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_RETURN    = 'return';
    public const TYPE_REFUND    = 'refund';

    public const STATUS_OPEN         = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_RESOLVED     = 'resolved';

    /**
     * Hanya isi pengajuan yang boleh datang dari pembeli. order_id, user_id, store_id,
     * status, jumlah yang disetujui, dan catatan admin ditentukan server.
     */
    protected $fillable = ['type', 'reason_code', 'description', 'requested_amount'];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'integer',
            'approved_amount'  => 'integer',
            'reviewed_at'      => 'datetime',
            'resolved_at'      => 'datetime',
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

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Admin mulai memeriksa: open -> under_review. */
    public function startReview(User $admin): void
    {
        $this->moveTo([self::STATUS_OPEN], self::STATUS_UNDER_REVIEW, [
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
    }

    /** Admin menyetujui: under_review -> approved. Jumlah tidak boleh melebihi total pesanan. */
    public function approve(User $admin, int $approvedAmount, ?string $note = null): void
    {
        if ($approvedAmount < 0) {
            throw new DomainException('Jumlah pengembalian tidak boleh negatif.');
        }

        $this->moveTo(
            [self::STATUS_UNDER_REVIEW],
            self::STATUS_APPROVED,
            [
                'approved_amount' => $approvedAmount,
                'admin_note'      => $note,
                'reviewed_by'     => $admin->id,
                'reviewed_at'     => now(),
            ],
            function (Dispute $dispute) use ($approvedAmount) {
                $totalPesanan = (int) Order::query()->whereKey($dispute->order_id)->value('grand_total');

                if ($approvedAmount > $totalPesanan) {
                    throw new DomainException('Jumlah pengembalian melebihi total pesanan.');
                }
            }
        );
    }

    /** Admin menolak: under_review -> rejected. Alasan wajib diisi. */
    public function reject(User $admin, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('Alasan penolakan wajib diisi.');
        }

        $this->moveTo([self::STATUS_UNDER_REVIEW], self::STATUS_REJECTED, [
            'admin_note'  => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'resolved_at' => now(),
        ]);
    }

    /** Kasus selesai (pengembalian sudah dilakukan): approved -> resolved. */
    public function resolve(User $admin): void
    {
        $this->moveTo([self::STATUS_APPROVED], self::STATUS_RESOLVED, [
            'reviewed_by' => $admin->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Mengubah status dengan baris terkunci, hanya jika status saat ini diizinkan.
     *
     * @param  array<int, string>  $allowedFrom
     * @param  array<string, mixed>  $attributes
     *
     * @throws DomainException
     */
    private function moveTo(array $allowedFrom, string $to, array $attributes, ?callable $check = null): void
    {
        DB::transaction(function () use ($allowedFrom, $to, $attributes, $check) {
            $dispute = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! in_array($dispute->status, $allowedFrom, true)) {
                throw new DomainException("Komplain berstatus {$dispute->status} tidak bisa diubah menjadi {$to}.");
            }

            if ($check !== null) {
                $check($dispute);
            }

            $dispute->forceFill(array_merge($attributes, ['status' => $to]))->save();
        });

        $this->refresh();
    }
}
