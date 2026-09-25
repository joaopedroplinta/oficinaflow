import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { api } from './api';

export type User = { id: number; name: string; email: string; role: 'admin' | 'technician' };

type Auth = {
    user: User | null;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
};

const Ctx = createContext<Auth>(null!);
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        api<User>('GET', '/me').then(setUser).catch(() => setUser(null)).finally(() => setReady(true));
    }, []);

    const login = async (email: string, password: string) => setUser(await api<User>('POST', '/login', { email, password }));
    const logout = async () => {
        await api('POST', '/logout');
        setUser(null);
    };

    if (!ready) return null;
    return <Ctx.Provider value={{ user, login, logout }}>{children}</Ctx.Provider>;
}
