import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, ApiError, errorMessage } from '../api';
import { useAuth } from '../auth';
import { Loading } from '../components/loading';
import { DiagnosisForm } from '../components/diagnosis-form';
import { ExecutionSection } from '../components/execution-section';
import { PartsSection } from '../components/parts-section';
import { AttachmentsSection } from '../components/attachments-section';
import { PdfLink } from '../components/pdf-link';
import { ReturnSection } from '../components/return-section';
import { SavedNotice } from '../components/saved-notice';
import { QuoteSection } from '../components/quote-section';
import { WorkOrderTimeline } from '../components/work-order-timeline';
import { formatDateTime } from '../format';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { WorkOrderDetail as Detail } from '../types/work-order';

export default function WorkOrderDetail() {
    const { id } = useParams();
    const { isAdmin } = useAuth();
    const { data, loading, error, reload: reloadOrder } = useLoad<Data<Detail>>(`/work-orders/${id}`);
    const start = useSubmit();
    // Aviso de tela velha (409): vive aqui porque o componente que o gerou pode sumir no reload.
    const [notice, setNotice] = useState('');
    const [saved, setSaved] = useState('');
    // O histórico não muda de URL, então precisa de uma chave nova a cada recarga.
    const [version, setVersion] = useState(0);
    const reload = () => {
        setVersion((v) => v + 1);
        reloadOrder();
    };
    const changedWith = (message: string) => {
        setNotice('');
        setSaved(message);
        reload();
    };
    const changed = () => changedWith('');
    const stale = (message: string) => {
        setSaved('');
        setNotice(message);
        reload();
    };

    // Recarregar mantém a tela (e as mensagens de erro) no lugar; só a primeira carga mostra “Carregando”.
    if (loading && !data) return <Loading title="Ordem de serviço" />;
    if (!data)
        return (
            <p role="alert" className="text-red-700">
                <span className="sr-only">Ordem de serviço: </span>
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
                    if (e instanceof ApiError && e.status === 409) stale(errorMessage(e));
                    throw e;
                }
            })
            .then((ok) => ok && changed());

    return (
        <div className="flex flex-col gap-8">
            <div>
                <Link to="/" className="text-sm text-slate-600 underline">
                    ← Ordens
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Ordem #{o.number}</h1>
                <p className="text-sm text-slate-600">
                    {o.status_label}
                    {o.status === 'approved' && o.pending.length > 0 && ' · aguardando peças'} · recebida em {formatDateTime(o.received_at)}
                </p>
                {o.kind !== 'regular' && (
                    <p className="mt-1 text-sm">
                        <span className="whitespace-nowrap rounded border border-sky-300 bg-sky-50 px-2 py-0.5 text-xs">{o.kind_label}</span>
                        {o.origin && (
                            <>
                                {' '}
                                de{' '}
                                <Link to={`/ordens/${o.origin.id}`} className="underline">
                                    ordem #{o.origin.number}
                                </Link>{' '}
                                · sem cobrança financeira no sistema
                            </>
                        )}
                    </p>
                )}
                <p className="mt-1">
                    <PdfLink href={`/api/v1/work-orders/${o.id}/pdf`}>Baixar ficha da ordem em PDF</PdfLink>
                </p>
            </div>

            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}{' '}
                    <button onClick={reload} className="underline">
                        Atualizar
                    </button>
                </p>
            )}

            {saved && <SavedNotice message={saved} />}

            {notice && (
                <div role="alert" className="flex items-start justify-between gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                    <p>{notice} A tela foi atualizada com o estado atual da ordem.</p>
                    <button onClick={() => setNotice('')} className="underline">
                        Fechar
                    </button>
                </div>
            )}

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
                {o.status === 'diagnosing' && <DiagnosisForm key={`${o.diagnosis}-${o.assigned_technician?.id}`} order={o} onSaved={() => changedWith('Diagnóstico salvo.')} onStale={stale} />}
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
                <QuoteSection order={o} onChanged={changed} onStale={stale} />
            </section>

            <section aria-labelledby="pecas" className="flex flex-col gap-2">
                <h2 id="pecas" className="font-semibold">
                    Peças
                </h2>
                <PartsSection order={o} canReserve={isAdmin} onChanged={changed} onStale={stale} />
            </section>

            <section aria-labelledby="execucao" className="flex flex-col gap-3">
                <h2 id="execucao" className="font-semibold">
                    Execução
                </h2>
                <ExecutionSection key={o.id} order={o} isAdmin={isAdmin} onChanged={changed} onStale={stale} />
            </section>

            {(o.status === 'delivered' || o.returns.length > 0) && (
                <section aria-labelledby="retornos" className="flex flex-col gap-3">
                    <h2 id="retornos" className="font-semibold">
                        Garantia e retrabalho
                    </h2>
                    <ReturnSection key={`${o.id}-${o.returns.map((r) => r.status).join(',')}`} order={o} isAdmin={isAdmin} />
                </section>
            )}

            <section aria-labelledby="anexos" className="flex flex-col gap-3">
                <h2 id="anexos" className="font-semibold">
                    Fotos e documentos
                </h2>
                <AttachmentsSection key={o.id} orderId={o.id} onChanged={changed} />
            </section>

            <section aria-labelledby="historico" className="flex flex-col gap-3">
                <h2 id="historico" className="font-semibold">
                    Histórico
                </h2>
                <WorkOrderTimeline key={version} orderId={o.id} />
            </section>
        </div>
    );
}
