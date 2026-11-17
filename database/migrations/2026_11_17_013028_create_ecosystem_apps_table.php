<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Informasi aplikasi lain milik Nexora. Bukan produk marketplace,
        // dan sengaja tidak punya hubungan apa pun ke tabel categories/products.
        Schema::create('ecosystem_apps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('tagline', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('icon_path')->nullable();
            $table->string('banner_path')->nullable();

            // Diisi nanti, setelah link Play Store tersedia.
            $table->string('play_store_url', 255)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosystem_apps');
    }
};
