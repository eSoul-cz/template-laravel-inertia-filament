<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Infrastructure\Models\User;
use Illuminate\Console\Command;

final class PromoteAdminCommand extends Command
{
    protected $signature = 'admin:promote {email : Email address of an existing user}';

    protected $description = 'Allow an existing user to open the admin panel';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ( ! $user instanceof User) {
            $this->components->error('No user with this email address. Create one first with `php artisan make:filament-user`.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->components->info("{$user->email} can now open the admin panel.");

        return self::SUCCESS;
    }
}
