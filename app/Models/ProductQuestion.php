<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestion extends Model
{
    use HasUuids;

    /** product_id, user_id, status, dan jawaban ditentukan server. */
    protected $fillable = ['question'];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /** Mencatat jawaban seller. Siapa yang berhak menjawab diperiksa di lapisan otorisasi nanti. */
    public function answer(User $answerer, string $text): void
    {
        $this->forceFill([
            'answer'      => $text,
            'answered_by' => $answerer->id,
            'answered_at' => now(),
        ])->save();
    }
}
