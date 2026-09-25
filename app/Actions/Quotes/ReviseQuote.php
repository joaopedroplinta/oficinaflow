<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class ReviseQuote
{
    /** Antes do reparo: substitui a versão atual por uma nova em rascunho e devolve a ordem ao diagnóstico. */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor): Quote
    {
        return DB::transaction(function () use ($order, $seenQuoteId, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id === null) {
                throw new InvalidTransition('Esta ordem ainda não tem orçamento para revisar.');
            }
            if ($order->current_quote_id !== $seenQuoteId) {
                throw new QuoteSuperseded;
            }
            if (! in_array($order->status, [WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved], true)) {
                throw InvalidTransition::from($order->status, 'revisar o orçamento');
            }

            $old = Quote::whereKey($order->current_quote_id)->with(['items', 'decision'])->firstOrFail();
            if ($old->status !== QuoteStatus::Issued) {
                throw new InvalidTransition('O orçamento atual ainda é um rascunho: edite-o em vez de criar uma revisão.');
            }

            // Sai da frente antes de criar a nova: só pode haver uma versão não substituída por ordem.
            $old->status = QuoteStatus::Superseded;
            $old->save();

            // ponytail: gancho do #18 — aqui as reservas ativas da versão antiga são liberadas (ReleaseReservations),
            // na mesma transação, com as peças travadas em ordem crescente de ID. Sem tabela de reservas até a semana 4.

            $new = new Quote;
            $new->work_order_id = $order->id;
            $new->version = $old->version + 1;
            $new->status = QuoteStatus::Draft;
            $new->notes = $old->notes;
            $new->total_cents = $old->total_cents;
            $new->created_by = $actor->id;
            $new->save();
            $new->items()->createMany($old->items->map->only(['kind', 'part_id', 'description', 'quantity', 'unit_price_cents', 'position'])->all());

            $from = $order->status;
            $order->current_quote_id = $new->id;
            $order->status = WorkOrderStatus::Diagnosing;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::QuoteRevised,
                'from_status' => $from,
                'to_status' => WorkOrderStatus::Diagnosing,
                'metadata' => [
                    'previous_quote_id' => $old->id,
                    'previous_version' => $old->version,
                    'previous_decision' => $old->decision?->decision->value,
                    'new_quote_id' => $new->id,
                    'new_version' => $new->version,
                ],
            ]);

            return $new->load('items');
        });
    }
}
