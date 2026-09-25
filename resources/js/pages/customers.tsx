import { Link, useSearchParams } from 'react-router';
import { useAuth } from '../auth';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import type { Customer } from '../types/customer';

export default function Customers() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const page = Number(params.get('page') ?? 1);
    const { data, loading, error, reload } = useLoad<Page<Customer>>(`/customers?page=${page}&q=${encodeURIComponent(q)}`);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">Clientes</h1>
                {isAdmin && (
                    <Link to="/clientes/novo" className="rounded bg-slate-900 px-4 py-2 text-white">
                        Novo cliente
                    </Link>
                )}
            </div>

            <form
                role="search"
                onSubmit={(e) => {
                    e.preventDefault();
                    setParams({ q: String(new FormData(e.currentTarget).get('q') ?? '').trim() });
                }}
                className="flex gap-2"
            >
                <input
                    name="q"
                    type="search"
                    defaultValue={q}
                    aria-label="Buscar cliente"
                    placeholder="Buscar por nome, telefone ou e-mail"
                    className="w-full max-w-md rounded border border-slate-300 bg-white px-3 py-2"
                />
                <button className="rounded border border-slate-300 px-4 py-2">Buscar</button>
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

            {data && data.data.length === 0 && (
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
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
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

                    <nav aria-label="Paginação" className="flex items-center gap-4 text-sm">
                        <button
                            disabled={page <= 1}
                            onClick={() => setParams({ q, page: String(page - 1) })}
                            className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40"
                        >
                            Anterior
                        </button>
                        <span>
                            Página {data.meta.current_page} de {data.meta.last_page} · {data.meta.total} clientes
                        </span>
                        <button
                            disabled={page >= data.meta.last_page}
                            onClick={() => setParams({ q, page: String(page + 1) })}
                            className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40"
                        >
                            Próxima
                        </button>
                    </nav>
                </>
            )}
        </div>
    );
}
