<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('amount');   // rupiah utuh

            // Snapshot rekening saat diajukan. Mengubah atau menghapus rekening
            // tidak mengubah permintaan yang sudah ada.
            $table->string('bank_code', 20);
            $table->string('bank_name', 100);
            $table->string('account_name', 100);
            $table->text('account_number_encrypted');
            $table->char('account_number_last4', 4);

            // pending, approved, paid, rejected
            $table->string('status', 15)->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('transfer_reference', 100)->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
