import { formatDateTime } from '../format';
import { Loading } from './loading';
import { useLoad } from '../hooks/use-load';
import type { Data } from '../types/api';
import type { WorkOrderEvent } from '../types/work-order';

export function WorkOrderTimeline({ orderId }: { orderId: number }) {
    const { data, loading, error, reload } = useLoad<Data<WorkOrderEvent[]>>(`/work-orders/${orderId}/events`);

    if (loading) return <Loading small />;
    if (error || !data)
        return (
            <p role="alert" className="text-sm text-red-700">
                {error}{' '}
                <button onClick={reload} className="underline">
                    Tentar de novo
                </button>
            </p>
        );
    if (data.data.length === 0) return <p className="text-sm text-slate-500">Nenhum evento registrado.</p>;

    return (
        <ol className="flex flex-col gap-3 border-l border-slate-200 pl-4">
            {data.data.map((e) => (
                <li key={e.id} className="text-sm">
                    <p className="font-medium">
                        {e.type_label}
                        {e.metadata.channel_label ? ` (${e.metadata.channel_label})` : ''}
                    </p>
                    {e.to_status_label && (
                        <p className="text-slate-600">{e.from_status_label ? `${e.from_status_label} → ${e.to_status_label}` : e.to_status_label}</p>
                    )}
                    <p className="text-slate-500">
                        {e.actor.name}, {formatDateTime(e.created_at)}
                    </p>
                </li>
            ))}
        </ol>
    );
}
