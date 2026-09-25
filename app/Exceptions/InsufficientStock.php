<?php

namespace App\Exceptions;

class InsufficientStock extends DomainConflict
{
    /** @param list<array{part_id: int, sku: string, name: string, needed: int, available: int}> $shortages */
    public function __construct(private array $shortages)
    {
        $list = collect($shortages)->map(fn ($s) => "{$s['name']} (precisa de {$s['needed']}, disponível {$s['available']})")->join(', ');

        parent::__construct("Estoque insuficiente: {$list}. Nenhuma peça foi reservada; registre a entrada das peças e tente de novo.");
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_STOCK';
    }

    public function details(): array
    {
        return ['shortages' => $this->shortages];
    }
}
