import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { Field } from '../components/field';
import { Loading } from '../components/loading';
import { SavedNotice } from '../components/saved-notice';
import { centsToReais, formatBRL, formatDateTime, toCents } from '../format';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data, Page } from '../types/api';
import type { InventoryMovement, Part } from '../types/part';

const PRICE_HINT = 'Informe o preço em reais com até duas casas, por exemplo 12,50.';

function Movements({ partId }: { partId: number }) {
    const [page, setPage] = useState(1);
    const { data, loading, error, reload } = useLoad<Page<InventoryMovement>>(`/parts/${partId}/movements?page=${page}`);

    if (loading && !data) return <Loading small />;
    if (error || !data)
        return (
            <p role="alert" className="text-sm text-red-700">
                {error}{' '}
                <button onClick={reload} className="underline">
                    Tentar de novo
                </button>
            </p>
        );
    if (data.data.length === 0) return <p className="text-sm text-slate-500">Nenhuma movimentação ainda. Registre a primeira entrada acima.</p>;

    return (
        <>
            <div className="table-frame">
                <table className="data-table">
                    <thead className="border-b border-slate-200 text-slate-500">
                        <tr>
                            <th className="py-2 pr-4 font-medium">Data</th>
                            <th className="py-2 pr-4 font-medium">Tipo</th>
                            <th className="py-2 pr-4 text-right font-medium">Qtd.</th>
                            <th className="py-2 pr-4 font-medium">Motivo</th>
                            <th className="py-2 font-medium">Por</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.data.map((m) => (
                            <tr key={m.id} className="border-b border-slate-100">
                                <td className="py-2 pr-4">{formatDateTime(m.created_at)}</td>
                                <td className="py-2 pr-4">{m.kind_label}</td>
                                <td className="py-2 pr-4 text-right">{m.kind === 'consumption' ? `−${m.quantity}` : `+${m.quantity}`}</td>
                                <td className="py-2 pr-4">{m.reason}</td>
                                <td className="py-2">{m.author.name}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <nav aria-label="Paginação dos movimentos" className="flex items-center gap-4 text-sm">
                <button disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                    Anterior
                </button>
                <span>
                    Página {data.meta.current_page} de {data.meta.last_page}
                </span>
                <button disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)} className="rounded border border-slate-300 px-3 py-1 disabled:opacity-40">
                    Próxima
                </button>
            </nav>
        </>
    );
}

export default function PartDetail() {
    const { id } = useParams();
    const { isAdmin } = useAuth();
    const part = useLoad<Data<Part>>(`/parts/${id}`);
    const edit = useSubmit();
    const receipt = useSubmit();
    const toggle = useSubmit();
    // Remonta os movimentos (voltando à página 1) a cada mudança de saldo.
    const [version, setVersion] = useState(0);
    const [priceError, setPriceError] = useState('');
    const [saved, setSaved] = useState('');
    const refresh = () => {
        setVersion((v) => v + 1);
        part.reload();
    };

    if (part.loading && !part.data) return <Loading title="Peça" />;
    if (!part.data)
        return (
            <p role="alert" className="text-red-700">
                <span className="sr-only">Peça: </span>
                {part.error}{' '}
                <button onClick={part.reload} className="underline">
                    Tentar de novo
                </button>{' '}
                ou{' '}
                <Link to="/estoque" className="underline">
                    Voltar para o estoque
                </Link>
            </p>
        );
    const p = part.data.data;

    async function saveEdit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        setSaved('');
        const price = toCents(String(f.get('price')));
        if (Number.isNaN(price)) return setPriceError(PRICE_HINT);
        setPriceError('');
        const ok = await edit.run(async () => {
            await api('PATCH', `/parts/${p.id}`, { name: String(f.get('name')).trim(), sale_price_cents: price });
        });
        if (ok) {
            setSaved('Dados da peça salvos.');
            refresh();
        }
    }

    async function saveReceipt(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setSaved('');
        const form = e.currentTarget;
        const f = new FormData(form);
        const ok = await receipt.run(async () => {
            await api('POST', `/parts/${p.id}/receipts`, { quantity: Number(f.get('quantity')), reason: String(f.get('reason')).trim() || null });
        });
        if (ok) {
            form.reset();
            setSaved('Entrada registrada.');
            refresh();
        }
    }

    return (
        <div className="flex flex-col gap-8">
            {part.error && (
                <p role="alert" className="text-sm text-red-700">
                    {part.error}{' '}
                    <button onClick={part.reload} className="underline">
                        Atualizar
                    </button>
                </p>
            )}

            {saved && <SavedNotice message={saved} />}

            <div>
                <Link to="/estoque" className="text-sm text-slate-600 underline">
                    ← Estoque
                </Link>
                <h1 className="mt-2 text-xl font-semibold">
                    {p.name} {!p.active && <span className="text-sm font-normal text-slate-500">(inativa)</span>}
                </h1>
                <p className="text-sm text-slate-600">
                    Código {p.sku} · {formatBRL(p.sale_price_cents)}
                </p>
            </div>

            <dl className="grid max-w-md grid-cols-3 gap-4 text-sm">
                <div>
                    <dt className="text-slate-500">Saldo</dt>
                    <dd className="text-lg font-semibold">{p.on_hand}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Reservado</dt>
                    <dd className="text-lg font-semibold">{p.reserved_quantity}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Disponível</dt>
                    <dd className="text-lg font-semibold">{p.available}</dd>
                </div>
            </dl>

            {isAdmin ? (
                <>
                    <section aria-labelledby="entrada" className="flex flex-col gap-3">
                        <h2 id="entrada" className="font-semibold">
                            Registrar entrada
                        </h2>
                        <form onSubmit={saveReceipt} className="grid max-w-xl gap-3 sm:grid-cols-[8rem_1fr_auto] sm:items-end">
                            <Field label="Quantidade" name="quantity" type="number" min={1} required error={receipt.errors.quantity?.[0]} />
                            <Field label="Motivo (opcional)" name="reason" placeholder="Compra, devolução…" error={receipt.errors.reason?.[0]} />
                            <button disabled={receipt.busy} className="btn btn-primary disabled:opacity-50">
                                {receipt.busy ? 'Registrando…' : 'Registrar entrada'}
                            </button>
                            {receipt.formError && (
                                <p role="alert" className="text-sm text-red-700 sm:col-span-3">
                                    {receipt.formError}
                                </p>
                            )}
                        </form>
                        <p className="text-sm text-slate-500">O saldo só muda por entrada, reserva e consumo; não é editável diretamente.</p>
                    </section>

                    <section aria-labelledby="dados" className="flex flex-col gap-3">
                        <h2 id="dados" className="font-semibold">
                            Dados da peça
                        </h2>
                        <form key={`${p.name}-${p.sale_price_cents}`} onSubmit={saveEdit} className="grid max-w-xl gap-3 sm:grid-cols-2">
                            <Field label="Nome" name="name" defaultValue={p.name} required error={edit.errors.name?.[0]} />
                            <Field label="Preço de venda (R$)" name="price" inputMode="decimal" defaultValue={centsToReais(p.sale_price_cents)} required error={priceError || edit.errors.sale_price_cents?.[0]} />
                            {edit.formError && (
                                <p role="alert" className="text-sm text-red-700 sm:col-span-2">
                                    {edit.formError}
                                </p>
                            )}
                            <button disabled={edit.busy} className="self-start btn btn-primary disabled:opacity-50 sm:col-span-2">
                                {edit.busy ? 'Salvando…' : 'Salvar alterações'}
                            </button>
                        </form>
                        <div>
                            <button
                                disabled={toggle.busy}
                                onClick={() =>
                                    void toggle.run(async () => {
                                        setSaved('');
                                        await api('PATCH', `/parts/${p.id}`, { active: !p.active });
                                        refresh();
                                    })
                                }
                                className="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-50"
                            >
                                {p.active ? 'Desativar peça' : 'Reativar peça'}
                            </button>
                            {!p.active && <p className="mt-1 text-sm text-slate-500">Peça inativa não aparece para novos orçamentos.</p>}
                            {toggle.formError && (
                                <p role="alert" className="mt-2 text-sm text-red-700">
                                    {toggle.formError}
                                </p>
                            )}
                        </div>
                    </section>

                    <section aria-labelledby="movimentos" className="flex flex-col gap-3">
                        <h2 id="movimentos" className="font-semibold">
                            Movimentações
                        </h2>
                        <Movements key={version} partId={p.id} />
                    </section>
                </>
            ) : (
                <p className="text-sm text-slate-500">Só o atendimento altera o estoque.</p>
            )}
        </div>
    );
}
