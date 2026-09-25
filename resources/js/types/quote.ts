export type QuoteItem = {
    id: number;
    kind: 'part' | 'service';
    kind_label: string;
    part_id: number | null;
    description: string;
    quantity: number;
    unit_price_cents: number;
    line_total_cents: number;
    position: number;
};

export type Quote = {
    id: number;
    work_order_id: number;
    version: number;
    status: 'draft' | 'issued' | 'superseded';
    status_label: string;
    notes: string | null;
    total_cents: number;
    issued_at: string | null;
    items: QuoteItem[];
};
