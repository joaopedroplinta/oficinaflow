<?php

namespace App\Enums;

enum QuoteItemKind: string
{
    case Part = 'part';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Part => 'Peça',
            self::Service => 'Serviço',
        };
    }
}
