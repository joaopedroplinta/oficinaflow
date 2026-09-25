<!DOCTYPE html>
<html lang="pt-BR">
<body style="font-family: sans-serif; color: #0f172a;">
    <p>Olá, {{ $order->customer->name }}!</p>
    <p>
        Segue em anexo {{ $quote->isAddendum() ? 'o adendo ao orçamento' : 'o orçamento' }} da ordem de serviço nº {{ $order->number }}
        ({{ $order->device_brand }} {{ $order->device_model }}):
        <strong>{{ \App\Support\Money::brl($quote->total_cents) }}</strong>.
    </p>
    <p>{{ $quote->isAddendum() ? 'Os itens do adendo só entram no serviço depois da sua aprovação.' : 'O reparo só começa depois da sua aprovação.' }} Se tiver dúvida, é só responder esta mensagem ou falar com a gente.</p>
    <p>{{ config('app.name') }}</p>
</body>
</html>
