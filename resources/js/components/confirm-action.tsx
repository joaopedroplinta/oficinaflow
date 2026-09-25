import { useState, type ReactNode } from 'react';

type Props = {
    label: string;
    confirmLabel: string;
    effect: ReactNode;
    busy: boolean;
    onConfirm: () => Promise<boolean>;
    tone?: 'primary' | 'secondary';
    onOpen?: () => void;
};

// Ação com efeito colateral: explica o que vai acontecer e só executa depois da confirmação.
export function ConfirmAction({ label, confirmLabel, effect, busy, onConfirm, tone = 'secondary', onOpen }: Props) {
    const [open, setOpen] = useState(false);

    if (!open)
        return (
            <button
                onClick={() => {
                    onOpen?.();
                    setOpen(true);
                }}
                className={tone === 'primary' ? 'rounded bg-slate-900 px-4 py-2 text-white' : 'rounded border border-slate-300 px-4 py-2'}
            >
                {label}
            </button>
        );

    return (
        <div role="group" aria-label={label} className="flex max-w-xl flex-col gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
            <div>{effect}</div>
            <div className="flex gap-3">
                <button
                    disabled={busy}
                    onClick={async () => {
                        if (await onConfirm()) setOpen(false);
                    }}
                    className="rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                >
                    {busy ? 'Aguarde…' : confirmLabel}
                </button>
                <button disabled={busy} onClick={() => setOpen(false)} className="rounded border border-slate-300 bg-white px-4 py-2 disabled:opacity-50">
                    Voltar
                </button>
            </div>
        </div>
    );
}
