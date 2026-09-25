<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case Received = 'received';
    case Diagnosing = 'diagnosing';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case ReadyForPickup = 'ready_for_pickup';
    case Delivered = 'delivered';
    case AwaitingReturn = 'awaiting_return';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recebida',
            self::Diagnosing => 'Em diagnóstico',
            self::AwaitingApproval => 'Aguardando aprovação',
            self::Approved => 'Aprovada',
            self::InProgress => 'Em reparo',
            self::ReadyForPickup => 'Pronta para retirada',
            self::Delivered => 'Entregue',
            self::AwaitingReturn => 'Aguardando devolução',
            self::Canceled => 'Cancelada',
        };
    }
}
