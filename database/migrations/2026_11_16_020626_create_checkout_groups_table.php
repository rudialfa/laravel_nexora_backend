<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Nomor ini nanti dikirim ke Midtrans sebagai order_id.
            $table->string('number', 40)->unique();

            // pending_payment, paid, expired, cancelled
            $table->string('status', 20)->default('pending_payment');

            // Uang dalam rupiah utuh.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('service_fee')->default(0);
            $table->unsignedBigInteger('grand_total');

            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            // Dipakai scheduler untuk mencari checkout yang kedaluwarsa.
            $table->index(['status', 'payment_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_groups');
    }
};
