# Regras — onde cada coisa mora

Arquivo no lugar errado é dívida silenciosa: ninguém acha, todo mundo duplica.

## Backend

```
app/
├── Enums/          estados, tipos e papéis. Todo enum tem label() em português
├── Models/         plano, sem subpasta — Eloquent espera assim
├── Policies/       um por model que tem regra de acesso
├── Actions/        um caso de uso por classe, agrupado por domínio
│   ├── WorkOrders/   OpenWorkOrder, StartDiagnosis, CancelWorkOrder...
│   ├── Quotes/       IssueQuote, RecordQuoteDecision, ReviseQuote...
│   └── Inventory/    ReserveParts, ReleaseReservations, StartRepair...
├── Exceptions/     erros de domínio (DomainConflict e derivados) → 409
├── Http/
│   ├── Controllers/Api/V1/   finos: validam, autorizam, chamam a Action
│   ├── Requests/             Form Request por endpoint que recebe corpo
│   └── Resources/            formato do JSON; nunca devolver Model cru
└── Support/        helpers de domínio sem estado (dinheiro, numeração)
```

**Action é classe de propósito único**, um método público `handle()`, dona da
própria transação (`DB::transaction`) e dos próprios locks. É o "caso de uso"
do PLANO.md: `ReserveParts`, `ReviseQuote`, `StartRepair`. Controller nunca
abre transação nem faz `lockForUpdate()`.

Leitura simples e listagem ficam no controller — Action para `index()` é
cerimônia sem ganho.

**Nada de `Services/`.** Vira classe-deus sem relação entre métodos.

**Status nunca é editável por CRUD.** Não existe `PATCH /work-orders/{id}`
com `status` no corpo; cada transição é uma rota-verbo com pré-condições
próprias (ver PLANO.md §5 e §7).

## Enums

Todo enum tem `label(): string` em português. Enum nunca chega cru na tela.
Um teste quebra o CI se um case novo ficar sem label. Regras que dependem só
do enum moram nele: `WorkOrderStatus::isFrozen()`, `QuoteStatus::isEditable()`.

## Frontend

```
resources/js/
├── api.ts          único ponto de fetch (CSRF, JSON, ApiError)
├── auth.tsx        contexto de usuário logado
├── pages/          uma por tela, espelha as rotas do react-router
├── components/     componentes do domínio e genéricos
└── types/          tipos espelhando os Resources da API
```

Arquivos em **minúsculo com hífen** (`work-order-list.tsx`); o componente
exportado continua em PascalCase. Página não chama `fetch` direto — só `api()`.

## Testes

`tests/Feature/` espelha os controllers/Actions; `tests/Unit/` só para o que
não precisa de HTTP nem banco (enums, cálculo de total). Teste de
concorrência fica em `tests/Feature/Concurrency/` e exige Postgres real.
