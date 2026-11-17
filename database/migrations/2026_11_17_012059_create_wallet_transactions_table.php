<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->string('bucket', 10);      // available, pending
            $table->string('type', 25);        // sale_hold, sale_release, sale_reversal, withdrawal, ...
            $table->bigInteger('amount');      // bertanda: positif = masuk, negatif = keluar
            $table->unsignedBigInteger('balance_after');

            // Pesanan atau penarikan yang memicu perubahan ini.
            $table->nullableMorphs('reference');

            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Ledger hanya ditambah, tidak pernah diubah.
            $table->timestamp('created_at')->nullable();

            $table->index(['wallet_id', 'id']);

            // Pengaman: perubahan yang sama untuk referensi yang sama tidak bisa tercatat dua kali.
            // Nama indeks dibuat manual karena nama otomatis melebihi batas 64 karakter MySQL.
            $table->unique(
                ['wallet_id', 'type', 'bucket', 'reference_type', 'reference_id'],
                'wallet_tx_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
