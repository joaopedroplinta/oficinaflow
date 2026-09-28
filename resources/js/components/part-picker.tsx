import { useEffect, useState } from 'react';
import { api, errorMessage } from '../api';
import { formatBRL } from '../format';
import type { Page } from '../types/api';
import type { Part } from '../types/part';

export type PickedPart = { id: number; name: string; sku?: string; price: number };

type Props = { name?: string; id: string; label: string; value: PickedPart | null; onPick: (part: PickedPart) => void; invalid: boolean; describedBy?: string };

const control = 'w-full min-w-0 rounded border border-slate-300 bg-white px-2 py-1 focus-visible:outline-2 focus-visible:outline-slate-900';
const LIMIT = 20;

// Busca de peça no catálogo (código ou nome), 20 por vez: o catálogo inteiro nunca é carregado. A peça escolhida fica
// guardada pela linha do orçamento, então continua aparecendo mesmo que saia dos resultados ou seja desativada depois.
export function PartPicker({ id, name, label, value, onPick, invalid, describedBy }: Props) {
    const [q, setQ] = useState('');
    // Linha que já tem peça só busca quando o usuário mexe na busca: evita uma requisição por linha a cada montagem.
    const [active, setActive] = useState(!value);
    const [state, setState] = useState<{ results: Part[]; total: number; loading: boolean; error: string }>({
        results: [],
        total: 0,
        loading: true,
        error: '',
    });
    const [tick, setTick] = useState(0);

    useEffect(() => {
        if (!active) return;
        let alive = true;
        // Resultado antigo não pode se passar pelo da busca nova durante a pausa do debounce.
        setState((s) => ({ ...s, loading: true, error: '' }));
        // Sem termo, busca já; digitando, espera uma pausa para não fazer uma requisição por tecla.
        const timer = setTimeout(
            () => {
                api<Page<Part>>('GET', `/parts?active=1&per_page=${LIMIT}&q=${encodeURIComponent(q.trim())}`)
                    .then((r) => alive && setState({ results: r.data, total: r.meta.total, loading: false, error: '' }))
                    .catch((e) => alive && setState((s) => ({ ...s, loading: false, error: errorMessage(e) })));
            },
            q.trim() ? 300 : 0,
        );
        return () => {
            alive = false;
            clearTimeout(timer);
        };
    }, [q, tick, active]);

    const { results, total, loading, error } = state;
    const inResults = value ? results.some((p) => p.id === value.id) : false;

    return (
        <div className="flex flex-col gap-1">
            <input
                id={id}
                name={name}
                type="search"
                value={q}
                maxLength={100}
                onFocus={() => setActive(true)}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Buscar por código ou nome"
                aria-invalid={invalid}
                aria-describedby={describedBy}
                className={control}
            />
            <select
                aria-label={`Resultados da busca de peça, ${label}`}
                value={value?.id ?? ''}
                onChange={(e) => {
                    const p = results.find((r) => String(r.id) === e.target.value);
                    if (p) onPick({ id: p.id, name: p.name, sku: p.sku, price: p.sale_price_cents });
                }}
                aria-invalid={invalid}
                className={control}
            >
                {!value && <option value="">Selecione…</option>}
                {value && !inResults && (
                    <option value={value.id}>
                        {value.sku ? `${value.sku} — ` : ''}
                        {value.name} ({formatBRL(value.price)})
                    </option>
                )}
                {results.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.sku} — {p.name} ({formatBRL(p.sale_price_cents)}, disp. {p.available})
                    </option>
                ))}
            </select>
            {active && loading && <p className="text-xs text-slate-500">Buscando…</p>}
            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}{' '}
                    <button type="button" onClick={() => setTick((t) => t + 1)} className="underline">
                        Tentar de novo
                    </button>
                </p>
            )}
            {active && !error && !loading && results.length === 0 && <p className="text-xs text-slate-500">Nenhuma peça ativa encontrada para essa busca.</p>}
            {active && !error && !loading && total > LIMIT && (
                <p className="text-xs text-slate-500">
                    Mostrando {LIMIT} de {total} peças: refine a busca.
                </p>
            )}
        </div>
    );
}
