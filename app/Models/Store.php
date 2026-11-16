<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Kolom sensitif sengaja TIDAK boleh diisi massal:
     * owner_id, status, commission_rate, rating_*, followers_count, verified_at.
     * Semuanya hanya diubah lewat kode yang kita tulis sendiri (service/admin).
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo_path',
        'banner_path',
        'phone',
        'province',
        'city',
        'district',
        'village',
        'postal_code',
        'street',
        'latitude',
        'longitude',
        'biteship_area_id',
        'timezone',
        'is_open',
        'closed_note',
    ];

    protected function casts(): array
    {
        return [
            'is_open'         => 'boolean',
            'commission_rate' => 'decimal:2',
            'rating_avg'      => 'decimal:2',
            'latitude'        => 'decimal:7',
            'longitude'       => 'decimal:7',
            'verified_at'     => 'datetime',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StoreApplication::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(StoreOperatingHour::class);
    }

    public function courierSettings(): HasMany
    {
        return $this->hasMany(StoreCourierSetting::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(StoreStaff::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_followers')
            ->withPivot('created_at');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Pesanan yang masuk ke toko ini. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
