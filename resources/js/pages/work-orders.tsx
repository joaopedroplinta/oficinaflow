import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { formatDateTime } from '../format';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import { statusOptions, type WorkOrder } from '../types/work-order';

const FILTERS = ['status', 'number', 'customer', 'awaiting_parts'] as const;

export default function WorkOrders() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const filtered = FILTERS.some((k) => params.get(k));

    const query = new URLSearchParams({ page: String(page) });
    for (const k of FILTERS) if (params.get(k)) query.set(k, params.get(k)!);
    const { data, loading, error, reload } = useLoad<Page<WorkOrder>>(`/work-orders?${query}`);

    const goToFirst = () => goToPage(1);
    const goToPage = (p: number) => {
        const next = new URLSearchParams(params);
        next.set('page', String(p));
        setParams(next);
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Ordens de serviço</h1>
                {isAdmin && (
                    <Link to="/clientes" className="rounded bg-slate-900 px-4 py-2 text-white">
                        Receber equipamento
                    </Link>
                )}
            </div>

            <form
                role="search"
                key={params.toString()}
                onSubmit={(e) => {
                    e.preventDefault();
                    const f = new FormData(e.currentTarget);
                    const next = new URLSearchParams();
                    for (const k of FILTERS) {
                        const v = String(f.get(k) ?? '').trim();
                        if (v) next.set(k, v);
                    }
                    setParams(next);
                }}
                className="grid gap-3 sm:grid-cols-[1fr_1fr_1fr_1fr_auto] sm:items-end"
            >
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-status" className="text-sm font-medium">
                        Estado
                    </label>
                    <select id="f-status" name="status" defaultValue={params.get('status') ?? ''} className="rounded border border-slate-300 bg-white px-3 py-2">
                        <option value="">Todos</option>
                        {statusOptions.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-number" className="text-sm font-medium">
                        Número
                    </label>
                    <input id="f-number" name="number" type="number" min={1} defaultValue={params.get('number') ?? ''} className="rounded border border-slate-300 bg-white px-3 py-2" />
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-customer" className="text-sm font-medium">
                        Cliente
                    </label>
                    <input id="f-customer" name="customer" type="search" placeholder="Nome do cliente" defaultValue={params.get('customer') ?? ''} className="rounded border border-slate-300 bg-white px-3 py-2" />
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-parts" className="text-sm font-medium">
                        Peças
                    </label>
                    <select id="f-parts" name="awaiting_parts" defaultValue={params.get('awaiting_parts') ?? ''} className="rounded border border-slate-300 bg-white px-3 py-2">
                        <option value="">Todas as ordens</option>
                        <option value="1">Aguardando peças</option>
                    </select>
                </div>
                <button className="rounded border border-slate-300 px-4 py-2">Filtrar</button>
            </form>

            {loading && <p className="text-slate-500">Carregando…</p>}

            {error && (
                <p role="alert" className="text-red-700">
                    {error}{' '}
                    <button onClick={reload} className="underline">
                        Tentar de novo
                    </button>
                </p>
            )}

            {data && data.data.length === 0 && page > 1 && (
                <p className="text-slate-500">
                    Esta página não existe (só há {data.meta.last_page}).{' '}
                    <button onClick={() => goToFirst()} className="underline">
                        Voltar para a primeira página
                    </button>
                </p>
            )}

            {data && data.data.length === 0 && page === 1 && (
                <p className="text-slate-500">
                    {filtered ? 'Nenhuma ordem encontrada para esses filtros.' : 'Nenhuma ordem ainda.'}{' '}
                    {isAdmin && !filtered && (
                        <Link to="/clientes" className="underline">
                            Escolha um cliente para abrir a primeira
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
                                    <th className="py-2 pr-4 font-medium">Cliente</th>
                                    <th className="py-2 pr-4 font-medium">Equipamento</th>
                                    <th className="py-2 pr-4 font-medium">Estado</th>
                                    <th className="py-2 font-medium">Entrada</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((o) => (
                                    <tr key={o.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">
                                            <Link to={`/ordens/${o.id}`} className="font-medium underline">
                                                #{o.number}
                                            </Link>
                                        </td>
                                        <td className="py-2 pr-4">{o.customer.name}</td>
                                        <td className="py-2 pr-4">
                                            {o.device.brand} {o.device.model}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {o.status_label}
                                            {o.awaiting_parts && (
                                                <span className="ml-2 whitespace-nowrap rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs">
                                                    <span aria-hidden="true">⏳ </span>aguardando peças
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2">{formatDateTime(o.received_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <nav aria-label="Paginação" className="flex flex-wrap items-center gap-4 text-sm">
                        <button disabled={page <= 1} onClick={() => goToPage(page - 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Anterior
                        </button>
                        <span>
                            Página {data.meta.current_page} de {data.meta.last_page} · {data.meta.total} ordens
                        </span>
                        <button disabled={page >= data.meta.last_page} onClick={() => goToPage(page + 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Próxima
                        </button>
                    </nav>
                </>
            )}
        </div>
    );
}
