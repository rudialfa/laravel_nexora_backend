<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreFollower extends Model
{
    /** Tabel ini hanya punya created_at. */
    const UPDATED_AT = null;

    protected $fillable = ['store_id', 'user_id'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
