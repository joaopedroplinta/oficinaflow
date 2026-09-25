<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class CreateQuoteDraft
{
    public function handle(WorkOrder $order, User $actor): Quote
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::Diagnosing) {
                throw InvalidTransition::from($order->status, 'criar o orçamento');
            }
            if ($order->current_quote_id !== null) {
                throw new InvalidTransition('Esta ordem já tem orçamento. Para alterá-lo, crie uma revisão.');
            }

            $quote = new Quote;
            $quote->work_order_id = $order->id;
            $quote->version = 1;
            $quote->status = QuoteStatus::Draft;
            $quote->total_cents = 0;
            $quote->created_by = $actor->id;
            $quote->save();

            $order->current_quote_id = $quote->id;
            $order->save();

            return $quote->load('items');
        });
    }
}
