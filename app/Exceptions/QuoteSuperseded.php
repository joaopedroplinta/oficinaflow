<?php

namespace App\Exceptions;

class QuoteSuperseded extends DomainConflict
{
    public function __construct()
    {
        parent::__construct('Este orçamento foi substituído por uma versão mais nova. Atualize a tela para ver a versão atual.');
    }

    public function errorCode(): string
    {
        return 'QUOTE_SUPERSEDED';
    }
}
