<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('checkout_group_id')->constrained()->restrictOnDelete();

            // Percobaan bayar ke-1, ke-2, dst. untuk checkout yang sama.
            $table->unsignedTinyInteger('attempt')->default(1);

            // order_id yang dikirim ke Midtrans: nomor grup + "-" + attempt.
            $table->string('midtrans_order_id', 50)->unique();
            $table->string('midtrans_transaction_id', 64)->nullable()->unique();

            $table->string('payment_type', 30);          // contoh: bank_transfer
            $table->string('bank', 20)->nullable();      // contoh: bca
            $table->string('va_number', 64)->nullable();
            $table->json('payment_details')->nullable(); // respons charge Midtrans

            // Nominal yang dikirim ke Midtrans (rupiah utuh).
            $table->unsignedBigInteger('gross_amount');

            // pending, paid, expired, cancelled, denied, failed
            $table->string('status', 20)->default('pending');

            // Nilai mentah terakhir dari Midtrans, untuk audit.
            $table->string('transaction_status', 30)->nullable();
            $table->string('fraud_status', 20)->nullable();
            $table->string('status_code', 10)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();

            $table->timestamps();

            $table->unique(['checkout_group_id', 'attempt']);
            // Dipakai scheduler untuk rekonsiliasi dan menutup pembayaran kedaluwarsa.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
