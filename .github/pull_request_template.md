## O que muda

<!-- Uma frase. Se precisar de três, provavelmente são dois PRs. -->

## Como testar

<!-- Passos para quem revisa reproduzir. Usuário/papel necessário. -->

## Definição de pronto (PLANO.md §11)

- [ ] Policy cobrindo quem pode fazer o quê
- [ ] Validação em Form Request (não só no front)
- [ ] Regra de negócio numa Action transacional
- [ ] Teste: caminho feliz + pelo menos 1 erro (403/422/409)
- [ ] Pint e `npm run build` (tsc) passando
- [ ] Funciona em tela de celular; vazio/carregando/erro tratados
- [ ] Textos em português, sem string técnica vazando

## Se mexeu em reserva, estoque, versão de orçamento ou transição

- [ ] Locks: ordem primeiro, peças por ID crescente, validação depois do lock
- [ ] Teste de concorrência real em PostgreSQL
- [ ] Dinheiro em centavos inteiros; total calculado no servidor
- [ ] Rodei o agente `revisor-invariantes`

Closes #
