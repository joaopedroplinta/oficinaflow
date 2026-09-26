import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { formatBRL, formatDateTime } from '../format';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import { purchaseStatusOptions, type PurchaseOrder } from '../types/purchase-order';

export default function PurchaseOrders() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const status = params.get('status') ?? '';
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const { data, loading, error, reload } = useLoad<Page<PurchaseOrder>>(`/purchase-orders?page=${page}&status=${status}`);

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento gerencia compras.</p>;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Pedidos de compra</h1>
                <Link to="/compras/nova" className="rounded bg-slate-900 px-4 py-2 text-white">
                    Novo pedido
                </Link>
            </div>

            <div className="flex max-w-xs flex-col gap-1">
                <label htmlFor="f-status" className="text-sm font-medium">
                    Estado
                </label>
                <select id="f-status" value={status} onChange={(e) => setParams(e.target.value ? { status: e.target.value } : {})} className="rounded border border-slate-300 bg-white px-3 py-2">
                    <option value="">Todos</option>
                    {purchaseStatusOptions.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            </div>

            {loading && (
                <p role="status" className="text-slate-500">
                    {data ? 'Atualizando…' : 'Carregando…'}
                </p>
            )}
            {error && (
                <p role="alert" className="text-red-700">
                    {error}{' '}
                    <button onClick={reload} className="underline">
                        Tentar de novo
                    </button>
                </p>
            )}
            {data && data.data.length === 0 && (
                <p className="text-slate-500">
                    {status ? 'Nenhum pedido nesse estado.' : 'Nenhum pedido de compra ainda.'}{' '}
                    {!status && (
                        <Link to="/compras/nova" className="underline">
                            Criar o primeiro
                        </Link>
                    )}
                </p>
            )}

            {data && data.data.length > 0 && (
                <>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Nº</th>
                                    <th className="py-2 pr-4 font-medium">Fornecedor</th>
                                    <th className="py-2 pr-4 font-medium">Estado</th>
                                    <th className="py-2 pr-4 text-right font-medium">Total</th>
                                    <th className="py-2 font-medium">Criado em</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((o) => (
                                    <tr key={o.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">
                                            <Link to={`/compras/${o.id}`} className="font-medium underline">
                                                #{o.number}
                                            </Link>
                                        </td>
                                        <td className="py-2 pr-4">{o.supplier?.name}</td>
                                        <td className="py-2 pr-4">{o.status_label}</td>
                                        <td className="py-2 pr-4 text-right">{formatBRL(o.total_cents)}</td>
                                        <td className="py-2">{formatDateTime(o.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Paginação" className="flex items-center gap-4 text-sm">
                        <button disabled={page <= 1} onClick={() => setParams({ status, page: String(page - 1) })} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Anterior
                        </button>
                        <span>
                            Página {data.meta.current_page} de {data.meta.last_page}
                        </span>
                        <button disabled={page >= data.meta.last_page} onClick={() => setParams({ status, page: String(page + 1) })} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Próxima
                        </button>
                    </nav>
                </>
            )}
        </div>
    );
}
