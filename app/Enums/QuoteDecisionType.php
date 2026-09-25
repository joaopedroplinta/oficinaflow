<?php

namespace App\Enums;

enum QuoteDecisionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
        };
    }
}
