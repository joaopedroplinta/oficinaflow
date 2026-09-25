import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router';
import '../css/app.css';
import { AuthProvider, useAuth } from './auth';
import Home from './pages/Home';
import Login from './pages/Login';

function App() {
    const { user } = useAuth();
    if (!user) return <Login />;

    return (
        <Routes>
            <Route path="*" element={<Home />} />
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
