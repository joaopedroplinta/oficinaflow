import { useState } from 'react';
import { Link } from 'react-router';
import { api } from '../api';
import { formatDateTime } from '../format';
import { useAction } from '../hooks/use-action';
import type { WorkOrderDetail } from '../types/work-order';
import { ConfirmAction } from './confirm-action';

type Shortage = { part_id: number; sku: string; name: string; needed: number; available: number };

type Props = { order: WorkOrderDetail; canReserve: boolean; onChanged: () => void; onStale: (message: string) => void };

const isShortages = (d: unknown): d is { shortages: Shortage[] } => typeof d === 'object' && d !== null && Array.isArray((d as { shortages?: unknown }).shortages);

export function PartsSection({ order, canReserve, onChanged, onStale }: Props) {
    // Falta de estoque não é tela velha: recarrega os números, mas mantém a explicação ao lado do botão.
    const reserve = useAction((message, code) => (code === 'INSUFFICIENT_STOCK' ? onChanged() : onStale(message)));
    const [partial, setPartial] = useState(false);
    const approved = order.status === 'approved';
    const quote = order.current_quote;
    const usesParts = !!quote?.items.some((i) => i.kind === 'part');
    const shortages = isShortages(reserve.details) ? reserve.details.shortages : [];

    return (
        <div className="flex flex-col gap-3">
            {order.reservations.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-slate-500">
                            <tr>
                                <th className="py-2 pr-4 font-medium">Peça</th>
                                <th className="py-2 pr-4 text-right font-medium">Qtd.</th>
                                <th className="py-2 pr-4 font-medium">Situação</th>
                                <th className="py-2 font-medium">Reservada em</th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.reservations.map((r) => (
                                <tr key={r.id} className="border-b border-slate-100">
                                    <td className="py-2 pr-4">
                                        {r.part.name} <span className="text-slate-500">({r.part.sku})</span>
                                    </td>
                                    <td className="py-2 pr-4 text-right">{r.quantity}</td>
                                    <td className="py-2 pr-4">{r.status_label}</td>
                                    <td className="py-2">{formatDateTime(r.reserved_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {approved && order.pending.length > 0 && (
                <div role="status" className="flex max-w-xl flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                    <p>
                        <span aria-hidden="true">⏳ </span>
                        <strong>Aprovada, aguardando peças.</strong> {order.pending.length === 1 ? '1 peça ainda não foi totalmente reservada:' : `${order.pending.length} peças ainda não foram totalmente reservadas:`}
                    </p>
                    <ul className="list-disc pl-5">
                        {order.pending.map((p) => (
                            <li key={p.part_id}>
                                {p.name} ({p.sku}): {p.reserved > 0 ? `${p.reserved} de ${p.quantity} reservadas, faltam ${p.needed}` : `precisa de ${p.needed}`}, disponível {p.available}
                                {p.available < p.needed ? ` — faltam ${p.needed - p.available} em estoque` : ' — há estoque, falta reservar'}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {approved && order.pending.length === 0 && usesParts && (
                <p className="text-sm">
                    <span aria-hidden="true">✓ </span>Todas as peças estão reservadas.
                </p>
            )}
            {approved && !usesParts && quote && <p className="text-sm text-slate-500">Este orçamento não usa peças.</p>}
            {!approved && !['received', 'diagnosing', 'awaiting_approval'].includes(order.status) && order.reservations.length === 0 && <p className="text-sm text-slate-500">Nenhuma peça reservada.</p>}
            {['received', 'diagnosing', 'awaiting_approval'].includes(order.status) && order.reservations.length === 0 && (
                <p className="text-sm text-slate-500">Nenhuma peça reservada. As peças são reservadas depois da aprovação do orçamento.</p>
            )}

            {canReserve && approved && quote && order.pending.length > 0 && (
                <ConfirmAction
                    label={order.reservations.length > 0 ? 'Reservar o que falta' : 'Reservar peças'}
                    confirmLabel="Reservar agora"
                    busy={reserve.busy}
                    tone="primary"
                    onOpen={() => {
                        reserve.reset();
                        setPartial(false);
                    }}
                    effect={
                        <>
                            <p>
                                {partial ? (
                                    <>
                                        <strong>Reserva o que houver disponível de cada peça da versão {quote.version}</strong> e deixa o resto pendente (só falha se não houver nada para reservar).
                                    </>
                                ) : (
                                    <>
                                        <strong>Reserva todas as peças da versão {quote.version} ou nenhuma:</strong> se faltar alguma, nada é reservado.
                                    </>
                                )}{' '}
                                Reservar não muda o estado da ordem nem baixa o estoque físico, e o reparo só pode começar com tudo reservado.
                            </p>
                            <label className="mt-2 flex items-center gap-2">
                                <input type="checkbox" checked={partial} onChange={(e) => setPartial(e.target.checked)} />
                                Reservar só o que houver em estoque (reserva parcial)
                            </label>
                            {reserve.error && (
                                <div role="alert" className="mt-2 text-red-700">
                                    <p>{reserve.error}</p>
                                    {shortages.length > 0 && (
                                        <ul className="mt-1 list-disc pl-5">
                                            {shortages.map((s) => (
                                                <li key={s.part_id}>
                                                    <Link to={`/estoque/${s.part_id}`} className="underline">
                                                        {s.name} ({s.sku})
                                                    </Link>
                                                    : precisa de {s.needed}, disponível {s.available}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </>
                    }
                    onConfirm={() =>
                        reserve.run(async () => {
                            await api('POST', `/work-orders/${order.id}/reservations`, { quote_id: quote.id, partial });
                            onChanged();
                        })
                    }
                />
            )}
        </div>
    );
}
