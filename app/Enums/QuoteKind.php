<?php

namespace App\Enums;

enum QuoteKind: string
{
    case Version = 'version';
    case Addendum = 'addendum';

    public function label(): string
    {
        return match ($this) {
            self::Version => 'Versão',
            self::Addendum => 'Adendo',
        };
    }
}
