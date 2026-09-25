<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = $this->password();

        User::create(['name' => 'Ana Atendimento', 'email' => 'admin@oficinaflow.test', 'password' => $password, 'role' => Role::Admin]);
        User::create(['name' => 'Tiago Técnico', 'email' => 'tecnico@oficinaflow.test', 'password' => $password, 'role' => Role::Technician]);

        $this->call(DemoSeeder::class);
    }

    // A senha fictícia "password" só existe em ambiente local/teste; em qualquer outro a senha vem de DEMO_PASSWORD.
    private function password(): string
    {
        $explicit = config('app.demo_password');
        if (is_string($explicit) && $explicit !== '') {
            if (! app()->environment('local', 'testing') && strlen($explicit) < 12) {
                throw new RuntimeException('DEMO_PASSWORD precisa ter pelo menos 12 caracteres fora de local/testing.');
            }

            return $explicit;
        }
        if (app()->environment('local', 'testing')) {
            return 'password';
        }

        throw new RuntimeException('Defina DEMO_PASSWORD para semear os usuários de demonstração fora de local/testing.');
    }
}
