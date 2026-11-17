<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_group_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('discount_amount');
            $table->string('status', 10)->default('reserved');   // reserved, used, released

            $table->timestamps();

            // Satu voucher hanya sekali per checkout.
            $table->unique(['voucher_id', 'checkout_group_id']);
            $table->index(['voucher_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usages');
    }
};
