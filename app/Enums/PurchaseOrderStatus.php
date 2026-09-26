<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Open = 'open';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::PartiallyReceived => 'Recebido em parte',
            self::Received => 'Recebido',
            self::Canceled => 'Cancelado',
        };
    }

    /** Ainda aceita recebimento e cancelamento. */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::PartiallyReceived;
    }
}
