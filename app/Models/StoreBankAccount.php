<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreBankAccount extends Model
{
    /** store_id ditentukan server dari toko yang sedang login. */
    protected $fillable = ['bank_code', 'bank_name', 'account_name', 'is_default'];

    /** Nomor rekening tidak ikut keluar saat model dijadikan JSON. */
    protected $hidden = ['account_number_encrypted'];

    protected function casts(): array
    {
        return [
            // Dienkripsi otomatis dengan APP_KEY saat disimpan.
            'account_number_encrypted' => 'encrypted',
            'is_default'               => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Mengisi nomor rekening terenkripsi dan 4 digit terakhirnya sekaligus. */
    public function setAccountNumber(string $number): static
    {
        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) < 4) {
            throw new DomainException('Nomor rekening tidak valid.');
        }

        $this->account_number_encrypted = $digits;
        $this->account_number_last4     = substr($digits, -4);

        return $this;
    }
}
