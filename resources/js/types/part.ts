export type Part = {
    id: number;
    sku: string;
    name: string;
    sale_price_cents: number;
    on_hand: number;
    reserved_quantity: number;
    available: number;
    active: boolean;
    // Só o atendimento recebe custo e margem.
    average_cost_cents?: number | null;
    margin_cents?: number | null;
    margin_percent?: number | null;
};

export type InventoryMovement = {
    id: number;
    kind: 'receipt' | 'consumption' | 'return';
    kind_label: string;
    quantity: number;
    reason: string;
    unit_cost_cents?: number | null;
    supplier?: { id: number; name: string } | null;
    author: { id: number; name: string };
    created_at: string;
};
