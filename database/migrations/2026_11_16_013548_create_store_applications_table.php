<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Terisi saat pengajuan disetujui.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('store_name', 100);
            $table->text('description')->nullable();
            $table->string('phone', 20);

            $table->string('province', 100);
            $table->string('city', 100);
            $table->string('district', 100);
            $table->string('village', 100)->nullable();
            $table->string('postal_code', 10);
            $table->text('street');

            // Data identitas. NIK dienkripsi; hash dipakai untuk mencegah NIK ganda.
            $table->string('id_holder_name', 100);
            $table->text('id_number_encrypted');
            $table->char('id_number_hash', 64)->unique();
            $table->string('id_photo_path');        // penyimpanan privat
            $table->string('selfie_path')->nullable();

            $table->string('status', 20)->default('pending');  // pending, approved, rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_applications');
    }
};
