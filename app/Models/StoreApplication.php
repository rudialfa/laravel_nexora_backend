<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreApplication extends Model
{
    use HasUuids;

    /**
     * user_id, store_id, status, reviewed_* dan id_number_hash tidak boleh
     * diisi massal. Hash dibuat lewat setIdNumber().
     */
    protected $fillable = [
        'store_name',
        'description',
        'phone',
        'province',
        'city',
        'district',
        'village',
        'postal_code',
        'street',
        'id_holder_name',
        'id_photo_path',
        'selfie_path',
    ];

    /** NIK tidak ikut tampil saat model diubah menjadi JSON. */
    protected $hidden = [
        'id_number_encrypted',
        'id_number_hash',
        'id_photo_path',
        'selfie_path',
    ];

    protected function casts(): array
    {
        return [
            // Dienkripsi otomatis dengan APP_KEY saat disimpan.
            'id_number_encrypted' => 'encrypted',
            'reviewed_at'         => 'datetime',
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

    /** Hash NIK (HMAC-SHA256 memakai APP_KEY), tidak bisa dibalik menjadi NIK. */
    public static function hashIdNumber(string $idNumber): string
    {
        return hash_hmac('sha256', $idNumber, config('app.key'));
    }

    /** Mengisi NIK terenkripsi dan hash-nya sekaligus. */
    public function setIdNumber(string $idNumber): static
    {
        $this->id_number_encrypted = $idNumber;
        $this->id_number_hash      = self::hashIdNumber($idNumber);

        return $this;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
