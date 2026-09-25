---
name: regras-oficina
description: Regras de negócio do OficinaFlow — máquina de estados da ordem de serviço, versões de orçamento, aprovação, reserva e consumo de peças, revisão e cancelamento. Use ao mexer em status da ordem, orçamento, decisão do cliente, reserva, estoque, início/conclusão de reparo, entrega ou cancelamento.
---

# Ordem de serviço, orçamento e estoque

Fonte completa: `PLANO.md` §3–§6. Este resumo é o que não pode ser esquecido.

## Estados da ordem

```
received → diagnosing → awaiting_approval → approved → in_progress
        → ready_for_pickup → delivered

received / diagnosing / awaiting_approval / approved → awaiting_return → canceled
```

`awaiting_return` = serviço cancelado, **equipamento ainda na assistência**.
Cancelar não é devolver.

| Ação | Efeito |
|---|---|
| Iniciar diagnóstico | `received → diagnosing` |
| Emitir orçamento | exige diagnóstico + itens válidos; → `awaiting_approval` |
| Aprovar | versão atual emitida e sem decisão; → `approved` |
| Rejeitar | registra rejeição; volta a `diagnosing` |
| Revisar | antes do reparo: substitui versão, libera reservas, → `diagnosing` |
| Reservar peças | exige aprovação atual (`approved` ou `in_progress`); **não muda o estado**; `partial` reserva `min(falta, disponível)` |
| Iniciar reparo | aprovação atual + todas as reservas (ou `partial_start`: só o reservado); consome peças; → `in_progress` |
| Consumir reservas | só em `in_progress`: baixa as reservas ativas novas |
| Concluir | exige descrição do trabalho e todas as peças consumidas; → `ready_for_pickup` |
| Entregar | `ready_for_pickup → delivered` |
| Cancelar | antes de `in_progress`; libera reservas; → `awaiting_return` |
| Devolver sem reparo | `awaiting_return → canceled` |

Orçamento só de mão de obra inicia reparo sem reservas. **Não existe edição
livre de `status`.**

## Orçamento

- Ordem tem várias **versões**; só uma é a atual (`current_quote_id`).
- Depois de `issued`, itens/quantidades/preços **não mudam**.
- Aprovação (`quote_decisions`) refere-se a **uma versão**; revisar cria outra
  versão e a aprovação antiga **não** autoriza a nova (fica no histórico).
- `total_cents` é calculado no servidor. Descrição/preço dos itens são
  snapshot do catálogo.
- Depois de iniciado o reparo, o orçamento fica congelado; só se reserva e consome o que faltou de um início parcial.

## Estoque

- `disponível = on_hand - reserved_quantity`.
- **Aprovação e disponibilidade são independentes**: ordem aprovada pode
  aguardar peças — mostre como pendência na UI, não como novo estado.
- Reserva: tudo ou nada. Consumo (ao iniciar reparo) baixa `on_hand` **e**
  `reserved_quantity`, marca reservas `consumed`, cria movimentos `consumption`.
- Liberar reserva (revisar/cancelar) **não** mexe em `on_hand`.
- Entrada de estoque: `POST /parts/{id}/receipts` (movimento `receipt`).
  Saldo nunca é editado por `PATCH /parts`.

## Códigos de erro

`422` dados inválidos · `403` sem permissão · `409` conflito, com `code`:
`QUOTE_SUPERSEDED`, `INSUFFICIENT_STOCK`, `INVALID_TRANSITION`.

## Fora do escopo do MVP (não implementar)

Multiempresa/filiais, portal do cliente, anexos/PDF/e-mail/WhatsApp,
pagamentos/notas fiscais, compras/custo médio, reserva ou consumo parcial,
alteração de orçamento após início do reparo, garantia/retrabalho, permissões
configuráveis.
