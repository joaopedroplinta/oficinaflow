import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { Field, TextArea } from '../components/field';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Page } from '../types/api';
import type { Supplier } from '../types/supplier';

export default function Suppliers() {
    const { isAdmin } = useAuth();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const page = Math.max(1, Math.floor(Number(params.get('page'))) || 1);
    const { data, loading, error, reload } = useLoad<Page<Supplier>>(`/suppliers?page=${page}&q=${encodeURIComponent(q)}`);
    const create = useSubmit();
    const [formKey, setFormKey] = useState(0);

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento gerencia fornecedores.</p>;

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const ok = await create.run(async () => {
            await api('POST', '/suppliers', {
                name: String(f.get('name')).trim(),
                contact: String(f.get('contact')).trim() || null,
                notes: String(f.get('notes')).trim() || null,
            });
        });
        if (ok) {
            setFormKey((k) => k + 1);
            reload();
        }
    }

    return (
        <div className="flex flex-col gap-6">
            <h1 className="text-xl font-semibold">Fornecedores</h1>

            <form key={formKey} onSubmit={submit} className="flex max-w-xl flex-col gap-3">
                <h2 className="font-semibold">Novo fornecedor</h2>
                <Field label="Nome" name="name" required error={create.errors.name?.[0]} />
                <Field label="Contato (opcional)" name="contact" placeholder="Telefone, e-mail ou vendedor" error={create.errors.contact?.[0]} />
                <TextArea label="Observações (opcional)" name="notes" rows={2} error={create.errors.notes?.[0]} />
                {create.formError && (
                    <p role="alert" className="text-sm text-red-700">
                        {create.formError}
                    </p>
                )}
                <div>
                    <button disabled={create.busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                        {create.busy ? 'Salvando…' : 'Cadastrar fornecedor'}
                    </button>
                </div>
            </form>

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
                    aria-label="Buscar fornecedor"
                    placeholder="Buscar por nome…"
                    className="w-full max-w-md rounded border border-slate-300 bg-white px-3 py-2"
                />
                <button className="rounded border border-slate-300 px-4 py-2">Buscar</button>
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
            {data && data.data.length === 0 && (
                <p className="text-slate-500">{q ? 'Nenhum fornecedor encontrado.' : 'Nenhum fornecedor ainda. Cadastre o primeiro acima.'}</p>
            )}

            {data && data.data.length > 0 && (
                <>
                    <ul className="flex flex-col divide-y divide-slate-100 text-sm">
                        {data.data.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                                <Link to={`/fornecedores/${s.id}`} className="font-medium underline">
                                    {s.name}
                                </Link>
                                <span className="text-slate-500">
                                    {s.contact ?? 'sem contato'}
                                    {!s.active && ' (inativo)'}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <nav aria-label="Paginação" className="flex items-center gap-4 text-sm">
                        <button
                            disabled={page <= 1}
                            onClick={() => setParams({ q, page: String(page - 1) })}
                            className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40"
                        >
                            Anterior
                        </button>
                        <span>
                            Página {data.meta.current_page} de {data.meta.last_page}
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
