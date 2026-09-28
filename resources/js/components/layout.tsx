import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { useAuth } from '../auth';
import { useConfirmExit } from './unsaved-changes';
import { Button } from './ui';

export function Layout() {
    const { user, isAdmin, logout } = useAuth();
    const location = useLocation();
    const confirmExit = useConfirmExit();
    const main = useRef<HTMLElement>(null);
    const [menu, setMenu] = useState(false);
    useEffect(() => {
        setMenu(false);
        const title = location.pathname.startsWith('/clientes')
            ? 'Clientes'
            : location.pathname.startsWith('/estoque')
              ? 'Estoque'
              : location.pathname === '/ordens/nova'
                ? 'Receber equipamento'
                : 'Ordens de serviço';
        document.title = `${title} | OficinaFlow`;
        main.current?.focus();
        window.scrollTo(0, 0);
    }, [location.pathname]);
    return (
        <div className="app-shell">
            <a href="#main-content" className="skip-link">
                Ir para o conteúdo
            </a>
            <header className="app-header">
                <div className="header-inner">
                    <NavLink to="/" className="brand" aria-label="OficinaFlow — início">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
                            <rect width="28" height="28" rx="8" fill="currentColor" />
                            <path d="M8 9h12M8 14h9M8 19h5" stroke="white" strokeWidth="2.5" strokeLinecap="round" />
                        </svg>
                        OficinaFlow
                    </NavLink>
                    <Button className="menu-toggle" aria-expanded={menu} aria-controls="main-nav" onClick={() => setMenu(!menu)}>
                        Menu
                    </Button>
                    <nav id="main-nav" aria-label="Principal" className={`main-nav ${menu ? 'is-open' : ''}`}>
                        <NavLink to="/" end>
                            Ordens
                        </NavLink>
                        <NavLink to="/clientes">Clientes</NavLink>
                        {isAdmin && <NavLink to="/estoque">Estoque</NavLink>}
                        {isAdmin && <NavLink to="/compras">Compras</NavLink>}
                        {isAdmin && <NavLink to="/fornecedores">Fornecedores</NavLink>}
                    </nav>
                    <div className="user-menu">
                        <div>
                            <strong>{user!.name}</strong>
                            <span>{user!.role_label}</span>
                        </div>
                        <button
                            onClick={() => {
                                if (confirmExit()) void logout();
                            }}
                            className="text-sm underline"
                        >
                            Sair
                        </button>
                    </div>
                </div>
            </header>
            <main id="main-content" ref={main} tabIndex={-1} className="app-main">
                <Outlet />
            </main>
            <footer className="app-footer">
                OficinaFlow <span>Do recebimento à entrega.</span>
            </footer>
        </div>
    );
}
