<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Produk dan varian yang pernah dibeli tidak boleh dihapus permanen.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();

            // Snapshot data saat dibeli.
            $table->string('product_name', 150);
            $table->string('variant_name', 150);
            $table->string('sku', 64)->nullable();
            $table->string('image_path')->nullable();
            $table->json('options')->nullable();

            $table->unsignedBigInteger('price');        // harga normal saat itu
            $table->unsignedBigInteger('unit_price');   // harga yang benar-benar dibayar per unit
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');     // unit_price x quantity
            $table->unsignedInteger('weight_gram');

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
