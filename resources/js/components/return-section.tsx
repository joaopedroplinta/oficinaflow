import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { api } from '../api';
import { formatDateTime } from '../format';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { WorkOrder, WorkOrderDetail } from '../types/work-order';
import { TextArea } from './field';

type Props = { order: WorkOrderDetail; isAdmin: boolean };

// Garantia e retrabalho de uma ordem entregue: cada retorno é uma ordem nova, ligada a esta (que continua como está).
export function ReturnSection({ order, isAdmin }: Props) {
    const { busy, errors, formError, run } = useSubmit();
    const navigate = useNavigate();
    const expired = !!order.warranty_until && new Date(order.warranty_until) < new Date();
    const open = order.returns.some((r) => r.status !== 'delivered' && r.status !== 'canceled');

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        await run(async () => {
            const res = await api<Data<WorkOrder>>('POST', `/work-orders/${order.id}/returns`, {
                kind: String(f.get('kind')),
                reported_problem: String(f.get('reported_problem')),
                intake_condition: String(f.get('intake_condition')),
                accessories: String(f.get('accessories')),
            });
            void navigate(`/ordens/${res.data.id}`);
        });
    }

    return (
        <div className="flex flex-col gap-3">
            {order.status === 'delivered' && order.warranty_until && (
                <p className="text-sm">
                    {expired ? 'Garantia vencida em ' : 'Garantia até '}
                    <strong>{formatDateTime(order.warranty_until)}</strong>
                    {expired && ': ainda é possível abrir um retrabalho.'}
                </p>
            )}

            {order.returns.length > 0 && (
                <ul className="flex flex-col gap-1 text-sm">
                    {order.returns.map((r) => (
                        <li key={r.id}>
                            <Link to={`/ordens/${r.id}`} className="underline">
                                Ordem #{r.number}
                            </Link>
                            , {r.kind_label.toLowerCase()}, {r.status_label.toLowerCase()}
                        </li>
                    ))}
                </ul>
            )}

            {order.status === 'delivered' && isAdmin && open && (
                <p className="text-sm text-slate-500">Já existe um retorno em andamento; conclua ou cancele esse retorno antes de abrir outro.</p>
            )}

            {order.status === 'delivered' && isAdmin && !open && (
                <form onSubmit={submit} className="flex max-w-xl flex-col gap-3">
                    <p className="text-sm text-slate-600">
                        Abre uma <strong>nova ordem</strong> para o mesmo cliente e equipamento, ligada a esta. Esta ordem continua como está, entregue.
                    </p>
                    <fieldset className="flex flex-col gap-1" aria-describedby={errors.kind ? 'kind-error' : undefined}>
                        <legend className="text-sm font-medium">Tipo de retorno</legend>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="radio" name="kind" value="warranty" defaultChecked={!expired} disabled={expired} />
                            Garantia (dentro do prazo){expired ? ' — vencida' : ''}
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="radio" name="kind" value="rework" defaultChecked={expired} />
                            Retrabalho (refazer o serviço, sem prazo)
                        </label>
                        {errors.kind && (
                            <p id="kind-error" className="text-sm text-red-700">
                                {errors.kind[0]}
                            </p>
                        )}
                    </fieldset>
                    <TextArea label="Problema relatado" name="reported_problem" required error={errors.reported_problem?.[0]} />
                    <TextArea
                        label="Condição de entrada"
                        name="intake_condition"
                        placeholder="Riscos, trincas, marcas de uso…"
                        required
                        error={errors.intake_condition?.[0]}
                    />
                    <TextArea
                        label="Acessórios entregues"
                        name="accessories"
                        placeholder="Carregador, capa… ou “Nenhum”"
                        required
                        error={errors.accessories?.[0]}
                    />
                    {formError && (
                        <p role="alert" className="text-sm text-red-700">
                            {formError}
                        </p>
                    )}
                    <div>
                        <button disabled={busy} className="btn btn-primary disabled:opacity-50">
                            {busy ? 'Abrindo…' : 'Abrir retorno'}
                        </button>
                    </div>
                </form>
            )}

            {order.returns.length === 0 && !(order.status === 'delivered' && isAdmin) && (
                <p className="text-sm text-slate-500">Nenhum retorno aberto para esta ordem.</p>
            )}
        </div>
    );
}
