<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create(['name' => 'Ana Atendimento', 'email' => 'admin@oficinaflow.test', 'password' => 'password', 'role' => Role::Admin]);
        User::create(['name' => 'Tiago Técnico', 'email' => 'tecnico@oficinaflow.test', 'password' => 'password', 'role' => Role::Technician]);
    }
}
