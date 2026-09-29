import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { useLoad } from '../hooks/use-load';
import { Alert, Button, OrderList, Pagination } from '../components/ui';
import type { Data, Page } from '../types/api';
import { kindOptions, statusOptions, type WorkOrder } from '../types/work-order';

const filters = ['status', 'kind', 'number', 'customer', 'awaiting_parts', 'technician_id', 'from', 'to', 'sort', 'scope', 'customer_id'] as const;
const summaries = [
    { key: 'awaiting_approval', label: 'Aguardando aprovação' },
    { key: 'awaiting_parts', label: 'Aguardando peças' },
    { key: 'in_progress', label: 'Em reparo' },
    { key: 'ready_for_pickup', label: 'Prontas para retirada' },
] as const;
type Orders = Page<WorkOrder> & { summary: Record<(typeof summaries)[number]['key'], number> };

export default function WorkOrders() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const mine = params.get('mine') === '1';
    const query = new URLSearchParams(params);
    query.set('page', String(page));
    query.set('include_summary', '1');
    const { data, loading, error, reload } = useLoad<Orders>(`/work-orders?${query}`);
    const technicians = useLoad<Data<{ id: number; name: string }[]>>('/technicians');
    const filtered = filters.some((k) => params.has(k)) || mine;
    const goTo = (p: number) => {
        const next = new URLSearchParams(params);
        next.set('page', String(p));
        setParams(next);
    };
    const returnTo = `/${params.size ? `?${params}` : ''}`;
    return (
        <div className="flex flex-col gap-6">
            <div className="page-heading">
                <div>
                    <h1>Ordens de serviço</h1>
                    <p>Acompanhe a oficina e encontre o próximo trabalho.</p>
                </div>
                {isAdmin && (
                    <Link to="/ordens/nova" className="btn btn-primary">
                        Receber equipamento
                    </Link>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    variant={!mine ? 'primary' : 'secondary'}
                    aria-pressed={!mine}
                    onClick={() => {
                        const n = new URLSearchParams(params);
                        n.delete('mine');
                        n.delete('page');
                        setParams(n);
                    }}
                >
                    Todas as ordens
                </Button>
                <Button
                    variant={mine ? 'primary' : 'secondary'}
                    aria-pressed={mine}
                    onClick={() => {
                        const n = new URLSearchParams(params);
                        n.set('mine', '1');
                        n.delete('technician_id');
                        n.delete('page');
                        setParams(n);
                    }}
                >
                    Minhas ordens
                </Button>
            </div>
            <div>
                <p className="mb-2 text-sm text-slate-600">{mine ? 'Pendências atribuídas a você' : 'Pendências da oficina'}, sem contar os filtros abaixo</p>
                <nav aria-label="Pendências" className="summary-strip">
                    {summaries.map((s) => {
                        const n = new URLSearchParams(mine ? { mine: '1' } : {});
                        n.set(s.key === 'awaiting_parts' ? s.key : 'status', s.key === 'awaiting_parts' ? '1' : s.key);
                        return (
                            <Link
                                className="summary-item"
                                key={s.key}
                                to={`/?${n}`}
                                aria-current={
                                    params.get('status') === s.key || (s.key === 'awaiting_parts' && params.get('awaiting_parts') === '1') ? 'true' : undefined
                                }
                            >
                                <strong>{loading ? '…' : (data?.summary?.[s.key] ?? '—')}</strong>
                                <span>{s.label}</span>
                            </Link>
                        );
                    })}
                </nav>
            </div>
            <details className="filter-panel">
                <summary>Buscar e filtrar {filtered ? '(filtros ativos)' : ''}</summary>
                <form
                    key={params.toString()}
                    role="search"
                    className="filter-grid"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const f = new FormData(e.currentTarget);
                        const n = new URLSearchParams(mine ? { mine: '1' } : {});
                        for (const k of filters) {
                            const v = String(f.get(k) ?? '').trim();
                            if (v) n.set(k, v);
                        }
                        setParams(n);
                    }}
                >
                    <label>
                        Cliente
                        <input name="customer" type="search" placeholder="Nome do cliente…" defaultValue={params.get('customer') ?? ''} />
                    </label>
                    <label>
                        Número da ordem
                        <input name="number" type="number" min={1} defaultValue={params.get('number') ?? ''} />
                    </label>
                    <label>
                        Estado
                        <select name="status" defaultValue={params.get('status') ?? ''}>
                            <option value="">Todos</option>
                            {statusOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Responsável técnico
                        <select name="technician_id" disabled={mine || technicians.loading} defaultValue={params.get('technician_id') ?? ''}>
                            <option value="">{mine ? 'Somente minhas ordens' : 'Todos'}</option>
                            {technicians.data?.data.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Entrada a partir de
                        <input type="date" name="from" defaultValue={params.get('from') ?? ''} />
                    </label>
                    <label>
                        Entrada até
                        <input type="date" name="to" defaultValue={params.get('to') ?? ''} />
                    </label>
                    <label>
                        Tipo
                        <select name="kind" defaultValue={params.get('kind') ?? ''}>
                            <option value="">Todos</option>
                            {kindOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Peças
                        <select name="awaiting_parts" defaultValue={params.get('awaiting_parts') ?? ''}>
                            <option value="">Todas as ordens</option>
                            <option value="1">Aguardando peças</option>
                        </select>
                    </label>
                    <label>
                        Ordenar por
                        <select name="sort" defaultValue={params.get('sort') ?? 'newest'}>
                            <option value="newest">Mais recentes</option>
                            <option value="oldest">Mais antigas</option>
                            <option value="customer">Nome do cliente</option>
                        </select>
                    </label>
                    <label>
                        Situação
                        <select name="scope" defaultValue={params.get('scope') ?? ''}>
                            <option value="">Todas</option>
                            <option value="open">Em aberto</option>
                            <option value="closed">Encerradas</option>
                        </select>
                    </label>
                    {params.get('customer_id') && <input name="customer_id" type="hidden" value={params.get('customer_id')!} />}
                    <div className="filter-actions">
                        <Button variant="primary">Aplicar filtros</Button>
                        <Button type="button" onClick={() => setParams({})}>
                            Limpar filtros
                        </Button>
                    </div>
                </form>
                {technicians.error && (
                    <Alert>
                        Não foi possível carregar os técnicos.{' '}
                        <button className="underline" onClick={technicians.reload}>
                            Tentar novamente
                        </button>
                    </Alert>
                )}
            </details>
            {loading && (
                <p role="status" className="text-sm text-slate-500">
                    Carregando ordens…
                </p>
            )}
            {error && (
                <Alert>
                    {error}{' '}
                    <button className="underline" onClick={reload}>
                        Tentar novamente
                    </button>
                </Alert>
            )}
            {!loading && data && data.data.length === 0 && (
                <div className="empty-state">
                    <h2>{filtered ? 'Nenhuma ordem com esses filtros' : 'A oficina está pronta para começar'}</h2>
                    <p>
                        {page > 1
                            ? 'Esta página não tem resultados.'
                            : filtered
                              ? 'Ajuste a busca para encontrar outras ordens.'
                              : 'Receba o primeiro equipamento para acompanhar o serviço.'}
                    </p>
                    {page > 1 ? (
                        <Button onClick={() => goTo(1)}>Voltar à primeira página</Button>
                    ) : filtered ? (
                        <Button onClick={() => setParams({})}>Limpar filtros</Button>
                    ) : (
                        isAdmin && (
                            <Link to="/ordens/nova" className="btn btn-primary">
                                Receber equipamento
                            </Link>
                        )
                    )}
                </div>
            )}
            {data && data.data.length > 0 && (
                <div aria-busy={loading} className={loading ? 'pointer-events-none opacity-50' : ''}>
                    <OrderList orders={data.data} returnTo={returnTo} />
                    <div className="mt-5">
                        <Pagination page={data.meta.current_page} last={data.meta.last_page} total={data.meta.total} noun="ordens" onPage={goTo} />
                    </div>
                </div>
            )}
        </div>
    );
}
