<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 20);                 // midtrans, biteship
            $table->string('event', 50)->nullable();        // contoh: order.status
            $table->string('external_id', 100)->nullable(); // order_id Midtrans / order Biteship

            $table->json('payload');
            $table->boolean('is_valid')->nullable();        // lolos verifikasi atau tidak
            $table->string('processing_status', 20)->default('received'); // received, processed, ignored, failed
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'external_id']);
            $table->index(['provider', 'processing_status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }   
};
