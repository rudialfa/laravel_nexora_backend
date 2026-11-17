<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Kosong = voucher marketplace. Terisi = voucher milik toko tersebut.
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();

            // Kode unik secara global (disimpan huruf besar).
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->string('discount_type', 15);                 // percentage, fixed
            $table->unsignedBigInteger('discount_value');        // persen (1-100) atau rupiah
            $table->unsignedBigInteger('max_discount')->nullable();   // batas potongan untuk persen
            $table->unsignedBigInteger('min_purchase')->default(0);

            $table->unsignedInteger('quota')->nullable();        // kosong = tanpa batas
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedSmallInteger('per_user_limit')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
