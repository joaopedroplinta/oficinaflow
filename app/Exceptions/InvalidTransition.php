<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;

class InvalidTransition extends DomainConflict
{
    public function __construct(WorkOrderStatus $current, string $action)
    {
        parent::__construct("Não é possível {$action} com a ordem em “{$current->label()}”. Atualize a tela e confira o estado atual.");
    }

    public function errorCode(): string
    {
        return 'INVALID_TRANSITION';
    }
}
