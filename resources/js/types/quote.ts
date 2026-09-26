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
    // Só o atendimento, peça: custo médio ATUAL e margem da linha (não vão para o PDF).
    unit_cost_cents?: number | null;
    line_margin_cents?: number | null;
};

export type QuoteDecision = {
    decision: 'approved' | 'rejected';
    decision_label: string;
    customer_name: string;
    channel: string;
    channel_label: string;
    decided_at: string;
    recorded_by: { id: number; name: string };
    notes: string | null;
};

export type Quote = {
    id: number;
    work_order_id: number;
    // Adendo (itens descobertos durante o reparo) tem numeração própria e aponta para a versão base.
    kind: 'version' | 'addendum';
    kind_label: string;
    base_quote_id: number | null;
    version: number;
    status: 'draft' | 'issued' | 'superseded';
    status_label: string;
    notes: string | null;
    total_cents: number;
    issued_at: string | null;
    items: QuoteItem[];
    // Só o atendimento; `cost_complete` falso = alguma peça ainda sem custo médio (valores parciais).
    cost_cents?: number;
    margin_cents?: number;
    cost_complete?: boolean;
    decision: QuoteDecision | null;
};
