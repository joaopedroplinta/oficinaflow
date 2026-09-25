import { Link } from 'react-router';

export default function Home() {
    return (
        <div>
            <h1 className="mb-2 text-xl font-semibold">Ordens de serviço</h1>
            <p className="text-slate-500">
                Nenhuma ordem de serviço ainda. Comece cadastrando um{' '}
                <Link to="/clientes" className="underline">
                    cliente
                </Link>
                .
            </p>
        </div>
    );
}
