import { useState, type FormEvent } from 'react';
import { ApiError } from '../api';
import { useAuth } from '../auth';

export default function Login() {
    const { login } = useAuth();
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        setBusy(true);
        setError('');
        try {
            await login(String(f.get('email')), String(f.get('password')));
        } catch (err) {
            setError(err instanceof ApiError ? (err.body.errors?.email?.[0] ?? err.message) : 'Falha de conexão.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-6 px-4">
            <h1 className="text-2xl font-semibold">OficinaFlow</h1>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <input name="email" type="email" required placeholder="E-mail" autoComplete="username" className="rounded border border-slate-300 px-3 py-2" />
                <input name="password" type="password" required placeholder="Senha" autoComplete="current-password" className="rounded border border-slate-300 px-3 py-2" />
                {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
                <button disabled={busy} className="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50">Entrar</button>
            </form>
        </main>
    );
}
