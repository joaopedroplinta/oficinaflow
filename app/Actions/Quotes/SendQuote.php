<?php

namespace App\Actions\Quotes;

use App\Enums\DeliveryChannel;
use App\Enums\QuoteStatus;
use App\Enums\WorkOrderEventType;
use App\Exceptions\QuoteNotIssued;
use App\Exceptions\QuoteSuperseded;
use App\Mail\QuoteMail;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SendQuote
{
    /**
     * E-mail: envia o PDF ao cliente. WhatsApp: só devolve o link `wa.me` (o atendente clica e envia; o sistema
     * nunca envia sozinho). Nos dois casos fica o evento `quote_sent`.
     *
     * @return array{channel: DeliveryChannel, recipient: string, url?: string}
     */
    public function handle(Quote $quote, DeliveryChannel $channel, User $actor): array
    {
        return DB::transaction(function () use ($quote, $channel, $actor) {
            $order = WorkOrder::whereKey($quote->work_order_id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($quote->id)->firstOrFail();

            if ($quote->status === QuoteStatus::Superseded || $order->current_quote_id !== $quote->id) {
                throw new QuoteSuperseded;
            }
            if ($quote->status !== QuoteStatus::Issued) {
                throw new QuoteNotIssued;
            }

            $customer = $order->customer;
            $recipient = $channel === DeliveryChannel::Email ? $this->email($customer->email) : $this->whatsappNumber($customer->phone);

            // Evento antes do envio: se o e-mail falhar, a transação desfaz o evento e nada fica registrado como enviado.
            // ponytail: envio síncrono com o lock da ordem preso; com fila (`Mail::queue`), enviar após o commit.
            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::QuoteSent,
                'metadata' => ['quote_id' => $quote->id, 'version' => $quote->version, 'channel' => $channel->value, 'channel_label' => $channel->label(), 'recipient' => $recipient],
            ]);

            if ($channel === DeliveryChannel::Email) {
                Mail::to($recipient)->send(new QuoteMail($quote->setRelation('workOrder', $order->setRelation('customer', $customer))));

                return ['channel' => $channel, 'recipient' => $recipient];
            }

            $text = "Olá, {$customer->name}! Aqui é da ".config('app.name').". O orçamento da sua ordem de serviço nº {$order->number} ({$order->device_brand} {$order->device_model}) ficou em ".Money::brl($quote->total_cents).'. Posso seguir com o reparo?';

            return ['channel' => $channel, 'recipient' => $recipient, 'url' => "https://wa.me/{$recipient}?text=".rawurlencode($text)];
        });
    }

    private function email(?string $email): string
    {
        if ($email === null || trim($email) === '') {
            throw ValidationException::withMessages(['channel' => 'Este cliente não tem e-mail cadastrado. Cadastre o e-mail no cliente ou use o WhatsApp.']);
        }

        return trim($email);
    }

    /** Telefone brasileiro só com dígitos e DDI 55 (formato do `wa.me`). */
    private function whatsappNumber(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }
        if (! preg_match('/^55\d{10,11}$/', $digits)) {
            throw ValidationException::withMessages(['channel' => 'O telefone deste cliente não é válido para o WhatsApp. Corrija o telefone no cadastro (DDD + número).']);
        }

        return $digits;
    }
}
