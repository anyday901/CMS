<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Staff log in');
    }

    public function test_staff_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);

        $this->get('/admin')->assertOk()->assertSee('Dashboard');

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_admin_create_command(): void
    {
        $this->artisan('admin:create', ['--name' => 'Corey', '--email' => 'corey@example.com', '--password' => 'long-enough-password'])
            ->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'corey@example.com']);

        $this->artisan('admin:create', ['--name' => 'X', '--email' => 'x@example.com', '--password' => 'short'])
            ->assertFailed();
    }
}
