import { formatBRL } from '../format';
import type { Quote } from '../types/quote';

export function QuoteItemsTable({ quote }: { quote: Quote }) {
    if (quote.items.length === 0) return <p className="text-sm text-slate-500">Esta versão não tem itens.</p>;
    // Custo e margem só chegam para o atendimento.
    const withCost = quote.cost_cents !== undefined;

    return (
        <div className="table-frame">
            <table className="data-table">
                <thead className="border-b border-slate-200 text-slate-500">
                    <tr>
                        <th className="py-2 pr-4 font-medium">Tipo</th>
                        <th className="py-2 pr-4 font-medium">Descrição</th>
                        <th className="py-2 pr-4 text-right font-medium">Qtd.</th>
                        <th className="py-2 pr-4 text-right font-medium">Unitário</th>
                        <th className="py-2 text-right font-medium">Total</th>
                        {withCost && <th className="py-2 pl-4 text-right font-medium">Custo médio e margem</th>}
                    </tr>
                </thead>
                <tbody>
                    {quote.items.map((i) => (
                        <tr key={i.id} className="border-b border-slate-100">
                            <td className="py-2 pr-4">{i.kind_label}</td>
                            <td className="py-2 pr-4">{i.description}</td>
                            <td className="py-2 pr-4 text-right">{i.quantity}</td>
                            <td className="py-2 pr-4 text-right">{formatBRL(i.unit_price_cents)}</td>
                            <td className="py-2 text-right">{formatBRL(i.line_total_cents)}</td>
                            {withCost && (
                                <td className="py-2 pl-4 text-right text-slate-600">
                                    {i.kind !== 'part'
                                        ? '—'
                                        : i.unit_cost_cents == null
                                          ? 'sem custo'
                                          : `${formatBRL(i.unit_cost_cents)}, margem ${formatBRL(i.line_margin_cents ?? 0)}`}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr>
                        <td colSpan={4} className="py-2 pr-4 text-right font-medium">
                            Total
                        </td>
                        <td className="py-2 text-right font-semibold">{formatBRL(quote.total_cents)}</td>
                        {withCost && (
                            <td className="py-2 pl-4 text-right text-slate-600">
                                custo das peças {formatBRL(quote.cost_cents ?? 0)}, margem {formatBRL(quote.margin_cents ?? 0)}
                                {quote.cost_complete === false && ' (parcial: há peça sem custo)'}
                            </td>
                        )}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

export function QuoteDecisionSummary({ quote }: { quote: Quote }) {
    const d = quote.decision;
    if (!d) return null;

    return (
        <p className="text-sm">
            <strong>{d.decision_label}</strong> por {d.customer_name} ({d.channel_label}), registrado por {d.recorded_by.name}.{d.notes ? ` ${d.notes}` : ''}
        </p>
    );
}
