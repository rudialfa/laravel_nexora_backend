<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('amount');                  // rupiah utuh
            $table->string('status', 15)->default('pending');      // pending, completed, failed
            $table->string('method', 20)->default('manual');       // diproses admin
            $table->string('reason')->nullable();

            $table->string('reference', 100)->nullable();          // nomor referensi pengembalian
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
