<?php

namespace App\Enums;

enum DecisionChannel: string
{
    case Phone = 'phone';
    case Whatsapp = 'whatsapp';
    case InPerson = 'in_person';
    case Email = 'email';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Telefone',
            self::Whatsapp => 'WhatsApp',
            self::InPerson => 'Presencial',
            self::Email => 'E-mail',
            self::Other => 'Outro',
        };
    }
}
