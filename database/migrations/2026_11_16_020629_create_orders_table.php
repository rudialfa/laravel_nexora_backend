<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('checkout_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();    // pembeli
            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            $table->string('number', 50)->unique();

            // pending_payment, paid, processing, packed, shipped, in_transit,
            // delivered, completed, cancelled, refunded
            $table->string('status', 20)->default('pending_payment');

            // Snapshot pengiriman. Mengubah alamat pengguna tidak mengubah pesanan lama.
            $table->json('shipping_address');
            $table->string('courier_company', 50)->nullable();   // contoh: jne
            $table->string('courier_type', 50)->nullable();      // contoh: reg
            $table->string('shipping_etd', 50)->nullable();
            $table->unsignedInteger('total_weight_gram')->default(0);

            // Uang dalam rupiah utuh.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('grand_total');

            // Snapshot komisi saat pesanan dibuat. Perubahan komisi tidak mengubah pesanan lama.
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);

            $table->text('buyer_note')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_complete_at')->nullable();   // batas konfirmasi otomatis
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('checkout_group_id');
            // Dipakai scheduler untuk menyelesaikan pesanan otomatis.
            $table->index(['status', 'auto_complete_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
