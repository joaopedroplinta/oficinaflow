<?php

namespace App\Exceptions;

use RuntimeException;

/** Conflito de regra de negócio; vira `409 {code, message}` no handler de bootstrap/app.php. */
abstract class DomainConflict extends RuntimeException
{
    abstract public function errorCode(): string;

    /** Detalhes estruturados para a tela (ex.: quais peças faltam). */
    public function details(): array
    {
        return [];
    }
}
