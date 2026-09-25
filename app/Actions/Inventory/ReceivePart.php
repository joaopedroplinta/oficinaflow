<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementKind;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePart
{
    public const MAX_ON_HAND = 1_000_000;

    /** Entrada física: aumenta `on_hand` e grava o movimento, na mesma transação, com a peça travada. */
    public function handle(Part $part, int $quantity, ?string $reason, User $actor): Part
    {
        return DB::transaction(function () use ($part, $quantity, $reason, $actor) {
            $part = Part::whereKey($part->id)->lockForUpdate()->firstOrFail();

            if ($part->on_hand + $quantity > self::MAX_ON_HAND) {
                throw ValidationException::withMessages(['quantity' => 'O saldo não pode passar de '.number_format(self::MAX_ON_HAND, 0, ',', '.').' unidades.']);
            }

            $part->on_hand += $quantity;
            $part->save();

            $movement = new InventoryMovement;
            $movement->part_id = $part->id;
            $movement->kind = InventoryMovementKind::Receipt;
            $movement->quantity = $quantity;
            $movement->reason = $reason !== null && trim($reason) !== '' ? trim($reason) : 'Entrada de estoque';
            $movement->created_by = $actor->id;
            $movement->save();

            return $part;
        });
    }
}
