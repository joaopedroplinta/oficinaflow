# Regras — transações, locks e concorrência

O diferencial do projeto: aprovação, estoque e execução continuam coerentes
mesmo com duas pessoas agindo ao mesmo tempo ou com o orçamento mudando.

## Toda Action que muda estado

1. Abre `DB::transaction()` **dentro da Action**.
2. Trava a **ordem de serviço** com `lockForUpdate()` primeiro.
3. Só **depois do lock** valida estado, `quote_id` recebido e pré-condições.
   Validar antes do lock é validar dado velho.
4. Trava as **peças em ordem crescente de ID**, sempre. Ordem fixa evita
   deadlock entre `ReserveParts`, `ReleaseReservations`, `ReviseQuote`,
   `CancelWorkOrder` e `StartRepair` — todas seguem o mesmo protocolo.
5. Grava o evento em `work_order_events` na mesma transação.

## Tudo ou nada

- Reserva é **integral**: falta uma peça → nenhuma reserva criada,
  `409 INSUFFICIENT_STOCK`.
- Falha em qualquer passo de `StartRepair` → rollback total (estado, saldo,
  reservas, movimentos).

## Idempotência

- Repetir `reservations` depois de sucesso devolve as reservas existentes,
  sem duplicar (`quote_item_id UNIQUE` é a rede de segurança).
- Repetir `start-repair` não consome duas vezes.

## Tela desatualizada

Ações que dependem de orçamento recebem o `quote_id` que o usuário via. Se
não for mais a versão atual (depois do lock) → `409 QUOTE_SUPERSEDED`. Nunca
aprovar/iniciar com base em versão substituída.

## Erros de domínio

`409` com `code` estável: `QUOTE_SUPERSEDED`, `INSUFFICIENT_STOCK`,
`INVALID_TRANSITION`. Exceção de domínio dedicada, não `abort(409)` solto.

## Testes

- Concorrência real: **duas conexões/processos simultâneos em PostgreSQL**.
  Chamadas sequenciais ou SQLite não provam nada.
- Depois de qualquer cenário, conferir a invariante:
  `parts.reserved_quantity == soma das reservas active` e nenhum saldo negativo.
