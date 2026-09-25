<?php

namespace App\Support;

use App\Models\Quote;
use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/** HTML dos documentos em PDF (dompdf) e a conversão para PDF. Sem estado. */
final class Documents
{
    public static function quote(Quote $quote): string
    {
        $quote->load(['workOrder.customer', 'items', 'decision.recorder']);

        return view('pdf.quote', ['quote' => $quote, 'order' => $quote->workOrder, 'generatedAt' => now()])->render();
    }

    public static function workOrder(WorkOrder $order): string
    {
        $order->load(['customer', 'technician', 'origin', 'currentQuote.items.reservations', 'currentQuote.decision.recorder']);
        $events = $order->events()->with('actor')->orderBy('id')->get();

        return view('pdf.work-order', ['order' => $order, 'quote' => $order->currentQuote, 'events' => $events, 'generatedAt' => now()])->render();
    }

    public static function pdf(string $html): DomPdf
    {
        // Subsetting: embute só os glifos usados (sem isso cada PDF leva a fonte inteira, ~900 KB).
        return Pdf::loadHTML($html)->setPaper('a4')->setOption(['enable_font_subsetting' => true]);
    }
}
