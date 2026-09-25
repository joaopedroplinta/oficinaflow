---
description: Abre a semana N do PLANO.md — situação real, o que falta e o próximo card a puxar do board
argument-hint: [número da semana]
---

Semana alvo: **$1** (se vazio, descubra qual é a primeira com issues abertas).

## 1. Situação real

Não confie no cronograma — confira o que existe de fato:

- `git log --oneline -15` e `git status`
- `php artisan migrate:status`
- `php artisan route:list --except-vendor`
- `php artisan test`
- Board: `gh project item-list 11 --owner joaopedroplinta --format json`
  e `gh issue list --milestone "<semana>" --state all`

## 2. Compare com o PLANO.md

Leia a linha da semana na tabela de cronograma (§10). Diga com clareza:

- O que já está feito
- O que falta para a "entrega verificável" da semana
- O que ficou pendente de semanas anteriores e atrapalha esta

## 3. Puxe o próximo card (Scrumban)

Respeite o WIP (2 em *In Progress*, 2 em *In Review*). Escolha o card de
*Ready* mais alto do milestone; se não houver, refine o primeiro de *Backlog*
(critério de aceite claro) e mova para *Ready*. Cada slice segue a skill
`slice-api`: migration → model → Policy → Form Request → Action → controller
→ rota → Resource → tipo TS → página → teste.

## 4. Antes de começar

Levante o que pode travar e resolva agora:

- Decisão de produto que só o usuário pode tomar (pergunte, não assuma)
- Regra do PLANO.md que o card contradiz
- Dependência externa (hospedagem, domínio, credencial)

Se nada travar, crie a branch (`feat/<slug>`) e comece pelo primeiro slice.
