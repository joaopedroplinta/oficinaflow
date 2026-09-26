# Regras — banco de dados

Invariantes. Violar qualquer uma quebra a consistência entre orçamento,
estoque e execução — que é a razão de existir do projeto.

## Tipos

- **Dinheiro em centavos inteiros** (`bigInteger`, colunas `*_cents`). Nunca
  `float`, nunca `decimal` para preço. Formatação em reais só na exibição.
- **Quantidades inteiras**. O MVP não tem peça fracionada nem hora fracionada.
- Datas `timestampsTz()` / `timestampTz()` — nunca `timestamp` sem fuso.
- Estado é `string` + `CHECK` no banco + enum PHP com cast. Enum nativo do
  Postgres exige migration para cada valor novo — não vale a pena.
- Chave primária `bigint`; relacionamentos por foreign key de verdade.

## Constraints que o banco garante (não só a aplicação)

- `parts`: `on_hand >= 0`, `reserved_quantity >= 0`,
  `reserved_quantity <= on_hand`.
- `quotes`: `UNIQUE(work_order_id, kind, version)` (versão e adendo têm numeração própria), `kind` `version`/`addendum`,
  `base_quote_id` só no adendo (FK composta para a mesma ordem; trigger: base emitida e aprovada, ordem em `in_progress`,
  tipo e base imutáveis) e **índice único parcial**
  "no máximo uma versão não substituída por ordem".
- `quote_items`: `quantity > 0`, `unit_price_cents >= 0`; item `part` exige
  `part_id`, item `service` não tem `part_id`; mesma peça não repete na versão.
- `quote_decisions.quote_id UNIQUE`: uma decisão por versão.
- `part_reservations`: um item pode ter várias reservas (reserva parcial); trigger: soma das
  ativas + consumidas ≤ `quote_items.quantity` e mesma peça do item. `inventory_movements.reservation_id UNIQUE`.
- `work_orders.current_quote_id` aponta para orçamento **da própria ordem**
  (FK composta).

- `inventory_movements` é livro-razão **append-only** (trigger recusa UPDATE/DELETE);
  `on_hand` = entradas + estornos − consumos (o estorno é um movimento `return` que aponta para o consumo; a soma dos estornos de um consumo ≤ o consumido, no trigger; o consumo nunca é apagado), então todo saldo entra por
  `ReceivePart` (seed inclusive) e o saldo máximo por peça é 1.000.000.

- `parts.average_cost_cents` (custo médio ponderado móvel, centavos inteiros, `NULL` até a primeira entrada com custo) só muda em `ReceivePart`
  (entrada com custo); consumo e estorno não o alteram. `inventory_movements.unit_cost_cents`/`supplier_id` só existem em `receipt` (CHECK).
  Custo e margem só saem para o atendimento e nunca nos PDFs do cliente.

A soma das reservas ativas = `parts.reserved_quantity` **não cabe em CHECK de
linha** — é mantida pelas Actions transacionais e conferida por teste.

## Integridade

- FK com `onDelete` explícito. Registro transacional (ordem, orçamento,
  decisão, reserva, movimento, evento) **não é apagado**: sem `cascade` para
  cima, sem soft delete. Cadastro (cliente, equipamento, peça, usuário) é
  desativado (`active`).
- Snapshot em vez de referência viva onde o histórico importa: descrição e
  preço em `quote_items`, identificação do equipamento em `work_orders`.
- Índice em toda coluna usada em `where` de listagem (`status`, `customer_id`,
  `number`, `work_order_id`).

## Migrations

- Uma tabela por migration, `down()` funcionando de verdade.
- CHECK e índice parcial via `DB::statement()` — o schema builder não cobre.
- Nunca editar migration já aplicada em produção — criar uma nova.
- Alteração destrutiva (drop de coluna com dado) exige aviso antes.
- `migrate:fresh`, `rollback` e `db:wipe` estão negados em `settings.json`;
  se precisar recriar o banco de dev, peça ao usuário.
