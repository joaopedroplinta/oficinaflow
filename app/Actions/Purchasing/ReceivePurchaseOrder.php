<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\ReceivePart;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\OverReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivePurchaseOrder
{
    /**
     * Recebe (inteira ou parcialmente) itens do pedido: cada linha vira uma entrada no livro-razão, com o custo da
     * linha (ou o combinado no pedido) e o fornecedor do pedido, e atualiza o custo médio. Tudo ou nada; o pedido
     * é travado primeiro e as peças em ID crescente (mesmo protocolo das outras Actions de estoque).
     *
     * @param  list<array{purchase_order_item_id: int, quantity: int, unit_cost_cents?: int|null}>  $lines
     */
    public function handle(PurchaseOrder $order, array $lines, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $lines, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Estado lido depois do lock: o que veio da rota pode estar velho.
            if (! $order->status->isOpen()) {
                throw new InvalidTransition("Este pedido está “{$order->status->label()}” e não recebe mais entregas.");
            }

            $items = $order->items()->with('part')->get()->keyBy('id');
            $seen = [];
            foreach (array_values($lines) as $n => $line) {
                if (! $items->has($line['purchase_order_item_id'])) {
                    throw ValidationException::withMessages(["items.{$n}.purchase_order_item_id" => 'Este item não pertence ao pedido.']);
                }
                if (isset($seen[$line['purchase_order_item_id']])) {
                    throw ValidationException::withMessages(["items.{$n}.purchase_order_item_id" => 'Este item aparece duas vezes.']);
                }
                $seen[$line['purchase_order_item_id']] = true;
            }

            // Peças em ordem crescente de ID (protocolo de locks; `ReceivePart` trava cada uma).
            $sorted = collect($lines)->sortBy(fn ($line) => $items[$line['purchase_order_item_id']]->part_id)->values();
            foreach ($sorted as $line) {
                $item = $items[$line['purchase_order_item_id']];
                $remaining = $item->quantity - $item->receivedQuantity();
                if ($line['quantity'] > $remaining) {
                    throw new OverReceipt($item->part->name, $remaining);
                }

                app(ReceivePart::class)->handle(
                    $item->part, $line['quantity'], "Recebimento do pedido de compra #{$order->number}", $actor,
                    $line['unit_cost_cents'] ?? $item->unit_cost_cents, $order->supplier_id, $item->id,
                );
            }

            $done = $items->every(fn ($item) => $item->receivedQuantity() >= $item->quantity);
            $order->status = $done ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
            $order->save();

            return $order;
        });
    }
}
