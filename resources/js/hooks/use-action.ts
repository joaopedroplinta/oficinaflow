import { useState } from 'react';
import { ApiError, errorMessage, fieldErrors } from '../api';

// Ação que muda a ordem. 409 = tela velha: entrega a mensagem a quem recarrega (o componente pode sumir
// no reload, então a mensagem precisa viver mais que ele); 422 volta por campo.
export function useAction(onStale: (message: string, code?: string) => void) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    // Detalhes estruturados do 409 (ex.: quais peças faltam).
    const [details, setDetails] = useState<unknown>(null);

    async function run(fn: () => Promise<void>): Promise<boolean> {
        setBusy(true);
        setError('');
        setErrors({});
        setDetails(null);
        try {
            await fn();
            return true;
        } catch (e) {
            const fe = fieldErrors(e);
            setErrors(fe);
            const message = Object.keys(fe).length === 0 ? errorMessage(e) : Object.values(fe).flat().join(' ');
            setError(message);
            if (e instanceof ApiError) setDetails(e.body.details ?? null);
            if (e instanceof ApiError && e.status === 409) onStale(message, e.body.code);
            return false;
        } finally {
            setBusy(false);
        }
    }

    return { busy, error, errors, details, run };
}
