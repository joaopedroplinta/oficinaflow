import type { FormEvent } from 'react';
import { api, ApiError, errorMessage } from '../api';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { WorkOrderDetail } from '../types/work-order';
import { TextArea } from './field';

type Props = { order: WorkOrderDetail; onSaved: () => void; onStale: (message: string) => void };

export function DiagnosisForm({ order, onSaved, onStale }: Props) {
    const technicians = useLoad<Data<{ id: number; name: string }[]>>('/technicians');
    const { busy, errors, formError, run } = useSubmit();

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        const technician = String(f.get('assigned_technician_id'));
        const ok = await run(async () => {
            try {
                await api('PATCH', `/work-orders/${order.id}/diagnosis`, {
                    diagnosis: String(f.get('diagnosis')),
                    assigned_technician_id: technician ? Number(technician) : null,
                });
            } catch (err) {
                if (err instanceof ApiError && err.status === 409) onStale(errorMessage(err)); // tela velha: recarrega com aviso
                throw err;
            }
        });
        if (ok) onSaved();
    }

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-col gap-3">
            <TextArea label="Diagnóstico" name="diagnosis" rows={5} defaultValue={order.diagnosis ?? ''} required error={errors.diagnosis?.[0]} />
            <div className="flex flex-col gap-1">
                <label htmlFor="assigned_technician_id" className="text-sm font-medium">
                    Responsável técnico
                </label>
                <select
                    key={String(technicians.loading)} // remonta ao carregar, para o defaultValue achar a opção
                    id="assigned_technician_id"
                    name="assigned_technician_id"
                    defaultValue={order.assigned_technician?.id ?? ''}
                    disabled={technicians.loading}
                    aria-invalid={!!errors.assigned_technician_id}
                    aria-describedby={errors.assigned_technician_id ? 'assigned_technician_id-error' : undefined}
                    className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900"
                >
                    <option value="">Sem responsável</option>
                    {technicians.data?.data.map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.name}
                        </option>
                    ))}
                </select>
                {technicians.error && (
                    <p role="alert" className="text-sm text-red-700">
                        {technicians.error}
                    </p>
                )}
                {errors.assigned_technician_id && (
                    <p id="assigned_technician_id-error" className="text-sm text-red-700">
                        {errors.assigned_technician_id[0]}
                    </p>
                )}
            </div>
            {formError && (
                <p role="alert" className="text-sm text-red-700">
                    {formError}
                </p>
            )}
            <button disabled={busy} className="self-start rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                {busy ? 'Salvando…' : 'Salvar diagnóstico'}
            </button>
        </form>
    );
}
