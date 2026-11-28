<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->string('seller_decision', 10)->nullable()->after('admin_note');
            $table->text('seller_response')->nullable()->after('seller_decision');
            $table->timestamp('seller_responded_at')->nullable()->after('seller_response');
            $table->string('bearer', 10)->default('seller')->after('seller_responded_at');
            $table->unsignedBigInteger('service_fee_refunded')->default(0)->after('bearer');
            $table->unsignedBigInteger('seller_charged')->default(0)->after('service_fee_refunded');
            $table->unsignedBigInteger('shortfall')->default(0)->after('seller_charged');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn(['seller_decision', 'seller_response', 'seller_responded_at', 'bearer', 'service_fee_refunded', 'seller_charged', 'shortfall']);
        });
    }
};
