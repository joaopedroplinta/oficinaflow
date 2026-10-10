import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { ApiError, errorMessage } from '../api';
import { useAuth } from '../auth';
import { BrandMark, Icon } from '../components/ui';

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
        <main className="login-page">
            <aside className="login-aside" aria-label="Sobre o OficinaFlow">
                <p className="eyebrow">Do balcão à bancada</p>
                <h2>
                    Um lugar para
                    <br />
                    cada reparo.
                </h2>
                <p>O atendimento registra. O técnico acompanha. A ordem guarda o caminho inteiro.</p>
                <img
                    src="/images/landing/workbench-premium-v1.webp"
                    alt="Bancada de assistência técnica organizada para o próximo reparo"
                    width="1672"
                    height="941"
                />
                <ol>
                    <li>Receber</li>
                    <li>Diagnosticar</li>
                    <li>Orçar</li>
                    <li>Reparar</li>
                    <li>Entregar</li>
                </ol>
            </aside>
            <section className="login-content" aria-labelledby="login-title">
                <Link to="/" className="brand login-brand">
                    <BrandMark size={32} />
                    <span translate="no">OficinaFlow</span>
                </Link>
                <div className="flex flex-col gap-3">
                    <h1 id="login-title">Entrar no OficinaFlow</h1>
                    <p className="text-sm text-slate-600">Use a conta de atendimento ou de técnico da sua assistência.</p>
                </div>
                {notice && (
                    <p role="status" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                        {notice}
                    </p>
                )}
                <form onSubmit={submit} className="login-form flex flex-col gap-5" aria-busy={busy}>
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
                            spellCheck={false}
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
                        <Icon name="arrow" size={18} />
                    </button>
                </form>
                <Link to="/" className="text-sm text-brand underline">
                    Conhecer o OficinaFlow
                </Link>
            </section>
        </main>
    );
}
