<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Models\User;
use Tests\TestCase;

final class PromoteAdminCommandTest extends TestCase
{
    public function test_it_promotes_an_existing_user(): void
    {
        $user = User::factory()->create();

        $this->artisan('admin:promote', ['email' => $user->email])->assertSuccessful();

        self::assertTrue($user->refresh()->is_admin);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('admin:promote', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
