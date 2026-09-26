<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelPurchaseOrder
{
    /** Cancela o que ainda não foi recebido: o que já entrou fica no estoque e no livro-razão (nada é desfeito). */
    public function handle(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->status->isOpen()) {
                throw new InvalidTransition("Este pedido está “{$order->status->label()}” e não pode ser cancelado.");
            }

            $order->status = PurchaseOrderStatus::Canceled;
            $order->canceled_at = now();
            $order->canceled_by = $actor->id;
            $order->cancellation_reason = trim($reason);
            $order->save();

            return $order;
        });
    }
}
