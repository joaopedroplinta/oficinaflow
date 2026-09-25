<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;

class InvalidTransition extends DomainConflict
{
    public function __construct(string $message, private array $details = [])
    {
        parent::__construct($message);
    }

    public static function from(WorkOrderStatus $current, string $action): self
    {
        return new self("Não é possível {$action} com a ordem em “{$current->label()}”. Atualize a tela e confira o estado atual.");
    }

    public function details(): array
    {
        return $this->details;
    }

    public function errorCode(): string
    {
        return 'INVALID_TRANSITION';
    }
}
