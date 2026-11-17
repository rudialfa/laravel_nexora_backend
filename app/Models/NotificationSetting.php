<?php

namespace App\Models;

use InvalidArgumentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    public const CATEGORIES = ['order', 'payment', 'shipping', 'chat', 'promo', 'system'];

    public const CHANNEL_PUSH  = 'push';
    public const CHANNEL_EMAIL = 'email';

    /**
     * Pilihan bawaan jika pengguna belum mengatur apa pun.
     * Notifikasi transaksi aktif; promosi nonaktif (pengguna harus memilih sendiri).
     */
    private const DEFAULT_ENABLED = [
        'order'    => true,
        'payment'  => true,
        'shipping' => true,
        'chat'     => true,
        'promo'    => false,
        'system'   => true,
    ];

    protected $fillable = ['user_id', 'category', 'push_enabled', 'email_enabled'];

    protected function casts(): array
    {
        return [
            'push_enabled'  => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Apakah pengguna mau menerima notifikasi kategori ini lewat saluran ini?
     *
     * @throws InvalidArgumentException
     */
    public static function channelEnabled(int $userId, string $category, string $channel): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Kategori notifikasi tidak dikenal: {$category}.");
        }

        if (! in_array($channel, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL], true)) {
            throw new InvalidArgumentException("Saluran notifikasi tidak dikenal: {$channel}.");
        }

        $setting = static::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->first();

        if ($setting === null) {
            return self::DEFAULT_ENABLED[$category];
        }

        return (bool) ($channel === self::CHANNEL_EMAIL ? $setting->email_enabled : $setting->push_enabled);
    }
}
