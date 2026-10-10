import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { useAuth } from '../auth';
import { useConfirmExit } from './unsaved-changes';
import { BrandMark, Button, Icon } from './ui';

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
              : location.pathname.startsWith('/compras')
                ? 'Compras'
                : location.pathname.startsWith('/fornecedores')
                  ? 'Fornecedores'
                  : location.pathname === '/ordens/nova'
                    ? 'Receber equipamento'
                    : 'Ordens de serviço';
        document.title = `${title} | OficinaFlow`;
        main.current?.focus();
        window.scrollTo(0, 0);
    }, [location.pathname]);
    useEffect(() => {
        if (!menu) return;
        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenu(false);
                document.getElementById('menu-toggle')?.focus();
            }
        };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [menu]);
    return (
        <div className="app-shell">
            <a href="#main-content" className="skip-link">
                Ir para o conteúdo
            </a>
            <header className="app-header">
                <div className="header-inner">
                    <NavLink to="/" className="brand" aria-label="OficinaFlow — início">
                        <BrandMark />
                        <span translate="no">
                            OficinaFlow<small>Gestão da assistência</small>
                        </span>
                    </NavLink>
                    <Button id="menu-toggle" className="menu-toggle" aria-expanded={menu} aria-controls="main-nav" onClick={() => setMenu(!menu)}>
                        <Icon name="menu" size={18} />
                        Menu
                    </Button>
                    <p className="nav-caption">Área de trabalho</p>
                    <nav id="main-nav" aria-label="Principal" className={`main-nav ${menu ? 'is-open' : ''}`}>
                        <NavLink to="/" end className={({ isActive }) => (isActive || location.pathname.startsWith('/ordens/') ? 'active' : undefined)}>
                            <Icon name="orders" />
                            Ordens
                        </NavLink>
                        <NavLink to="/clientes">
                            <Icon name="customers" />
                            Clientes
                        </NavLink>
                        {isAdmin && (
                            <NavLink to="/estoque">
                                <Icon name="stock" />
                                Estoque
                            </NavLink>
                        )}
                        {isAdmin && (
                            <NavLink to="/compras">
                                <Icon name="purchases" />
                                Compras
                            </NavLink>
                        )}
                        {isAdmin && (
                            <NavLink to="/fornecedores">
                                <Icon name="suppliers" />
                                Fornecedores
                            </NavLink>
                        )}
                    </nav>
                    <div className="user-menu">
                        <span className="user-avatar" aria-hidden="true">
                            {user!.name.charAt(0)}
                        </span>
                        <div>
                            <strong>{user!.name}</strong>
                            <span>{user!.role_label}</span>
                        </div>
                        <button
                            onClick={() => {
                                if (confirmExit()) void logout();
                            }}
                            className="logout-button"
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
