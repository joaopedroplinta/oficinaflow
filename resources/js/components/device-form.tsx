import type { FormEvent } from 'react';
import { api } from '../api';
import { useSubmit } from '../hooks/use-submit';
import { Field } from './field';

type Props = { customerId: number; onCreated: () => void };

export function DeviceForm({ customerId, onCreated }: Props) {
    const { busy, errors, formError, run } = useSubmit();

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = e.currentTarget;
        const f = new FormData(form);
        const ok = await run(async () => {
            await api('POST', `/customers/${customerId}/devices`, {
                category: String(f.get('category')),
                brand: String(f.get('brand')),
                model: String(f.get('model')),
                serial_number: String(f.get('serial_number')) || null,
            });
        });
        if (ok) {
            form.reset();
            onCreated();
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            <Field label="Categoria" name="category" placeholder="Notebook, celular…" required error={errors.category?.[0]} />
            <Field label="Marca" name="brand" required error={errors.brand?.[0]} />
            <Field label="Modelo" name="model" required error={errors.model?.[0]} />
            <Field label="Número de série (opcional)" name="serial_number" error={errors.serial_number?.[0]} />
            {formError && (
                <p role="alert" className="text-sm text-red-700 sm:col-span-2">
                    {formError}
                </p>
            )}
            <button disabled={busy} className="self-start rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50 sm:col-span-2">
                {busy ? 'Salvando…' : 'Adicionar equipamento'}
            </button>
        </form>
    );
}
