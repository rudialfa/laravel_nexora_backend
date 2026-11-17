<?php

namespace App\Models;

use InvalidArgumentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT    = 'int';
    public const TYPE_FLOAT  = 'float';
    public const TYPE_BOOL   = 'bool';
    public const TYPE_JSON   = 'json';

    protected $fillable = ['name', 'value', 'type', 'section', 'description', 'is_public'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Cache selalu dibuang saat pengaturan berubah, agar nilai lama tidak bertahan.
        static::saved(fn(Setting $s) => Cache::forget(self::cacheKey($s->name)));
        static::deleted(fn(Setting $s) => Cache::forget(self::cacheKey($s->name)));
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private static function cacheKey(string $name): string
    {
        return 'setting:' . $name;
    }

    /** Membaca satu pengaturan (sudah diubah ke tipenya). Mengembalikan $default jika tidak ada. */
    public static function getValue(string $name, mixed $default = null): mixed
    {
        $row = Cache::rememberForever(self::cacheKey($name), function () use ($name) {
            $setting = static::query()->where('name', $name)->first();

            return $setting
                ? ['value' => $setting->getRawOriginal('value'), 'type' => $setting->type]
                : false;
        });

        if ($row === false) {
            return $default;
        }

        return self::decode($row['value'], $row['type']);
    }

    /**
     * Mengubah nilai pengaturan yang sudah ada. Nilai divalidasi menurut tipenya;
     * jika tidak cocok, tidak ada yang berubah.
     *
     * @throws InvalidArgumentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function put(string $name, mixed $value, ?int $userId = null): void
    {
        $setting = static::query()->where('name', $name)->firstOrFail();

        $setting->value      = self::encode($value, $setting->type, $name);
        $setting->updated_by = $userId;
        $setting->save();
    }

    /**
     * Pengaturan yang boleh dilihat aplikasi tanpa login, dalam bentuk [nama => nilai].
     *
     * @return array<string, mixed>
     */
    public static function publicMap(): array
    {
        return static::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn(Setting $s) => [$s->name => self::decode($s->getRawOriginal('value'), $s->type)])
            ->all();
    }

    private static function decode(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            self::TYPE_INT   => (int) $value,
            self::TYPE_FLOAT => (float) $value,
            self::TYPE_BOOL  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON  => json_decode($value, true),
            default          => $value,
        };
    }

    private static function encode(mixed $value, string $type, string $name): ?string
    {
        switch ($type) {
            case self::TYPE_INT:
                if (! is_numeric($value) || (float) $value < 0 || (float) $value != (int) $value) {
                    throw new InvalidArgumentException("Pengaturan {$name} harus berupa bilangan bulat tidak negatif.");
                }

                return (string) (int) $value;

            case self::TYPE_FLOAT:
                if (! is_numeric($value) || (float) $value < 0) {
                    throw new InvalidArgumentException("Pengaturan {$name} harus berupa angka tidak negatif.");
                }

                return (string) (float) $value;

            case self::TYPE_BOOL:
                if (! is_bool($value)) {
                    throw new InvalidArgumentException("Pengaturan {$name} harus berupa true atau false.");
                }

                return $value ? '1' : '0';

            case self::TYPE_JSON:
                if (! is_array($value)) {
                    throw new InvalidArgumentException("Pengaturan {$name} harus berupa array.");
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE);

            default:
                if ($value !== null && ! is_scalar($value)) {
                    throw new InvalidArgumentException("Pengaturan {$name} harus berupa teks.");
                }

                return $value === null ? null : (string) $value;
        }
    }
}
