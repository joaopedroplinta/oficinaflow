import { api } from '../api';
import { useAuth } from '../auth';
import { formatBRL, formatDateTime } from '../format';
import { useAction } from '../hooks/use-action';
import { useLoad } from '../hooks/use-load';
import type { Data } from '../types/api';
import type { Quote } from '../types/quote';
import type { WorkOrderDetail } from '../types/work-order';
import { AddendaSection } from './addenda-section';
import { ConfirmAction } from './confirm-action';
import { Loading } from './loading';
import { PdfLink } from './pdf-link';
import { QuoteDecisionForm } from './quote-decision-form';
import { QuoteDelivery } from './quote-delivery';
import { QuoteDraftEditor } from './quote-draft-editor';
import { QuoteDecisionSummary, QuoteItemsTable } from './quote-items-table';

type Props = { order: WorkOrderDetail; onChanged: () => void; onStale: (message: string) => void };

// Estados em que ainda dá para revisar o orçamento (antes do reparo).
const REVISABLE = ['diagnosing', 'awaiting_approval', 'approved'];

export function QuoteSection({ order, onChanged, onStale }: Props) {
    const { isAdmin } = useAuth();
    const versions = useLoad<Data<Quote[]>>(`/work-orders/${order.id}/quotes`);
    const editingDraft = isAdmin && order.current_quote?.status === 'draft' && order.status === 'diagnosing';
    const refresh = () => {
        onChanged();
        versions.reload();
    };
    const stale = (message: string) => {
        onStale(message);
        versions.reload();
    };
    const create = useAction(stale);
    const revise = useAction(stale);
    const current = order.current_quote;
    const history = versions.data?.data.filter((q) => q.kind === 'version' && q.id !== current?.id) ?? [];
    const addenda = (versions.data?.data.filter((q) => q.kind === 'addendum') ?? []).sort((a, b) => a.version - b.version);

    return (
        <div className="flex flex-col gap-4">
            {!current && (
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-slate-500">
                        {order.status === 'diagnosing' ? 'Nenhum orçamento ainda.' : 'Nenhum orçamento: o diagnóstico precisa ser iniciado antes.'}
                    </p>
                    {isAdmin && order.status === 'diagnosing' && (
                        <div>
                            <button
                                disabled={create.busy}
                                onClick={() =>
                                    void create.run(async () => {
                                        await api('POST', `/work-orders/${order.id}/quotes`);
                                        refresh();
                                    })
                                }
                                className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                            >
                                {create.busy ? 'Criando…' : 'Criar orçamento'}
                            </button>
                            {create.error && (
                                <p role="alert" className="mt-2 text-sm text-red-700">
                                    {create.error}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {current && (
                <div className="flex flex-col gap-3">
                    <p className="text-sm">
                        <strong>Versão {current.version}</strong> · {current.status_label} · {formatBRL(current.total_cents)}
                        {current.issued_at ? ` · emitida em ${formatDateTime(current.issued_at)}` : ''}
                    </p>

                    {editingDraft ? (
                        <QuoteDraftEditor key={`${current.id}-${current.items.map((i) => i.id).join(',')}`} quote={current} onSaved={refresh} onStale={stale} />
                    ) : (
                        <>
                            <QuoteItemsTable quote={current} />
                            {current.notes && <p className="whitespace-pre-line text-sm text-slate-600">{current.notes}</p>}
                            {current.status === 'draft' && <p className="text-sm text-slate-500">Rascunho em elaboração pelo atendimento.</p>}
                        </>
                    )}

                    <QuoteDecisionSummary quote={current} />

                    <PdfLink href={`/api/v1/quotes/${current.id}/pdf`}>{`Baixar orçamento (versão ${current.version}) em PDF`}</PdfLink>

                    {isAdmin && current.status === 'issued' && order.status === 'awaiting_approval' && !current.decision && <QuoteDelivery key={current.id} order={order} quote={current} onSent={onChanged} onStale={stale} />}

                    {isAdmin && current.status === 'issued' && order.status === 'awaiting_approval' && !current.decision && (
                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-medium">Registrar a decisão do cliente</h3>
                            <QuoteDecisionForm quote={current} onSaved={refresh} onStale={stale} />
                        </div>
                    )}

                    {isAdmin && current.status === 'issued' && REVISABLE.includes(order.status) && (
                        <div className="flex flex-col gap-2">
                            <ConfirmAction
                                label="Revisar orçamento"
                                confirmLabel="Criar revisão"
                                busy={revise.busy}
                                effect={
                                    <>
                                        <p>
                                            <strong>Cria a versão {current.version + 1} em rascunho</strong> com os mesmos itens e preços da versão {current.version} e a substitui. Os preços das peças só são atualizados para o catálogo quando você salvar o novo rascunho.
                                            A ordem volta para “Em diagnóstico”.
                                        </p>
                                        <ul className="mt-2 list-disc pl-5">
                                            <li>As peças já reservadas para a versão atual serão liberadas.</li>
                                            <li>A nova versão precisa ser emitida e aprovada de novo.</li>
                                            <li>{current.decision ? 'A decisão atual continua no histórico, mas não vale para a nova versão.' : 'A versão atual fica no histórico como substituída.'}</li>
                                        </ul>
                                        {revise.error && (
                                            <p role="alert" className="mt-2 text-red-700">
                                                {revise.error}
                                            </p>
                                        )}
                                    </>
                                }
                                onConfirm={() =>
                                    revise.run(async () => {
                                        await api('POST', `/work-orders/${order.id}/quote-revisions`, { quote_id: current.id });
                                        refresh();
                                    })
                                }
                            />
                        </div>
                    )}
                </div>
            )}

            {current && <AddendaSection order={order} addenda={addenda} isAdmin={isAdmin} onChanged={refresh} onStale={stale} />}

            <div className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">Versões anteriores</h3>
                {versions.loading && !versions.data && <Loading small />}
                {versions.error && (
                    <p role="alert" className="text-sm text-red-700">
                        {versions.error}{' '}
                        <button onClick={versions.reload} className="underline">
                            Tentar de novo
                        </button>
                    </p>
                )}
                {versions.data && history.length === 0 && <p className="text-sm text-slate-500">Nenhuma versão anterior.</p>}
                {history.map((q) => (
                    <details key={q.id} className="rounded border border-slate-200 p-3">
                        <summary className="cursor-pointer text-sm">
                            Versão {q.version} · {q.status_label} · {formatBRL(q.total_cents)}
                            {q.decision ? ` · ${q.decision.decision_label.toLowerCase()} por ${q.decision.customer_name}` : ''}
                        </summary>
                        <div className="mt-3 flex flex-col gap-2">
                            <QuoteItemsTable quote={q} />
                            {q.notes && <p className="whitespace-pre-line text-sm text-slate-600">{q.notes}</p>}
                            <QuoteDecisionSummary quote={q} />
                            <PdfLink href={`/api/v1/quotes/${q.id}/pdf`}>{`Baixar versão ${q.version} em PDF`}</PdfLink>
                        </div>
                    </details>
                ))}
            </div>
        </div>
    );
}
