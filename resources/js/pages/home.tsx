import { useAuth } from '../auth';

export default function Home() {
    const { user, logout } = useAuth();

    return (
        <div className="mx-auto max-w-5xl px-4">
            <header className="flex items-center justify-between border-b border-slate-200 py-4">
                <strong>OficinaFlow</strong>
                <div className="flex items-center gap-4 text-sm">
                    <span>{user!.name} · {user!.role_label}</span>
                    <button onClick={logout} className="underline">Sair</button>
                </div>
            </header>
            <p className="py-8 text-slate-500">Nenhuma ordem de serviço ainda.</p>
        </div>
    );
}
