<?php

namespace App\Actions\Inventory;

use App\Enums\QuoteDecisionType;
use App\Enums\QuoteItemKind;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class StartRepair
{
    /**
     * Inicia o reparo consumindo as peças reservadas: `on_hand` e `reserved_quantity` baixam juntos, as reservas
     * viram `consumed`, cada consumo entra no livro-razão e a ordem vai a `in_progress`. Tudo ou nada: qualquer
     * falha desfaz estado, saldo, reservas e movimentos. Repetir depois de sucesso não consome de novo.
     *
     * Padrão: exige tudo reservado. Com `$partialStart` (confirmado por quem inicia), consome só o que já está
     * reservado e o resto segue pendente: em `in_progress` dá para reservar o que faltava e consumi-lo depois.
     */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor, bool $partialStart = false): WorkOrder
    {
        return DB::transaction(function () use ($order, $seenQuoteId, $actor, $partialStart) {
            // Ordem primeiro; tudo o que vem depois lê estado já travado.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id === null) {
                throw new InvalidTransition('Esta ordem ainda não tem orçamento.');
            }
            if ($order->current_quote_id !== $seenQuoteId) {
                throw new QuoteSuperseded;
            }
            // Idempotência: o reparo já começou sobre esta versão — nada a fazer, nada é consumido de novo.
            if ($order->status === WorkOrderStatus::InProgress) {
                return $order;
            }
            if ($order->status !== WorkOrderStatus::Approved) {
                throw InvalidTransition::from($order->status, 'iniciar o reparo');
            }

            $quote = Quote::whereKey($order->current_quote_id)->with(['decision', 'items.reservations'])->firstOrFail();
            if ($quote->decision?->decision !== QuoteDecisionType::Approved) {
                throw new InvalidTransition('O orçamento atual não tem aprovação registrada.');
            }

            $pending = $quote->items->where('kind', QuoteItemKind::Part)->filter(fn ($i) => $i->missingQuantity() > 0);
            if ($pending->isNotEmpty() && ! $partialStart) {
                $missing = $pending->map(fn ($i) => ['part_id' => $i->part_id, 'name' => $i->description, 'quantity' => $i->missingQuantity()])->values()->all();

                throw new InvalidTransition('Faltam reservas para: '.collect($missing)->pluck('name')->join(', ').'. Reserve as peças antes de iniciar o reparo.', ['missing' => $missing]);
            }

            $consume = app(ConsumeReservations::class);
            $consumed = $consume->consumeActive($order, $actor);

            $order->status = WorkOrderStatus::InProgress;
            $order->started_at = now();
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::RepairStarted,
                'from_status' => WorkOrderStatus::Approved,
                'to_status' => WorkOrderStatus::InProgress,
                'metadata' => [
                    'quote_id' => $quote->id,
                    'partial_start' => $partialStart && $pending->isNotEmpty(),
                    'consumed' => $consume->summary($consumed),
                    'pending' => $pending->map(fn ($i) => ['part_id' => $i->part_id, 'quantity' => $i->missingQuantity()])->values()->all(),
                ],
            ]);

            return $order;
        });
    }
}
