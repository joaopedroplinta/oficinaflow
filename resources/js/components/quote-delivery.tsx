import { useState } from 'react';
import { api } from '../api';
import { useAction } from '../hooks/use-action';
import type { Data } from '../types/api';
import type { Quote } from '../types/quote';
import type { WorkOrderDetail } from '../types/work-order';
import { ConfirmAction } from './confirm-action';

type Delivery = { channel: 'email' | 'whatsapp'; channel_label: string; recipient: string; url: string | null };

type Props = { order: WorkOrderDetail; quote: Quote; onSent: () => void; onStale: (message: string) => void };

// Envio ao cliente: e-mail com o PDF (o sistema envia) ou link do WhatsApp (o atendente clica e envia).
export function QuoteDelivery({ order, quote, onSent, onStale }: Props) {
    const email = useAction(onStale);
    const whatsapp = useAction(onStale);
    const [sent, setSent] = useState('');
    const [link, setLink] = useState('');
    const address = order.customer.email;

    const deliver = async (channel: Delivery['channel']) => {
        const res = await api<Data<Delivery>>('POST', `/quotes/${quote.id}/deliveries`, { channel });
        onSent();
        return res.data;
    };

    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">Enviar ao cliente</h3>
            <div className="flex flex-wrap items-start gap-3">
                <ConfirmAction
                    label="Enviar por e-mail"
                    confirmLabel="Enviar e-mail"
                    busy={email.busy}
                    disabled={!address}
                    disabledReason="email-missing"
                    effect={
                        <>
                            <p>
                                <strong>Envia agora o PDF da versão {quote.version} para {address}.</strong> O envio fica registrado no histórico da ordem.
                            </p>
                            {email.error && (
                                <p role="alert" className="mt-2 text-red-700">
                                    {email.error}
                                </p>
                            )}
                        </>
                    }
                    onConfirm={() =>
                        email.run(async () => {
                            const d = await deliver('email');
                            setLink('');
                            setSent(`E-mail enviado para ${d.recipient}.`);
                        })
                    }
                />
                <button
                    type="button"
                    disabled={whatsapp.busy}
                    onClick={() =>
                        void whatsapp.run(async () => {
                            const d = await deliver('whatsapp');
                            setSent('');
                            setLink(d.url ?? '');
                        })
                    }
                    className="rounded border border-slate-300 px-4 py-2 disabled:opacity-50"
                >
                    {whatsapp.busy ? 'Preparando…' : 'Preparar mensagem no WhatsApp'}
                </button>
            </div>

            {!address && (
                <p id="email-missing" className="text-sm text-slate-500">
                    Este cliente não tem e-mail cadastrado; cadastre o e-mail no cliente para enviar por e-mail.
                </p>
            )}
            {whatsapp.error && (
                <p role="alert" className="text-sm text-red-700">
                    {whatsapp.error}
                </p>
            )}
            {sent && (
                <p role="status" className="rounded border border-green-300 bg-green-50 p-3 text-sm">
                    <span aria-hidden="true">✓ </span>
                    {sent}
                </p>
            )}
            {link && (
                <p role="status" className="rounded border border-green-300 bg-green-50 p-3 text-sm">
                    <span aria-hidden="true">✓ </span>
                    Mensagem registrada no histórico.{' '}
                    <a href={link} target="_blank" rel="noopener noreferrer" className="underline">
                        Abrir a conversa no WhatsApp
                    </a>{' '}
                    e enviar. Lembre de anexar o PDF do orçamento (botão “Baixar orçamento” acima): o sistema não envia o arquivo pelo WhatsApp.
                </p>
            )}
        </div>
    );
}
