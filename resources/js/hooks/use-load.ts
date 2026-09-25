import { useEffect, useState } from 'react';
import { api, errorMessage } from '../api';

export function useLoad<T>(path: string) {
    const [state, setState] = useState<{ data: T | null; loading: boolean; error: string }>({ data: null, loading: true, error: '' });
    const [tick, setTick] = useState(0);

    useEffect(() => {
        let alive = true;
        setState((s) => ({ ...s, loading: true, error: '' }));
        api<T>('GET', path)
            .then((data) => alive && setState({ data, loading: false, error: '' }))
            .catch((e) => alive && setState({ data: null, loading: false, error: errorMessage(e) }));
        return () => {
            alive = false;
        };
    }, [path, tick]);

    return { ...state, reload: () => setTick((t) => t + 1) };
}
