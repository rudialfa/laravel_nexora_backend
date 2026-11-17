<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    /** Ledger tidak pernah diubah, jadi tidak ada updated_at. */
    const UPDATED_AT = null;

    /** Hanya dibuat oleh Wallet::mutate(); tidak pernah dari input pengguna. */
    protected $fillable = [
        'wallet_id',
        'bucket',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
