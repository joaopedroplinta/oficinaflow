import { ErrorSummary } from './ui';
import type { FormEvent } from 'react';
import { useSubmit } from '../hooks/use-submit';
import type { Customer } from '../types/customer';
import { Field } from './field';

export type CustomerValues = { name: string; phone: string; email: string | null };

type Props = { initial?: Customer; submitLabel: string; onSubmit: (values: CustomerValues) => Promise<void> };

export function CustomerForm({ initial, submitLabel, onSubmit }: Props) {
    const { busy, errors, formError, run } = useSubmit();

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        void run(() => onSubmit({ name: String(f.get('name')), phone: String(f.get('phone')), email: String(f.get('email')) || null }));
    }

    return (
        <form onSubmit={submit} className="flex max-w-md flex-col gap-3">
            <ErrorSummary errors={errors} />
            <Field label="Nome" name="name" defaultValue={initial?.name} required error={errors.name?.[0]} />
            <Field label="Telefone" name="phone" type="tel" defaultValue={initial?.phone} required error={errors.phone?.[0]} />
            <Field label="E-mail (opcional)" name="email" type="email" defaultValue={initial?.email ?? ''} error={errors.email?.[0]} />
            {formError && (
                <p role="alert" className="text-sm text-red-700">
                    {formError}
                </p>
            )}
            <button disabled={busy} className="self-start btn btn-primary disabled:opacity-50">
                {busy ? 'Salvando…' : submitLabel}
            </button>
        </form>
    );
}
