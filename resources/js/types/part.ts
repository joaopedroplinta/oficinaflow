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
