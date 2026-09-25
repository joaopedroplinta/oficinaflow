import type { Customer } from './customer';

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

// Orçamento, reservas e pendências chegam vazios até os cards das semanas 3 e 4.
export type WorkOrderDetail = WorkOrder & {
    assigned_technician: { id: number; name: string } | null;
    current_quote: null;
    reservations: never[];
    pending: never[];
};
