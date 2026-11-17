<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Aplikasi lain milik Nexora (Absen Pintar, OSPay PPOB, Gudang Pro, Cipta Usaha).
 * Hanya informasi dan link Play Store; BUKAN produk atau kategori marketplace.
 */
class EcosystemApp extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'tagline',
        'description',
        'icon_path',
        'banner_path',
        'play_store_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Aplikasi yang ditampilkan di halaman ekosistem, berurutan. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Sudah punya link Play Store? Jika belum, aplikasi tampil sebagai "segera hadir". */
    public function isAvailable(): bool
    {
        return $this->play_store_url !== null && trim($this->play_store_url) !== '';
    }
}
