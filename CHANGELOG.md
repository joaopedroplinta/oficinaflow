# Changelog

Mudanças notáveis deste projeto, por versão. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); o projeto segue
[SemVer](https://semver.org/lang/pt-BR/) — enquanto a major for `0`, um minor
pode trazer mudança que quebra compatibilidade.

## [Não lançado]

## [0.9.0] - 2026-09-25

MVP completo: o fluxo inteiro da assistência técnica, do recebimento do
equipamento à entrega, com aprovação, estoque e execução coerentes mesmo sob
concorrência. Falta a validação (deploy, teste de usabilidade com outra pessoa
e gravação da demonstração) para a 1.0.

### Adicionado

- Clientes e equipamentos, com edição e desativação; equipamento novo bloqueado
  para cliente inativo ([#40](https://github.com/joaopedroplinta/oficinaflow/pull/40)) ([#48](https://github.com/joaopedroplinta/oficinaflow/pull/48))
- Abertura de ordem de serviço com número único e **snapshot** do equipamento na
  entrada, listagem com filtros por estado, cliente e número, detalhe e linha do
  tempo com autor e estados de/para ([#44](https://github.com/joaopedroplinta/oficinaflow/pull/44)) ([#45](https://github.com/joaopedroplinta/oficinaflow/pull/45)) ([#47](https://github.com/joaopedroplinta/oficinaflow/pull/47))
- Diagnóstico: iniciar, registrar e atribuir responsável técnico ([#46](https://github.com/joaopedroplinta/oficinaflow/pull/46))
- Orçamento com **versões**: rascunho com itens de peça e serviço (preço e nome
  copiados do catálogo, total calculado no servidor), emissão que congela a
  versão, decisão do cliente presa à versão e revisão que substitui a atual;
  tela de versões com confirmação do efeito ([#51](https://github.com/joaopedroplinta/oficinaflow/pull/51)) ([#52](https://github.com/joaopedroplinta/oficinaflow/pull/52)) ([#53](https://github.com/joaopedroplinta/oficinaflow/pull/53)) ([#54](https://github.com/joaopedroplinta/oficinaflow/pull/54)) ([#56](https://github.com/joaopedroplinta/oficinaflow/pull/56))
- Catálogo de peças, entradas de estoque e livro-razão de movimentos
  (append-only), com telas de estoque ([#59](https://github.com/joaopedroplinta/oficinaflow/pull/59))
- Reserva de peças **tudo ou nada** e indicador "aguardando peças" na tela,
  sem novo estado da ordem ([#60](https://github.com/joaopedroplinta/oficinaflow/pull/60)) ([#61](https://github.com/joaopedroplinta/oficinaflow/pull/61))
- Iniciar reparo com consumo atômico e idempotente das peças reservadas,
  concluir reparo, entregar, cancelar o serviço (libera reservas sem baixar o
  saldo físico) e devolver sem reparo; ações na tela ([#64](https://github.com/joaopedroplinta/oficinaflow/pull/64)) ([#65](https://github.com/joaopedroplinta/oficinaflow/pull/65)) ([#66](https://github.com/joaopedroplinta/oficinaflow/pull/66)) ([#68](https://github.com/joaopedroplinta/oficinaflow/pull/68))
- Seed de demonstração com quatro ordens de exemplo e README com diagramas,
  regras, permissões, limitações e roteiro da demo ([#75](https://github.com/joaopedroplinta/oficinaflow/pull/75)) ([#76](https://github.com/joaopedroplinta/oficinaflow/pull/76))
- Testes de concorrência com duas conexões reais ao PostgreSQL para aprovação
  presa à versão, reserva, consumo, cancelamento e entradas; teste de ponta a
  ponta e matriz de permissões de todas as rotas ([#55](https://github.com/joaopedroplinta/oficinaflow/pull/55)) ([#64](https://github.com/joaopedroplinta/oficinaflow/pull/64)) ([#66](https://github.com/joaopedroplinta/oficinaflow/pull/66)) ([#69](https://github.com/joaopedroplinta/oficinaflow/pull/69))

### Alterado

- Mensagens de erro e validação em português em toda a interface, estados de
  vazio, carregando e erro revisados e alvos de toque para celular ([#72](https://github.com/joaopedroplinta/oficinaflow/pull/72))

### Segurança

- Sessão de usuário desativado depois do login passa a ser encerrada ([#43](https://github.com/joaopedroplinta/oficinaflow/pull/43))
- Limite de tentativas no login (5 falhas por e-mail e IP por minuto) e seed de
  demonstração que exige `DEMO_PASSWORD` fora de ambiente local ou de teste ([#71](https://github.com/joaopedroplinta/oficinaflow/pull/71))

## [0.1.0] - 2026-09-25

Primeira versão: fundação do projeto, com login e perfis, integração contínua
e o fluxo de trabalho.

### Adicionado

- Base do sistema: Laravel 13, PostgreSQL 18 e React/TypeScript/Tailwind, com
  login por sessão (Sanctum) e perfis de atendimento e técnico
- Rótulo do perfil em português na API e teste que exige rótulo em todo enum
  ([#35](https://github.com/joaopedroplinta/oficinaflow/pull/35))
- Integração contínua com Pint, `tsc` e testes em PostgreSQL
  ([#35](https://github.com/joaopedroplinta/oficinaflow/pull/35))
- Documentação do projeto, regras e agentes do Claude Code e board Scrumban
  ([#35](https://github.com/joaopedroplinta/oficinaflow/pull/35))

[Não lançado]: https://github.com/joaopedroplinta/oficinaflow/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/joaopedroplinta/oficinaflow/compare/v0.1.0...v0.9.0
[0.1.0]: https://github.com/joaopedroplinta/oficinaflow/releases/tag/v0.1.0
