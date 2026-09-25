import { useState } from 'react';
import { ApiError, errorMessage, fieldErrors } from '../api';

// Ação que muda a ordem. 409 = tela velha: mostra a mensagem e recarrega; 422 volta por campo.
export function useAction(onStale: () => void) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    async function run(fn: () => Promise<void>): Promise<boolean> {
        setBusy(true);
        setError('');
        setErrors({});
        try {
            await fn();
            return true;
        } catch (e) {
            const fe = fieldErrors(e);
            setErrors(fe);
            if (Object.keys(fe).length === 0) setError(errorMessage(e));
            else setError(Object.values(fe).flat().join(' '));
            if (e instanceof ApiError && e.status === 409) onStale();
            return false;
        } finally {
            setBusy(false);
        }
    }

    return { busy, error, errors, run };
}
