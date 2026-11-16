<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64)->nullable();
            $table->string('name', 150)->default('Default');   // contoh: "Merah / L"
            $table->json('options')->nullable();               // contoh: {"Warna":"Merah","Ukuran":"L"}

            // Uang dalam rupiah utuh.
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount_price')->nullable();
            $table->timestamp('discount_starts_at')->nullable();
            $table->timestamp('discount_ends_at')->nullable();

            // Stok hanya diubah lewat ProductVariant::adjustStock().
            $table->unsignedInteger('stock')->default(0);

            // Untuk perhitungan ongkir.
            $table->unsignedInteger('weight_gram');
            $table->unsignedInteger('length_cm')->nullable();
            $table->unsignedInteger('width_cm')->nullable();
            $table->unsignedInteger('height_cm')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // SKU boleh sama antar toko, tapi unik di dalam satu produk.
            $table->unique(['product_id', 'sku']);
            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
