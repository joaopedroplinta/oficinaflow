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

class IssueAddendum
{
    /** Emite o adendo (congela itens e total). A ordem continua em reparo; a decisão do cliente vem depois. */
    public function handle(Quote $addendum, User $actor): Quote
    {
        return DB::transaction(function () use ($addendum, $actor) {
            $order = WorkOrder::whereKey($addendum->work_order_id)->lockForUpdate()->firstOrFail();
            $addendum = Quote::whereKey($addendum->id)->firstOrFail();

            if ($order->current_quote_id !== $addendum->base_quote_id) {
                throw new QuoteSuperseded;
            }
            if ($addendum->status !== QuoteStatus::Draft) {
                throw new InvalidTransition('Este adendo já foi emitido.');
            }
            if ($order->status !== WorkOrderStatus::InProgress) {
                throw InvalidTransition::from($order->status, 'emitir o adendo');
            }
            if (! $addendum->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'Adicione ao menos um item ao adendo antes de emitir.']);
            }

            $addendum->status = QuoteStatus::Issued;
            $addendum->issued_at = now();
            $addendum->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::AddendumIssued,
                'from_status' => $order->status,
                'to_status' => $order->status,
                'metadata' => ['quote_id' => $addendum->id, 'number' => $addendum->version, 'total_cents' => $addendum->total_cents],
            ]);

            return $addendum->load('items');
        });
    }
}
