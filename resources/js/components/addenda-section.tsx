import { api } from '../api';
import { formatBRL, formatDateTime } from '../format';
import { useAction } from '../hooks/use-action';
import type { Quote } from '../types/quote';
import type { WorkOrderDetail } from '../types/work-order';
import { PdfLink } from './pdf-link';
import { QuoteDecisionForm } from './quote-decision-form';
import { QuoteDelivery } from './quote-delivery';
import { QuoteDraftEditor } from './quote-draft-editor';
import { QuoteDecisionSummary, QuoteItemsTable } from './quote-items-table';

type Props = { refreshing?: boolean; order: WorkOrderDetail; addenda: Quote[]; isAdmin: boolean; onChanged: () => void; onStale: (message: string) => void };

// Adendo: itens descobertos durante o reparo. A versão aprovada não muda; o adendo tem itens, emissão e decisão próprios.
export function AddendaSection({ order, addenda, isAdmin, onChanged, onStale, refreshing = false }: Props) {
    const create = useAction(onStale);
    const inProgress = order.status === 'in_progress';
    const open = addenda.some((a) => a.status === 'draft' || (a.status === 'issued' && !a.decision));
    if (!inProgress && addenda.length === 0) return null;

    return (
        <div className="flex flex-col gap-3">
            <h3 className="text-sm font-medium">Adendos ao orçamento</h3>

            {addenda.length === 0 && (
                <p className="text-sm text-slate-500">
                    Nenhum adendo. Se aparecer um item novo durante o reparo, o atendimento cria um adendo: a versão aprovada não muda.
                </p>
            )}

            {addenda.map((a) => (
                <div key={a.id} className="flex flex-col gap-2 rounded border border-slate-200 p-3">
                    <p className="meta-row">
                        <strong>Adendo {a.version}</strong>
                        <span>{a.decision ? a.decision.decision_label : a.status_label}</span>
                        <span className="font-semibold">{formatBRL(a.total_cents)}</span>
                        {a.issued_at && <span className="text-slate-500">emitido em {formatDateTime(a.issued_at)}</span>}
                    </p>

                    {isAdmin && inProgress && a.status === 'draft' ? (
                        <QuoteDraftEditor
                            key={`${a.id}-${a.items.map((i) => i.id).join(',')}`}
                            quote={a}
                            refreshing={refreshing}
                            onSaved={onChanged}
                            onStale={onStale}
                        />
                    ) : (
                        <>
                            <QuoteItemsTable quote={a} />
                            {a.notes && <p className="whitespace-pre-line text-sm text-slate-600">{a.notes}</p>}
                            {a.status === 'draft' && <p className="text-sm text-slate-500">Rascunho em elaboração pelo atendimento.</p>}
                        </>
                    )}

                    <QuoteDecisionSummary quote={a} />
                    {a.status === 'issued' && <PdfLink href={`/api/v1/quotes/${a.id}/pdf`}>{`Baixar adendo ${a.version} em PDF`}</PdfLink>}

                    {isAdmin && inProgress && a.status === 'issued' && !a.decision && (
                        <>
                            <QuoteDelivery key={a.id} order={order} quote={a} onSent={onChanged} onStale={onStale} />
                            <div className="flex flex-col gap-2">
                                <h4 className="text-sm font-medium">Registrar a decisão do cliente</h4>
                                <QuoteDecisionForm quote={a} onSaved={onChanged} onStale={onStale} />
                            </div>
                        </>
                    )}
                </div>
            ))}

            {isAdmin && inProgress && !open && (
                <div>
                    <button
                        disabled={create.busy}
                        onClick={() =>
                            void create.run(async () => {
                                await api('POST', `/work-orders/${order.id}/addenda`);
                                onChanged();
                            })
                        }
                        className="rounded border border-slate-300 px-4 py-2 disabled:opacity-50"
                    >
                        {create.busy ? 'Criando…' : 'Criar adendo'}
                    </button>
                    {create.error && (
                        <p role="alert" className="mt-2 text-sm text-red-700">
                            {create.error}
                        </p>
                    )}
                </div>
            )}
            {isAdmin && inProgress && open && (
                <p className="text-sm text-slate-500">Há um adendo em aberto: emita e registre a decisão do cliente antes de criar outro.</p>
            )}

            {addenda.some((a) => a.decision?.decision === 'approved') && (
                <p className="text-sm">
                    Total da ordem (versão atual + adendos aprovados): <strong>{formatBRL(order.total_cents)}</strong>
                </p>
            )}
        </div>
    );
}
