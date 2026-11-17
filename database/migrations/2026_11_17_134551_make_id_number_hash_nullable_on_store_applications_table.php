<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            // Dikosongkan saat pengajuan ditolak, supaya NIK yang sama bisa dipakai lagi.
            // Indeks unik tetap berlaku untuk nilai yang terisi (NULL boleh berulang).
            $table->char('id_number_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris yang hash-nya sudah NULL harus diisi dulu sebelum kolom bisa dibuat wajib lagi.
        Schema::table('store_applications', function (Blueprint $table) {
            $table->char('id_number_hash', 64)->nullable(false)->change();
        });
    }
};
