<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();    // pembeli
            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            $table->string('type', 15);          // complaint, return, refund
            $table->string('reason_code', 30);
            $table->text('description');

            // open, under_review, approved, rejected, resolved
            $table->string('status', 20)->default('open');

            // Uang dalam rupiah utuh.
            $table->unsignedBigInteger('requested_amount')->nullable();
            $table->unsignedBigInteger('approved_amount')->nullable();

            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
