<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Atendimento',
            self::Technician => 'Técnico',
        };
    }
}
