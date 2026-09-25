<?php

namespace App\Exceptions;

class ReturnAlreadyOpen extends DomainConflict
{
    public function __construct(int $number)
    {
        parent::__construct("Esta ordem já tem um retorno em andamento (ordem #{$number}). Conclua ou cancele esse retorno antes de abrir outro.");
    }

    public function errorCode(): string
    {
        return 'RETURN_ALREADY_OPEN';
    }
}
