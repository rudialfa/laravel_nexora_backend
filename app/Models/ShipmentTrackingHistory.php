<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentTrackingHistory extends Model
{
    /** Riwayat tidak pernah diubah, jadi tidak ada updated_at. */
    const UPDATED_AT = null;

    protected $fillable = ['shipment_id', 'status', 'note', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
