import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

type Props = {
    label: string;
    confirmLabel: string;
    effect: ReactNode;
    busy: boolean;
    onConfirm: () => Promise<boolean>;
    tone?: 'primary' | 'secondary';
    onOpen?: () => void;
    // Botão de abrir indisponível (o servidor continua sendo a rede de segurança); `disabledReason` é o id do texto que explica por quê.
    disabled?: boolean;
    disabledReason?: string;
};

// Ação com efeito colateral: explica o que vai acontecer e só executa depois da confirmação.
// Foco: ao abrir vai para o painel; ao fechar volta para o botão que abriu (senão cai no <body>).
export function ConfirmAction({ label, confirmLabel, effect, busy, onConfirm, tone = 'secondary', onOpen, disabled, disabledReason }: Props) {
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const opened = useRef(false);
    const effectId = useId();

    useEffect(() => {
        if (open) {
            opened.current = true;
            panel.current?.focus();
        } else if (opened.current) {
            opened.current = false;
            trigger.current?.focus();
        }
    }, [open]);

    if (!open)
        return (
            <button
                ref={trigger}
                disabled={disabled}
                aria-describedby={disabled ? disabledReason : undefined}
                onClick={() => {
                    onOpen?.();
                    setOpen(true);
                }}
                className={(tone === 'primary' ? 'rounded bg-slate-900 px-4 py-2 text-white' : 'rounded border border-slate-300 px-4 py-2') + ' disabled:opacity-50'}
            >
                {label}
            </button>
        );

    return (
        <div ref={panel} tabIndex={-1} role="group" aria-label={label} aria-describedby={effectId} className="flex max-w-xl flex-col gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm focus-visible:outline-2 focus-visible:outline-slate-900">
            <div id={effectId}>{effect}</div>
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
