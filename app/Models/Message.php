<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasUuids;

    public const TYPE_TEXT  = 'text';
    public const TYPE_IMAGE = 'image';

    /**
     * Pesan dibuat lewat Conversation::postMessage(), yang menentukan pengirim
     * dan sisinya dari pengguna yang login. "read_at" hanya berubah lewat markReadBy().
     */
    protected $fillable = ['conversation_id', 'sender_id', 'sender_side', 'type', 'body'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
