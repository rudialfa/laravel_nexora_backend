<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Satu pesanan satu pengiriman.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('biteship_order_id', 64)->nullable()->unique();
            $table->string('tracking_id', 64)->nullable()->index();  // courier_tracking_id
            $table->string('waybill_id', 64)->nullable()->index();   // nomor resi

            $table->string('courier_company', 50)->nullable();
            $table->string('courier_type', 50)->nullable();
            $table->unsignedBigInteger('courier_price')->nullable(); // ongkir menurut Biteship (bisa berubah)

            // Data kurir/driver dari webhook: name, phone, plate_number, photo_url, link.
            $table->json('driver')->nullable();

            // "pending" = belum dibuat di Biteship. Selanjutnya status Biteship
            // dalam snake_case: confirmed, allocated, picking_up, picked, ...
            $table->string('status', 30)->default('pending');

            $table->timestamp('picked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->json('raw_response')->nullable();   // respons pembuatan order Biteship
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
