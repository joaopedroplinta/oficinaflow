import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { PartPicker, type PickedPart } from '../components/part-picker';
import { TextArea } from '../components/field';
import { formatBRL, toCents } from '../format';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data, Page } from '../types/api';
import type { PurchaseOrder } from '../types/purchase-order';
import type { Supplier } from '../types/supplier';

type Row = { key: number; part: PickedPart | null; quantity: string; cost: string };

const input = 'w-full min-w-0 rounded border border-slate-300 bg-white px-2 py-1 focus-visible:outline-2 focus-visible:outline-slate-900';

export default function PurchaseOrderNew() {
    const { isAdmin } = useAuth();
    const suppliers = useLoad<Page<Supplier>>('/suppliers?active=1');
    const submit = useSubmit();
    const navigate = useNavigate();
    const [rows, setRows] = useState<Row[]>([{ key: 0, part: null, quantity: '1', cost: '' }]);
    const [next, setNext] = useState(1);
    const [localError, setLocalError] = useState('');

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento gerencia compras.</p>;

    const patch = (key: number, p: Partial<Row>) => setRows((all) => all.map((r) => (r.key === key ? { ...r, ...p } : r)));
    const total = rows.reduce((sum, r) => sum + (Number(r.quantity) || 0) * (toCents(r.cost) || 0), 0);
    const err = (n: number, f: string) => submit.errors[`items.${n}.${f}`]?.[0];

    async function send(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        if (rows.some((r) => !r.part)) return setLocalError('Escolha a peça de cada linha (ou remova a linha vazia).');
        if (rows.some((r) => Number.isNaN(toCents(r.cost)))) return setLocalError('Informe o custo em reais com até duas casas, por exemplo 12,50.');
        setLocalError('');
        await submit.run(async () => {
            const res = await api<Data<PurchaseOrder>>('POST', '/purchase-orders', {
                supplier_id: Number(f.get('supplier_id')),
                notes: String(f.get('notes')).trim() || null,
                items: rows.map((r) => ({ part_id: r.part!.id, quantity: Number(r.quantity), unit_cost_cents: toCents(r.cost) })),
            });
            void navigate(`/compras/${res.data.id}`);
        });
    }

    return (
        <form onSubmit={send} className="flex max-w-3xl flex-col gap-4">
            <div>
                <Link to="/compras" className="text-sm text-slate-600 underline">
                    ← Pedidos de compra
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Novo pedido de compra</h1>
                <p className="text-sm text-slate-500">O pedido não muda o estoque; o estoque só entra quando você registra o recebimento. Depois de criado, o pedido não é editado: para mudar, cancele e refaça.</p>
            </div>

            <div className="flex max-w-md flex-col gap-1">
                <label htmlFor="supplier_id" className="text-sm font-medium">
                    Fornecedor
                </label>
                <select id="supplier_id" name="supplier_id" required defaultValue="" disabled={suppliers.loading} aria-invalid={!!submit.errors.supplier_id} className={input}>
                    <option value="" disabled>
                        Selecione…
                    </option>
                    {suppliers.data?.data.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
                {suppliers.data && suppliers.data.data.length === 0 && (
                    <p className="text-sm text-slate-500">
                        Nenhum fornecedor ativo.{' '}
                        <Link to="/fornecedores" className="underline">
                            Cadastre um
                        </Link>
                        .
                    </p>
                )}
                {suppliers.error && <p role="alert" className="text-sm text-red-700">{suppliers.error}</p>}
                {submit.errors.supplier_id && <p className="text-sm text-red-700">{submit.errors.supplier_id[0]}</p>}
            </div>

            <ul className="flex flex-col gap-3">
                {rows.map((r, n) => (
                    <li key={r.key} className="grid gap-2 rounded border border-slate-200 p-3 sm:grid-cols-[1fr_6rem_8rem_auto] sm:items-start">
                        <div className="flex min-w-0 flex-col gap-1">
                            <label htmlFor={`part-${r.key}`} className="text-xs text-slate-500">
                                Peça
                            </label>
                            <PartPicker id={`part-${r.key}`} label={`linha ${n + 1}`} value={r.part} onPick={(part) => patch(r.key, { part, cost: r.cost })} invalid={!!err(n, 'part_id')} />
                            {err(n, 'part_id') && <p className="text-sm text-red-700">{err(n, 'part_id')}</p>}
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor={`qty-${r.key}`} className="text-xs text-slate-500">
                                Quantidade
                            </label>
                            <input id={`qty-${r.key}`} type="number" min={1} value={r.quantity} onChange={(e) => patch(r.key, { quantity: e.target.value })} aria-invalid={!!err(n, 'quantity')} className={input} />
                            {err(n, 'quantity') && <p className="text-sm text-red-700">{err(n, 'quantity')}</p>}
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor={`cost-${r.key}`} className="text-xs text-slate-500">
                                Custo unitário (R$)
                            </label>
                            <input id={`cost-${r.key}`} inputMode="decimal" placeholder="0,00" value={r.cost} onChange={(e) => patch(r.key, { cost: e.target.value })} aria-invalid={!!err(n, 'unit_cost_cents')} className={input} />
                            {err(n, 'unit_cost_cents') && <p className="text-sm text-red-700">{err(n, 'unit_cost_cents')}</p>}
                        </div>
                        <button type="button" aria-label={`Remover linha ${n + 1}`} disabled={rows.length === 1} onClick={() => setRows((all) => all.filter((x) => x.key !== r.key))} className="self-end rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-40">
                            Remover
                        </button>
                    </li>
                ))}
            </ul>

            <div>
                <button
                    type="button"
                    onClick={() => {
                        setRows((all) => [...all, { key: next, part: null, quantity: '1', cost: '' }]);
                        setNext(next + 1);
                    }}
                    className="rounded border border-slate-300 px-3 py-1 text-sm"
                >
                    Adicionar peça
                </button>
            </div>

            <TextArea label="Observações (opcional)" name="notes" rows={2} error={submit.errors.notes?.[0]} />
            <p className="text-sm">
                Total do pedido: <strong>{formatBRL(total)}</strong>
            </p>

            {(localError || submit.formError || submit.errors.items?.[0]) && (
                <p role="alert" className="text-sm text-red-700">
                    {localError || submit.formError || submit.errors.items?.[0]}
                </p>
            )}
            <div>
                <button disabled={submit.busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                    {submit.busy ? 'Criando…' : 'Criar pedido'}
                </button>
            </div>
        </form>
    );
}
