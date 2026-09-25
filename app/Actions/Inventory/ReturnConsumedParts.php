<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementKind;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnConsumedParts
{
    /** Consumos da ordem, do livro-razão. */
    public function consumptionsOf(WorkOrder $order): Collection
    {
        return InventoryMovement::query()
            ->select('inventory_movements.*')
            ->where('inventory_movements.kind', InventoryMovementKind::Consumption->value)
            ->whereIn('inventory_movements.reservation_id', DB::table('part_reservations as pr')
                ->join('quote_items as qi', 'qi.id', '=', 'pr.quote_item_id')
                ->join('quotes as q', 'q.id', '=', 'qi.quote_id')
                ->where('q.work_order_id', $order->id)->select('pr.id'))
            ->orderBy('inventory_movements.part_id')->orderBy('inventory_movements.id')->get();
    }

    /**
     * Para cada peça consumida na ordem, devolve ao estoque (`return`: um movimento de estorno por consumo, `on_hand`
     * sobe) ou mantém baixada (`keep`: usada ou perdida; nada muda). O consumo nunca é apagado. Quem chama já travou
     * a ordem e as peças (em ID crescente) e é dono da transação.
     *
     * @param  array<int, string>  $decisions  part_id => 'return'|'keep'; precisa cobrir exatamente as peças consumidas
     * @return array{returned: list<array{part_id: int, quantity: int}>, kept: list<array{part_id: int, quantity: int}>}
     */
    public function handle(WorkOrder $order, array $decisions, User $actor): array
    {
        $consumptions = $this->consumptionsOf($order);
        $consumedParts = $consumptions->pluck('part_id')->unique()->sort()->values();

        if ($consumedParts->sort()->values()->all() !== collect(array_keys($decisions))->map(fn ($id) => (int) $id)->sort()->values()->all()) {
            $names = Part::whereIn('id', $consumedParts)->orderBy('name')->pluck('name')->join(', ');

            throw ValidationException::withMessages(['parts' => $consumedParts->isEmpty()
                ? 'Esta ordem não tem peça consumida: não envie decisão de estorno.'
                : "Diga, para cada peça consumida, se volta ao estoque ou fica baixada: {$names}."]);
        }

        $parts = Part::whereIn('id', $consumedParts)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $returned = [];
        $kept = [];

        foreach ($consumptions as $consumption) {
            // Estorno só acontece no cancelamento, uma vez: o consumo inteiro volta (a soma ≤ consumido é do trigger).
            $open = $consumption->quantity;
            if (($decisions[$consumption->part_id] ?? null) !== 'return') {
                $kept[$consumption->part_id] = ($kept[$consumption->part_id] ?? 0) + $open;

                continue;
            }

            $part = $parts[$consumption->part_id];
            $part->on_hand += $open;
            $part->save();

            $movement = new InventoryMovement;
            $movement->part_id = $part->id;
            $movement->kind = InventoryMovementKind::Return;
            $movement->quantity = $open;
            $movement->reverses_movement_id = $consumption->id;
            $movement->reason = "Estorno do consumo na ordem #{$order->number} (serviço cancelado)";
            $movement->created_by = $actor->id;
            $movement->save();

            $returned[$part->id] = ($returned[$part->id] ?? 0) + $open;
        }

        $list = fn (array $totals) => collect($totals)->map(fn ($quantity, $partId) => ['part_id' => $partId, 'quantity' => $quantity])->values()->all();

        return ['returned' => $list($returned), 'kept' => $list($kept)];
    }
}
