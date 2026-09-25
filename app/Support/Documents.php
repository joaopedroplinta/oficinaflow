<?php

namespace App\Support;

use App\Models\Quote;
use App\Models\WorkOrder;

/** HTML dos documentos em PDF (dompdf). Sem estado; o controller só converte em PDF. */
final class Documents
{
    public static function quote(Quote $quote): string
    {
        $quote->load(['workOrder.customer', 'items', 'decision.recorder']);

        return view('pdf.quote', ['quote' => $quote, 'order' => $quote->workOrder, 'generatedAt' => now()])->render();
    }

    public static function workOrder(WorkOrder $order): string
    {
        $order->load(['customer', 'technician', 'currentQuote.items.reservation', 'currentQuote.decision.recorder']);
        $events = $order->events()->with('actor')->orderBy('id')->get();

        return view('pdf.work-order', ['order' => $order, 'quote' => $order->currentQuote, 'events' => $events, 'generatedAt' => now()])->render();
    }
}
