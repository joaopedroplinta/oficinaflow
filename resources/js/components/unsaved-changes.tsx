import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useBlocker } from 'react-router';
import { Button } from './ui';

const Context = createContext<{ register: (id: string, dirty: boolean) => void; confirmExit: () => boolean }>({ register: () => {}, confirmExit: () => true });
export function UnsavedChangesProvider({ children }: { children: ReactNode }) {
    const dirty = useRef(new Set<string>());
    const [hasChanges, setHasChanges] = useState(false);
    const register = useCallback((id: string, value: boolean) => {
        if (value) dirty.current.add(id);
        else dirty.current.delete(id);
        setHasChanges(dirty.current.size > 0);
    }, []);
    const blocker = useBlocker(
        ({ currentLocation, nextLocation }) =>
            dirty.current.size > 0 && (currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search),
    );
    const dialog = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const beforeUnload = (e: BeforeUnloadEvent) => {
            if (dirty.current.size) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        return () => window.removeEventListener('beforeunload', beforeUnload);
    }, []);
    useEffect(() => {
        if (blocker.state === 'blocked') dialog.current?.showModal();
        else dialog.current?.close();
    }, [blocker.state]);
    const confirmExit = () => !hasChanges || window.confirm('Há alterações não salvas. Deseja sair e descartá-las?');
    return (
        <Context.Provider value={{ register, confirmExit }}>
            {children}
            <dialog ref={dialog} className="dialog" aria-labelledby="unsaved-title" onCancel={() => blocker.state === 'blocked' && blocker.reset()}>
                <h2 id="unsaved-title" className="text-lg">
                    Descartar alterações?
                </h2>
                <p className="my-4 text-sm text-slate-600">Há alterações não salvas. Volte para salvar ou continue para descartá-las.</p>
                <div className="flex flex-wrap gap-3">
                    <Button autoFocus variant="primary" onClick={() => blocker.state === 'blocked' && blocker.reset()}>
                        Continuar editando
                    </Button>
                    <Button
                        onClick={() => {
                            dirty.current.clear();
                            setHasChanges(false);
                            if (blocker.state === 'blocked') blocker.proceed();
                        }}
                    >
                        Descartar e sair
                    </Button>
                </div>
            </dialog>
        </Context.Provider>
    );
}
export function useUnsavedChanges(dirty: boolean) {
    const id = useId();
    const { register } = useContext(Context);
    useEffect(() => {
        register(id, dirty);
        return () => register(id, false);
    }, [id, dirty, register]);
    return () => register(id, false);
}
export const useConfirmExit = () => useContext(Context).confirmExit;
