<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasUuids;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_PROCESSING      = 'processing';
    public const STATUS_PACKED          = 'packed';
    public const STATUS_SHIPPED         = 'shipped';
    public const STATUS_IN_TRANSIT      = 'in_transit';
    public const STATUS_DELIVERED       = 'delivered';
    public const STATUS_COMPLETED       = 'completed';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_REFUNDED        = 'refunded';

    public const ACTOR_SYSTEM = 'system';   // webhook, scheduler
    public const ACTOR_BUYER  = 'buyer';
    public const ACTOR_SELLER = 'seller';
    public const ACTOR_ADMIN  = 'admin';

    /** Lama pembeli punya waktu mengonfirmasi/komplain sebelum otomatis selesai. */
    public const AUTO_COMPLETE_DAYS = 3;

    /**
     * Aturan perpindahan status: dari status => [ke status => [aktor yang berhak]].
     * Perpindahan yang tidak tercantum di sini DITOLAK.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT => [
            self::STATUS_PAID      => [self::ACTOR_SYSTEM],
            self::STATUS_CANCELLED => [self::ACTOR_BUYER, self::ACTOR_SYSTEM, self::ACTOR_ADMIN],
        ],
        self::STATUS_PAID => [
            self::STATUS_PROCESSING => [self::ACTOR_SELLER],
            self::STATUS_CANCELLED  => [self::ACTOR_SELLER, self::ACTOR_ADMIN],
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_PACKED    => [self::ACTOR_SELLER],
            self::STATUS_CANCELLED => [self::ACTOR_SELLER, self::ACTOR_ADMIN],
        ],
        self::STATUS_PACKED => [
            self::STATUS_SHIPPED   => [self::ACTOR_SELLER, self::ACTOR_SYSTEM],
            self::STATUS_CANCELLED => [self::ACTOR_SELLER, self::ACTOR_ADMIN],
        ],
        self::STATUS_SHIPPED => [
            self::STATUS_IN_TRANSIT => [self::ACTOR_SYSTEM],
            self::STATUS_DELIVERED  => [self::ACTOR_SYSTEM],
        ],
        self::STATUS_IN_TRANSIT => [
            self::STATUS_DELIVERED => [self::ACTOR_SYSTEM],
        ],
        self::STATUS_DELIVERED => [
            self::STATUS_COMPLETED => [self::ACTOR_BUYER, self::ACTOR_SYSTEM],
            self::STATUS_REFUNDED  => [self::ACTOR_ADMIN],
        ],
        self::STATUS_COMPLETED => [
            self::STATUS_REFUNDED => [self::ACTOR_ADMIN],
        ],
        self::STATUS_CANCELLED => [
            self::STATUS_REFUNDED => [self::ACTOR_ADMIN, self::ACTOR_SYSTEM],
        ],
        self::STATUS_REFUNDED => [],
    ];

    /** Status yang mengisi kolom waktu tertentu. */
    private const STATUS_TIMESTAMPS = [
        self::STATUS_PAID      => 'paid_at',
        self::STATUS_SHIPPED   => 'shipped_at',
        self::STATUS_DELIVERED => 'delivered_at',
        self::STATUS_COMPLETED => 'completed_at',
        self::STATUS_CANCELLED => 'cancelled_at',
    ];

    /**
     * Semua jumlah uang dihitung server. "status" dan seluruh kolom waktu
     * sengaja TIDAK bisa diisi massal: status hanya berubah lewat transitionTo().
     */
    protected $fillable = [
        'checkout_group_id',
        'user_id',
        'store_id',
        'number',
        'shipping_address',
        'courier_company',
        'courier_type',
        'shipping_etd',
        'total_weight_gram',
        'subtotal',
        'shipping_cost',
        'discount_total',
        'grand_total',
        'commission_rate',
        'commission_amount',
        'buyer_note',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address'  => 'array',
            'total_weight_gram' => 'integer',
            'subtotal'          => 'integer',
            'shipping_cost'     => 'integer',
            'discount_total'    => 'integer',
            'grand_total'       => 'integer',
            'commission_rate'   => 'decimal:2',
            'commission_amount' => 'integer',
            'paid_at'           => 'datetime',
            'shipped_at'        => 'datetime',
            'delivered_at'      => 'datetime',
            'auto_complete_at'  => 'datetime',
            'completed_at'      => 'datetime',
            'cancelled_at'      => 'datetime',
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

    /** Contoh: NXR-261116093045-K7QZ2-1 */
    public static function generateNumber(CheckoutGroup $group, int $index): string
    {
        return $group->number . '-' . $index;
    }

    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(CheckoutGroup::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** Apakah aktor ini boleh memindahkan pesanan ke status tersebut (dari status saat ini)? */
    public function canTransitionTo(string $to, string $actor): bool
    {
        $aktor = self::TRANSITIONS[$this->status][$to] ?? null;

        return $aktor !== null && in_array($actor, $aktor, true);
    }

    /**
     * Satu-satunya jalan yang sah untuk mengubah status pesanan.
     *
     * - Status dibaca ulang dari database dengan kunci baris (lockForUpdate),
     *   sehingga dua proses yang bersamaan (misalnya webhook ganda) tidak bisa
     *   sama-sama lolos dari status yang sama.
     * - Perpindahan yang tidak ada di TRANSITIONS atau aktor yang tidak berhak
     *   menghasilkan DomainException dan tidak ada yang berubah.
     * - Setiap perubahan tercatat di order_status_histories.
     *
     * @throws DomainException
     */
    public function transitionTo(
        string $to,
        string $actor,
        ?int $userId = null,
        ?string $note = null
    ): void {
        DB::transaction(function () use ($to, $actor, $userId, $note) {
            $order = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $from  = $order->status;

            $aktorBerhak = self::TRANSITIONS[$from][$to] ?? null;

            if ($aktorBerhak === null) {
                throw new DomainException("Status tidak boleh berpindah dari {$from} ke {$to}.");
            }

            if (! in_array($actor, $aktorBerhak, true)) {
                throw new DomainException("Aktor {$actor} tidak berhak mengubah status {$from} menjadi {$to}.");
            }

            $order->status = $to;

            if (isset(self::STATUS_TIMESTAMPS[$to])) {
                $order->{self::STATUS_TIMESTAMPS[$to]} = now();
            }

            if ($to === self::STATUS_DELIVERED) {
                $order->auto_complete_at = now()->addDays(self::AUTO_COMPLETE_DAYS);
            }

            $order->save();

            OrderStatusHistory::create([
                'order_id'    => $order->id,
                'from_status' => $from,
                'to_status'   => $to,
                'actor_type'  => $actor,
                'user_id'     => $userId,
                'note'        => $note,
            ]);
        });

        $this->refresh();
    }
}
