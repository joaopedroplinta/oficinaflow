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

export type WorkOrder = {
    id: number;
    number: number;
    status: WorkOrderStatus;
    status_label: string;
    customer: Customer;
    device_id: number;
    device: { category: string; brand: string; model: string; serial_number: string | null };
    reported_problem: string;
    intake_condition: string;
    accessories: string;
    diagnosis: string | null;
    received_at: string;
};

// Reservas e pendências chegam vazias até o card de estoque (semana 4).
export type WorkOrderDetail = WorkOrder & {
    assigned_technician: { id: number; name: string } | null;
    current_quote: Quote | null;
    reservations: never[];
    pending: never[];
};

export type WorkOrderEvent = {
    id: number;
    type: string;
    type_label: string;
    from_status: WorkOrderStatus | null;
    from_status_label: string | null;
    to_status: WorkOrderStatus | null;
    to_status_label: string | null;
    actor: { id: number; name: string };
    created_at: string;
};
