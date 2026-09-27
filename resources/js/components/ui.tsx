import { useEffect, useRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from 'react-router';
import type { WorkOrder } from '../types/work-order';
import { formatDateTime } from '../format';

export function Button({ variant = 'secondary', className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' }) {
    return <button className={`btn btn-${variant} ${className}`} {...props} />;
}
export function Alert({ children, tone = 'error' }: { children: ReactNode; tone?: 'error' | 'success' | 'info' }) {
    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`alert alert-${tone}`}>
            {children}
        </div>
    );
}
export function StatusBadge({ status, children }: { status: string; children: ReactNode }) {
    return <span className={`status-badge status-${status}`}>{children}</span>;
}
export function Pagination({ page, last, total, noun, onPage }: { page: number; last: number; total: number; noun: string; onPage: (page: number) => void }) {
    return (
        <nav aria-label="Paginação" className="pagination">
            <Button disabled={page <= 1} onClick={() => onPage(page - 1)}>
                Anterior
            </Button>
            <span>
                Página {page} de {last}{' '}
                <span className="pagination-total">
                    · {total} {noun}
                </span>
            </span>
            <Button disabled={page >= last} onClick={() => onPage(page + 1)}>
                Próxima
            </Button>
        </nav>
    );
}
export function OrderList({ orders, returnTo }: { orders: WorkOrder[]; returnTo: string }) {
    const link = (o: WorkOrder) => (
        <Link className="order-link" to={`/ordens/${o.id}`} state={{ returnTo }}>
            #{o.number}
        </Link>
    );
    const badge = (o: WorkOrder) => (
        <>
            <StatusBadge status={o.status}>{o.status_label}</StatusBadge>
            {o.awaiting_parts && <StatusBadge status="awaiting_parts">Aguardando peças</StatusBadge>}
            {o.kind !== 'regular' && <StatusBadge status="warranty">{o.kind_label}</StatusBadge>}
        </>
    );
    return (
        <>
            <div className="table-frame desktop-orders">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>Ordem</th>
                            <th>Cliente / equipamento</th>
                            <th>Situação</th>
                            <th>Responsável</th>
                            <th>Entrada</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.map((o) => (
                            <tr key={o.id}>
                                <td>{link(o)}</td>
                                <td>
                                    <strong className="font-medium">{o.customer.name}</strong>
                                    <span className="block text-slate-500">
                                        {o.device.brand} {o.device.model}
                                    </span>
                                </td>
                                <td>
                                    <div className="flex flex-wrap gap-1.5">{badge(o)}</div>
                                </td>
                                <td>{o.assigned_technician?.name ?? 'Não atribuído'}</td>
                                <td className="whitespace-nowrap">{formatDateTime(o.received_at)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mobile-orders">
                {orders.map((o) => (
                    <article className="order-card" key={o.id}>
                        <div className="flex items-center justify-between gap-2">
                            {link(o)}
                            <span className="text-xs text-slate-500">{formatDateTime(o.received_at)}</span>
                        </div>
                        <p className="font-semibold">{o.customer.name}</p>
                        <p className="text-sm text-slate-600">
                            {o.device.brand} {o.device.model}
                        </p>
                        <div className="flex flex-wrap gap-1.5">{badge(o)}</div>
                        <p className="text-sm text-slate-500">{o.assigned_technician?.name ?? 'Sem responsável técnico'}</p>
                    </article>
                ))}
            </div>
        </>
    );
}
export function Section({ id, title, children, open = false }: { id: string; title: string; children: ReactNode; open?: boolean }) {
    return (
        <details className="order-section" id={id} open={open || undefined}>
            <summary>
                <h2>{title}</h2>
                <span aria-hidden="true">+</span>
            </summary>
            <div className="section-content">{children}</div>
        </details>
    );
}
export function ErrorSummary({ errors }: { errors: Record<string, string[]> }) {
    const ref = useRef<HTMLDivElement>(null);
    const entries = Object.entries(errors);
    useEffect(() => {
        if (Object.keys(errors).length) ref.current?.focus();
    }, [errors]);
    if (!entries.length) return null;
    const focusField = (name: string) => {
        const root = ref.current?.closest('form') ?? ref.current?.parentElement;
        const field = root?.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"]`) ?? root?.querySelector<HTMLElement>('[aria-invalid="true"]');
        field?.focus();
    };
    return (
        <div ref={ref} tabIndex={-1} role="alert" className="alert alert-error col-span-full">
            <p className="font-semibold">Revise os campos para continuar</p>
            <ul>
                {entries.map(([name, messages]) => (
                    <li key={name}>
                        <button type="button" className="underline" onClick={() => focusField(name)}>
                            {messages[0]}
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
