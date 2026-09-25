<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Issued => 'Emitido',
            self::Superseded => 'Substituído',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
