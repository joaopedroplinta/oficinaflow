export type Part = {
    id: number;
    sku: string;
    name: string;
    sale_price_cents: number;
    on_hand: number;
    reserved_quantity: number;
    available: number;
    active: boolean;
};

export type InventoryMovement = {
    id: number;
    kind: 'receipt' | 'consumption' | 'return';
    kind_label: string;
    quantity: number;
    reason: string;
    author: { id: number; name: string };
    created_at: string;
};
