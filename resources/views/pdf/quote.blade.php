@extends('pdf.layout')
@section('title', ($quote->isAddendum() ? 'Adendo' : 'Orçamento').' da OS #'.$order->number)
@section('heading', $quote->isAddendum() ? 'Adendo ao orçamento — OS #'.$order->number.' · adendo '.$quote->version : 'Orçamento — OS #'.$order->number.' · versão '.$quote->version)
@section('subheading', $quote->status->label().($quote->issued_at ? ' em '.$quote->issued_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : ''))

@section('content')
    @if ($quote->isAddendum())
        <div class="banner">Itens adicionais descobertos durante o reparo, sobre o orçamento (versão {{ $quote->base->version }}) já aprovado. Só vale depois da aprovação do cliente.</div>
    @endif
    @if ($quote->status === \App\Enums\QuoteStatus::Draft)
        <div class="banner"><strong>Rascunho.</strong> Este orçamento ainda não foi emitido e pode mudar; não vale como proposta ao cliente.</div>
    @elseif ($quote->status === \App\Enums\QuoteStatus::Superseded)
        <div class="banner"><strong>Versão substituída.</strong> Existe uma versão mais nova deste orçamento; esta fica apenas como histórico.</div>
    @endif

    <h2>Cliente e equipamento</h2>
    <dl>
        <dt>Cliente</dt><dd>{{ $order->customer->name }}</dd>
        <dt>Telefone</dt><dd>{{ $order->customer->phone }}</dd>
        <dt>Equipamento</dt><dd>{{ $order->device_category }} {{ $order->device_brand }} {{ $order->device_model }}@if ($order->device_serial_number) (série {{ $order->device_serial_number }})@endif</dd>
        <dt>Problema relatado</dt><dd class="pre">{{ $order->reported_problem }}</dd>
    </dl>

    <h2>Itens</h2>
    @if ($quote->items->isEmpty())
        <p class="muted">Este orçamento não tem itens.</p>
    @else
        <table>
            <thead><tr><th>Tipo</th><th>Descrição</th><th class="right">Qtd.</th><th class="right">Unitário</th><th class="right">Total</th></tr></thead>
            <tbody>
                @foreach ($quote->items as $item)
                    <tr>
                        <td>{{ $item->kind->label() }}</td>
                        <td>{{ $item->description }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        <td class="right">{{ \App\Support\Money::brl($item->unit_price_cents) }}</td>
                        <td class="right">{{ \App\Support\Money::brl($item->quantity * $item->unit_price_cents) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="4" class="right">Total</td><td class="right">{{ \App\Support\Money::brl($quote->total_cents) }}</td></tr>
            </tbody>
        </table>
    @endif

    @if ($quote->notes)
        <h2>Observações</h2>
        <div class="pre">{{ $quote->notes }}</div>
    @endif

    <h2>Decisão do cliente</h2>
    @if ($quote->decision)
        <dl>
            <dt>Decisão</dt><dd>{{ $quote->decision->decision->label() }}</dd>
            <dt>Registrada para</dt><dd>{{ $quote->decision->customer_name }} ({{ $quote->decision->channel->label() }})</dd>
            <dt>Data</dt><dd>{{ $quote->decision->decided_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</dd>
            <dt>Registrada por</dt><dd>{{ $quote->decision->recorder->name }}</dd>
            @if ($quote->decision->notes)<dt>Observações</dt><dd class="pre">{{ $quote->decision->notes }}</dd>@endif
        </dl>
    @else
        <p class="muted">Ainda sem decisão registrada.</p>
    @endif
@endsection
