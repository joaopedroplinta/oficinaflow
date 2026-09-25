<?php

namespace App\Enums;

enum WorkOrderEventType: string
{
    case Opened = 'opened';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Ordem aberta',
        };
    }
}
