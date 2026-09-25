<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    private function setDemoPassword(?string $value): void
    {
        config(['app.demo_password' => $value]);
    }

    public function test_in_testing_the_fictional_password_is_used_when_none_is_set(): void
    {
        $this->setDemoPassword(null);
        app(DatabaseSeeder::class)->run();

        $this->postJson('/api/v1/login', ['email' => 'admin@oficinaflow.test', 'password' => 'password'])->assertOk();
        $this->assertSame(2, User::count());
    }

    public function test_outside_local_the_seed_refuses_to_run_without_an_explicit_password(): void
    {
        $this->setDemoPassword(null);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_PASSWORD');
        try {
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->assertSame(0, User::count(), 'nenhum usuário com senha conhecida foi criado');
        }
    }

    public function test_outside_local_the_explicit_password_is_used_instead_of_the_fictional_one(): void
    {
        $this->setDemoPassword('senha-de-demo-longa-e-unica');
        $this->app['env'] = 'production';
        app(DatabaseSeeder::class)->run();

        $this->app['env'] = 'testing';
        $this->postJson('/api/v1/login', ['email' => 'admin@oficinaflow.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'admin@oficinaflow.test', 'password' => 'senha-de-demo-longa-e-unica'])->assertOk();
    }

    public function test_outside_local_a_short_demo_password_is_refused(): void
    {
        $this->setDemoPassword('password');
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('12 caracteres');
        try {
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->assertSame(0, User::count());
        }
    }
}
