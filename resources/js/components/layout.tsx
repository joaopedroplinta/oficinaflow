import { NavLink, Outlet } from 'react-router';
import { useAuth } from '../auth';

const link = ({ isActive }: { isActive: boolean }) => (isActive ? 'font-semibold underline' : 'text-slate-600 hover:underline');

export function Layout() {
    const { user, logout } = useAuth();

    return (
        <div className="mx-auto max-w-5xl px-4">
            <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-slate-200 py-4">
                <nav className="flex items-center gap-5 text-sm">
                    <strong className="text-base">OficinaFlow</strong>
                    <NavLink to="/" end className={link}>
                        Ordens
                    </NavLink>
                    <NavLink to="/clientes" className={link}>
                        Clientes
                    </NavLink>
                </nav>
                <div className="flex items-center gap-4 text-sm">
                    <span>
                        {user!.name} · {user!.role_label}
                    </span>
                    <button onClick={logout} className="underline">
                        Sair
                    </button>
                </div>
            </header>
            <main className="py-6">
                <Outlet />
            </main>
        </div>
    );
}
