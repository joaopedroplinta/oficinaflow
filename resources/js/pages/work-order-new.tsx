import type { FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { TextArea } from '../components/field';
import { Loading } from '../components/loading';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { Customer, Device } from '../types/customer';
import type { WorkOrder } from '../types/work-order';

export default function WorkOrderNew() {
    const customerId = useSearchParams()[0].get('cliente');
    const { isAdmin } = useAuth();

    if (!isAdmin) return <p className="text-slate-500">Só o atendimento abre ordens de serviço.</p>;
    if (!customerId)
        return (
            <p className="text-slate-500">
                Escolha o cliente para abrir a ordem: vá em{' '}
                <Link to="/clientes" className="underline">
                    Clientes
                </Link>{' '}
                e use “Abrir ordem de serviço”.
            </p>
        );

    return <OpenOrderForm customerId={customerId} />;
}

// Só existe com um cliente escolhido: assim nenhuma requisição sai para /customers/null.
function OpenOrderForm({ customerId }: { customerId: string }) {
    const customer = useLoad<Data<Customer>>(`/customers/${customerId}`);
    const devices = useLoad<Data<Device[]>>(`/customers/${customerId}/devices`);
    const { busy, errors, formError, run } = useSubmit();
    const navigate = useNavigate();

    if ((customer.loading && !customer.data) || (devices.loading && !devices.data)) return <Loading title="Abrir ordem de serviço" />;
    if (customer.error || devices.error || !customer.data || !devices.data)
        return (
            <p role="alert" className="text-red-700">
                {customer.error || devices.error}{' '}
                <Link to="/clientes" className="underline">
                    Voltar para clientes
                </Link>
            </p>
        );

    const c = customer.data.data;
    const active = devices.data.data.filter((d) => d.active);

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const f = new FormData(e.currentTarget);
        await run(async () => {
            const res = await api<Data<WorkOrder>>('POST', '/work-orders', {
                customer_id: c.id,
                device_id: Number(f.get('device_id')),
                reported_problem: String(f.get('reported_problem')),
                intake_condition: String(f.get('intake_condition')),
                accessories: String(f.get('accessories')),
            });
            void navigate(`/ordens/${res.data.id}`);
        });
    }

    return (
        <div className="flex flex-col gap-4">
            <div>
                <Link to={`/clientes/${c.id}`} className="text-sm text-slate-600 underline">
                    ← {c.name}
                </Link>
                <h1 className="mt-2 text-xl font-semibold">Abrir ordem de serviço</h1>
            </div>

            {active.length === 0 ? (
                <p className="text-slate-500">
                    Este cliente não tem equipamento ativo.{' '}
                    <Link to={`/clientes/${c.id}`} className="underline">
                        Cadastre um equipamento
                    </Link>{' '}
                    antes de abrir a ordem.
                </p>
            ) : (
                <form onSubmit={submit} className="flex max-w-xl flex-col gap-3">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="device_id" className="text-sm font-medium">
                            Equipamento
                        </label>
                        <select
                            id="device_id"
                            name="device_id"
                            required
                            defaultValue=""
                            aria-invalid={!!errors.device_id}
                            aria-describedby={errors.device_id ? 'device_id-error' : undefined}
                            className="rounded border border-slate-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-slate-900"
                        >
                            <option value="" disabled>
                                Selecione…
                            </option>
                            {active.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.category} {d.brand} {d.model}
                                    {d.serial_number ? ` (${d.serial_number})` : ''}
                                </option>
                            ))}
                        </select>
                        {errors.device_id && (
                            <p id="device_id-error" className="text-sm text-red-700">
                                {errors.device_id[0]}
                            </p>
                        )}
                    </div>
                    <TextArea label="Problema relatado" name="reported_problem" required error={errors.reported_problem?.[0]} />
                    <TextArea label="Condição de entrada" name="intake_condition" placeholder="Riscos, trincas, marcas de uso…" required error={errors.intake_condition?.[0]} />
                    <TextArea label="Acessórios entregues" name="accessories" placeholder="Carregador, capa… ou “Nenhum”" required error={errors.accessories?.[0]} />
                    {errors.customer_id && (
                        <p role="alert" className="text-sm text-red-700">
                            {errors.customer_id[0]}
                        </p>
                    )}
                    {formError && (
                        <p role="alert" className="text-sm text-red-700">
                            {formError}
                        </p>
                    )}
                    <button disabled={busy} className="self-start rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">
                        {busy ? 'Abrindo…' : 'Abrir ordem'}
                    </button>
                </form>
            )}
        </div>
    );
}
