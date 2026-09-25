<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Support\Documents;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

// PDFs sob demanda: nada é gravado em disco. Quem pode consultar a ordem pode baixar seus documentos.
class PdfController extends Controller
{
    public function quote(Quote $quote): Response
    {
        $order = $quote->workOrder;
        Gate::authorize('view', $order);

        return $this->download(Documents::quote($quote), "orcamento-os-{$order->number}-v{$quote->version}.pdf");
    }

    public function workOrder(WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);

        return $this->download(Documents::workOrder($workOrder), "ordem-de-servico-{$workOrder->number}.pdf");
    }

    private function download(string $html, string $filename): Response
    {
        // Subsetting: embute só os glifos usados (sem isso cada PDF leva a fonte inteira, ~900 KB).
        return Pdf::loadHTML($html)->setPaper('a4')->setOption(['enable_font_subsetting' => true])->download($filename);
    }
}
