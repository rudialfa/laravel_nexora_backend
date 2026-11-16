<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Satu pengguna satu toko.
            $table->foreignId('owner_id')->unique()->constrained('users')->restrictOnDelete();

            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('phone', 20);

            // Alamat toko (asal pengiriman).
            $table->string('province', 100);
            $table->string('city', 100);
            $table->string('district', 100);
            $table->string('village', 100)->nullable();
            $table->string('postal_code', 10);
            $table->text('street');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Sementara: dipastikan dengan dokumentasi Biteship di tahap pengiriman.
            $table->string('biteship_area_id', 100)->nullable();

            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->string('status', 20)->default('active');   // active, suspended, closed
            $table->boolean('is_open')->default(true);
            $table->string('closed_note')->nullable();

            // Komisi khusus toko. Kosong = pakai komisi global.
            $table->decimal('commission_rate', 5, 2)->nullable();

            // Denormalisasi: dihitung ulang oleh kode.
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);

            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['city', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
