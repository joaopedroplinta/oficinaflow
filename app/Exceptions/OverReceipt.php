<?php

namespace App\Exceptions;

class OverReceipt extends DomainConflict
{
    public function __construct(string $partName, int $remaining)
    {
        parent::__construct("Não dá para receber mais do que o pedido: de {$partName} faltam receber {$remaining}. Atualize a tela e confira as quantidades.");
    }

    public function errorCode(): string
    {
        return 'OVER_RECEIPT';
    }
}
