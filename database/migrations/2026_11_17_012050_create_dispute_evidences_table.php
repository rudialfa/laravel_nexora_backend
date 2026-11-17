<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();   // pengunggah

            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('dispute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidences');
    }
};
