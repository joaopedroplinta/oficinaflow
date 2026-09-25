import type { Customer } from './customer';
import type { Quote } from './quote';

export type WorkOrderStatus =
    | 'received'
    | 'diagnosing'
    | 'awaiting_approval'
    | 'approved'
    | 'in_progress'
    | 'ready_for_pickup'
    | 'delivered'
    | 'awaiting_return'
    | 'canceled';

// Só para o filtro da listagem; o rótulo exibido em cada ordem vem da API (`status_label`).
export const statusOptions: { value: WorkOrderStatus; label: string }[] = [
    { value: 'received', label: 'Recebida' },
    { value: 'diagnosing', label: 'Em diagnóstico' },
    { value: 'awaiting_approval', label: 'Aguardando aprovação' },
    { value: 'approved', label: 'Aprovada' },
    { value: 'in_progress', label: 'Em reparo' },
    { value: 'ready_for_pickup', label: 'Pronta para retirada' },
    { value: 'delivered', label: 'Entregue' },
    { value: 'awaiting_return', label: 'Aguardando devolução' },
    { value: 'canceled', label: 'Cancelada' },
];

export type WorkOrderKind = 'regular' | 'warranty' | 'rework';

export const kindOptions: { value: WorkOrderKind; label: string }[] = [
    { value: 'regular', label: 'Normal' },
    { value: 'warranty', label: 'Garantia' },
    { value: 'rework', label: 'Retrabalho' },
];

export type PartReservation = {
    id: number;
    quote_item_id: number;
    part: { id: number; sku: string; name: string };
    quantity: number;
    status: 'active' | 'released' | 'consumed';
    status_label: string;
    reserved_at: string;
};

// Peça da versão aprovada que ainda não tem reserva.
// `needed` = o que ainda falta reservar; `reserved` de `quantity` já estão reservadas (reserva parcial).
export type PendingPart = { part_id: number; sku: string; name: string; needed: number; reserved: number; quantity: number; available: number };

export type WorkOrder = {
    id: number;
    number: number;
    status: WorkOrderStatus;
    status_label: string;
    // Só vem na listagem: ordem aprovada com peça ainda sem reserva (indicador, não um estado).
    awaiting_parts?: boolean;
    // Garantia/retrabalho: nova ordem ligada à ordem entregue de origem.
    kind: WorkOrderKind;
    kind_label: string;
    origin?: { id: number; number: number } | null;
    warranty_until: string | null;
    customer: Customer;
    device_id: number;
    device: { category: string; brand: string; model: string; serial_number: string | null };
    reported_problem: string;
    intake_condition: string;
    accessories: string;
    diagnosis: string | null;
    work_performed: string | null;
    cancellation_reason: string | null;
    received_at: string;
    started_at: string | null;
    ready_at: string | null;
    delivered_at: string | null;
};

export type WorkOrderDetail = WorkOrder & {
    assigned_technician: { id: number; name: string } | null;
    // Versão atual mais os adendos aprovados.
    total_cents: number;
    current_quote: Quote | null;
    reservations: PartReservation[];
    pending: PendingPart[];
    // Adendo em rascunho ou emitido sem decisão: bloqueia a conclusão do reparo.
    has_open_addendum: boolean;
    // Reparo em andamento: consumidas até agora, por peça (o cancelamento pede o destino de cada uma).
    consumed_parts: { part_id: number; sku: string; name: string; quantity: number }[];
    // Reparo em andamento: reservadas e ainda não consumidas.
    to_consume: { part_id: number; sku: string; name: string; quantity: number }[];
    returns: { id: number; number: number; kind: WorkOrderKind; kind_label: string; status: WorkOrderStatus; status_label: string }[];
};

export type WorkOrderEvent = {
    id: number;
    type: string;
    type_label: string;
    from_status: WorkOrderStatus | null;
    from_status_label: string | null;
    to_status: WorkOrderStatus | null;
    to_status_label: string | null;
    metadata: { channel_label?: string };
    actor: { id: number; name: string };
    created_at: string;
};

export type Attachment = {
    id: number;
    original_name: string;
    mime_type: string;
    is_image: boolean;
    size_bytes: number;
    uploader: { id: number; name: string };
    url: string;
    created_at: string;
};
