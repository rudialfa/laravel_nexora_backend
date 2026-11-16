<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('quantity_change');                  // negatif = stok keluar
            $table->unsignedInteger('stock_after');
            $table->string('reason', 50);                        // initial, order, cancel, manual, ...

            // Data pemicu (misalnya pesanan). Diisi saat modul pesanan dibuat.
            $table->nullableMorphs('reference');
            $table->string('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_movements');
    }
};
