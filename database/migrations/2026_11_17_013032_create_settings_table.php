<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100)->unique();   // contoh: marketplace.commission_rate
            $table->text('value')->nullable();
            $table->string('type', 10)->default('string');   // string, int, float, bool, json
            $table->string('section', 30)->default('general');
            $table->string('description')->nullable();

            // true = boleh dikirim ke aplikasi Flutter tanpa login.
            $table->boolean('is_public')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
