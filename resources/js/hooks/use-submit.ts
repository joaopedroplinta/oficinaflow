import { useState } from 'react';
import { errorMessage, fieldErrors } from '../api';

export function useSubmit() {
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [formError, setFormError] = useState('');

    async function run(fn: () => Promise<void>): Promise<boolean> {
        setBusy(true);
        setErrors({});
        setFormError('');
        try {
            await fn();
            return true;
        } catch (e) {
            const fe = fieldErrors(e);
            setErrors(fe);
            if (Object.keys(fe).length === 0) setFormError(errorMessage(e));
            return false;
        } finally {
            setBusy(false);
        }
    }

    return { busy, errors, formError, run };
}
