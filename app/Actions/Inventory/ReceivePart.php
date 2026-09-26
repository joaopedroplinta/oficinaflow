<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementKind;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePart
{
    public const MAX_ON_HAND = 1_000_000;

    /**
     * Entrada física: aumenta `on_hand` e grava o movimento, na mesma transação, com a peça travada. Com custo
     * unitário, atualiza o custo médio ponderado (entrada sem custo só muda o saldo).
     */
    public function handle(Part $part, int $quantity, ?string $reason, User $actor, ?int $unitCostCents = null, ?int $supplierId = null): Part
    {
        return DB::transaction(function () use ($part, $quantity, $reason, $actor, $unitCostCents, $supplierId) {
            $part = Part::whereKey($part->id)->lockForUpdate()->firstOrFail();

            if ($part->on_hand + $quantity > self::MAX_ON_HAND) {
                throw ValidationException::withMessages(['quantity' => 'O saldo não pode passar de '.number_format(self::MAX_ON_HAND, 0, ',', '.').' unidades.']);
            }

            if ($supplierId !== null && ! Supplier::whereKey($supplierId)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Fornecedor não encontrado ou inativo.']);
            }

            if ($unitCostCents !== null) {
                $part->average_cost_cents = self::newAverageCost($part->on_hand, $part->average_cost_cents, $quantity, $unitCostCents);
            }
            $part->on_hand += $quantity;
            $part->save();

            $movement = new InventoryMovement;
            $movement->part_id = $part->id;
            $movement->kind = InventoryMovementKind::Receipt;
            $movement->quantity = $quantity;
            $movement->unit_cost_cents = $unitCostCents;
            $movement->supplier_id = $supplierId;
            $movement->reason = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Entrada de estoque';
            $movement->created_by = $actor->id;
            $movement->save();

            return $part;
        });
    }

    /**
     * Custo médio ponderado móvel, em centavos inteiros (meio para cima): (saldo × médio + entrada × custo) ÷ novo saldo.
     * Sem custo médio ainda, ele vira o custo da entrada (unidades sem custo não pesam).
     * ponytail: arredonda a cada entrada, então o erro acumula centavos; guardar em milésimos de centavo se pesar.
     */
    public static function newAverageCost(int $onHand, ?int $average, int $quantity, int $unitCost): int
    {
        if ($average === null) {
            return $unitCost;
        }
        $total = $onHand + $quantity;

        return intdiv(2 * ($onHand * $average + $quantity * $unitCost) + $total, 2 * $total);
    }
}
