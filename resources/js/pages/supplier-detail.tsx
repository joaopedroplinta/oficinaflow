import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { Field, TextArea } from '../components/field';
import { Loading } from '../components/loading';
import { SavedNotice } from '../components/saved-notice';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { Supplier } from '../types/supplier';

export default function SupplierDetail() {
    const { id } = useParams();
    const { isAdmin } = useAuth();
    const supplier = useLoad<Data<Supplier>>(`/suppliers/${id}`);
    const edit = useSubmit();
    const toggle = useSubmit();
    const [saved, setSaved] = useState('');

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento gerencia fornecedores.</p>;
    if (supplier.loading && !supplier.data) return <Loading title="Fornecedor" />;
    if (!supplier.data)
        return (
            <p role="alert" className="text-red-700">
                <span className="sr-only">Fornecedor: </span>
                {supplier.error}{' '}
                <Link to="/fornecedores" className="underline">
                    Voltar para fornecedores
                </Link>
            </p>
        );
    const s = supplier.data.data;

    async function save(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        setSaved('');
        const ok = await edit.run(async () => {
            await api('PATCH', `/suppliers/${s.id}`, { name: String(f.get('name')).trim(), contact: String(f.get('contact')).trim() || null, notes: String(f.get('notes')).trim() || null });
        });
        if (ok) {
            setSaved('Fornecedor salvo.');
            supplier.reload();
        }
    }

    async function setActive(active: boolean) {
        setSaved('');
        const ok = await toggle.run(async () => {
            await api('PATCH', `/suppliers/${s.id}`, { active });
        });
        if (ok) {
            setSaved(active ? 'Fornecedor reativado.' : 'Fornecedor desativado: não aparece mais para novas entradas, e o histórico continua.');
            supplier.reload();
        }
    }

    return (
        <div className="flex flex-col gap-6">
            {saved && <SavedNotice message={saved} />}
            <div>
                <Link to="/fornecedores" className="back-link">
                    Fornecedores
                </Link>
                <h1 className="mt-2 text-xl font-semibold">
                    {s.name} {!s.active && <span className="text-sm font-normal text-slate-500">(inativo)</span>}
                </h1>
            </div>

            <form key={s.name + s.contact + s.notes} onSubmit={save} className="flex max-w-xl flex-col gap-3">
                <Field label="Nome" name="name" defaultValue={s.name} required error={edit.errors.name?.[0]} />
                <Field label="Contato (opcional)" name="contact" defaultValue={s.contact ?? ''} error={edit.errors.contact?.[0]} />
                <TextArea label="Observações (opcional)" name="notes" rows={2} defaultValue={s.notes ?? ''} error={edit.errors.notes?.[0]} />
                {edit.formError && (
                    <p role="alert" className="text-sm text-red-700">
                        {edit.formError}
                    </p>
                )}
                <div>
                    <button disabled={edit.busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                        {edit.busy ? 'Salvando…' : 'Salvar'}
                    </button>
                </div>
            </form>

            <div className="flex flex-col gap-2">
                <button disabled={toggle.busy} onClick={() => void setActive(!s.active)} className="w-fit rounded border border-slate-300 px-4 py-2 disabled:opacity-50">
                    {s.active ? 'Desativar fornecedor' : 'Reativar fornecedor'}
                </button>
                {toggle.formError && (
                    <p role="alert" className="text-sm text-red-700">
                        {toggle.formError}
                    </p>
                )}
            </div>
        </div>
    );
}
