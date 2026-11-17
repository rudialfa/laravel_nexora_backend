<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Satu ulasan per item pesanan: hanya pembeli sungguhan yang bisa mengulas.
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Rentang 1-5 divalidasi di Form Request.
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_anonymous')->default(false);

            $table->string('status', 20)->default('published');   // published, hidden (oleh admin)

            $table->text('seller_reply')->nullable();
            $table->timestamp('seller_replied_at')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
