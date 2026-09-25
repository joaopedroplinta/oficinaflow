export const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' });

export const formatBRL = (cents: number) => (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

// "12,50" → 1250; NaN quando não for um valor em reais com até duas casas.
export const toCents = (reais: string) => {
    const v = reais.trim();
    return /^\d+([.,]\d{1,2})?$/.test(v) ? Math.round(Number(v.replace(',', '.')) * 100) : NaN;
};

export const centsToReais = (cents: number) => (cents / 100).toFixed(2).replace('.', ',');
