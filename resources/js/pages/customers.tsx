import { Pagination } from '../components/ui';
import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import type { Customer } from '../types/customer';

export default function Customers() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const { data, loading, error, reload } = useLoad<Page<Customer>>(`/customers?page=${page}&q=${encodeURIComponent(q)}`);

    const goToFirst = () => setParams({ q });

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Clientes</h1>
                {isAdmin && (
                    <Link to="/clientes/novo" className="btn btn-primary">
                        Novo cliente
                    </Link>
                )}
            </div>

            <form
                key={q}
                role="search"
                onSubmit={(e) => {
                    e.preventDefault();
                    setParams({ q: String(new FormData(e.currentTarget).get('q') ?? '').trim() });
                }}
                className="flex flex-wrap gap-2"
            >
                <input
                    name="q"
                    type="search"
                    defaultValue={q}
                    aria-label="Buscar cliente"
                    placeholder="Buscar por nome, telefone ou e-mail"
                    className="w-full max-w-md rounded border border-slate-300 bg-white px-3 py-2"
                />
                <button className="btn btn-primary">Buscar</button>
                {q && (
                    <button type="button" className="btn btn-secondary" onClick={() => setParams({})}>
                        Limpar busca
                    </button>
                )}
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
                    {q ? 'Nenhum cliente encontrado para essa busca.' : 'Nenhum cliente cadastrado ainda.'}{' '}
                    {isAdmin && !q && (
                        <Link to="/clientes/novo" className="underline">
                            Cadastrar o primeiro cliente
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
                                    <th className="py-2 pr-4 font-medium">Nome</th>
                                    <th className="py-2 pr-4 font-medium">Telefone</th>
                                    <th className="py-2 pr-4 font-medium">E-mail</th>
                                    <th className="py-2 pr-4 font-medium">Equipamentos</th>
                                    <th className="py-2 font-medium">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((c) => (
                                    <tr key={c.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">
                                            <Link to={`/clientes/${c.id}`} className="font-medium underline">
                                                {c.name}
                                            </Link>
                                        </td>
                                        <td className="py-2 pr-4">{c.phone}</td>
                                        <td className="py-2 pr-4">{c.email ?? '—'}</td>
                                        <td className="py-2 pr-4">{c.devices_count ?? 0}</td>
                                        <td className="py-2">{c.active ? 'Ativo' : 'Inativo'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        page={page}
                        last={data.meta.last_page}
                        total={data.meta.total}
                        noun="clientes"
                        onPage={(p) => setParams({ q, page: String(p) })}
                    />
                </>
            )}
        </div>
    );
}
