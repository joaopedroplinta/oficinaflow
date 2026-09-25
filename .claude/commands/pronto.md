---
description: Confere as mudanças atuais contra a Definição de Pronto do PLANO.md
---

Revise o que mudou no working tree (`git status` + `git diff`, e a branch
contra `main`) contra a Definição de Pronto da seção 11 do `PLANO.md`.

Para cada arquivo/feature tocada, verifique abrindo os arquivos, não por
suposição:

1. **Policy** — existe Policy cobrindo a ação? Controller chama `authorize()`
   ou a rota tem `can:`? Rota pública é intencional?
2. **Form Request** — a entrada é validada no servidor? As regras batem com as
   constraints do banco?
3. **Action transacional** — regra de negócio que toca mais de uma tabela está
   numa Action com `DB::transaction` e locks na ordem certa
   (`.claude/rules/concorrencia.md`)? Sem `status` editável por CRUD?
4. **Testes** — caminho feliz **e** pelo menos um erro (403, 422 ou 409)?
   Se mexeu em reserva/consumo/versão, há teste de concorrência real em
   Postgres? Rode `php artisan test` e reporte o resultado real.
5. **Tipos e build** — `npm run build` (tsc + vite) e
   `./vendor/bin/pint --test` passam?
6. **Celular** — classes Tailwind com breakpoint? Tabela larga com scroll
   próprio?
7. **Estados da UI** — vazio, carregando e erro tratados?
8. **Português** — nada técnico em inglês vazando para o usuário (enum cru,
   mensagem de exceção)?
9. **Dinheiro** — centavos inteiros, total calculado no servidor?

Ao final, liste em duas seções: **Bloqueia o merge** e **Pode ficar para
depois**. Se estiver tudo certo, diga isso direto — sem inventar pendência.

$ARGUMENTS
