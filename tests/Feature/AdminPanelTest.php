<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Models\User;
use Tests\TestCase;

final class AdminPanelTest extends TestCase
{
    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_users_without_the_admin_flag_cannot_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admins_can_open_the_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin')
            ->assertOk();
    }
}
