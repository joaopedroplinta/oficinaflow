# OficinaFlow

Sistema de ordens de serviço para assistência técnica (projeto de portfólio).
Percurso do MVP: receber equipamento → diagnosticar → orçar → registrar
aprovação → reservar peças → reparar → entregar. Foco técnico: aprovação,
estoque e execução coerentes sob concorrência e revisão de orçamento.

## Estado atual

Semana 1 (fundação) pronta: Laravel + Sanctum (sessão/SPA), login/logout/me em
`/api/v1`, perfis `admin` e `technician`, shell React com login, Postgres via
Docker, CI e board Scrumban. Semanas 2 a 7 prontas: o fluxo inteiro (cadastros,
abertura com snapshot, diagnóstico, orçamento com versões, decisão, estoque com
livro-razão, reserva tudo-ou-nada, reparo com consumo, conclusão, entrega,
cancelamento e devolução) com API, telas, matriz de permissões, teste de ponta
a ponta, testes de concorrência em Postgres, seed de demonstração (4 ordens) e
README com diagramas e roteiro. A instalação em ambiente limpo foi verificada
(#33). Faltam o primeiro deploy (#4/#31, depende de hospedagem), o teste com
outra pessoa (#32) e a gravação da demonstração (#34). Sem deploy ainda. Confira `git log` e o board antes de afirmar progresso —
esta seção não é atualizada a cada PR.

## Leia o PLANO.md primeiro

`PLANO.md` é a fonte de verdade sobre escopo, schema, máquina de estados,
operações transacionais e cronograma. **Antes de propor tabela nova, estado
novo ou mudança de stack, verifique se a decisão já está lá.** O que está em
"Fica fora" (§8) não se implementa sem o usuário mudar o escopo.

## Stack

Laravel 13 (PHP 8.5), PostgreSQL 18, React + TypeScript + Tailwind 4 (Vite),
Sanctum em modo SPA, PHPUnit. Monólito modular: o Laravel serve o shell React
(`resources/views/app.blade.php`) e a API REST em `/api/v1`. Sem Inertia.

## Comandos

```bash
docker compose up -d --wait        # Postgres :5432 (dbs oficinaflow e oficinaflow_testing)
php artisan migrate --seed         # admin@ / tecnico@oficinaflow.test, senha "password"
php artisan serve                  # app em :8000 (usa public/build)
npm run dev                        # Vite com HMR
npm run build                      # tsc --noEmit + vite build
php artisan test                   # PHPUnit, sempre em Postgres
./vendor/bin/pint                  # formatação PHP
```

O CI (`.github/workflows/ci.yml`) roda Pint, `tsc` e os testes contra Postgres
a cada push e PR. Antes de abrir PR, rode `pint --test`, `npm run build` e
`php artisan test` localmente.

`migrate:fresh`, `migrate:rollback` e `db:wipe` estão negados em
`.claude/settings.json`. Se precisar recriar o banco, peça ao usuário.

## Convenções

**Idioma:** interface, mensagens de erro e docs em **português**. Código, nomes
de tabela, migrations, comentários e commits em **inglês**.

**Dinheiro/quantidade:** centavos inteiros (`*_cents`) e quantidades inteiras;
nunca `float`. Datas `timestamptz`.

**Estado:** coluna texto + `CHECK` + enum PHP com `label()` em português. **Não
existe edição livre de `status`** — cada transição é uma Action.

**Regra de negócio:** em `app/Actions/<Dominio>/`, uma classe por caso de uso,
dona da própria transação e dos locks. Controller é fino.

**Autorização:** Policy, nunca `if ($user->role === ...)` solto.

**Validação:** Form Request; o servidor calcula totais e copia snapshots.

**Erros de API:** `422` validação, `403` permissão, `409` conflito com `code`
(`QUOTE_SUPERSEDED`, `INSUFFICIENT_STOCK`, `INVALID_TRANSITION`).

**Front:** arquivos em minúsculo com hífen, `type` (não `interface`), todo
fetch por `resources/js/api.ts`.

**Migrations:** uma tabela por migration, `down()` de verdade.

**Testes:** feature tests herdam o header `Referer` stateful de
`tests/TestCase.php` (sem ele o Sanctum não abre sessão). Concorrência exige
Postgres com duas conexões reais — SQLite não vale.

## Regras

Invariantes, carregadas junto com este arquivo:

@.claude/rules/estrutura.md
@.claude/rules/banco.md
@.claude/rules/concorrencia.md
@.claude/rules/seguranca.md
@.claude/rules/frontend.md

## Ferramentas do projeto

**Skills** (carregam sozinhas quando o assunto aparece)
- `slice-api` — feature vertical completa (migration → Action → controller → página → teste)
- `regras-oficina` — máquina de estados, versões de orçamento, reserva e consumo

**Comandos** (você dispara)
- `/semana [n]` — abre a semana N: situação real vs. PLANO.md e próximo card a puxar
- `/pronto` — confere as mudanças contra a Definição de Pronto (PLANO.md §11)
- `/demo` — ensaia a demonstração final (ordem completa + uma exceção)

**Agentes**
- `revisor-invariantes` — caça violação de lock, reserva, versão, transição e autorização
- `testador` — escreve testes de uma feature já pronta (inclui concorrência)
- `pr-housekeeping` — abre/mergeia PR e issue e move os cards no board
- `lancador` — bump de versão, CHANGELOG, tag e GitHub Release

## Fluxo de trabalho (Scrumban)

Board: <https://github.com/users/joaopedroplinta/projects/11> — Backlog → Ready
→ In Progress → In Review → Done, WIP máx. 2 em *In Progress* e 2 em *In
Review*. Uma branch por issue; toda PR nasce de uma issue e termina com
`Closes #N`; merge com **merge commit** (`--merge`, sem squash), feito por mim com CI verde e sem
bloqueio do `revisor-invariantes` (condições em `pr-housekeeping`). Detalhes em
`PLANO.md` §12 e `.claude/agents/pr-housekeeping.md`. `board.yml` move os
cards e precisa do secret `PROJECT_TOKEN`.

## Testar no navegador

Complementa os testes, não substitui: o CI não roda navegador.

```bash
docker compose up -d && php artisan serve   # :8000
npm run dev                                  # outro terminal, se editar o front
```

Em tela React, prefira digitar com eventos reais e clicar por referência
(`ref`), não por coordenada; `form_input` altera o DOM sem o React saber.

## Cuidado

- `.env` não é lido nem editado por aqui — negado nas permissões
- Não commitar `.env`, `node_modules/`, `vendor/`, `public/build`
- `.claude/settings.local.json` (se criado) vai no `.gitignore`
- Nunca push direto em `main` para trabalho de feature; sempre branch + PR
- Autonomia de merge só com CI verde; tag/release sempre com confirmação do usuário
