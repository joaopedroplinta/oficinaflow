import { Link, useParams } from 'react-router';
import { api, ApiError } from '../api';
import { DiagnosisForm } from '../components/diagnosis-form';
import { QuoteSection } from '../components/quote-section';
import { WorkOrderTimeline } from '../components/work-order-timeline';
import { formatDateTime } from '../format';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { WorkOrderDetail as Detail } from '../types/work-order';

export default function WorkOrderDetail() {
    const { id } = useParams();
    const { data, loading, error, reload } = useLoad<Data<Detail>>(`/work-orders/${id}`);
    const start = useSubmit();

    // Recarregar mantém a tela (e as mensagens de erro) no lugar; só a primeira carga mostra “Carregando”.
    if (loading && !data) return <p className="text-slate-500">Carregando…</p>;
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

    // Tela desatualizada (409): a mensagem explica e o dado é recarregado.
    const startDiagnosis = () =>
        void start
            .run(async () => {
                try {
                    await api('POST', `/work-orders/${o.id}/start-diagnosis`);
                } catch (e) {
                    if (e instanceof ApiError && e.status === 409) reload();
                    throw e;
                }
            })
            .then((ok) => ok && reload());

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

            <section aria-labelledby="diagnostico" className="flex flex-col gap-3">
                <h2 id="diagnostico" className="font-semibold">
                    Diagnóstico
                </h2>
                {o.status === 'received' && (
                    <div>
                        <p className="mb-2 text-sm text-slate-500">O diagnóstico ainda não foi iniciado.</p>
                        <button onClick={startDiagnosis} disabled={start.busy} className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                            {start.busy ? 'Iniciando…' : 'Iniciar diagnóstico'}
                        </button>
                        {start.formError && (
                            <p role="alert" className="mt-2 text-sm text-red-700">
                                {start.formError}
                            </p>
                        )}
                    </div>
                )}
                {o.status === 'diagnosing' && <DiagnosisForm key={`${o.diagnosis}-${o.assigned_technician?.id}`} order={o} onSaved={reload} />}
                {o.status !== 'received' && o.status !== 'diagnosing' && (
                    <p className="whitespace-pre-line text-sm">{o.diagnosis ?? 'Nenhum diagnóstico registrado.'}</p>
                )}
                {o.status !== 'received' && o.status !== 'diagnosing' && o.assigned_technician && (
                    <p className="text-sm text-slate-600">Responsável: {o.assigned_technician.name}</p>
                )}
            </section>

            <section aria-labelledby="orcamento" className="flex flex-col gap-3">
                <h2 id="orcamento" className="font-semibold">
                    Orçamento
                </h2>
                <QuoteSection order={o} onChanged={reload} />
            </section>

            <section aria-labelledby="pecas" className="flex flex-col gap-2">
                <h2 id="pecas" className="font-semibold">
                    Peças reservadas
                </h2>
                <p className="text-sm text-slate-500">Nenhuma peça reservada.</p>
            </section>

            <section aria-labelledby="historico" className="flex flex-col gap-3">
                <h2 id="historico" className="font-semibold">
                    Histórico
                </h2>
                <WorkOrderTimeline orderId={o.id} />
            </section>
        </div>
    );
}
