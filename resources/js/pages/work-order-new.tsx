import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { api } from '../api';
import { useAuth } from '../auth';
import { CustomerForm } from '../components/customer-form';
import { DeviceForm } from '../components/device-form';
import { TextArea } from '../components/field';
import { Alert, Button, ErrorSummary, Pagination } from '../components/ui';
import { useUnsavedChanges } from '../components/unsaved-changes';
import { useLoad } from '../hooks/use-load';
import { useSubmit } from '../hooks/use-submit';
import type { Data, Page } from '../types/api';
import type { Customer, Device } from '../types/customer';
import type { WorkOrder } from '../types/work-order';

export default function WorkOrderNew() {
    const customerId = useSearchParams()[0].get('cliente');
    const { isAdmin } = useAuth();
    if (!isAdmin) return <p>Só o atendimento abre ordens de serviço.</p>;
    return customerId ? <LoadCustomer id={customerId} /> : <ReceivingFlow />;
}
function LoadCustomer({ id }: { id: string }) {
    const { data, loading, error, reload } = useLoad<Data<Customer>>(`/customers/${id}`);
    if (loading) return <p role="status">Carregando cliente…</p>;
    if (!data)
        return (
            <Alert>
                {error}{' '}
                <button onClick={reload} className="underline">
                    Tentar novamente
                </button>
            </Alert>
        );
    if (!data.data.active)
        return (
            <Alert>
                Reative este cliente antes de abrir uma ordem.{' '}
                <Link className="underline" to={`/clientes/${id}`}>
                    Ver cadastro
                </Link>
            </Alert>
        );
    return <ReceivingFlow initial={data.data} />;
}
function ReceivingFlow({ initial }: { initial?: Customer }) {
    const [step, setStep] = useState(initial ? 2 : 1);
    const [customer, setCustomer] = useState<Customer | null>(initial ?? null);
    const [device, setDevice] = useState<Device | null>(null);
    const [values, setValues] = useState({ reported_problem: '', intake_condition: '', accessories: '' });
    const clearDirty = useUnsavedChanges(Object.values(values).some(Boolean));
    const submit = useSubmit();
    const navigate = useNavigate();
    async function open(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        await submit.run(async () => {
            const result = await api<Data<WorkOrder>>('POST', '/work-orders', { customer_id: customer!.id, device_id: device!.id, ...values });
            clearDirty();
            setValues({ reported_problem: '', intake_condition: '', accessories: '' });
            void navigate(`/ordens/${result.data.id}`, { replace: true });
        });
    }
    return (
        <div className="mx-auto flex max-w-4xl flex-col gap-6">
            <div>
                <Link to="/" className="text-sm text-slate-600 underline">
                    Voltar às ordens
                </Link>
                <h1 className="mt-3">Receber equipamento</h1>
                <p className="mt-2 text-sm text-slate-600">Identifique o cliente, escolha o equipamento e registre a entrada.</p>
            </div>
            <ol className="wizard-steps" aria-label="Etapas do recebimento">
                {['Cliente', 'Equipamento', 'Entrada'].map((label, index) => (
                    <li key={label} aria-current={step === index + 1 ? 'step' : undefined}>
                        <span>{index + 1}</span>
                        {label}
                    </li>
                ))}
            </ol>
            {step > 1 && customer && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p>
                        <strong>{customer.name}</strong>
                        {step === 3 && device && (
                            <span className="block text-sm text-slate-600">
                                {device.category} {device.brand} {device.model}
                            </span>
                        )}
                    </p>
                    <Button disabled={submit.busy} onClick={() => setStep(step - 1)}>
                        Voltar uma etapa
                    </Button>
                </div>
            )}
            <div className="panel">
                {step === 1 && (
                    <CustomerPicker
                        onPick={(c) => {
                            if (c.id !== customer?.id) {
                                if (
                                    Object.values(values).some(Boolean) &&
                                    !window.confirm('Trocar o cliente descarta os dados de entrada preenchidos. Deseja continuar?')
                                )
                                    return;
                                setDevice(null);
                                setValues({ reported_problem: '', intake_condition: '', accessories: '' });
                            }
                            setCustomer(c);
                            setStep(2);
                        }}
                    />
                )}
                {step === 2 && customer && (
                    <DevicePicker
                        customer={customer}
                        selected={device}
                        onPick={(d) => {
                            setDevice(d);
                            setStep(3);
                        }}
                    />
                )}
                {step === 3 && (
                    <form onSubmit={open} className="flex flex-col gap-4">
                        <h2>Como o equipamento chegou?</h2>
                        <ErrorSummary errors={submit.errors} />
                        <TextArea
                            label="Problema relatado"
                            name="reported_problem"
                            required
                            value={values.reported_problem}
                            onChange={(e) => setValues({ ...values, reported_problem: e.target.value })}
                            error={submit.errors.reported_problem?.[0]}
                        />
                        <TextArea
                            label="Condição de entrada"
                            name="intake_condition"
                            required
                            placeholder="Riscos, trincas, marcas de uso…"
                            value={values.intake_condition}
                            onChange={(e) => setValues({ ...values, intake_condition: e.target.value })}
                            error={submit.errors.intake_condition?.[0]}
                        />
                        <TextArea
                            label="Acessórios entregues"
                            name="accessories"
                            required
                            placeholder="Carregador, capa… ou Nenhum"
                            value={values.accessories}
                            onChange={(e) => setValues({ ...values, accessories: e.target.value })}
                            error={submit.errors.accessories?.[0]}
                        />
                        {submit.formError && <Alert>{submit.formError}</Alert>}
                        <div className="border-t border-slate-200 pt-4">
                            <Button variant="primary" disabled={submit.busy}>
                                {submit.busy ? 'Abrindo ordem…' : 'Confirmar recebimento e abrir ordem'}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
function CustomerPicker({ onPick }: { onPick: (customer: Customer) => void }) {
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [create, setCreate] = useState(false);
    const { data, loading, error, reload } = useLoad<Page<Customer>>(`/customers?active=1&q=${encodeURIComponent(q)}&page=${page}`);
    return (
        <div className="flex flex-col gap-4">
            <div className="page-heading">
                <h2>{create ? 'Cadastrar cliente' : 'Quem está trazendo o equipamento?'}</h2>
                <Button onClick={() => setCreate(!create)}>{create ? 'Buscar cliente existente' : 'Cadastrar novo cliente'}</Button>
            </div>
            {create ? (
                <CustomerForm
                    submitLabel="Cadastrar e continuar"
                    onSubmit={async (values) => {
                        const r = await api<Data<Customer>>('POST', '/customers', values);
                        onPick(r.data);
                    }}
                />
            ) : (
                <>
                    <form
                        role="search"
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            setQ(String(new FormData(e.currentTarget).get('q') ?? '').trim());
                            setPage(1);
                        }}
                    >
                        <input
                            className="min-w-0 flex-1 rounded border border-slate-300 px-3 py-2"
                            type="search"
                            name="q"
                            aria-label="Buscar cliente"
                            placeholder="Nome, telefone ou e-mail"
                        />
                        <Button>Buscar</Button>
                    </form>
                    {loading && <p role="status">Buscando clientes…</p>}
                    {error && (
                        <Alert>
                            {error}{' '}
                            <button onClick={reload} className="underline">
                                Tentar novamente
                            </button>
                        </Alert>
                    )}
                    {!loading &&
                        data &&
                        (data.data.length ? (
                            <>
                                <ul className="divide-y divide-slate-100">
                                    {data.data.map((c) => (
                                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                            <div>
                                                <p className="font-medium">{c.name}</p>
                                                <p className="text-sm text-slate-500">{c.phone}</p>
                                            </div>
                                            <Button onClick={() => onPick(c)} aria-label={`Selecionar ${c.name}`}>
                                                Selecionar
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                                <Pagination page={page} last={data.meta.last_page} total={data.meta.total} noun="clientes" onPage={setPage} />
                            </>
                        ) : (
                            <p className="text-sm text-slate-600">Nenhum cliente encontrado. Use “Cadastrar novo cliente” para continuar.</p>
                        ))}
                </>
            )}
        </div>
    );
}
function DevicePicker({ customer, selected, onPick }: { customer: Customer; selected: Device | null; onPick: (device: Device) => void }) {
    const [create, setCreate] = useState(false);
    const { data, loading, error, reload } = useLoad<Data<Device[]>>(`/customers/${customer.id}/devices`);
    const active = data?.data.filter((d) => d.active) ?? [];
    return (
        <div className="flex flex-col gap-4">
            <div className="page-heading">
                <h2>{create ? 'Cadastrar equipamento' : 'Qual equipamento será recebido?'}</h2>
                <Button onClick={() => setCreate(!create)}>{create ? 'Escolher cadastrado' : 'Cadastrar equipamento'}</Button>
            </div>
            {create ? (
                <DeviceForm
                    customerId={customer.id}
                    onSaved={(d) => {
                        if (d) onPick(d);
                    }}
                />
            ) : (
                <>
                    {loading && <p role="status">Carregando equipamentos…</p>}
                    {error && (
                        <Alert>
                            {error}{' '}
                            <button onClick={reload} className="underline">
                                Tentar novamente
                            </button>
                        </Alert>
                    )}
                    {!loading &&
                        data &&
                        (active.length ? (
                            <ul className="divide-y divide-slate-100">
                                {active.map((d) => (
                                    <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 py-4">
                                        <div>
                                            <p className="font-medium">
                                                {d.category} {d.brand} {d.model}
                                            </p>
                                            <p className="text-sm text-slate-500">{d.serial_number ? `Série ${d.serial_number}` : 'Sem número de série'}</p>
                                        </div>
                                        <Button variant={selected?.id === d.id ? 'primary' : 'secondary'} onClick={() => onPick(d)}>
                                            Usar equipamento
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-600">Nenhum equipamento ativo. Cadastre o equipamento para continuar.</p>
                        ))}
                </>
            )}
        </div>
    );
}
