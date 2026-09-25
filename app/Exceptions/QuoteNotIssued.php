<?php

namespace App\Exceptions;

class QuoteNotIssued extends DomainConflict
{
    public function __construct()
    {
        parent::__construct('Só é possível enviar uma versão já emitida. Emita o orçamento antes de enviar.');
    }

    public function errorCode(): string
    {
        return 'QUOTE_NOT_ISSUED';
    }
}
