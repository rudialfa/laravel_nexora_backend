<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();   // contoh: featured, new_arrivals
            $table->string('title', 100);
            $table->string('type', 30);             // featured_products, new_products, ...
            $table->json('config')->nullable();     // contoh: {"limit": 10}

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_sections');
    }
};
