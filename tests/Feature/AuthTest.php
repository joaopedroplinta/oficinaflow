<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

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

    public function test_the_attempt_is_counted_before_the_password_is_checked(): void
    {
        $user = User::factory()->create();
        $key = strtolower($user->email).'|127.0.0.1';
        $seenWhileChecking = null;
        Event::listen(Attempting::class, function () use ($key, &$seenWhileChecking) {
            $seenWhileChecking = RateLimiter::attempts($key);
        });

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();

        // Já contava 1 no momento da conferência da senha: requisições paralelas não escapam do limite.
        $this->assertSame(1, $seenWhileChecking);
        $this->assertSame(1, RateLimiter::attempts($key));

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertSame(0, RateLimiter::attempts($key), 'o login válido zera o contador');
    }

    public function test_without_trusted_proxies_the_forwarded_for_header_cannot_dodge_the_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', "203.0.113.{$i}")
                ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertUnprocessable();
        }

        // Cada tentativa veio "de um IP diferente" pelo cabeçalho, mas o IP real é o do proxy: o limite vale.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', '203.0.113.99')
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertStatus(429);
    }

    public function test_behind_a_trusted_proxy_each_client_has_its_own_counter(): void
    {
        // Pelo caminho real: a configuração (TRUSTED_PROXIES → config/trustedproxy.php), não o atalho estático.
        config(['trustedproxy.proxies' => '10.0.0.0/8']);
        $user = User::factory()->create();
        $attempt = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Forwarded-For', $client)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada']);

        for ($i = 0; $i < 5; $i++) {
            $attempt('203.0.113.1')->assertUnprocessable();
        }

        $attempt('203.0.113.1')->assertStatus(429);
        // Outro cliente atrás do mesmo proxy não é travado junto: o atacante não trava o e-mail para todo mundo.
        $attempt('203.0.113.2')->assertUnprocessable();
    }

    public function test_login_from_a_non_stateful_origin_is_refused_clearly_and_not_counted(): void
    {
        $user = User::factory()->create();
        $key = strtolower($user->email).'|127.0.0.1';

        $this->withHeaders(['Referer' => 'https://evil.example', 'Origin' => 'https://evil.example'])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('message', 'Origem não autorizada para entrar no sistema.');

        $this->assertSame(0, RateLimiter::attempts($key));
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
