import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router';
import '../css/app.css';
import { AuthProvider, useAuth } from './auth';
import { Layout } from './components/layout';
import CustomerDetail from './pages/customer-detail';
import CustomerNew from './pages/customer-new';
import Customers from './pages/customers';
import Home from './pages/home';
import Login from './pages/login';

function App() {
    const { user } = useAuth();
    if (!user) return <Login />;

    return (
        <Routes>
            <Route element={<Layout />}>
                <Route index element={<Home />} />
                <Route path="clientes" element={<Customers />} />
                <Route path="clientes/novo" element={<CustomerNew />} />
                <Route path="clientes/:id" element={<CustomerDetail />} />
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
