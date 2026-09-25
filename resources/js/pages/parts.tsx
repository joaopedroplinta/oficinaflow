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
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const query = new URLSearchParams({ page: String(page) });
    if (q) query.set('q', q);
    if (active) query.set('active', active);
    const { data, loading, error, reload } = useLoad<Page<Part>>(`/parts?${query}`);
    const filtered = !!(q || active);

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
                    <Link to="/estoque/nova" className="rounded bg-slate-900 px-4 py-2 text-white">
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
                    if (f.get('active')) next.set('active', String(f.get('active')));
                    setParams(next);
                }}
                className="grid gap-3 sm:grid-cols-[2fr_1fr_auto] sm:items-end"
            >
                <div className="flex flex-col gap-1">
                    <label htmlFor="f-q" className="text-sm font-medium">
                        Buscar
                    </label>
                    <input id="f-q" name="q" type="search" defaultValue={q} placeholder="Código ou nome da peça" className="rounded border border-slate-300 bg-white px-3 py-2" />
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
                <button className="rounded border border-slate-300 px-4 py-2">Filtrar</button>
            </form>

            {loading && <p role="status" className="text-slate-500">{data ? 'Atualizando…' : 'Carregando…'}</p>}
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
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
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
                                        <td className="py-2 pr-4 text-right">{p.available}</td>
                                        <td className="py-2">{p.active ? 'Ativa' : 'Inativa'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <nav aria-label="Paginação" className="flex flex-wrap items-center gap-4 text-sm">
                        <button disabled={page <= 1} onClick={() => goTo(page - 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Anterior
                        </button>
                        <span>
                            Página {data.meta.current_page} de {data.meta.last_page} · {data.meta.total} peças
                        </span>
                        <button disabled={page >= data.meta.last_page} onClick={() => goTo(page + 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                            Próxima
                        </button>
                    </nav>
                </>
            )}
        </div>
    );
}
