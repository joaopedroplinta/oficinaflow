<?php

namespace App\Enums;

enum WorkOrderKind: string
{
    case Regular = 'regular';
    case Warranty = 'warranty';
    case Rework = 'rework';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Normal',
            self::Warranty => 'Garantia',
            self::Rework => 'Retrabalho',
        };
    }
}
