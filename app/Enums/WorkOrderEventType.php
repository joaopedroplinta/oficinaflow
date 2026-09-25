<?php

namespace App\Enums;

enum WorkOrderEventType: string
{
    case Opened = 'opened';
    case DiagnosisStarted = 'diagnosis_started';
    case DiagnosisRecorded = 'diagnosis_recorded';
    case QuoteIssued = 'quote_issued';
    case QuoteApproved = 'quote_approved';
    case QuoteRejected = 'quote_rejected';
    case QuoteRevised = 'quote_revised';
    case PartsReserved = 'parts_reserved';
    case RepairStarted = 'repair_started';
    case RepairCompleted = 'repair_completed';
    case Delivered = 'delivered';
    case Canceled = 'canceled';
    case ReturnedWithoutRepair = 'returned_without_repair';
    case AttachmentAdded = 'attachment_added';
    case QuoteSent = 'quote_sent';
    case ReturnOpened = 'return_opened';
    case PartsConsumed = 'parts_consumed';
    case AddendumIssued = 'addendum_issued';
    case AddendumApproved = 'addendum_approved';
    case AddendumRejected = 'addendum_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Ordem aberta',
            self::DiagnosisStarted => 'Diagnóstico iniciado',
            self::DiagnosisRecorded => 'Diagnóstico registrado',
            self::QuoteIssued => 'Orçamento emitido',
            self::QuoteApproved => 'Orçamento aprovado',
            self::QuoteRejected => 'Orçamento rejeitado',
            self::QuoteRevised => 'Orçamento revisado',
            self::PartsReserved => 'Peças reservadas',
            self::RepairStarted => 'Reparo iniciado',
            self::RepairCompleted => 'Reparo concluído',
            self::Delivered => 'Equipamento entregue',
            self::Canceled => 'Serviço cancelado',
            self::ReturnedWithoutRepair => 'Equipamento devolvido sem reparo',
            self::AttachmentAdded => 'Anexo adicionado',
            self::QuoteSent => 'Orçamento enviado',
            self::ReturnOpened => 'Retorno aberto (garantia ou retrabalho)',
            self::PartsConsumed => 'Peças consumidas',
            self::AddendumIssued => 'Adendo emitido',
            self::AddendumApproved => 'Adendo aprovado',
            self::AddendumRejected => 'Adendo rejeitado',
        };
    }
}
