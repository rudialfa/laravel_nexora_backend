<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_courier_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('courier_code', 50);   // kode kurir menurut Biteship
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['store_id', 'courier_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_courier_settings');
    }
};
