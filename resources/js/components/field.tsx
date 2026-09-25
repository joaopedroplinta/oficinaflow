import { useId, type InputHTMLAttributes, type TextareaHTMLAttributes } from 'react';

type Props = { label: string; name: string; error?: string } & InputHTMLAttributes<HTMLInputElement>;

export function Field({ label, error, ...input }: Props) {
    const id = useId();

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <input
                id={id}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900"
                {...input}
            />
            {error && (
                <p id={`${id}-error`} className="text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

type TextAreaProps = { label: string; name: string; error?: string } & TextareaHTMLAttributes<HTMLTextAreaElement>;

export function TextArea({ label, error, ...input }: TextAreaProps) {
    const id = useId();

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <textarea
                id={id}
                rows={3}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900"
                {...input}
            />
            {error && (
                <p id={`${id}-error`} className="text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
