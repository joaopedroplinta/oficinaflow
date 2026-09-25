import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router';
import '../css/app.css';
import { AuthProvider, useAuth } from './auth';
import { Layout } from './components/layout';
import CustomerDetail from './pages/customer-detail';
import CustomerNew from './pages/customer-new';
import Customers from './pages/customers';
import Login from './pages/login';
import PartDetail from './pages/part-detail';
import PartNew from './pages/part-new';
import Parts from './pages/parts';
import WorkOrderDetail from './pages/work-order-detail';
import WorkOrderNew from './pages/work-order-new';
import WorkOrders from './pages/work-orders';

function App() {
    const { user } = useAuth();
    if (!user) return <Login />;

    return (
        <Routes>
            <Route element={<Layout />}>
                <Route index element={<WorkOrders />} />
                <Route path="clientes" element={<Customers />} />
                <Route path="clientes/novo" element={<CustomerNew />} />
                <Route path="clientes/:id" element={<CustomerDetail />} />
                <Route path="estoque" element={<Parts />} />
                <Route path="estoque/nova" element={<PartNew />} />
                <Route path="estoque/:id" element={<PartDetail />} />
                <Route path="ordens/nova" element={<WorkOrderNew />} />
                <Route path="ordens/:id" element={<WorkOrderDetail />} />
                <Route path="*" element={<p className="text-slate-500">Página não encontrada.</p>} />
            </Route>
        </Routes>
    );
}

createRoot(document.getElementById('root')!).render(
    <BrowserRouter>
        <AuthProvider>
            <App />
        </AuthProvider>
    </BrowserRouter>,
);
