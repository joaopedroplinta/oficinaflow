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
            ->assertOk()->assertJsonPath('data.role', 'technician')->assertJsonPath('data.role_label', 'Técnico')->assertJsonMissingPath('data.password');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);

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

    public function test_session_of_a_user_deactivated_after_login_is_dropped(): void
    {
        $user = User::factory()->technician()->create();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/customers')->assertOk();

        $user->update(['active' => false]);
        $this->app['auth']->forgetGuards(); // próxima requisição = processo novo

        $this->getJson('/api/v1/customers')->assertUnauthorized()->assertJsonPath('message', 'Sua conta foi desativada.');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
