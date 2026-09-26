export type PurchaseOrderStatus = 'open' | 'partially_received' | 'received' | 'canceled';

export const purchaseStatusOptions: { value: PurchaseOrderStatus; label: string }[] = [
    { value: 'open', label: 'Aberto' },
    { value: 'partially_received', label: 'Recebido em parte' },
    { value: 'received', label: 'Recebido' },
    { value: 'canceled', label: 'Cancelado' },
];

export type PurchaseOrderItem = {
    id: number;
    part: { id: number; sku: string; name: string };
    quantity: number;
    unit_cost_cents: number;
    line_total_cents: number;
    received_quantity: number;
    remaining_quantity: number;
};

export type PurchaseOrder = {
    id: number;
    number: number;
    status: PurchaseOrderStatus;
    status_label: string;
    supplier?: { id: number; name: string };
    notes: string | null;
    total_cents: number;
    created_by?: { id: number; name: string };
    created_at: string;
    canceled_at: string | null;
    cancellation_reason: string | null;
    items?: PurchaseOrderItem[];
};
