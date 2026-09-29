# Changelog

Mudanças notáveis deste projeto, por versão. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); o projeto segue
[SemVer](https://semver.org/lang/pt-BR/) — enquanto a major for `0`, um minor
pode trazer mudança que quebra compatibilidade.

## [Não lançado]

## [1.0.0] - 2026-09-28

Primeira versão estável: o escopo completo do PLANO, incluindo a fase 2 (PDF,
anexos, envio ao cliente, garantia e retorno, reserva e início parciais, adendo,
estorno, fornecedores com custo médio e pedidos de compra), no ar no Railway em
<https://oficinaflow-production.up.railway.app> com deploy automático a cada
merge em `main`, e com a interface redesenhada.

### Adicionado

- PDF do orçamento e da ordem de serviço ([#108](https://github.com/joaopedroplinta/oficinaflow/pull/108))
- Fotos e documentos anexados à ordem ([#109](https://github.com/joaopedroplinta/oficinaflow/pull/109))
- Envio do orçamento ao cliente por e-mail ou link de WhatsApp ([#110](https://github.com/joaopedroplinta/oficinaflow/pull/110))
- Garantia e retrabalho: nova ordem ligada à ordem entregue ([#111](https://github.com/joaopedroplinta/oficinaflow/pull/111))
- Reserva parcial opcional, com várias reservas por item ([#112](https://github.com/joaopedroplinta/oficinaflow/pull/112))
- Início parcial do reparo e consumo passo a passo durante a execução ([#113](https://github.com/joaopedroplinta/oficinaflow/pull/113))
- Adendo ao orçamento durante o reparo, com numeração própria ([#114](https://github.com/joaopedroplinta/oficinaflow/pull/114))
- Estorno de peças no livro-razão e cancelamento de reparo em andamento ([#115](https://github.com/joaopedroplinta/oficinaflow/pull/115))
- Fornecedores, custo nas entradas, custo médio ponderado e margem (só para o
  atendimento, nunca nos PDFs do cliente) ([#116](https://github.com/joaopedroplinta/oficinaflow/pull/116))
- Pedidos de compra com recebimento parcial contra o pedido ([#117](https://github.com/joaopedroplinta/oficinaflow/pull/117))
- Navegação de área de trabalho redesenhada, abertura de ordem guiada, aviso de
  alterações não salvas e testes de navegador (Playwright) no CI ([#119](https://github.com/joaopedroplinta/oficinaflow/pull/119))
- Busca de peças no seletor do editor de orçamento ([#95](https://github.com/joaopedroplinta/oficinaflow/pull/95))
- Indicador de peças pendentes na lista de ordens ([#93](https://github.com/joaopedroplinta/oficinaflow/pull/93))
- Deploy no Railway com publicação automática a cada merge em `main` ([#122](https://github.com/joaopedroplinta/oficinaflow/pull/122))
- Constraints de banco para datas de execução e tipo do item reservado ([#91](https://github.com/joaopedroplinta/oficinaflow/pull/91))
- Teste da ordem de locks entre peças e entre iniciar e concluir reparo ([#92](https://github.com/joaopedroplinta/oficinaflow/pull/92))

### Alterado

- Redesenho visual (direção "bancada"): cabeçalho da ordem, linhas de metadados,
  novos textos da página inicial e do login, ajustes de diretrizes de interface ([#124](https://github.com/joaopedroplinta/oficinaflow/pull/124))
- Polimento de interface: foco, retorno de "salvo" e papéis dos estados ([#94](https://github.com/joaopedroplinta/oficinaflow/pull/94))
- Confiança em proxies e limite de tentativas de login atômico ([#90](https://github.com/joaopedroplinta/oficinaflow/pull/90))
- PLANO alinhado à API implementada, ao escopo da fase 2 e à decisão sobre o
  escopo do técnico ([#96](https://github.com/joaopedroplinta/oficinaflow/pull/96)) ([#107](https://github.com/joaopedroplinta/oficinaflow/pull/107)) ([#120](https://github.com/joaopedroplinta/oficinaflow/pull/120))
- README com a documentação de deploy a partir de merges no GitHub ([#126](https://github.com/joaopedroplinta/oficinaflow/pull/126))

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

[Não lançado]: https://github.com/joaopedroplinta/oficinaflow/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/joaopedroplinta/oficinaflow/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/joaopedroplinta/oficinaflow/compare/v0.1.0...v0.9.0
[0.1.0]: https://github.com/joaopedroplinta/oficinaflow/releases/tag/v0.1.0
