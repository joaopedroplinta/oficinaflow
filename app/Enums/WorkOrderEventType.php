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
        };
    }
}
