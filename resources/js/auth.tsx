import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { api } from './api';
import type { Data } from './types/api';

export type User = { id: number; name: string; email: string; role: 'admin' | 'technician'; role_label: string };

type Auth = {
    user: User | null;
    isAdmin: boolean;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
};

const Ctx = createContext<Auth>(null!);
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        api<Data<User>>('GET', '/me').then((r) => setUser(r.data)).catch(() => setUser(null)).finally(() => setReady(true));
    }, []);

    useEffect(() => {
        const expired = () => setUser(null);
        window.addEventListener('auth:expired', expired);
        return () => window.removeEventListener('auth:expired', expired);
    }, []);

    const login = async (email: string, password: string) => setUser((await api<Data<User>>('POST', '/login', { email, password })).data);
    const logout = async () => {
        await api('POST', '/logout');
        setUser(null);
    };

    if (!ready) return null;
    return <Ctx.Provider value={{ user, isAdmin: user?.role === 'admin', login, logout }}>{children}</Ctx.Provider>;
}
