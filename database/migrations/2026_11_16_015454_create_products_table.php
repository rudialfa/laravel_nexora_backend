<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();

            // draft, active, inactive, suspended (oleh admin)
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);

            // Denormalisasi dari varian aktif: dihitung ulang oleh kode.
            $table->unsignedBigInteger('price_min')->default(0);
            $table->unsignedBigInteger('price_max')->default(0);

            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['status', 'sold_count']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
