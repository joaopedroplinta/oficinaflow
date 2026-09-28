import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router';
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
import { Section, StatusBadge } from '../components/ui';
import { formatDateTime, formatBRL } from '../format';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { WorkOrderDetail as Detail } from '../types/work-order';

export default function WorkOrderDetail() {
    const { id } = useParams();
    const location = useLocation();
    const candidate = location.state?.returnTo;
    const returnTo =
        typeof candidate === 'string' && (candidate === '/' || candidate.startsWith('/?') || /^\/clientes\/\d+$/.test(candidate)) ? candidate : '/';
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
    const next =
        o.status === 'received' || (o.status === 'diagnosing' && !o.diagnosis)
            ? { section: 'diagnostico', label: 'Ir para diagnóstico', hint: 'Registre o diagnóstico para preparar o orçamento.' }
            : o.status === 'diagnosing'
              ? {
                    section: 'orcamento',
                    label: isAdmin ? 'Preparar orçamento' : 'Ver orçamento',
                    hint: isAdmin
                        ? 'Diagnóstico registrado. Prepare e emita o orçamento para o cliente.'
                        : 'Diagnóstico registrado. O atendimento pode preparar o orçamento.',
                }
              : o.status === 'awaiting_approval'
                ? {
                      section: 'orcamento',
                      label: 'Ver orçamento',
                      hint: isAdmin ? 'Envie o orçamento e registre a decisão do cliente.' : 'Aguardando o atendimento registrar a decisão do cliente.',
                  }
                : o.status === 'approved' && o.pending.length > 0
                  ? { section: 'pecas', label: 'Ver peças pendentes', hint: 'Confira a disponibilidade das peças para iniciar o reparo.' }
                  : o.status === 'approved' || o.status === 'in_progress'
                    ? { section: 'execucao', label: 'Ir para execução', hint: 'Acompanhe o reparo e registre o trabalho executado.' }
                    : o.status === 'ready_for_pickup' || o.status === 'awaiting_return'
                      ? {
                            section: 'execucao',
                            label: isAdmin ? (o.status === 'ready_for_pickup' ? 'Ir para entrega' : 'Ir para devolução') : 'Ver execução',
                            hint: isAdmin
                                ? 'Equipamento aguardando retirada. Confira os dados antes de finalizar.'
                                : 'O atendimento pode registrar a retirada do equipamento.',
                        }
                      : { section: 'historico', label: 'Ver histórico', hint: 'Serviço encerrado. Consulte os registros desta ordem.' };
    const openSection = (event: React.MouseEvent<HTMLAnchorElement>, id: string) => {
        event.preventDefault();
        const section = document.getElementById(id) as HTMLDetailsElement | null;
        if (section) {
            section.open = true;
            section.scrollIntoView({ block: 'start' });
            section.querySelector('summary')?.focus({ preventScroll: true });
        }
    };

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
        <div className="flex flex-col gap-4">
            <div>
                <Link to={returnTo} className="text-sm text-slate-600 underline">
                    ← Voltar à consulta
                </Link>
                <div className="page-heading mt-3">
                    <h1>Ordem #{o.number}</h1>
                    <StatusBadge status={o.status}>{o.status_label}</StatusBadge>
                </div>
                <p className="text-sm text-slate-600">
                    {o.status_label}
                    {(o.status === 'approved' || o.status === 'in_progress') && o.pending.length > 0 && ' · aguardando peças'} · recebida em{' '}
                    {formatDateTime(o.received_at)}
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

            <div className="order-summary">
                <div className="page-heading">
                    <div>
                        <p className="text-lg font-semibold">{o.customer.name}</p>
                        <p>
                            {d.brand} {d.model}
                        </p>
                    </div>
                    <div>
                        <p className="text-sm text-slate-500">Orçamento atual e adendos aprovados</p>
                        <p className="text-2xl font-semibold">{o.current_quote ? formatBRL(o.total_cents) : 'Ainda não orçado'}</p>
                    </div>
                </div>
                <div className="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-4">
                    <p className="max-w-xl text-sm text-slate-600">{next.hint}</p>
                    <a href={`#${next.section}`} onClick={(e) => openSection(e, next.section)} className="btn btn-primary">
                        {next.label}
                    </a>
                </div>
            </div>
            <nav aria-label="Seções da ordem" className="section-nav">
                {[
                    ['entrada', 'Entrada'],
                    ['diagnostico', 'Diagnóstico'],
                    ['orcamento', 'Orçamento'],
                    ['pecas', 'Peças'],
                    ['execucao', 'Execução'],
                    ['anexos', 'Anexos'],
                    ['historico', 'Histórico'],
                ].map(([id, label]) => (
                    <a key={id} href={`#${id}`} onClick={(e) => openSection(e, id)}>
                        {label}
                    </a>
                ))}
            </nav>

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

            <Section id="execucao" title="Execução" open={next.section === 'execucao'}>
                <ExecutionSection key={o.id} order={o} isAdmin={isAdmin} onChanged={changed} onStale={stale} />
            </Section>

            <Section id="entrada" title="Entrada" open={next.section === 'entrada'}>
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
            </Section>

            <Section id="diagnostico" title="Diagnóstico" open={next.section === 'diagnostico'}>
                {o.status === 'received' && (
                    <div>
                        <p className="mb-2 text-sm text-slate-500">O diagnóstico ainda não foi iniciado.</p>
                        <button onClick={startDiagnosis} disabled={start.busy} className="btn btn-primary disabled:opacity-50">
                            {start.busy ? 'Iniciando…' : 'Iniciar diagnóstico'}
                        </button>
                        {start.formError && (
                            <p role="alert" className="mt-2 text-sm text-red-700">
                                {start.formError}
                            </p>
                        )}
                    </div>
                )}
                {o.status === 'diagnosing' && (
                    <DiagnosisForm
                        key={`${o.diagnosis}-${o.assigned_technician?.id}`}
                        order={o}
                        onSaved={() => changedWith('Diagnóstico salvo.')}
                        onStale={stale}
                    />
                )}
                {o.status !== 'received' && o.status !== 'diagnosing' && (
                    <p className="whitespace-pre-line text-sm">{o.diagnosis ?? 'Nenhum diagnóstico registrado.'}</p>
                )}
                {o.status !== 'received' && o.status !== 'diagnosing' && o.assigned_technician && (
                    <p className="text-sm text-slate-600">Responsável: {o.assigned_technician.name}</p>
                )}
            </Section>

            <Section id="orcamento" title="Orçamento" open={next.section === 'orcamento'}>
                <QuoteSection refreshing={loading || !!error} order={o} onChanged={changed} onStale={stale} />
            </Section>

            <Section id="pecas" title="Peças" open={next.section === 'pecas'}>
                <PartsSection order={o} canReserve={isAdmin} onChanged={changed} onStale={stale} />
            </Section>

            {(o.status === 'delivered' || o.returns.length > 0) && (
                <Section id="retornos" title="Garantia e retrabalho" open={next.section === 'retornos'}>
                    <ReturnSection key={`${o.id}-${o.returns.map((r) => r.status).join(',')}`} order={o} isAdmin={isAdmin} />
                </Section>
            )}

            <Section id="anexos" title="Fotos e documentos" open={next.section === 'anexos'}>
                <AttachmentsSection key={o.id} orderId={o.id} onChanged={changed} />
            </Section>

            <Section id="historico" title="Histórico" open={next.section === 'historico'}>
                <WorkOrderTimeline key={version} orderId={o.id} />
            </Section>
        </div>
    );
}
