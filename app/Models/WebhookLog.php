<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    /** Log tidak pernah diubah, jadi tidak ada updated_at. */
    const UPDATED_AT = null;

    public const PROVIDER_MIDTRANS = 'midtrans';
    public const PROVIDER_BITESHIP = 'biteship';

    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED   = 'ignored';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'provider',
        'event',
        'external_id',
        'payload',
        'is_valid',
        'processing_status',
        'error',
        'ip_address',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'is_valid'     => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
