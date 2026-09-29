import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { ApiError, errorMessage } from '../api';
import { useAuth } from '../auth';
import { BrandMark } from '../components/ui';

export default function Login() {
    const { login, notice } = useAuth();
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
            setError(err instanceof ApiError && err.body.errors?.email?.[0] ? err.body.errors.email[0] : errorMessage(err));
        } finally {
            setBusy(false);
        }
    }

    return (
        <main className="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-6 px-4">
            <div className="flex flex-col gap-3">
                <BrandMark size={40} />
                <h1>Entrar no OficinaFlow</h1>
                <p className="text-sm text-slate-600">Use a conta de atendimento ou de técnico da sua assistência.</p>
            </div>
            {notice && (
                <p role="status" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                    {notice}
                </p>
            )}
            <form onSubmit={submit} className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <label htmlFor="login-email" className="text-sm font-medium">
                        E-mail
                    </label>
                    <input
                        id="login-email"
                        name="email"
                        type="email"
                        required
                        autoComplete="username"
                        aria-invalid={!!error}
                        aria-describedby={error ? 'login-error' : undefined}
                        className="rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor="login-password" className="text-sm font-medium">
                        Senha
                    </label>
                    <input
                        id="login-password"
                        name="password"
                        type="password"
                        required
                        autoComplete="current-password"
                        aria-invalid={!!error}
                        aria-describedby={error ? 'login-error' : undefined}
                        className="rounded border border-slate-300 px-3 py-2"
                    />
                </div>
                {error && (
                    <p id="login-error" role="alert" className="text-sm text-red-700">
                        {error}
                    </p>
                )}
                <button disabled={busy} className="btn btn-primary disabled:opacity-50">
                    {busy ? 'Entrando…' : 'Entrar'}
                </button>
            </form>
            <Link to="/" className="text-sm text-brand underline">
                Conhecer o OficinaFlow
            </Link>
        </main>
    );
}
