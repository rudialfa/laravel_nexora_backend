<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ID publik. nullable hanya agar migration aman pada tabel yang sudah ada;
            // aplikasi (model User) selalu mengisinya otomatis.
            $table->uuid('uuid')->nullable()->unique()->after('id');

            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('avatar_path')->nullable()->after('password');
            $table->date('birth_date')->nullable()->after('avatar_path');
            $table->string('gender', 10)->nullable()->after('birth_date');
            $table->string('status', 20)->default('active')->after('gender');
            $table->timestamp('last_login_at')->nullable()->after('status');

            $table->softDeletes();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropUnique(['uuid']);
            $table->dropUnique(['phone']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'uuid',
                'phone',
                'phone_verified_at',
                'avatar_path',
                'birth_date',
                'gender',
                'status',
                'last_login_at',
            ]);
        });
    }
};
