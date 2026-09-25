import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { api } from './api';
import type { Data } from './types/api';

export type User = { id: number; name: string; email: string; role: 'admin' | 'technician'; role_label: string };

type Auth = {
    user: User | null;
    isAdmin: boolean;
    notice: string;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
};

const Ctx = createContext<Auth>(null!);
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [ready, setReady] = useState(false);
    // Por que o usuário voltou ao login (sessão expirada ou conta desativada).
    const [notice, setNotice] = useState('');
    const loggedIn = useRef(false);

    useEffect(() => {
        api<Data<User>>('GET', '/me').then((r) => setUser(r.data)).catch(() => setUser(null)).finally(() => setReady(true));
    }, []);

    useEffect(() => {
        const expired = (e: Event) => {
            // Só quem estava logado precisa de explicação; na primeira visita o /me sem sessão é esperado.
            if (loggedIn.current) {
                const detail = (e as CustomEvent<string>).detail;
                setNotice(detail && detail !== 'Unauthenticated.' ? detail : 'Sua sessão expirou. Entre novamente.');
            }
            setUser(null);
        };
        window.addEventListener('auth:expired', expired);
        return () => window.removeEventListener('auth:expired', expired);
    }, []);

    const login = async (email: string, password: string) => {
        setUser((await api<Data<User>>('POST', '/login', { email, password })).data);
        setNotice('');
    };
    const logout = async () => {
        // Mesmo que a chamada falhe (rede, sessão já expirada), o usuário sai da tela.
        await api('POST', '/logout').catch(() => undefined);
        setNotice('');
        setUser(null);
    };

    loggedIn.current = user !== null;

    if (!ready) return <p className="p-6 text-slate-500">Carregando…</p>;
    return <Ctx.Provider value={{ user, isAdmin: user?.role === 'admin', notice, login, logout }}>{children}</Ctx.Provider>;
}
