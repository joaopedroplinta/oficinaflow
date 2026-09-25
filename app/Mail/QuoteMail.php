<?php

namespace App\Mail;

use App\Models\Quote;
use App\Support\Documents;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Orçamento para o cliente: resumo no corpo e o PDF da versão em anexo. */
class QuoteMail extends Mailable
{
    public function __construct(public Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->quote->isAddendum() ? 'Adendo ao orçamento' : 'Orçamento')." da ordem de serviço nº {$this->quote->workOrder->number}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.quote', with: ['quote' => $this->quote, 'order' => $this->quote->workOrder]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $name = $this->quote->isAddendum()
            ? "adendo-os-{$this->quote->workOrder->number}-{$this->quote->version}.pdf"
            : "orcamento-os-{$this->quote->workOrder->number}-v{$this->quote->version}.pdf";

        return [Attachment::fromData(fn () => Documents::pdf(Documents::quote($this->quote))->output(), $name)->withMime('application/pdf')];
    }
}
