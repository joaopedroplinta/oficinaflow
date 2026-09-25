# OficinaFlow

Sistema de ordens de serviço para assistência técnica (projeto de portfólio).
Percurso do MVP: receber equipamento → diagnosticar → orçar → registrar
aprovação → reservar peças → reparar → entregar. Foco técnico: aprovação,
estoque e execução coerentes sob concorrência e revisão de orçamento.

## Estado atual

Semana 1 (fundação) feita: Laravel + Sanctum (sessão/SPA), login/logout/me em
`/api/v1`, perfis `admin` (atendimento) e `technician`, shell React com login,
Postgres via Docker. Sem domínio ainda (clientes, ordens, orçamentos, estoque).
Deploy ainda não configurado.

## Stack

Laravel 13 (PHP 8.5), PostgreSQL 18, React + TypeScript + Tailwind 4 (Vite),
Sanctum em modo SPA. Monólito modular: o Laravel serve o shell React
(`resources/views/app.blade.php`) e a API em `/api/v1`. Sem Inertia.

## Comandos

```bash
docker compose up -d --wait        # Postgres :5432 (dbs oficinaflow e oficinaflow_testing)
php artisan migrate:fresh --seed   # admin@ / tecnico@oficinaflow.test, senha "password"
php artisan serve                  # :8000 (usa public/build)
npm run dev                        # Vite HMR
npm run build                      # tsc + vite build
php artisan test                   # PHPUnit, sempre em Postgres
```

## Convenções

- **Dinheiro em centavos inteiros, quantidades inteiras**; datas `timestamptz` (`timestampsTz()`).
- **Estados em coluna texto + `CHECK` + enum PHP** (ver `users.role` / `App\Enums\Role`).
- **Sem edição livre de `status`**: cada transição é um caso de uso (`app/Actions/*`) com transação própria e `lockForUpdate()` em ordem fixa de ID.
- Registros transacionais não são apagados; cadastros são desativados (`active`).
- Erros de API: `422` validação, `403` permissão, `409` conflito com códigos (`QUOTE_SUPERSEDED`, `INSUFFICIENT_STOCK`, `INVALID_TRANSITION`).
- Testes de concorrência exigem Postgres com duas conexões reais; SQLite não vale.
- Feature tests herdam o header `Referer` stateful de `tests/TestCase.php` (senão o Sanctum não abre sessão).
