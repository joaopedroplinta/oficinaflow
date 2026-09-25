import { Navigate, useNavigate } from 'react-router';
import { api } from '../api';
import { CustomerForm } from '../components/customer-form';
import { useAuth } from '../auth';
import type { Data } from '../types/api';
import type { Customer } from '../types/customer';

export default function CustomerNew() {
    const { isAdmin } = useAuth();
    const navigate = useNavigate();

    if (!isAdmin) return <Navigate to="/clientes" replace />;

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-xl font-semibold">Novo cliente</h1>
            <CustomerForm
                submitLabel="Cadastrar cliente"
                onSubmit={async (values) => {
                    const { data } = await api<Data<Customer>>('POST', '/customers', values);
                    navigate(`/clientes/${data.id}`);
                }}
            />
        </div>
    );
}
