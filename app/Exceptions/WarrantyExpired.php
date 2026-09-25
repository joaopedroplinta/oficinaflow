<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;

class WarrantyExpired extends DomainConflict
{
    public function __construct(CarbonInterface $until)
    {
        parent::__construct('A garantia venceu em '.$until->timezone('America/Sao_Paulo')->format('d/m/Y').'. Se o serviço precisa ser refeito, abra um retrabalho.');
    }

    public function errorCode(): string
    {
        return 'WARRANTY_EXPIRED';
    }
}
