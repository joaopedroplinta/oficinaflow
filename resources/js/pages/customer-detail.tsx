import { Link, useParams } from 'react-router';
import { api, errorMessage } from '../api';
import { useAuth } from '../auth';
import { CustomerForm } from '../components/customer-form';
import { DeviceForm } from '../components/device-form';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data } from '../types/api';
import type { Customer, Device } from '../types/customer';

export default function CustomerDetail() {
    const { id } = useParams();
    const { isAdmin } = useAuth();
    const customer = useLoad<Data<Customer>>(`/customers/${id}`);
    const devices = useLoad<Data<Device[]>>(`/customers/${id}/devices`);
    const toggle = useSubmit();

    if (customer.loading) return <p className="text-slate-500">Carregando…</p>;
    if (customer.error || !customer.data)
        return (
            <p role="alert" className="text-red-700">
                {customer.error}{' '}
                <Link to="/clientes" className="underline">
                    Voltar para clientes
                </Link>
            </p>
        );

    const c = customer.data.data;

    return (
        <div className="flex flex-col gap-8">
            <div>
                <Link to="/clientes" className="text-sm text-slate-600 underline">
                    ← Clientes
                </Link>
                <h1 className="mt-2 text-xl font-semibold">
                    {c.name} {!c.active && <span className="text-sm font-normal text-slate-500">(inativo)</span>}
                </h1>
                {isAdmin && c.active && (
                    <Link to={`/ordens/nova?cliente=${c.id}`} className="mt-3 inline-block rounded bg-slate-900 px-4 py-2 text-sm text-white">
                        Abrir ordem de serviço
                    </Link>
                )}
            </div>

            {isAdmin ? (
                <section aria-labelledby="dados" className="flex flex-col gap-3">
                    <h2 id="dados" className="font-semibold">
                        Dados do cliente
                    </h2>
                    <CustomerForm
                        key={c.id + c.name + c.phone + c.email}
                        initial={c}
                        submitLabel="Salvar alterações"
                        onSubmit={async (values) => {
                            await api('PATCH', `/customers/${c.id}`, values);
                            customer.reload();
                        }}
                    />
                    <div>
                        <button
                            disabled={toggle.busy}
                            onClick={() =>
                                void toggle.run(async () => {
                                    await api('PATCH', `/customers/${c.id}`, { active: !c.active });
                                    customer.reload();
                                })
                            }
                            className="rounded border border-slate-300 px-3 py-1 text-sm disabled:opacity-50"
                        >
                            {c.active ? 'Desativar cliente' : 'Reativar cliente'}
                        </button>
                        {toggle.formError && (
                            <p role="alert" className="mt-2 text-sm text-red-700">
                                {toggle.formError}
                            </p>
                        )}
                    </div>
                </section>
            ) : (
                <dl className="grid gap-1 text-sm">
                    <dt className="text-slate-500">Telefone</dt>
                    <dd>{c.phone}</dd>
                    <dt className="text-slate-500">E-mail</dt>
                    <dd>{c.email ?? '—'}</dd>
                </dl>
            )}

            <section aria-labelledby="equipamentos" className="flex flex-col gap-3">
                <h2 id="equipamentos" className="font-semibold">
                    Equipamentos
                </h2>

                {devices.loading && <p className="text-slate-500">Carregando…</p>}
                {devices.error && (
                    <p role="alert" className="text-red-700">
                        {devices.error}{' '}
                        <button onClick={devices.reload} className="underline">
                            Tentar de novo
                        </button>
                    </p>
                )}
                {devices.data && devices.data.data.length === 0 && (
                    <p className="text-slate-500">Este cliente ainda não tem equipamentos cadastrados.</p>
                )}
                {devices.data && devices.data.data.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Categoria</th>
                                    <th className="py-2 pr-4 font-medium">Marca</th>
                                    <th className="py-2 pr-4 font-medium">Modelo</th>
                                    <th className="py-2 font-medium">Nº de série</th>
                                </tr>
                            </thead>
                            <tbody>
                                {devices.data.data.map((d) => (
                                    <tr key={d.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">{d.category}</td>
                                        <td className="py-2 pr-4">{d.brand}</td>
                                        <td className="py-2 pr-4">{d.model}</td>
                                        <td className="py-2">{d.serial_number ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {isAdmin && (
                    <div className="mt-2">
                        <h3 className="mb-2 text-sm font-medium">Novo equipamento</h3>
                        <DeviceForm customerId={c.id} onCreated={devices.reload} />
                    </div>
                )}
            </section>
        </div>
    );
}
