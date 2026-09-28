import { Fragment, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider, Link, Navigate, Route, Routes, useLocation, useParams } from 'react-router';
import '../css/app.css';
import { AuthProvider, useAuth } from './auth';
import { UnsavedChangesProvider } from './components/unsaved-changes';
import { Layout } from './components/layout';
import CustomerDetail from './pages/customer-detail';
import CustomerNew from './pages/customer-new';
import Customers from './pages/customers';
import Login from './pages/login';
import Landing from './pages/landing';
import PartDetail from './pages/part-detail';
import PartNew from './pages/part-new';
import PurchaseOrderDetail from './pages/purchase-order-detail';
import PurchaseOrderNew from './pages/purchase-order-new';
import PurchaseOrders from './pages/purchase-orders';
import SupplierDetail from './pages/supplier-detail';
import Suppliers from './pages/suppliers';
import Parts from './pages/parts';
import WorkOrderDetail from './pages/work-order-detail';
import WorkOrderNew from './pages/work-order-new';
import WorkOrders from './pages/work-orders';

// Telas de detalhe remontam quando o :id muda (o histórico do navegador reaproveitaria a instância e o estado local).
function ById({ children }: { children: ReactNode }) {
    const { id } = useParams();

    return <Fragment key={id}>{children}</Fragment>;
}

function NotFound() {
    return (
        <p className="text-slate-500">
            Página não encontrada.{' '}
            <Link to="/" className="underline">
                Voltar para as ordens
            </Link>
        </p>
    );
}

function App() {
    const { user, notice } = useAuth();
    const { pathname } = useLocation();
    if (pathname === '/apresentacao' || (pathname === '/' && !user && !notice)) return <Landing />;
    if (!user) return <Login />;
    if (pathname === '/login') return <Navigate to="/" replace />;

    return (
        <Routes>
            <Route element={<Layout />}>
                <Route index element={<WorkOrders />} />
                <Route path="clientes" element={<Customers />} />
                <Route path="clientes/novo" element={<CustomerNew />} />
                <Route
                    path="clientes/:id"
                    element={
                        <ById>
                            <CustomerDetail />
                        </ById>
                    }
                />
                <Route path="estoque" element={<Parts />} />
                <Route path="estoque/nova" element={<PartNew />} />
                <Route
                    path="estoque/:id"
                    element={
                        <ById>
                            <PartDetail />
                        </ById>
                    }
                />
                <Route path="compras" element={<PurchaseOrders />} />
                <Route path="compras/nova" element={<PurchaseOrderNew />} />
                <Route
                    path="compras/:id"
                    element={
                        <ById>
                            <PurchaseOrderDetail />
                        </ById>
                    }
                />
                <Route path="fornecedores" element={<Suppliers />} />
                <Route
                    path="fornecedores/:id"
                    element={
                        <ById>
                            <SupplierDetail />
                        </ById>
                    }
                />
                <Route path="ordens/nova" element={<WorkOrderNew />} />
                <Route
                    path="ordens/:id"
                    element={
                        <ById>
                            <WorkOrderDetail />
                        </ById>
                    }
                />
                <Route path="*" element={<NotFound />} />
            </Route>
        </Routes>
    );
}

const router = createBrowserRouter([
    {
        path: '*',
        element: (
            <AuthProvider>
                <UnsavedChangesProvider>
                    <App />
                </UnsavedChangesProvider>
            </AuthProvider>
        ),
    },
]);

createRoot(document.getElementById('root')!).render(<RouterProvider router={router} />);
