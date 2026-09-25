import { useState } from 'react';
import { api } from '../api';
import { centsToReais, formatBRL, toCents } from '../format';
import { useAction } from '../hooks/use-action';
import type { Quote } from '../types/quote';
import { ConfirmAction } from './confirm-action';
import { PartPicker, type PickedPart } from './part-picker';

type Row = { key: number; kind: 'part' | 'service'; part: PickedPart | null; description: string; quantity: string; price: string };

const input = 'w-full min-w-0 rounded border border-slate-300 bg-white px-2 py-1 focus-visible:outline-2 focus-visible:outline-slate-900';

type Props = { quote: Quote; onSaved: () => void; onStale: (message: string) => void };

export function QuoteDraftEditor({ quote, onSaved, onStale }: Props) {
    const save = useAction(onStale);
    const issue = useAction(onStale);
    const [notes, setNotes] = useState(quote.notes ?? '');
    const [next, setNext] = useState(quote.items.length);
    const [rows, setRows] = useState<Row[]>(
        quote.items.map((i, n) => ({
            key: n,
            kind: i.kind,
            // Nome e preço do snapshot do item; ao salvar, o servidor copia o preço atual do catálogo.
            part: i.kind === 'part' && i.part_id ? { id: i.part_id, name: i.description, price: i.unit_price_cents } : null,
            description: i.kind === 'service' ? i.description : '',
            quantity: String(i.quantity),
            price: i.kind === 'service' ? centsToReais(i.unit_price_cents) : '',
        })),
    );
    const [dirty, setDirty] = useState(false);
    const [localError, setLocalError] = useState('');
    const change = (fn: (r: Row[]) => Row[]) => {
        setRows(fn);
        setDirty(true);
    };
    const patch = (key: number, p: Partial<Row>) => change((r) => r.map((x) => (x.key === key ? { ...x, ...p } : x)));

    const preview = rows.reduce((sum, r) => {
        const qty = Number(r.quantity) || 0;
        const price = r.kind === 'part' ? (r.part?.price ?? 0) : toCents(r.price) || 0;
        return sum + qty * price;
    }, 0);

    const payload = () => ({
        notes: notes.trim() || null,
        items: rows.map((r) =>
            r.kind === 'part'
                ? { kind: 'part', part_id: r.part ? r.part.id : null, quantity: Number(r.quantity) }
                : { kind: 'service', description: r.description, quantity: Number(r.quantity), unit_price_cents: Number.isNaN(toCents(r.price)) ? null : toCents(r.price) },
        ),
    });

    const saveDraft = () => {
        if (rows.some((r) => r.kind === 'service' && Number.isNaN(toCents(r.price)))) {
            setLocalError('Informe o preço dos serviços em reais com até duas casas, por exemplo 12,50.');
            return Promise.resolve(false);
        }
        setLocalError('');
        return save.run(async () => {
            await api('PUT', `/quotes/${quote.id}/draft`, payload());
            setDirty(false);
            onSaved();
        });
    };

    const rowError = (n: number, field: string) => save.errors[`items.${n}.${field}`]?.[0] ?? issue.errors[`items.${n}.${field}`]?.[0];

    return (
        <div className="flex flex-col gap-4">
            {rows.length === 0 && <p className="text-sm text-slate-500">Nenhum item ainda. Adicione uma peça ou um serviço.</p>}

            <ul className="flex flex-col gap-3">
                {rows.map((r, n) => (
                    <li key={r.key} className="grid gap-2 rounded border border-slate-200 p-3 [&>div]:min-w-0 sm:grid-cols-[8rem_1fr_6rem_9rem_auto] sm:items-start">
                        <div className="flex flex-col gap-1">
                            <label htmlFor={`kind-${r.key}`} className="text-xs text-slate-500">
                                Tipo
                            </label>
                            <select id={`kind-${r.key}`} value={r.kind} onChange={(e) => patch(r.key, { kind: e.target.value as Row['kind'] })} className={input}>
                                <option value="part">Peça</option>
                                <option value="service">Serviço</option>
                            </select>
                        </div>

                        {r.kind === 'part' ? (
                            <div className="flex flex-col gap-1">
                                <label htmlFor={`part-${r.key}`} className="text-xs text-slate-500">
                                    Peça
                                </label>
                                <PartPicker
                                    id={`part-${r.key}`}
                                    value={r.part}
                                    onPick={(part) => patch(r.key, { part })}
                                    invalid={!!rowError(n, 'part_id')}
                                    describedBy={rowError(n, 'part_id') ? `err-${r.key}-part_id` : undefined}
                                />
                                {rowError(n, 'part_id') && <p id={`err-${r.key}-part_id`} className="text-sm text-red-700">{rowError(n, 'part_id')}</p>}
                            </div>
                        ) : (
                            <div className="flex flex-col gap-1">
                                <label htmlFor={`desc-${r.key}`} className="text-xs text-slate-500">
                                    Descrição
                                </label>
                                <input id={`desc-${r.key}`} value={r.description} onChange={(e) => patch(r.key, { description: e.target.value })} aria-invalid={!!rowError(n, 'description')} aria-describedby={rowError(n, 'description') ? `err-${r.key}-description` : undefined} className={input} />
                                {rowError(n, 'description') && <p id={`err-${r.key}-description`} className="text-sm text-red-700">{rowError(n, 'description')}</p>}
                            </div>
                        )}

                        <div className="flex flex-col gap-1">
                            <label htmlFor={`qty-${r.key}`} className="text-xs text-slate-500">
                                Quantidade
                            </label>
                            <input id={`qty-${r.key}`} type="number" min={1} value={r.quantity} onChange={(e) => patch(r.key, { quantity: e.target.value })} aria-invalid={!!rowError(n, 'quantity')} aria-describedby={rowError(n, 'quantity') ? `err-${r.key}-quantity` : undefined} className={input} />
                            {rowError(n, 'quantity') && <p id={`err-${r.key}-quantity`} className="text-sm text-red-700">{rowError(n, 'quantity')}</p>}
                        </div>

                        <div className="flex flex-col gap-1">
                            <span className="text-xs text-slate-500">Preço unitário</span>
                            {r.kind === 'part' ? (
                                <span className="py-1 text-sm">{r.part ? formatBRL(r.part.price) : '—'} (do catálogo, copiado ao salvar)</span>
                            ) : (
                                <>
                                    <input aria-label="Preço unitário em reais" inputMode="decimal" placeholder="0,00" value={r.price} onChange={(e) => patch(r.key, { price: e.target.value })} aria-invalid={!!rowError(n, 'unit_price_cents')} aria-describedby={rowError(n, 'unit_price_cents') ? `err-${r.key}-unit_price_cents` : undefined} className={input} />
                                    {rowError(n, 'unit_price_cents') && <p id={`err-${r.key}-unit_price_cents`} className="text-sm text-red-700">{rowError(n, 'unit_price_cents')}</p>}
                                </>
                            )}
                        </div>

                        <button type="button" aria-label={`Remover item ${n + 1}`} onClick={() => change((all) => all.filter((x) => x.key !== r.key))} className="self-end rounded border border-slate-300 px-3 py-1 text-sm">
                            Remover
                        </button>
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap gap-3">
                <button
                    type="button"
                    onClick={() => {
                        change((all) => [...all, { key: next, kind: 'part', part: null, description: '', quantity: '1', price: '' }]);
                        setNext(next + 1);
                    }}
                    className="rounded border border-slate-300 px-3 py-1 text-sm"
                >
                    Adicionar item
                </button>
            </div>

            <div className="flex max-w-xl flex-col gap-1">
                <label htmlFor="quote-notes" className="text-sm font-medium">
                    Observações (opcional)
                </label>
                <textarea
                    id="quote-notes"
                    rows={2}
                    aria-invalid={!!save.errors.notes}
                    aria-describedby={save.errors.notes ? 'quote-notes-error' : undefined}
                    value={notes}
                    onChange={(e) => {
                        setNotes(e.target.value);
                        setDirty(true);
                    }}
                    className={input}
                />
                {save.errors.notes && (
                    <p id="quote-notes-error" className="text-sm text-red-700">
                        {save.errors.notes[0]}
                    </p>
                )}
            </div>

            <p className="text-sm">
                Total estimado: <strong>{formatBRL(preview)}</strong> <span className="text-slate-500">(o valor final é calculado ao salvar)</span>
            </p>
            <p className="text-sm text-slate-500">O preço de cada peça é copiado do catálogo (preço atual) no momento de salvar o rascunho. Depois de salvo, e depois de emitido, ele não muda mais.</p>

            {(localError || save.error) && (
                <p role="alert" className="text-sm text-red-700">
                    {localError || save.error}
                </p>
            )}

            <div className="flex flex-wrap items-start gap-3">
                <button disabled={save.busy || !dirty} onClick={() => void saveDraft()} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                    {save.busy ? 'Salvando…' : 'Salvar rascunho'}
                </button>
                <ConfirmAction
                    label="Emitir orçamento"
                    confirmLabel="Emitir agora"
                    busy={issue.busy}
                    effect={
                        <>
                            <p>
                                <strong>Emitir a versão {quote.version} congela os itens, os preços e o total.</strong> A ordem passa para “Aguardando aprovação” e, depois disso, só é possível alterar o orçamento criando uma revisão.
                            </p>
                            {dirty && <p className="mt-2 text-red-700">Há alterações não salvas: salve o rascunho antes de emitir.</p>}
                            {issue.error && (
                                <p role="alert" className="mt-2 text-red-700">
                                    {issue.error}
                                </p>
                            )}
                        </>
                    }
                    onConfirm={() => {
                        if (dirty) return Promise.resolve(false);
                        return issue.run(async () => {
                            await api('POST', `/quotes/${quote.id}/issue`);
                            onSaved();
                        });
                    }}
                    tone="secondary"
                />
            </div>
        </div>
    );
}
