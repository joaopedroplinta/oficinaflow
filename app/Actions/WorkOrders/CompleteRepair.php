<?php

namespace App\Actions\WorkOrders;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class CompleteRepair
{
    /** `in_progress → ready_for_pickup`, registrando o que foi feito. */
    public function handle(WorkOrder $order, string $workPerformed, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $workPerformed, $actor) {
            // Estado lido depois do lock: o que veio da rota pode estar velho.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::InProgress) {
                throw InvalidTransition::from($order->status, 'concluir o reparo');
            }

            // Adendo em rascunho ou emitido sem decisão ficaria sem saída depois da conclusão (só se decide em reparo).
            if ($order->addenda()->where(fn ($q) => $q->where('status', QuoteStatus::Draft->value)
                ->orWhere(fn ($i) => $i->where('status', QuoteStatus::Issued->value)->whereDoesntHave('decision')))->exists()) {
                throw new InvalidTransition('Há um adendo ao orçamento em aberto. Registre a decisão do cliente (aprovando ou rejeitando) antes de concluir o reparo.');
            }

            // Peça do orçamento que ainda não saiu do estoque (não reservada, ou reservada e não consumida): depois de um
            // início parcial, é preciso reservar e consumir o que faltou antes de concluir.
            $unfinished = $order->reservableItems()->filter(fn ($i) => $i->consumedQuantity() < $i->quantity);
            if ($unfinished->isNotEmpty()) {
                $pending = $unfinished->map(fn ($i) => ['part_id' => $i->part_id, 'name' => $i->description, 'quantity' => $i->quantity - $i->consumedQuantity()])->values()->all();

                throw new InvalidTransition('Ainda faltam peças do orçamento: '.collect($pending)->map(fn ($p) => "{$p['name']} ({$p['quantity']})")->join(', ').'. Reserve e consuma as peças antes de concluir o reparo.', ['pending' => $pending]);
            }

            $order->work_performed = trim($workPerformed);
            $order->ready_at = now();
            $order->status = WorkOrderStatus::ReadyForPickup;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::RepairCompleted,
                'from_status' => WorkOrderStatus::InProgress,
                'to_status' => WorkOrderStatus::ReadyForPickup,
            ]);

            return $order;
        });
    }
}
