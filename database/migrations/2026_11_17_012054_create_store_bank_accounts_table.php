<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('bank_code', 20);
            $table->string('bank_name', 100);
            $table->string('account_name', 100);

            // Nomor rekening dienkripsi; 4 digit terakhir terpisah untuk ditampilkan.
            $table->text('account_number_encrypted');
            $table->char('account_number_last4', 4);

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['store_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_bank_accounts');
    }
};
