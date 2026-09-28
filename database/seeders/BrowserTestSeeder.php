<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'oficinaflow_testing') {
            throw new RuntimeException('Browser fixtures require APP_ENV=testing and the oficinaflow_testing database.');
        }

        User::firstOrCreate(['email' => 'browser-admin@oficinaflow.test'], ['name' => 'Atendimento de teste', 'password' => 'password', 'role' => Role::Admin]);
        User::firstOrCreate(['email' => 'browser-tech@oficinaflow.test'], ['name' => 'Técnico de teste', 'password' => 'password', 'role' => Role::Technician]);
    }
}
