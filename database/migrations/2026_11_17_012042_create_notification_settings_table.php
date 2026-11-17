<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // order, payment, shipping, chat, promo, system
            $table->string('category', 20);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
