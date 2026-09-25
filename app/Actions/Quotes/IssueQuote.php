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
use Illuminate\Validation\ValidationException;

class IssueQuote
{
    public function handle(Quote $quote, User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $actor) {
            // Ordem primeiro; versão e estado só são lidos depois do lock.
            $order = WorkOrder::whereKey($quote->work_order_id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($quote->id)->firstOrFail();

            if ($order->current_quote_id !== $quote->id) {
                throw new QuoteSuperseded;
            }
            if ($quote->status !== QuoteStatus::Draft) {
                throw new InvalidTransition('Esta versão já foi emitida.');
            }
            if ($order->status !== WorkOrderStatus::Diagnosing) {
                throw InvalidTransition::from($order->status, 'emitir o orçamento');
            }
            if (trim((string) $order->diagnosis) === '') {
                throw ValidationException::withMessages(['diagnosis' => 'Registre o diagnóstico antes de emitir o orçamento.']);
            }
            if (! $quote->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'Adicione ao menos um item ao orçamento antes de emitir.']);
            }

            $quote->status = QuoteStatus::Issued;
            $quote->issued_at = now();
            $quote->save();

            $order->status = WorkOrderStatus::AwaitingApproval;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::QuoteIssued,
                'from_status' => WorkOrderStatus::Diagnosing,
                'to_status' => WorkOrderStatus::AwaitingApproval,
                'metadata' => ['quote_id' => $quote->id, 'version' => $quote->version, 'total_cents' => $quote->total_cents],
            ]);

            return $quote->load('items');
        });
    }
}
