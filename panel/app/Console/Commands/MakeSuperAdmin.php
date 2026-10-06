<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSuperAdmin extends Command
{
    protected $signature = 'zyro:make-super-admin {email}';

    protected $description = 'Give an existing user access to the super admin panel';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email. Create it first: php artisan make:filament-user');

            return self::FAILURE;
        }
        $user->forceFill(['is_super_admin' => true])->save();
        $this->info("{$user->email} is now a super admin.");

        return self::SUCCESS;
    }
}
