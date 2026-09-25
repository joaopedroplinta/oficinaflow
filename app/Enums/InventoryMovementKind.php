<?php

namespace App\Enums;

enum InventoryMovementKind: string
{
    case Receipt = 'receipt';
    case Consumption = 'consumption';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Entrada',
            self::Consumption => 'Consumo',
        };
    }
}
