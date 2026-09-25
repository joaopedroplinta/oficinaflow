# OficinaFlow

Ordens de serviço para assistência técnica: receber equipamento → diagnosticar →
orçar → registrar aprovação → reservar peças → reparar → entregar. Laravel +
React + PostgreSQL.

Escopo, modelo e cronograma em [`PLANO.md`](PLANO.md); comandos e convenções em
[`CLAUDE.md`](CLAUDE.md).

```bash
docker compose up -d --wait && cp .env.example .env && php artisan key:generate
composer install && npm ci && npm run build
php artisan migrate --seed && php artisan serve
```
