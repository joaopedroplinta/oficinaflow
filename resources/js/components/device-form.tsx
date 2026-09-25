import type { FormEvent } from 'react';
import { api } from '../api';
import { useSubmit } from '../hooks/use-submit';
import type { Device } from '../types/customer';
import { Field } from './field';

type Props = { customerId: number; device?: Device; onSaved: () => void; onCancel?: () => void };

export function DeviceForm({ customerId, device, onSaved, onCancel }: Props) {
    const { busy, errors, formError, run } = useSubmit();

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = e.currentTarget;
        const f = new FormData(form);
        const body = {
            category: String(f.get('category')),
            brand: String(f.get('brand')),
            model: String(f.get('model')),
            serial_number: String(f.get('serial_number')) || null,
        };
        const ok = await run(async () => {
            if (device) await api('PATCH', `/devices/${device.id}`, body);
            else await api('POST', `/customers/${customerId}/devices`, body);
        });
        if (ok) {
            if (!device) form.reset();
            onSaved();
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            <Field label="Categoria" name="category" placeholder="Notebook, celular…" defaultValue={device?.category} required error={errors.category?.[0]} />
            <Field label="Marca" name="brand" defaultValue={device?.brand} required error={errors.brand?.[0]} />
            <Field label="Modelo" name="model" defaultValue={device?.model} required error={errors.model?.[0]} />
            <Field label="Número de série (opcional)" name="serial_number" defaultValue={device?.serial_number ?? ''} error={errors.serial_number?.[0]} />
            {(formError || errors.customer) && (
                <p role="alert" className="text-sm text-red-700 sm:col-span-2">
                    {formError || errors.customer?.[0]}
                </p>
            )}
            <div className="flex gap-3 sm:col-span-2">
                <button disabled={busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                    {busy ? 'Salvando…' : device ? 'Salvar equipamento' : 'Adicionar equipamento'}
                </button>
                {onCancel && (
                    <button type="button" onClick={onCancel} className="rounded border border-slate-300 px-4 py-2">
                        Cancelar
                    </button>
                )}
            </div>
        </form>
    );
}
