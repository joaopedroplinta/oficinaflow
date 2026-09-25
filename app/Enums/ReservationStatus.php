<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Consumed = 'consumed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Reservada',
            self::Released => 'Liberada',
            self::Consumed => 'Consumida',
        };
    }
}
