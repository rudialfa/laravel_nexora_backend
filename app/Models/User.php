<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    /**
     * "status" sengaja TIDAK ada di sini agar tidak bisa diubah lewat
     * input pengguna; status hanya diubah lewat kode admin.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar_path',
        'birth_date',
        'gender',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'birth_date'        => 'date',
            'password'          => 'hashed',
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

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Toko milik pengguna ini (maksimal satu). */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_id');
    }

    public function storeApplications(): HasMany
    {
        return $this->hasMany(StoreApplication::class);
    }

    /** Toko yang diikuti pengguna ini. */
    public function followedStores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_followers')
            ->withPivot('created_at');
    }

    /** Produk favorit (wishlist) pengguna ini. */
    public function wishlistProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')
            ->withPivot('created_at');
    }
}
