import { Link, useParams } from 'react-router';
import { formatDateTime } from '../format';
import { useLoad } from '../hooks/use-load';
import type { Data } from '../types/api';
import type { WorkOrderDetail as Detail } from '../types/work-order';

export default function WorkOrderDetail() {
    const { id } = useParams();
    const { data, loading, error, reload } = useLoad<Data<Detail>>(`/work-orders/${id}`);

    if (loading) return <p className="text-slate-500">Carregando…</p>;
    if (error || !data)
        return (
            <p role="alert" className="text-red-700">
                {error}{' '}
                <button onClick={reload} className="underline">
                    Tentar de novo
                </button>{' '}
                ou{' '}
                <Link to="/" className="underline">
                    voltar para as ordens
                </Link>
            </p>
        );

    const o = data.data;
    const d = o.device;

    return (
        <div className="flex flex-col gap-8">
            <div>
                <Link to="/" className="text-sm text-slate-600 underline">
                    ← Ordens
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Ordem #{o.number}</h1>
                <p className="text-sm text-slate-600">
                    {o.status_label} · recebida em {formatDateTime(o.received_at)}
                </p>
            </div>

            <section aria-labelledby="entrada" className="flex flex-col gap-3">
                <h2 id="entrada" className="font-semibold">
                    Entrada
                </h2>
                <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt className="text-slate-500">Cliente</dt>
                    <dd>
                        <Link to={`/clientes/${o.customer.id}`} className="underline">
                            {o.customer.name}
                        </Link>
                    </dd>
                    <dt className="text-slate-500">Equipamento</dt>
                    <dd>
                        {d.category} {d.brand} {d.model}
                        {d.serial_number ? ` (série ${d.serial_number})` : ''}
                    </dd>
                    <dt className="text-slate-500">Problema relatado</dt>
                    <dd className="whitespace-pre-line">{o.reported_problem}</dd>
                    <dt className="text-slate-500">Condição de entrada</dt>
                    <dd className="whitespace-pre-line">{o.intake_condition}</dd>
                    <dt className="text-slate-500">Acessórios</dt>
                    <dd className="whitespace-pre-line">{o.accessories}</dd>
                </dl>
            </section>

            <section aria-labelledby="orcamento" className="flex flex-col gap-2">
                <h2 id="orcamento" className="font-semibold">
                    Orçamento
                </h2>
                <p className="text-sm text-slate-500">Nenhum orçamento emitido ainda.</p>
            </section>

            <section aria-labelledby="pecas" className="flex flex-col gap-2">
                <h2 id="pecas" className="font-semibold">
                    Peças reservadas
                </h2>
                <p className="text-sm text-slate-500">Nenhuma peça reservada.</p>
            </section>
        </div>
    );
}
