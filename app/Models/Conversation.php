<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Conversation extends Model
{
    use HasUuids;

    public const SIDE_BUYER = 'buyer';
    public const SIDE_STORE = 'store';

    /** Izin staff yang dibutuhkan untuk membalas chat atas nama toko. */
    public const STAFF_PERMISSION = 'chat.reply';

    protected $fillable = ['user_id', 'store_id'];

    protected function casts(): array
    {
        return [
            'last_message_at'    => 'datetime',
            'buyer_unread_count' => 'integer',
            'store_unread_count' => 'integer',
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

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * Pengguna ini berada di sisi mana percakapan?
     *
     * - "buyer": pembeli pemilik percakapan.
     * - "store": pemilik toko, atau staff yang statusnya aktif dan punya izin chat.reply.
     * - null: orang asing, tidak boleh membaca maupun menulis.
     *
     * Aturan ini juga yang dipakai untuk otorisasi channel Reverb nanti.
     */
    public function sideOf(User $user): ?string
    {
        if ((int) $user->id === (int) $this->user_id) {
            return self::SIDE_BUYER;
        }

        $toko = $this->store;

        if ($toko === null) {
            return null;
        }

        if ((int) $toko->owner_id === (int) $user->id) {
            return self::SIDE_STORE;
        }

        $staff = StoreStaff::query()
            ->where('store_id', $toko->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($staff !== null && in_array(self::STAFF_PERMISSION, $staff->permissions ?? [], true)) {
            return self::SIDE_STORE;
        }

        return null;
    }

    /**
     * Mengirim pesan. Pengirim harus peserta percakapan; pesan tidak boleh kosong
     * (kecuali ada lampiran). Penghitung belum-dibaca milik lawan bicara naik satu.
     *
     * @param  array<int, array{path: string, mime_type: string, size_bytes: int}>  $attachments
     *
     * @throws DomainException
     */
    public function postMessage(User $sender, ?string $body, array $attachments = []): Message
    {
        $sisi = $this->sideOf($sender);

        if ($sisi === null) {
            throw new DomainException('Anda bukan peserta percakapan ini.');
        }

        if (($body === null || trim($body) === '') && $attachments === []) {
            throw new DomainException('Pesan tidak boleh kosong.');
        }

        return DB::transaction(function () use ($sender, $sisi, $body, $attachments) {
            $pesan = $this->messages()->create([
                'sender_id'   => $sender->id,
                'sender_side' => $sisi,
                'type'        => $attachments === [] ? Message::TYPE_TEXT : Message::TYPE_IMAGE,
                'body'        => $body,
            ]);

            foreach ($attachments as $lampiran) {
                $pesan->attachments()->create($lampiran);
            }

            $kolomLawan = $sisi === self::SIDE_BUYER ? 'store_unread_count' : 'buyer_unread_count';

            static::query()->whereKey($this->id)
                ->increment($kolomLawan, 1, ['last_message_at' => now()]);

            return $pesan;
        });
    }

    /**
     * Menandai pesan dari lawan bicara sebagai sudah dibaca oleh pengguna ini,
     * dan menolkan penghitung belum-dibaca miliknya. Mengembalikan jumlah pesan yang ditandai.
     *
     * @throws DomainException
     */
    public function markReadBy(User $reader): int
    {
        $sisi = $this->sideOf($reader);

        if ($sisi === null) {
            throw new DomainException('Anda bukan peserta percakapan ini.');
        }

        return DB::transaction(function () use ($sisi) {
            $jumlah = $this->messages()
                ->where('sender_side', '!=', $sisi)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $kolomSendiri = $sisi === self::SIDE_BUYER ? 'buyer_unread_count' : 'store_unread_count';

            static::query()->whereKey($this->id)->update([$kolomSendiri => 0]);

            return $jumlah;
        });
    }
}
