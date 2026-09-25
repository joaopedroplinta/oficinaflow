<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_me_and_logout(): void
    {
        $user = User::factory()->technician()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('role', 'technician');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);

        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_password_and_inactive_user_are_rejected(): void
    {
        $user = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $inactive->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
