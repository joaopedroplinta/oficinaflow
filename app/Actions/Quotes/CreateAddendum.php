<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteDecisionType;
use App\Enums\QuoteKind;
use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class CreateAddendum
{
    /**
     * Adendo ao orçamento aprovado: itens novos descobertos durante o reparo. A versão emitida continua imutável;
     * o adendo tem itens, emissão e decisão próprios e a ordem segue `in_progress`. Um adendo em aberto por vez.
     */
    public function handle(WorkOrder $order, User $actor): Quote
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::InProgress) {
                throw InvalidTransition::from($order->status, 'criar um adendo ao orçamento');
            }

            $base = Quote::whereKey($order->current_quote_id)->with('decision')->firstOrFail();
            if ($base->status !== QuoteStatus::Issued || $base->decision?->decision !== QuoteDecisionType::Approved) {
                throw new InvalidTransition('O adendo só existe sobre um orçamento aprovado.');
            }

            // Um por vez: rascunho ou emitido ainda sem decisão bloqueia outro.
            $open = $order->addenda()->where(fn ($q) => $q->where('status', QuoteStatus::Draft->value)
                ->orWhere(fn ($i) => $i->where('status', QuoteStatus::Issued->value)->whereDoesntHave('decision')))->first();
            if ($open) {
                throw new InvalidTransition("O adendo {$open->version} ainda está em aberto: emita e registre a decisão do cliente antes de criar outro.");
            }

            $addendum = new Quote;
            $addendum->work_order_id = $order->id;
            $addendum->kind = QuoteKind::Addendum;
            $addendum->base_quote_id = $base->id;
            $addendum->version = (int) $order->addenda()->max('version') + 1;
            $addendum->status = QuoteStatus::Draft;
            $addendum->total_cents = 0;
            $addendum->created_by = $actor->id;
            $addendum->save();

            return $addendum->load('items');
        });
    }
}
