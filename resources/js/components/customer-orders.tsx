import { useState } from 'react';
import { useLoad } from '../hooks/use-load';
import type { Page } from '../types/api';
import type { WorkOrder } from '../types/work-order';
import { Alert, Button, OrderList, Pagination } from './ui';

export function CustomerOrders({ customerId }: { customerId: number }) {
    const [scope, setScope] = useState('open');
    const [page, setPage] = useState(1);
    const { data, loading, error, reload } = useLoad<Page<WorkOrder>>(`/work-orders?customer_id=${customerId}&scope=${scope}&page=${page}`);
    return (
        <section className="flex flex-col gap-4" aria-labelledby="customer-orders">
            <div className="page-heading">
                <h2 id="customer-orders">Ordens deste cliente</h2>
                <div className="flex gap-2">
                    <Button
                        aria-pressed={scope === 'open'}
                        variant={scope === 'open' ? 'primary' : 'secondary'}
                        onClick={() => {
                            setScope('open');
                            setPage(1);
                        }}
                    >
                        Em aberto
                    </Button>
                    <Button
                        aria-pressed={scope === 'closed'}
                        variant={scope === 'closed' ? 'primary' : 'secondary'}
                        onClick={() => {
                            setScope('closed');
                            setPage(1);
                        }}
                    >
                        Histórico
                    </Button>
                </div>
            </div>
            {loading && <p role="status">Carregando ordens…</p>}
            {error && (
                <Alert>
                    {error}{' '}
                    <button onClick={reload} className="underline">
                        Tentar novamente
                    </button>
                </Alert>
            )}
            {!loading &&
                data &&
                (data.data.length ? (
                    <>
                        <OrderList orders={data.data} returnTo={`/clientes/${customerId}`} />
                        <Pagination page={page} last={data.meta.last_page} total={data.meta.total} noun="ordens" onPage={setPage} />
                    </>
                ) : (
                    <p className="panel text-sm text-slate-500">Nenhuma ordem {scope === 'open' ? 'em aberto' : 'encerrada'} para este cliente.</p>
                ))}
        </section>
    );
}
