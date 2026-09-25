@extends('pdf.layout')
@section('title', 'Ordem de serviço #'.$order->number)
@section('heading', 'Ordem de serviço #'.$order->number)
@section('subheading', $order->status->label().($order->origin ? ' · '.$order->kind->label().' da ordem #'.$order->origin->number : '').' · recebida em '.$order->received_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'))

@section('content')
    <h2>Cliente e equipamento</h2>
    <dl>
        <dt>Cliente</dt><dd>{{ $order->customer->name }} · {{ $order->customer->phone }}</dd>
        <dt>Equipamento</dt><dd>{{ $order->device_category }} {{ $order->device_brand }} {{ $order->device_model }}@if ($order->device_serial_number) (série {{ $order->device_serial_number }})@endif</dd>
        @if ($order->warranty_until)<dt>Garantia</dt><dd>até {{ $order->warranty_until->timezone('America/Sao_Paulo')->format('d/m/Y') }}</dd>@endif
        <dt>Problema relatado</dt><dd class="pre">{{ $order->reported_problem }}</dd>
        <dt>Condição de entrada</dt><dd class="pre">{{ $order->intake_condition }}</dd>
        <dt>Acessórios</dt><dd class="pre">{{ $order->accessories }}</dd>
    </dl>

    <h2>Diagnóstico</h2>
    @if ($order->diagnosis)
        <div class="pre">{{ $order->diagnosis }}</div>
        @if ($order->technician)<div class="muted">Responsável técnico: {{ $order->technician->name }}</div>@endif
    @else
        <p class="muted">Ainda sem diagnóstico registrado.</p>
    @endif

    <h2>Orçamento atual</h2>
    @if ($quote)
        <div class="muted">Versão {{ $quote->version }} · {{ $quote->status->label() }}@if ($quote->decision) · {{ $quote->decision->decision->label() }} por {{ $quote->decision->customer_name }} ({{ $quote->decision->channel->label() }}) em {{ $quote->decision->decided_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}@endif</div>
        <table>
            <thead><tr><th>Tipo</th><th>Descrição</th><th class="right">Qtd.</th><th class="right">Unitário</th><th class="right">Total</th><th>Reserva</th></tr></thead>
            <tbody>
                @foreach ($quote->items as $item)
                    <tr>
                        <td>{{ $item->kind->label() }}</td>
                        <td>{{ $item->description }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        <td class="right">{{ \App\Support\Money::brl($item->unit_price_cents) }}</td>
                        <td class="right">{{ \App\Support\Money::brl($item->quantity * $item->unit_price_cents) }}</td>
                        <td>{{ $item->reservation ? $item->reservation->status->label() : ($item->kind === \App\Enums\QuoteItemKind::Part ? 'Sem reserva' : '—') }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="4" class="right">Total</td><td class="right">{{ \App\Support\Money::brl($quote->total_cents) }}</td><td></td></tr>
            </tbody>
        </table>
    @else
        <p class="muted">Ainda sem orçamento.</p>
    @endif

    <h2>Execução</h2>
    <dl>
        <dt>Reparo iniciado</dt><dd>{{ $order->started_at ? $order->started_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—' }}</dd>
        <dt>Reparo concluído</dt><dd>{{ $order->ready_at ? $order->ready_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—' }}</dd>
        <dt>Entregue</dt><dd>{{ $order->delivered_at ? $order->delivered_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—' }}</dd>
        @if ($order->work_performed)<dt>Trabalho executado</dt><dd class="pre">{{ $order->work_performed }}</dd>@endif
        @if ($order->cancellation_reason)<dt>Motivo do cancelamento</dt><dd class="pre">{{ $order->cancellation_reason }}</dd>@endif
    </dl>

    <h2>Histórico</h2>
    <table>
        <thead><tr><th>Data</th><th>Evento</th><th>Estado</th><th>Por</th></tr></thead>
        <tbody>
            @foreach ($events as $event)
                <tr>
                    <td>{{ $event->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                    <td>{{ $event->type->label() }}</td>
                    <td>{{ $event->to_status?->label() ?? '—' }}</td>
                    <td>{{ $event->actor->name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
