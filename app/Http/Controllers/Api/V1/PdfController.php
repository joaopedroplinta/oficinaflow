<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Support\Documents;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

// PDFs sob demanda: nada é gravado em disco. Quem pode consultar a ordem pode baixar seus documentos.
class PdfController extends Controller
{
    public function quote(Quote $quote): Response
    {
        $order = $quote->workOrder;
        Gate::authorize('view', $order);

        $name = $quote->isAddendum() ? "adendo-os-{$order->number}-{$quote->version}" : "orcamento-os-{$order->number}-v{$quote->version}";

        return $this->download(Documents::quote($quote), "{$name}.pdf");
    }

    public function workOrder(WorkOrder $workOrder): Response
    {
        Gate::authorize('view', $workOrder);

        return $this->download(Documents::workOrder($workOrder), "ordem-de-servico-{$workOrder->number}.pdf");
    }

    private function download(string $html, string $filename): Response
    {
        return Documents::pdf($html)->download($filename);
    }
}
