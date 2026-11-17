<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();    // pembeli
            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            $table->timestamp('last_message_at')->nullable();

            // Denormalisasi: jumlah pesan yang belum dibaca oleh masing-masing pihak.
            $table->unsignedInteger('buyer_unread_count')->default(0);
            $table->unsignedInteger('store_unread_count')->default(0);

            $table->timestamps();

            // Satu percakapan per pembeli per toko.
            $table->unique(['user_id', 'store_id']);
            $table->index(['store_id', 'last_message_at']);
            $table->index(['user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
