<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // Satu dompet per toko.
            $table->foreignId('store_id')->unique()->constrained()->restrictOnDelete();

            // Rupiah utuh. Hanya boleh berubah lewat method Wallet (dengan catatan di ledger).
            $table->unsignedBigInteger('balance')->default(0);           // bisa ditarik
            $table->unsignedBigInteger('pending_balance')->default(0);   // ditahan sampai pesanan selesai

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
