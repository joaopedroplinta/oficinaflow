import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { Field } from '../components/field';
import { toCents } from '../format';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { Part } from '../types/part';

export default function PartNew() {
    const { isAdmin } = useAuth();
    const { busy, errors, formError, run } = useSubmit();
    const navigate = useNavigate();
    const [priceError, setPriceError] = useState('');

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento cadastra peças.</p>;

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const price = toCents(String(f.get('price')));
        if (Number.isNaN(price)) return setPriceError('Informe o preço em reais com até duas casas, por exemplo 12,50.');
        setPriceError('');
        await run(async () => {
            const res = await api<Data<Part>>('POST', '/parts', {
                sku: String(f.get('sku')).trim(),
                name: String(f.get('name')).trim(),
                sale_price_cents: price,
            });
            void navigate(`/estoque/${res.data.id}`);
        });
    }

    return (
        <div className="flex flex-col gap-4">
            <div>
                <Link to="/estoque" className="back-link">
                    Estoque
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Nova peça</h1>
            </div>
            <form onSubmit={submit} className="flex max-w-md flex-col gap-3">
                <Field label="Código (SKU)" name="sku" required error={errors.sku?.[0]} />
                <Field label="Nome da peça" name="name" required error={errors.name?.[0]} />
                <Field
                    label="Preço de venda (R$)"
                    name="price"
                    inputMode="decimal"
                    placeholder="0,00"
                    required
                    error={priceError || errors.sale_price_cents?.[0]}
                />
                <p className="text-sm text-slate-500">A peça nasce com saldo zero: registre uma entrada depois de cadastrar.</p>
                {formError && (
                    <p role="alert" className="text-sm text-red-700">
                        {formError}
                    </p>
                )}
                <button disabled={busy} className="self-start btn btn-primary disabled:opacity-50">
                    {busy ? 'Salvando…' : 'Cadastrar peça'}
                </button>
            </form>
        </div>
    );
}
