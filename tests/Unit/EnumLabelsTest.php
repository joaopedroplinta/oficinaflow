<?php

namespace Tests\Unit;

use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class EnumLabelsTest extends TestCase
{
    public function test_every_enum_case_has_a_portuguese_label(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/app/Enums/*.php') as $file) {
            $enum = 'App\\Enums\\'.basename($file, '.php');

            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), "$enum::{$case->name} has no label");
            }
        }
    }

    public function test_role_labels(): void
    {
        $this->assertSame('Atendimento', Role::Admin->label());
    }
}
