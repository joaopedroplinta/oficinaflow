import { Link } from 'react-router';
import { useAuth } from '../auth';

export default function Home() {
    const { isAdmin } = useAuth();

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
            {isAdmin && (
                <p className="mt-2 text-slate-500">
                    Para receber um equipamento, abra a ordem a partir do cadastro do cliente.
                </p>
            )}
        </div>
    );
}
