<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteDecisionType;
use App\Enums\QuoteStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\Quote;
use App\Models\QuoteDecision;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordQuoteDecision
{
    /** @param array{decision: string, customer_name: string, channel: string, decided_at?: string|null, notes?: string|null} $data */
    public function handle(Quote $quote, array $data, User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $data, $actor) {
            // Ordem primeiro; versão, decisão e estado só são lidos depois do lock.
            $order = WorkOrder::whereKey($quote->work_order_id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($quote->id)->firstOrFail();

            if ($order->current_quote_id !== $quote->id) {
                throw new QuoteSuperseded;
            }
            if ($quote->decision()->exists()) {
                throw new InvalidTransition('Este orçamento já tem uma decisão registrada.');
            }
            if ($quote->status !== QuoteStatus::Issued) {
                throw new InvalidTransition('Este orçamento ainda não foi emitido.');
            }
            if ($order->status !== WorkOrderStatus::AwaitingApproval) {
                throw InvalidTransition::from($order->status, 'registrar a decisão do cliente');
            }

            $decision = QuoteDecisionType::from($data['decision']);
            $decidedAt = isset($data['decided_at']) ? Carbon::parse($data['decided_at']) : now();

            $record = new QuoteDecision;
            $record->quote_id = $quote->id;
            $record->decision = $decision;
            $record->customer_name = $data['customer_name'];
            $record->channel = $data['channel'];
            $record->decided_at = $decidedAt;
            $record->recorded_by = $actor->id;
            $record->notes = $data['notes'] ?? null;
            $record->save();

            // Aprovada segue para reserva/reparo; rejeitada volta ao diagnóstico para revisar ou cancelar.
            $next = $decision === QuoteDecisionType::Approved ? WorkOrderStatus::Approved : WorkOrderStatus::Diagnosing;
            $order->status = $next;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => $decision === QuoteDecisionType::Approved ? WorkOrderEventType::QuoteApproved : WorkOrderEventType::QuoteRejected,
                'from_status' => WorkOrderStatus::AwaitingApproval,
                'to_status' => $next,
                'metadata' => [
                    'quote_id' => $quote->id,
                    'version' => $quote->version,
                    'customer_name' => $record->customer_name,
                    'channel' => $data['channel'],
                    'decided_at' => $decidedAt->toIso8601String(),
                ],
            ]);

            return $quote->load(['items', 'decision.recorder']);
        });
    }
}
