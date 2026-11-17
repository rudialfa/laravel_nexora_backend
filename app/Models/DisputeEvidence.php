<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeEvidence extends Model
{
    /**
     * Ditulis eksplisit: Laravel menganggap "evidence" tidak punya bentuk jamak
     * dan akan mencari tabel "dispute_evidence".
     */
    protected $table = 'dispute_evidences';

    /** Tabel ini hanya punya created_at. */
    const UPDATED_AT = null;

    protected $fillable = ['dispute_id', 'user_id', 'path', 'mime_type', 'size_bytes', 'note'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
