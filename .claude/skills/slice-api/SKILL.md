---
name: slice-api
description: Construir uma feature vertical completa neste projeto — migration, model, Policy, Form Request, Action transacional, controller da API v1, Resource, tipo TS, página React e teste. Use ao criar ou alterar qualquer endpoint ou tela do OficinaFlow (clientes, equipamentos, ordens, orçamentos, peças, reservas), ou quando precisar do padrão de Action com lock, resposta 409 ou formulário React contra a API.
---

# Slice vertical: Laravel API + React

Ordem de construção. Não pule etapa — teste escrito no fim costuma testar o que
foi feito, não o que era para ser feito.

**1. Migration + model → 2. Policy → 3. Form Request → 4. Action →
5. Controller + rota → 6. Resource → 7. Tipo TS → 8. Página React → 9. Teste**

## 1. Migration + model

Constraints no banco (CHECK, único parcial, FK) via `DB::statement()` —
ver `.claude/rules/banco.md`. Dinheiro em `*_cents`, datas `timestampsTz()`.

```php
#[Fillable(['name', 'phone', 'email'])]   // status/saldos/total ficam de fora
class Customer extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
```

Estado = coluna `string` + `CHECK` + enum PHP com `label()` em português.

## 2. Policy

```php
class WorkOrderPolicy
{
    public function deliver(User $user, WorkOrder $order): bool
    {
        return $user->isAdmin();
    }
}
```

Registre no controller com `$this->authorize('deliver', $order)`. Nunca
`if ($user->role === ...)`.

## 3. Form Request

Regras espelham as constraints do banco. Mensagens em português.

```php
class StoreQuoteDraftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.kind' => ['required', 'in:part,service'],
            'items.*.part_id' => ['required_if:items.*.kind,part', 'nullable', 'exists:parts,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

O cliente **não** envia preço de peça de catálogo nem total — o servidor copia
o snapshot e calcula `total_cents`.

## 4. Action

Um caso de uso, `handle()`, dona da transação e dos locks
(`.claude/rules/concorrencia.md`):

```php
class ReserveParts
{
    public function handle(WorkOrder $order, int $quoteId, User $actor): Collection
    {
        return DB::transaction(function () use ($order, $quoteId, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id !== $quoteId) {
                throw new QuoteSuperseded;               // 409 QUOTE_SUPERSEDED
            }
            // ...exige aprovação atual; se já há reservas active, devolve-as (idempotente)

            $partIds = $items->pluck('part_id')->sort()->values();      // ordem fixa de ID
            $parts = Part::whereIn('id', $partIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $item) {                   // tudo ou nada
                if ($parts[$item->part_id]->available() < $item->quantity) {
                    throw new InsufficientStock($item->part_id);   // 409 INSUFFICIENT_STOCK
                }
            }
            // cria reservas, incrementa reserved_quantity, grava work_order_events
        });
    }
}
```

## 5. Controller + rota

Fino: valida (Form Request), autoriza, chama a Action, devolve Resource.

```php
// routes/api.php — dentro do grupo auth:sanctum de /api/v1
Route::post('work-orders/{workOrder}/reservations', [ReservationController::class, 'store']);
```

Exceção de domínio (`DomainConflict`) é convertida em `409 {code, message}`
num único handler em `bootstrap/app.php` — não em cada controller.

## 6. Resource

`JsonResource` define o formato; nunca `return $model`. Dinheiro sai em
centavos (`total_cents`), o front formata.

## 7. Tipo TS

Em `resources/js/types/`, com `type`, espelhando o Resource.

## 8. Página React

Só usa `api()` de `resources/js/api.ts`. Trata vazio, carregando e erro;
`ApiError` com `code` vira mensagem em português; `422` mostra `errors` no
campo. Envia `quote_id` nas ações dependentes de orçamento.

## 9. Teste

`RefreshDatabase`, caminho feliz + 403 + 422/409, asserção no **estado final
do banco**. Se mexeu em reserva/consumo/versão: teste de concorrência real em
Postgres (`tests/Feature/Concurrency/`).
