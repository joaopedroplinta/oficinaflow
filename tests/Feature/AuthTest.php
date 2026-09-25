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

        // Reativado, o usuário ainda precisa logar de novo: a sessão antiga foi invalidada.
        $user->update(['active' => true]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_per_email_and_ip_after_repeated_failures(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();
        }

        // A sexta tentativa é barrada mesmo com a senha certa, em português e com Retry-After.
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('errors.email.0', fn ($m) => str_starts_with($m, 'Muitas tentativas de entrada. Tente novamente em '));
        $this->getJson('/api/v1/me')->assertUnauthorized();

        // Outro e-mail, mesmo IP, segue livre.
        $this->postJson('/api/v1/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
    }

    public function test_a_successful_login_resets_the_failure_counter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();
        }
    }

    public function test_the_limit_also_covers_unknown_emails_and_ignores_letter_case(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $case = $i % 2 ? 'FANTASMA@Oficina.test' : 'fantasma@oficina.test';
            $this->postJson('/api/v1/login', ['email' => $case, 'password' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/login', ['email' => 'Fantasma@OFICINA.test', 'password' => 'x'])->assertStatus(429);
    }
}
