import { useState } from 'react';
import { api } from '../api';
import { formatDateTime } from '../format';
import { useAction } from '../hooks/use-action';
import type { WorkOrderDetail } from '../types/work-order';
import { ConfirmAction } from './confirm-action';

type Props = { order: WorkOrderDetail; isAdmin: boolean; onChanged: () => void; onStale: (message: string) => void };

const CANCELABLE = ['received', 'diagnosing', 'awaiting_approval', 'approved'];
const input = 'rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900';

type Missing = { part_id: number; name: string; quantity: number };
const missingOf = (d: unknown): Missing[] => (typeof d === 'object' && d !== null && Array.isArray((d as { missing?: unknown }).missing) ? (d as { missing: Missing[] }).missing : []);

export function ExecutionSection({ order, isAdmin, onChanged, onStale }: Props) {
    const start = useAction(onStale);
    const complete = useAction(onStale);
    const deliver = useAction(onStale);
    const cancel = useAction(onStale);
    const [work, setWork] = useState('');
    const [reason, setReason] = useState('');
    const quote = order.current_quote;
    const post = (path: string, body?: unknown) => api('POST', `/work-orders/${order.id}${path}`, body);

    return (
        <div className="flex flex-col gap-4">
            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                {order.started_at && (
                    <>
                        <dt className="text-slate-500">Reparo iniciado em</dt>
                        <dd>{formatDateTime(order.started_at)}</dd>
                    </>
                )}
                {order.ready_at && (
                    <>
                        <dt className="text-slate-500">Reparo concluído em</dt>
                        <dd>{formatDateTime(order.ready_at)}</dd>
                        <dt className="text-slate-500">Trabalho executado</dt>
                        <dd className="whitespace-pre-line">{order.work_performed}</dd>
                    </>
                )}
                {order.delivered_at && (
                    <>
                        <dt className="text-slate-500">Entregue em</dt>
                        <dd>{formatDateTime(order.delivered_at)}</dd>
                    </>
                )}
                {order.cancellation_reason && (
                    <>
                        <dt className="text-slate-500">Motivo do cancelamento</dt>
                        <dd className="whitespace-pre-line">{order.cancellation_reason}</dd>
                    </>
                )}
            </dl>

            {order.status === 'approved' && quote && (
                <div className="flex flex-col gap-2">
                    {order.pending.length > 0 && <p className="text-sm text-slate-500">Reserve as peças pendentes (seção Peças) antes de iniciar o reparo.</p>}
                    <div>
                        <ConfirmAction
                            label="Iniciar reparo"
                            confirmLabel="Iniciar agora"
                            busy={start.busy}
                            tone="primary"
                            onOpen={start.reset}
                            effect={
                                <>
                                    <p>
                                        <strong>Inicia o reparo e baixa do estoque as peças reservadas</strong> (cada baixa fica registrada nas movimentações). A ordem passa para “Em reparo”. Depois disso o orçamento e as peças ficam congelados e o
                                        serviço não pode mais ser cancelado.
                                    </p>
                                    {start.error && (
                                        <div role="alert" className="mt-2 text-red-700">
                                            <p>{start.error}</p>
                                            {missingOf(start.details).length > 0 && (
                                                <ul className="mt-1 list-disc pl-5">
                                                    {missingOf(start.details).map((m) => (
                                                        <li key={m.part_id}>
                                                            {m.name}: falta reservar {m.quantity}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    )}
                                </>
                            }
                            onConfirm={() =>
                                start.run(async () => {
                                    await post('/start-repair', { quote_id: quote.id });
                                    onChanged();
                                })
                            }
                        />
                    </div>
                </div>
            )}

            {order.status === 'in_progress' && (
                <div className="flex max-w-xl flex-col gap-2">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="work-performed" className="text-sm font-medium">
                            Trabalho executado
                        </label>
                        <textarea
                            id="work-performed"
                            rows={3}
                            value={work}
                            onChange={(e) => setWork(e.target.value)}
                            aria-invalid={!!complete.errors.work_performed}
                            aria-describedby={complete.errors.work_performed ? 'work-performed-error' : undefined}
                            placeholder="O que foi feito no equipamento"
                            className={input}
                        />
                        {complete.errors.work_performed && (
                            <p id="work-performed-error" className="text-sm text-red-700">
                                {complete.errors.work_performed[0]}
                            </p>
                        )}
                    </div>
                    <div>
                        <ConfirmAction
                            label="Concluir reparo"
                            confirmLabel="Concluir agora"
                            busy={complete.busy}
                            tone="primary"
                            onOpen={complete.reset}
                            effect={
                                <>
                                    <p>
                                        <strong>Conclui o reparo:</strong> a ordem passa para “Pronta para retirada” e o atendimento pode entregá-la ao cliente.
                                    </p>
                                    {complete.error && (
                                        <p role="alert" className="mt-2 text-red-700">
                                            {complete.error}
                                        </p>
                                    )}
                                </>
                            }
                            onConfirm={() =>
                                complete.run(async () => {
                                    await post('/complete-repair', { work_performed: work });
                                    onChanged();
                                })
                            }
                        />
                    </div>
                </div>
            )}

            {order.status === 'ready_for_pickup' &&
                (isAdmin ? (
                    <div>
                        <ConfirmAction
                            label="Entregar equipamento"
                            confirmLabel="Registrar entrega"
                            busy={deliver.busy}
                            tone="primary"
                            onOpen={deliver.reset}
                            effect={
                                <>
                                    <p>
                                        <strong>Registra a entrega do equipamento reparado ao cliente.</strong> A ordem passa para “Entregue” e é encerrada.
                                    </p>
                                    {deliver.error && (
                                        <p role="alert" className="mt-2 text-red-700">
                                            {deliver.error}
                                        </p>
                                    )}
                                </>
                            }
                            onConfirm={() =>
                                deliver.run(async () => {
                                    await post('/deliver');
                                    onChanged();
                                })
                            }
                        />
                    </div>
                ) : (
                    <p className="text-sm text-slate-500">Pronta para retirada: a entrega ao cliente é feita pelo atendimento.</p>
                ))}

            {order.status === 'awaiting_return' &&
                (isAdmin ? (
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-slate-500">O serviço foi cancelado, mas o equipamento ainda está na assistência.</p>
                        <div>
                            <ConfirmAction
                                label="Devolver sem reparo"
                                confirmLabel="Registrar devolução"
                                busy={deliver.busy}
                                onOpen={deliver.reset}
                                effect={
                                    <>
                                        <p>
                                            <strong>Registra que o equipamento foi devolvido ao cliente sem reparo.</strong> A ordem passa para “Cancelada” e é encerrada.
                                        </p>
                                        {deliver.error && (
                                            <p role="alert" className="mt-2 text-red-700">
                                                {deliver.error}
                                            </p>
                                        )}
                                    </>
                                }
                                onConfirm={() =>
                                    deliver.run(async () => {
                                        await post('/deliver');
                                        onChanged();
                                    })
                                }
                            />
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-slate-500">Serviço cancelado: o equipamento aguarda devolução pelo atendimento.</p>
                ))}

            {isAdmin && CANCELABLE.includes(order.status) && (
                <div className="flex max-w-xl flex-col gap-2 border-t border-slate-200 pt-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="cancel-reason" className="text-sm font-medium">
                            Motivo do cancelamento
                        </label>
                        <textarea
                            id="cancel-reason"
                            rows={2}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            aria-invalid={!!cancel.errors.reason}
                            aria-describedby={cancel.errors.reason ? 'cancel-reason-error' : undefined}
                            className={input}
                        />
                        {cancel.errors.reason && (
                            <p id="cancel-reason-error" className="text-sm text-red-700">
                                {cancel.errors.reason[0]}
                            </p>
                        )}
                    </div>
                    <div>
                        <ConfirmAction
                            label="Cancelar serviço"
                            confirmLabel="Cancelar o serviço"
                            busy={cancel.busy}
                            onOpen={cancel.reset}
                            effect={
                                <>
                                    <p>
                                        <strong>Cancela o serviço.</strong> As peças reservadas são liberadas (o estoque físico não muda) e a ordem passa para “Aguardando devolução”: o equipamento continua na assistência até ser devolvido. Cancelar não é
                                        entregar.
                                    </p>
                                    {cancel.error && (
                                        <p role="alert" className="mt-2 text-red-700">
                                            {cancel.error}
                                        </p>
                                    )}
                                </>
                            }
                            onConfirm={() =>
                                cancel.run(async () => {
                                    await post('/cancel', { reason });
                                    onChanged();
                                })
                            }
                        />
                    </div>
                </div>
            )}

            {order.status === 'delivered' && <p className="text-sm">Ordem encerrada: equipamento entregue.</p>}
            {order.status === 'canceled' && <p className="text-sm">Ordem encerrada: equipamento devolvido sem reparo.</p>}
        </div>
    );
}
