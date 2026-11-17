<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class MakeAdminCommand extends Command
{
    protected $signature = 'nexora:make-admin
                            {email : Email akun yang sudah terdaftar}
                            {--super : Jadikan super admin (bukan admin biasa)}';

    protected $description = 'Memberi role admin (atau super_admin) kepada akun yang sudah terdaftar';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $role  = $this->option('super') ? 'super_admin' : 'admin';

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Akun dengan email {$email} tidak ditemukan. Daftarkan akunnya lebih dulu lewat API.");

            return self::FAILURE;
        }

        if ($user->hasRole($role)) {
            $this->info("{$email} sudah memiliki role {$role}.");

            return self::SUCCESS;
        }

        try {
            $user->assignRole($role);
        } catch (RoleDoesNotExist) {
            $this->error("Role {$role} belum ada. Jalankan dulu: php artisan db:seed");

            return self::FAILURE;
        }

        ActivityLog::create([
            'user_id'    => $user->id,
            'action'     => 'admin.role_granted',
            'properties' => ['role' => $role, 'via' => 'artisan'],
            'user_agent' => 'artisan',
        ]);

        $this->info("Berhasil: {$email} sekarang memiliki role {$role}.");

        return self::SUCCESS;
    }
}
