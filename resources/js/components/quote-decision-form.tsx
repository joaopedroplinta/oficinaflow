import { useState, type FormEvent } from 'react';
import { api } from '../api';
import { useAction } from '../hooks/use-action';
import type { Quote } from '../types/quote';
import { Field, TextArea } from './field';

const channels = [
    { value: 'phone', label: 'Telefone' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'in_person', label: 'Presencial' },
    { value: 'email', label: 'E-mail' },
    { value: 'other', label: 'Outro' },
];

type Draft = { decision: string; customer_name: string; channel: string; decided_at: string; notes: string };
const empty: Draft = { decision: '', customer_name: '', channel: '', decided_at: '', notes: '' };

export function QuoteDecisionForm({ quote, onSaved, onStale }: { quote: Quote; onSaved: () => void; onStale: (message: string) => void }) {
    const action = useAction(onStale);
    // `values` sobrevive ao painel de confirmação: “Voltar” não pode apagar o que foi digitado.
    const [values, setValues] = useState<Draft>(empty);
    const [pending, setPending] = useState<Draft | null>(null);

    function review(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const draft = {
            decision: String(f.get('decision')),
            customer_name: String(f.get('customer_name')).trim(),
            channel: String(f.get('channel')),
            decided_at: String(f.get('decided_at')),
            notes: String(f.get('notes')).trim(),
        };
        setValues(draft);
        setPending(draft);
    }

    const confirm = () =>
        action.run(async () => {
            const d = pending!;
            await api('POST', `/quotes/${quote.id}/decision`, {
                decision: d.decision,
                customer_name: d.customer_name,
                channel: d.channel,
                // datetime-local vem sem fuso: é o horário de Brasília (UTC−3; sem horário de verão desde 2019), não o do navegador.
                decided_at: d.decided_at ? `${d.decided_at.length === 16 ? `${d.decided_at}:00` : d.decided_at}-03:00` : null,
                notes: d.notes || null,
            });
            setPending(null);
            setValues(empty);
            onSaved();
        });

    if (pending)
        return (
            <div role="group" aria-label="Confirmar decisão" className="flex max-w-xl flex-col gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                <p>
                    Registrar que <strong>{pending.customer_name}</strong> <strong>{pending.decision === 'approved' ? 'aprovou' : 'rejeitou'}</strong> a versão {quote.version} (
                    {channels.find((c) => c.value === pending.channel)?.label}).
                </p>
                <p>
                    {pending.decision === 'approved'
                        ? 'A ordem passa para “Aprovada”. Se o orçamento for revisado depois, esta aprovação continua no histórico, mas não vale para a nova versão.'
                        : 'A ordem volta para “Em diagnóstico”, para revisar o orçamento ou cancelar o serviço. A decisão não pode ser desfeita.'}
                </p>
                {action.error && (
                    <p role="alert" className="text-red-700">
                        {action.error}
                    </p>
                )}
                <div className="flex gap-3">
                    <button disabled={action.busy} onClick={() => void confirm()} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                        {action.busy ? 'Registrando…' : 'Confirmar decisão'}
                    </button>
                    <button disabled={action.busy} onClick={() => setPending(null)} className="rounded border border-slate-300 bg-white px-4 py-2 disabled:opacity-50">
                        Voltar
                    </button>
                </div>
            </div>
        );

    return (
        <form onSubmit={review} className="grid max-w-xl gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
                <label htmlFor="decision" className="text-sm font-medium">
                    Decisão do cliente
                </label>
                <select id="decision" name="decision" required defaultValue={values.decision} aria-invalid={!!action.errors.decision} aria-describedby={action.errors.decision ? 'decision-error' : undefined} className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900">
                    <option value="" disabled>
                        Selecione…
                    </option>
                    <option value="approved">Aprovou o orçamento</option>
                    <option value="rejected">Rejeitou o orçamento</option>
                </select>
                {action.errors.decision && (
                    <p id="decision-error" className="text-sm text-red-700">
                        {action.errors.decision[0]}
                    </p>
                )}
            </div>
            <Field label="Nome de quem decidiu" name="customer_name" required defaultValue={values.customer_name} error={action.errors.customer_name?.[0]} />
            <div className="flex flex-col gap-1">
                <label htmlFor="channel" className="text-sm font-medium">
                    Canal
                </label>
                <select id="channel" name="channel" required defaultValue={values.channel} aria-invalid={!!action.errors.channel} aria-describedby={action.errors.channel ? 'channel-error' : undefined} className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900">
                    <option value="" disabled>
                        Selecione…
                    </option>
                    {channels.map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label}
                        </option>
                    ))}
                </select>
                {action.errors.channel && (
                    <p id="channel-error" className="text-sm text-red-700">
                        {action.errors.channel[0]}
                    </p>
                )}
            </div>
            <Field label="Data e hora, horário de Brasília (opcional, padrão: agora)" name="decided_at" type="datetime-local" defaultValue={values.decided_at} error={action.errors.decided_at?.[0]} />
            <div className="sm:col-span-2">
                <TextArea label="Observações (opcional)" name="notes" rows={2} defaultValue={values.notes} error={action.errors.notes?.[0]} />
            </div>
            {action.error && (
                <p role="alert" className="text-sm text-red-700 sm:col-span-2">
                    {action.error}
                </p>
            )}
            <button className="self-start rounded bg-slate-900 px-4 py-2 text-white sm:col-span-2">Revisar decisão</button>
        </form>
    );
}
