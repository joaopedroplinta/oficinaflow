import { CustomerOrders } from '../components/customer-orders';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { api, errorMessage } from '../api';
import { useAuth } from '../auth';
import { CustomerForm } from '../components/customer-form';
import { DeviceForm } from '../components/device-form';
import { Loading } from '../components/loading';
import { SavedNotice } from '../components/saved-notice';
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
    const deviceToggle = useSubmit();
    const [editing, setEditing] = useState<Device | null>(null);
    const [saved, setSaved] = useState('');

    if (customer.loading && !customer.data) return <Loading title="Cliente" />;
    if (!customer.data)
        return (
            <p role="alert" className="text-red-700">
                <span className="sr-only">Cliente: </span>
                {customer.error}{' '}
                <button onClick={customer.reload} className="underline">
                    Tentar de novo
                </button>{' '}
                ou{' '}
                <Link to="/clientes" className="underline">
                    Voltar para clientes
                </Link>
            </p>
        );

    const c = customer.data.data;

    return (
        <div className="flex flex-col gap-8">
            {customer.error && (
                <p role="alert" className="text-sm text-red-700">
                    {customer.error}{' '}
                    <button onClick={customer.reload} className="underline">
                        Atualizar
                    </button>
                </p>
            )}

            {saved && <SavedNotice message={saved} />}

            <div>
                <Link to="/clientes" className="back-link">
                    Clientes
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

            <CustomerOrders customerId={c.id} />

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
                            setSaved('Dados do cliente salvos.');
                            customer.reload();
                        }}
                    />
                    <div>
                        <button
                            disabled={toggle.busy}
                            onClick={() =>
                                void toggle.run(async () => {
                                    setSaved('');
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

                {devices.loading && <Loading />}
                {devices.error && (
                    <p role="alert" className="text-red-700">
                        {devices.error}{' '}
                        <button onClick={devices.reload} className="underline">
                            Tentar de novo
                        </button>
                    </p>
                )}
                {devices.data && devices.data.data.length === 0 && <p className="text-slate-500">Este cliente ainda não tem equipamentos cadastrados.</p>}
                {devices.data && devices.data.data.length > 0 && (
                    <div className="table-frame">
                        <table className="data-table">
                            <thead className="border-b border-slate-200 text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-medium">Categoria</th>
                                    <th className="py-2 pr-4 font-medium">Marca</th>
                                    <th className="py-2 pr-4 font-medium">Modelo</th>
                                    <th className="py-2 pr-4 font-medium">Nº de série</th>
                                    <th className="py-2 font-medium">{isAdmin ? 'Ações' : 'Situação'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {devices.data.data.map((d) => (
                                    <tr key={d.id} className="border-b border-slate-100">
                                        <td className="py-2 pr-4">{d.category}</td>
                                        <td className="py-2 pr-4">{d.brand}</td>
                                        <td className="py-2 pr-4">{d.model}</td>
                                        <td className="py-2 pr-4">{d.serial_number ?? '—'}</td>
                                        <td className="py-2">
                                            {!d.active && <span className="mr-3 text-slate-500">Inativo</span>}
                                            {isAdmin && (
                                                <span className="inline-flex gap-3">
                                                    <button onClick={() => setEditing(d)} className="underline">
                                                        Editar
                                                    </button>
                                                    <button
                                                        disabled={deviceToggle.busy}
                                                        onClick={() =>
                                                            void deviceToggle.run(async () => {
                                                                setSaved('');
                                                                await api('PATCH', `/devices/${d.id}`, { active: !d.active });
                                                                devices.reload();
                                                            })
                                                        }
                                                        className="underline disabled:opacity-50"
                                                    >
                                                        {d.active ? 'Desativar' : 'Reativar'}
                                                    </button>
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {deviceToggle.formError && (
                    <p role="alert" className="text-sm text-red-700">
                        {deviceToggle.formError}
                    </p>
                )}

                {isAdmin && editing && (
                    <div className="mt-2">
                        <h3 className="mb-2 text-sm font-medium">Editar equipamento</h3>
                        <DeviceForm
                            key={editing.id}
                            customerId={c.id}
                            device={editing}
                            onSaved={() => {
                                setSaved('Equipamento salvo.');
                                setEditing(null);
                                devices.reload();
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    </div>
                )}

                {isAdmin && !editing && c.active && (
                    <div className="mt-2">
                        <h3 className="mb-2 text-sm font-medium">Novo equipamento</h3>
                        <DeviceForm
                            customerId={c.id}
                            onSaved={() => {
                                setSaved('Equipamento adicionado.');
                                devices.reload();
                            }}
                        />
                    </div>
                )}
                {isAdmin && !editing && !c.active && (
                    <p className="text-sm text-slate-500">Cliente inativo: reative o cliente para cadastrar novos equipamentos.</p>
                )}
            </section>
        </div>
    );
}
