---
description: Ensaia a demonstração — uma ordem completa e uma exceção relevante, contra o ambiente de dev
---

Simulação da demonstração final (semanas 7–8 do PLANO.md). Rode isto antes de
gravar ou apresentar, não depois.

Trabalhe **contra o banco de desenvolvimento**, nunca produção. Confirme o
ambiente antes de qualquer escrita.

## 1. Estado inicial (seed de demonstração)

Confira que o seed entrega, sem cadastrar nada do zero:

- [ ] Ordem em diagnóstico
- [ ] Ordem aguardando aprovação
- [ ] Orçamento aprovado **sem estoque** (aguardando peças)
- [ ] Orçamento revisado (V1 substituída, V2 vigente)
- [ ] Reparo pronto para retirada

## 2. Ordem completa (caminho feliz)

Execute de verdade (teste, tinker ou navegador) e reporte o **observado**:

receber → diagnosticar → orçar (emitir) → aprovar → reservar → iniciar reparo
→ concluir → entregar. Confira a linha do tempo e o saldo da peça em cada
passo.

## 3. Uma exceção relevante

Pelo menos uma destas, com a explicação de como o banco preserva a
consistência:

- [ ] Duas ordens disputam a última peça → só uma reserva vence, sem saldo negativo
- [ ] Revisar orçamento aprovado → reservas liberadas, V1 no histórico, V2 exige nova aprovação
- [ ] Ação com `quote_id` antigo → `409 QUOTE_SUPERSEDED`
- [ ] Cancelar com peças reservadas → reserva liberada, saldo físico intacto, ordem em "aguardando devolução"

## 4. Verificações finais

- [ ] Os quatro grupos de teste passam em PostgreSQL (`php artisan test`)
- [ ] Instalação em ambiente limpo (clone, `docker compose up`, migrate, seed, build)
- [ ] Interface usável no celular

## 5. Relatório

Duas seções: **Impede a demonstração** e **Aceitável**. Para cada item que
falhou, diga o que aconteceu de fato e onde está o problema. Se algo passou,
diga que passou — sem inventar pendência.

$ARGUMENTS
