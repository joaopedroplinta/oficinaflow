import { Pagination, StatusBadge } from '../components/ui';
import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { formatBRL } from '../format';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import type { Part } from '../types/part';

export default function Parts() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const active = params.get('active') ?? '';
    const unavailable = params.get('unavailable') === '1';
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const query = new URLSearchParams({ page: String(page) });
    if (q) query.set('q', q);
    if (unavailable) query.set('unavailable', '1');
    if (active) query.set('active', active);
    const { data, loading, error, reload } = useLoad<Page<Part>>(`/parts?${query}`);
    const filtered = !!(q || active || unavailable);

    const goToFirst = () => goTo(1);
    const goTo = (p: number) => {
        const next = new URLSearchParams(params);
        next.set('page', String(p));
        setParams(next);
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Estoque</h1>
                {isAdmin && (
                    <Link to="/estoque/nova" className="btn btn-primary">
                        Nova peça
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
                    const nq = String(f.get('q') ?? '').trim();
                    if (nq) next.set('q', nq);
                    if (f.get('unavailable')) next.set('unavailable', '1');
                    if (f.get('active')) next.set('active', String(f.get('active')));
                    setParams(next);
                }}
                className="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 md:grid-cols-2 lg:grid-cols-4 lg:items-end"
            >
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-q" className="text-sm font-medium">
                        Buscar
                    </label>
                    <input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={q}
                        placeholder="Código ou nome da peça"
                        className="rounded border border-slate-300 bg-white px-3 py-2"
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-active" className="text-sm font-medium">
                        Situação
                    </label>
                    <select id="f-active" name="active" defaultValue={active} className="rounded border border-slate-300 bg-white px-3 py-2">
                        <option value="">Todas</option>
                        <option value="1">Ativas</option>
                        <option value="0">Inativas</option>
                    </select>
                </div>
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input type="checkbox" name="unavailable" value="1" defaultChecked={unavailable} />
                    Sem disponibilidade
                </label>
                <div className="flex flex-wrap gap-2">
                    <button className="btn btn-primary">Filtrar</button>
                    <button type="button" className="btn btn-secondary" onClick={() => setParams({})}>
                        Limpar
                    </button>
                </div>
            </form>

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
                    {filtered ? 'Nenhuma peça encontrada para esses filtros.' : 'Nenhuma peça cadastrada ainda.'}{' '}
                    {isAdmin && !filtered && (
                        <Link to="/estoque/nova" className="underline">
                            Cadastrar a primeira peça
                        </Link>
                    )}
                </p>
            )}

            {data && data.data.length > 0 && (
                <>
                    <div className="table-frame">
                        <table className="data-table">
                            <thead className="border-b border-slate-200 text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Código</th>
                                    <th className="py-2 pr-4 font-medium">Peça</th>
                                    <th className="py-2 pr-4 text-right font-medium">Preço</th>
                                    <th className="py-2 pr-4 text-right font-medium">Saldo</th>
                                    <th className="py-2 pr-4 text-right font-medium">Reservado</th>
                                    <th className="py-2 pr-4 text-right font-medium">Disponível</th>
                                    <th className="py-2 font-medium">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((p) => (
                                    <tr key={p.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">{p.sku}</td>
                                        <td className="py-2 pr-4">
                                            <Link to={`/estoque/${p.id}`} className="font-medium underline">
                                                {p.name}
                                            </Link>
                                        </td>
                                        <td className="py-2 pr-4 text-right">{formatBRL(p.sale_price_cents)}</td>
                                        <td className="py-2 pr-4 text-right">{p.on_hand}</td>
                                        <td className="py-2 pr-4 text-right">{p.reserved_quantity}</td>
                                        <td className="py-2 pr-4 text-right">
                                            {p.available === 0 ? <StatusBadge status="awaiting_parts">Indisponível · 0</StatusBadge> : p.available}
                                        </td>
                                        <td className="py-2">{p.active ? 'Ativa' : 'Inativa'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination page={page} last={data.meta.last_page} total={data.meta.total} noun="peças" onPage={goTo} />
                </>
            )}
        </div>
    );
}
