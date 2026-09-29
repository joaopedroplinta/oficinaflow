import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { ConfirmAction } from '../components/confirm-action';
import { Loading } from '../components/loading';
import { SavedNotice } from '../components/saved-notice';
import { centsToReais, formatBRL, formatDateTime, toCents } from '../format';
import { useAction } from '../hooks/use-action';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { PurchaseOrder } from '../types/purchase-order';

const input = 'w-full min-w-0 rounded border border-slate-300 bg-white px-2 py-1 focus-visible:outline-2 focus-visible:outline-slate-900';

export default function PurchaseOrderDetail() {
    const { id } = useParams();
    const { isAdmin } = useAuth();
    const order = useLoad<Data<PurchaseOrder>>(`/purchase-orders/${id}`);
    const receive = useSubmit();
    const cancel = useAction(() => order.reload());
    const [saved, setSaved] = useState('');
    const [reason, setReason] = useState('');
    const [localError, setLocalError] = useState('');
    // Alterar a quantidade/custo de uma linha não pode apagar o que foi digitado nas outras: o formulário remonta a cada recarga.
    const [version, setVersion] = useState(0);

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento gerencia compras.</p>;
    if (order.loading && !order.data) return <Loading title="Pedido de compra" />;
    if (!order.data)
        return (
            <p role="alert" className="text-red-700">
                <span className="sr-only">Pedido de compra: </span>
                {order.error}{' '}
                <Link to="/compras" className="underline">
                    Voltar para os pedidos
                </Link>
            </p>
        );
    const o = order.data.data;
    const items = o.items ?? [];
    const open = o.status === 'open' || o.status === 'partially_received';
    const refresh = () => {
        setVersion((v) => v + 1);
        order.reload();
    };

    async function submitReceipt(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setSaved('');
        const f = new FormData(e.currentTarget);
        const lines: { purchase_order_item_id: number; quantity: number; unit_cost_cents: number }[] = [];
        for (const i of items) {
            const qty = Number(f.get(`qty-${i.id}`));
            if (!qty) continue;
            const cost = toCents(String(f.get(`cost-${i.id}`)));
            if (Number.isNaN(cost)) return setLocalError('Informe o custo em reais com até duas casas, por exemplo 12,50.');
            lines.push({ purchase_order_item_id: i.id, quantity: qty, unit_cost_cents: cost });
        }
        if (lines.length === 0) return setLocalError('Informe a quantidade recebida de ao menos um item.');
        setLocalError('');
        const ok = await receive.run(async () => {
            await api('POST', `/purchase-orders/${o.id}/receipts`, { items: lines });
        });
        if (ok) {
            setSaved('Recebimento registrado: o estoque e o custo médio foram atualizados.');
            refresh();
        } else {
            // Quantidade acima do que falta (tela velha): recarrega os saldos do pedido.
            order.reload();
        }
    }

    return (
        <div className="flex flex-col gap-6">
            {saved && <SavedNotice message={saved} />}
            <div>
                <Link to="/compras" className="back-link">
                    Pedidos de compra
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Pedido de compra #{o.number}</h1>
                <p className="text-sm text-slate-600">
                    {o.status_label} · {o.supplier?.name} · criado em {formatDateTime(o.created_at)} por {o.created_by?.name} · total {formatBRL(o.total_cents)}
                </p>
                {o.notes && <p className="mt-1 whitespace-pre-line text-sm text-slate-600">{o.notes}</p>}
                {o.status === 'canceled' && (
                    <p className="mt-1 text-sm">
                        <strong>Cancelado</strong> em {o.canceled_at ? formatDateTime(o.canceled_at) : ''}: {o.cancellation_reason}. O que já foi recebido continua no estoque.
                    </p>
                )}
            </div>

            <section aria-labelledby="itens" className="flex flex-col gap-3">
                <h2 id="itens" className="font-semibold">
                    {open ? 'Receber itens' : 'Itens'}
                </h2>
                <form key={version} onSubmit={submitReceipt} className="flex flex-col gap-3">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Peça</th>
                                    <th className="py-2 pr-4 text-right font-medium">Pedido</th>
                                    <th className="py-2 pr-4 text-right font-medium">Recebido</th>
                                    <th className="py-2 pr-4 text-right font-medium">Falta</th>
                                    <th className="py-2 pr-4 text-right font-medium">Custo combinado</th>
                                    {open && <th className="py-2 pr-4 font-medium">Receber agora</th>}
                                    {open && <th className="py-2 font-medium">Custo efetivo (R$)</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((i) => (
                                    <tr key={i.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">
                                            <Link to={`/estoque/${i.part.id}`} className="underline">
                                                {i.part.name}
                                            </Link>{' '}
                                            <span className="text-slate-500">({i.part.sku})</span>
                                        </td>
                                        <td className="py-2 pr-4 text-right">{i.quantity}</td>
                                        <td className="py-2 pr-4 text-right">{i.received_quantity}</td>
                                        <td className="py-2 pr-4 text-right">{i.remaining_quantity}</td>
                                        <td className="py-2 pr-4 text-right">{formatBRL(i.unit_cost_cents)}</td>
                                        {open && (
                                            <td className="py-2 pr-4">
                                                <input aria-label={`Quantidade recebida de ${i.part.name}`} name={`qty-${i.id}`} type="number" min={0} max={i.remaining_quantity} defaultValue={0} disabled={i.remaining_quantity === 0} className={`${input} w-24`} />
                                                {receive.errors[`items.${items.indexOf(i)}.quantity`] && <p className="text-sm text-red-700">{receive.errors[`items.${items.indexOf(i)}.quantity`][0]}</p>}
                                            </td>
                                        )}
                                        {open && (
                                            <td className="py-2">
                                                <input aria-label={`Custo efetivo de ${i.part.name}`} name={`cost-${i.id}`} inputMode="decimal" defaultValue={centsToReais(i.unit_cost_cents)} disabled={i.remaining_quantity === 0} className={`${input} w-28`} />
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {(localError || receive.formError) && (
                        <p role="alert" className="text-sm text-red-700">
                            {localError || receive.formError}
                        </p>
                    )}
                    {open && (
                        <div className="flex flex-col gap-1">
                            <div>
                                <button disabled={receive.busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                                    {receive.busy ? 'Registrando…' : 'Registrar recebimento'}
                                </button>
                            </div>
                            <p className="text-sm text-slate-500">Cada linha vira uma entrada no estoque com o fornecedor do pedido e o custo efetivo, e atualiza o custo médio da peça. Não dá para desfazer.</p>
                        </div>
                    )}
                </form>
            </section>

            {open && (
                <section aria-labelledby="cancelar" className="flex max-w-xl flex-col gap-2 border-t border-slate-200 pt-4">
                    <h2 id="cancelar" className="font-semibold">
                        Cancelar pedido
                    </h2>
                    <label htmlFor="cancel-reason" className="text-sm font-medium">
                        Motivo do cancelamento
                    </label>
                    <textarea id="cancel-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} aria-invalid={!!cancel.errors.reason} className={input} />
                    {cancel.errors.reason && <p className="text-sm text-red-700">{cancel.errors.reason[0]}</p>}
                    <div>
                        <ConfirmAction
                            label="Cancelar pedido"
                            confirmLabel="Cancelar o pedido"
                            busy={cancel.busy}
                            onOpen={cancel.reset}
                            effect={
                                <>
                                    <p>
                                        <strong>Cancela o que ainda não foi recebido.</strong> O que já entrou continua no estoque e no histórico. O pedido não recebe mais entregas.
                                    </p>
                                    {cancel.error && (
                                        <p role="alert" className="mt-2 text-red-700">
                                            {cancel.error}
                                        </p>
                                    )}
                                </>
                            }
                            onConfirm={() =>
                                cancel.run(async () => {
                                    await api('POST', `/purchase-orders/${o.id}/cancel`, { reason });
                                    refresh();
                                })
                            }
                        />
                    </div>
                </section>
            )}
        </div>
    );
}
