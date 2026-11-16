<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Kategori utama: parent_id kosong. Subkategori: parent_id berisi kategori induk.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();

            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('icon_path')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
