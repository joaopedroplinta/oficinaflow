import { useEffect, useState } from 'react';
import { api, errorMessage } from '../api';

type State<T> = { data: T | null; dataPath: string; loading: boolean; error: string };

export function useLoad<T>(path: string) {
    const [state, setState] = useState<State<T>>({ data: null, dataPath: '', loading: true, error: '' });
    const [tick, setTick] = useState(0);

    useEffect(() => {
        let alive = true;
        setState((s) => ({ ...s, loading: true, error: '' }));
        api<T>('GET', path)
            .then((data) => alive && setState({ data, dataPath: path, loading: false, error: '' }))
            // Recarregar o mesmo recurso e falhar mantém o que estava na tela (com o erro ao lado); mudar de recurso
            // (ou de filtro/página) e falhar não pode deixar o dado antigo passando por atual.
            .catch(
                (e) => alive && setState((s) => ({ data: s.dataPath === path ? s.data : null, dataPath: s.dataPath, loading: false, error: errorMessage(e) })),
            );
        return () => {
            alive = false;
        };
    }, [path, tick]);

    return {
        data: state.data,
        loading: state.loading,
        error: state.error,
        reload: () => {
            setState((s) => ({ ...s, loading: true }));
            setTick((t) => t + 1);
        },
    };
}
