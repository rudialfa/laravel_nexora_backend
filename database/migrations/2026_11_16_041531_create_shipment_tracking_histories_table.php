<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();

            $table->string('status', 30);
            $table->string('note')->nullable();
            $table->json('payload')->nullable();   // isi webhook, untuk audit

            // Payload webhook Biteship tidak memuat waktu kejadian,
            // jadi created_at = waktu kita menerimanya.
            $table->timestamp('created_at')->nullable();

            $table->index(['shipment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_histories');
    }
};
