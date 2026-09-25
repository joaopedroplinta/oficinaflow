# OficinaFlow — Plano de projeto

Documento vivo: atualizar conforme decisões mudarem. **Fonte de verdade** sobre
escopo, modelo de dados, regras e cronograma.

---

## 1. Objetivo

Monólito modular para uma assistência técnica que cobre o percurso:

**Receber equipamento → diagnosticar → emitir orçamento → registrar aprovação →
reservar peças → executar reparo → entregar.**

Valor a demonstrar: **aprovação, estoque e execução permanecem coerentes mesmo
quando o orçamento muda ou duas pessoas agem ao mesmo tempo.**

Prazo: 8 semanas a 10–15 h semanais (com testes, demonstração, documentação e
deploy). A estimativa anterior de 4–5 semanas era otimista para esse
acabamento.

## 2. Stack

| Camada | Escolha |
|---|---|
| Backend | Laravel 13 (PHP 8.5), monólito modular |
| API | REST em `/api/v1`, autenticação por sessão com Sanctum (SPA) |
| Frontend | React + TypeScript + Tailwind 4 (Vite), servido pelo Laravel |
| Banco | PostgreSQL 18 (Docker em dev; mesmo banco em CI e produção) |
| Testes | PHPUnit, sempre contra PostgreSQL |
| CI | GitHub Actions (Pint, tsc/build, testes com Postgres) |

Sem Inertia, sem filas, sem Redis no MVP.

## 3. Decisões de domínio

| Assunto | Regra do MVP |
|---|---|
| Operação | Uma assistência, um estoque, um equipamento por ordem |
| Orçamento | Uma ordem possui várias versões; apenas uma é a versão atual |
| Imutabilidade | Depois de emitida, uma versão não permite alterar itens, quantidades ou preços |
| Aprovação | Sempre se refere a uma versão específica |
| Revisão | Antes do reparo, cria outra versão, libera reservas anteriores e exige nova aprovação |
| Reserva | Ocorre após aprovação; reserva todas as peças necessárias ou nenhuma |
| Consumo | Ocorre ao iniciar o reparo, baixando as peças reservadas do estoque |
| Alterações posteriores | Depois de iniciado o reparo, orçamento e peças ficam congelados |
| Pagamento | Não controla recebimentos; entrega e conclusão são operacionais |
| Aprovação do cliente | Registrada pelo atendente, com nome, canal e data; sem portal ou assinatura digital |

**Aprovação e disponibilidade são independentes.** Uma ordem pode estar
aprovada e ainda aguardar peças. A interface mostra essa pendência, sem criar
outro estado da ordem.

## 4. Entidades e schema

Convenções:

- Chaves primárias `bigint`; relacionamentos por foreign keys.
- Datas em `timestamptz`.
- Dinheiro em **centavos inteiros**, nunca `float`.
- Quantidades inteiras (sem peças ou horas fracionadas).
- Estados em colunas texto com `CHECK` + enums PHP.
- Registros transacionais não são apagados; cadastros podem ser desativados.
- `created_at`/`updated_at` onde houver edição.

### Cadastros e entrada do equipamento

| Tabela | Campos principais |
|---|---|
| `users` | `id`, `name`, `email UNIQUE`, `password`, `role`, `active` |
| `customers` | `id`, `name`, `phone`, `email NULL` |
| `devices` | `id`, `customer_id`, `category`, `brand`, `model`, `serial_number NULL`, `active` |
| `work_orders` | `id`, `number UNIQUE`, `customer_id`, `device_id`, `status`, `reported_problem`, `intake_condition`, `accessories`, `diagnosis NULL`, `assigned_technician_id NULL`, `current_quote_id NULL`, `received_at`, `started_at NULL`, `ready_at NULL`, `delivered_at NULL`, `cancellation_reason NULL` |

Em `work_orders`, guardar também um **snapshot da identificação do
equipamento na entrada**: alterar o cadastro depois não muda o histórico. O
cliente vinculado e a identificação de entrada ficam congelados após a
abertura.

### Orçamentos e decisões

| Tabela | Campos principais |
|---|---|
| `quotes` | `id`, `work_order_id`, `version`, `status`, `notes NULL`, `total_cents`, `created_by`, `issued_at NULL`, timestamps |
| `quote_items` | `id`, `quote_id`, `kind`, `part_id NULL`, `description`, `quantity`, `unit_price_cents`, `position` |
| `quote_decisions` | `id`, `quote_id UNIQUE`, `decision`, `customer_name`, `channel`, `decided_at`, `recorded_by`, `notes NULL` |

Valores: `quotes.status` ∈ `draft`, `issued`, `superseded`;
`quote_items.kind` ∈ `part`, `service`; `quote_decisions.decision` ∈
`approved`, `rejected`; `channel` ∈ `phone`, `whatsapp`, `in_person`, …

Separar a decisão do orçamento preserva a aprovação mesmo depois que a versão
vira `superseded`.

Regras: `UNIQUE(work_order_id, version)`; no máximo uma versão não
substituída por ordem (índice único parcial); `quantity > 0` e
`unit_price_cents >= 0`; item de peça exige `part_id`, item de serviço não;
sem peça repetida na versão; descrição e preço são snapshot; o servidor
calcula `total_cents`; `current_quote_id` aponta para orçamento da própria
ordem (FK composta).

### Estoque, reservas e histórico

| Tabela | Campos principais |
|---|---|
| `parts` | `id`, `sku UNIQUE`, `name`, `sale_price_cents`, `on_hand`, `reserved_quantity`, `active` |
| `part_reservations` | `id`, `quote_item_id UNIQUE`, `part_id`, `quantity`, `status`, `reserved_by`, `reserved_at`, `released_at NULL`, `consumed_at NULL` |
| `inventory_movements` | `id`, `part_id`, `kind`, `quantity`, `reservation_id NULL UNIQUE`, `reason`, `created_by`, `created_at` |
| `work_order_events` | `id`, `work_order_id`, `actor_id`, `type`, `from_status NULL`, `to_status NULL`, `metadata JSONB`, `created_at` |

Reserva: `active`, `released`, `consumed`. Movimento: `receipt`,
`consumption`. Eventos: abertura, diagnóstico, emissão, aprovação, revisão,
reserva, início, conclusão, cancelamento e entrega.

```text
disponível = on_hand - reserved_quantity
```

Constraints em `parts`: `on_hand >= 0`, `reserved_quantity >= 0`,
`reserved_quantity <= on_hand`. A soma das reservas ativas deve bater com
`reserved_quantity`; isso é mantido pelas operações transacionais (não cabe em
`CHECK` de linha). Movimentos explicam as entradas e saídas físicas; reservas
explicam o comprometimento temporário; ambos mudam junto com os saldos, na
mesma transação.

## 5. Máquina de estados da ordem

```text
received → diagnosing → awaiting_approval → approved
         → in_progress → ready_for_pickup → delivered
```

Encerramento sem reparo:

```text
received / diagnosing / awaiting_approval / approved
  → awaiting_return → canceled
```

`awaiting_return` = serviço cancelado, **equipamento ainda na assistência**
(cancelar ≠ devolver).

| Ação | Condição e efeito |
|---|---|
| Iniciar diagnóstico | `received → diagnosing` |
| Emitir orçamento | Exige diagnóstico e itens válidos; `diagnosing → awaiting_approval` |
| Aprovar | Exige versão atual emitida e sem decisão; `awaiting_approval → approved` |
| Rejeitar | Registra rejeição e retorna a `diagnosing`; permite revisar ou cancelar |
| Revisar orçamento | Antes do reparo, substitui a versão atual, libera reservas e retorna a `diagnosing` |
| Reservar peças | Exige aprovação atual; não muda o estado da ordem |
| Iniciar reparo | Exige aprovação atual e todas as reservas; consome peças e entra em `in_progress` |
| Concluir reparo | Exige descrição do trabalho executado; entra em `ready_for_pickup` |
| Entregar | `ready_for_pickup → delivered` |
| Cancelar serviço | Permitido antes de `in_progress`; libera reservas e entra em `awaiting_return` |
| Devolver sem reparo | `awaiting_return → canceled` |

Orçamento só de mão de obra pode iniciar reparo sem reservas. **Não há edição
livre de `status`**: cada transição é uma operação com pré-condições próprias.

## 6. Operações transacionais críticas

Cada uma é uma Action (`app/Actions/`) com transação própria e locks na
**mesma ordem** (ordem primeiro, depois peças por ID crescente).

**Reservar peças** — bloquear a ordem; confirmar orçamento atual aprovado;
bloquear as peças em ordem de ID; verificar disponibilidade de todas; criar
reservas e aumentar `reserved_quantity`; registrar evento. Faltou uma → nenhuma
reserva (`409 INSUFFICIENT_STOCK`). Repetir a requisição após sucesso devolve
as reservas existentes.

**Revisar orçamento aprovado** — bloquear a ordem e confirmar que o reparo não
começou; liberar reservas ativas atualizando saldos; marcar o anterior como
`superseded`; copiar itens para nova versão `draft`; atualizar
`current_quote_id`; voltar a `diagnosing` e registrar evento. A aprovação antiga
permanece no histórico mas **não** autoriza a versão nova; a UI explica o efeito
antes da ação.

**Iniciar reparo** — validar aprovação e reservas da versão atual; reduzir
`on_hand` e `reserved_quantity`; marcar reservas `consumed`; criar movimentos de
consumo; ordem → `in_progress`. Qualquer falha → nenhuma alteração persistida.

## 7. API (`/api/v1`)

Sessão Sanctum para o React da própria aplicação. Respostas: `422` dados
inválidos, `403` sem permissão, `409` conflito (`QUOTE_SUPERSEDED`,
`INSUFFICIENT_STOCK`, `INVALID_TRANSITION`).

**Cadastros e consultas**

| Rota | Finalidade |
|---|---|
| `GET/POST /customers` | Buscar e cadastrar clientes |
| `GET/PATCH /customers/{id}` | Consultar e editar cadastro |
| `GET/POST /customers/{id}/devices` | Consultar e cadastrar equipamentos |
| `PATCH /devices/{id}` | Corrigir dados e ativar/desativar equipamento |
| `GET/POST /parts` | Consultar peças e cadastrar catálogo |
| `PATCH /parts/{id}` | Alterar descrição, preço ou atividade; nunca saldo |
| `POST /parts/{id}/receipts` | Registrar entrada física |
| `GET /parts/{id}/movements` | Histórico de estoque |
| `GET /work-orders` | Listar com paginação e filtros |
| `POST /work-orders` | Abrir ordem |
| `GET /work-orders/{id}` | Detalhe, orçamento atual, reservas e pendências |
| `GET /work-orders/{id}/events` | Linha do tempo |

**Diagnóstico e orçamento**

| Rota | Finalidade |
|---|---|
| `POST /work-orders/{id}/start-diagnosis` | Iniciar diagnóstico |
| `PATCH /work-orders/{id}/diagnosis` | Registrar diagnóstico e responsável |
| `POST /work-orders/{id}/quotes` | Criar o primeiro rascunho |
| `GET /work-orders/{id}/quotes` | Listar versões |
| `GET /quotes/{id}` | Consultar uma versão |
| `PUT /quotes/{id}/draft` | Substituir itens e observações do rascunho |
| `POST /quotes/{id}/issue` | Congelar e emitir versão |
| `POST /quotes/{id}/decision` | Registrar aprovação ou rejeição |
| `POST /work-orders/{id}/quote-revisions` | Criar revisão (efeitos da §6) |

**Execução e encerramento**

| Rota | Finalidade |
|---|---|
| `POST /work-orders/{id}/reservations` | Reservar todas as peças |
| `POST /work-orders/{id}/start-repair` | Consumir reservas e iniciar |
| `POST /work-orders/{id}/complete-repair` | Registrar serviço executado e liberar retirada |
| `POST /work-orders/{id}/cancel` | Cancelar serviço e aguardar devolução |
| `POST /work-orders/{id}/deliver` | Entregar reparado ou devolver sem reparo |

Ações dependentes de orçamento enviam o `quote_id` que o usuário estava vendo;
o servidor compara com a versão atual **depois de bloquear a ordem**.

## 8. Escopo do MVP

**Entra:** login e dois perfis fixos (administrador/atendimento e técnico);
administrador gerencia estoque, orçamentos, decisões, cancelamentos e entrega;
técnico consulta ordens e registra diagnóstico, início e conclusão; clientes,
equipamentos e peças; abertura com relato, condição de entrada e acessórios;
diagnóstico e responsável técnico; orçamento com peças e serviços, versões e
aprovação; reserva integral, consumo e liberação; entrada simples de estoque;
entrega e cancelamento antes do reparo; linha do tempo e filtros por estado,
cliente e número; interface responsiva com erros e estados vazios; deploy, seed
de demonstração, testes e README.

**Fica fora:** multiempresa/filiais/depósitos; portal do cliente, links
públicos e assinatura digital; fotos, anexos, PDF, e-mails e WhatsApp
automático; pagamentos, conciliação, impostos, notas fiscais e contas a
receber; fornecedores, compras e custo médio; reservas/consumo parciais;
alteração de orçamento, cancelamento ou devolução de peças após iniciar reparo;
garantia, retrabalho e reabertura de ordens; permissões configuráveis e
criação pública de contas.

Recorte: reparos cujo escopo é definido **antes** do início da execução.

## 9. Testes centrais

Quatro grupos, cada um com alguns cenários:

| Comportamento | Cenário | Prova |
|---|---|---|
| **1. Reserva concorrente e integral** | Duas ordens disputam a última peça; outra precisa de duas mas há uma | Só uma disputa vence; sem saldo negativo nem reserva parcial |
| **2. Aprovação vinculada à versão** | Aprovar e reservar V1; criar V2; tentar usar V1 | V1 e aprovação ficam no histórico; reservas liberadas; V2 exige nova aprovação |
| **3. Início do reparo atômico** | Iniciar com todas as reservas; repetir; falhar durante o consumo | Consumo uma vez; estado e estoque avançam juntos; falha faz rollback integral |
| **4. Cancelamento e transições** | Cancelar com peças reservadas e depois devolver | Reserva liberada sem baixar saldo físico; cancelar ≠ entregar; transições inválidas rejeitadas |

**O teste de concorrência usa PostgreSQL e duas conexões/processos realmente
simultâneos.** Chamadas sequenciais ou SQLite não provam nada. Além disso:
verificações pequenas de autorização, validação e cálculo de totais, e um teste
ponta a ponta do fluxo feliz (abertura → entrega).

## 10. Cronograma (8 semanas)

| Semana | Entrega verificável |
|---|---|
| **1 — Fundação** | Projeto, banco, migrations iniciais, login, perfis, estrutura React; primeira versão acessível para validar o caminho de deploy |
| **2 — Entrada e diagnóstico** | Clientes, equipamentos, abertura de ordem, listagem, detalhe, diagnóstico e primeiros eventos |
| **3 — Orçamentos** | Itens, totais, emissão, decisão e revisão; interface de versões; testes da aprovação |
| **4 — Estoque e reservas** | Entradas, saldos, reserva integral, liberação e teste concorrente |
| **5 — Execução completa** | Início e consumo, conclusão, entrega, cancelamento e devolução sem reparo; testes de atomicidade e transições |
| **6 — Integração e usabilidade** | Telas completas, permissões, filtros, mensagens de conflito, responsividade e teste ponta a ponta |
| **7 — Apresentação** | Seed consistente, README, diagrama, testes automatizados no CI e deploy atualizado |
| **8 — Validação e margem** | Teste com outra pessoa, correções, instalação em ambiente limpo, gravação da demonstração |

Desde a semana 3, testes nascem junto das regras. A semana 8 corrige o que for
encontrado; não começa a verificar.

**Critério de conclusão:** visitante acessa a demo e executa o fluxo sem
cadastrar tudo do zero; há exemplos de ordem em diagnóstico, orçamento
aprovado sem estoque, orçamento revisado e reparo pronto; os quatro grupos de
teste passam em PostgreSQL; README explica problema, escopo, execução, modelo,
regras e limitações; dá para demonstrar em poucos minutos **uma ordem completa
e uma exceção relevante**, explicando como o banco preserva a consistência.

## 11. Definição de pronto

Uma feature não está pronta sem:

- Policy cobrindo quem pode fazer o quê
- Validação em Form Request (não só no front)
- Regra de negócio numa Action transacional, com locks na ordem certa
- Teste do caminho feliz **e** de pelo menos um erro (403/422/409); em
  concorrência, teste real em PostgreSQL
- `./vendor/bin/pint --test` e `npm run build` (tsc) passando
- Funciona em tela de celular; estados de vazio, carregando e erro tratados
- Textos em português, sem enum cru nem string técnica vazando
- Dinheiro em centavos inteiros; total calculado no servidor

`/pronto` confere isso antes do merge.

## 12. Fluxo de trabalho (Scrumban)

Board: <https://github.com/users/joaopedroplinta/projects/11>.

- **Colunas:** Backlog → Ready → In Progress → In Review → Done.
- **Puxar, não empurrar:** só entra em *In Progress* o que está em *Ready*.
- **WIP:** máx. 2 em *In Progress* e 2 em *In Review*.
- **Cadência:** milestones "Semana 1–8" dão o ritmo; sem sprint fechado — o que
  não terminou na semana é repriorizado, não descartado.
- **Uma branch por issue** (`feat/…`, `fix/…`, `chore/…`, `docs/…`); toda PR
  nasce de uma issue e termina com `Closes #N`; merge com **merge commit** (sem
  squash, o histórico da branch é preservado), feito pelo agente com CI verde e sem
  bloqueio do `revisor-invariantes`; tag/release seguem com confirmação do usuário.
- **Commits e títulos de PR em inglês** com prefixo (`feat:`, `fix:`, `docs:`,
  `chore:`, `test:`); código, issues e docs em português onde o leitor é humano.
- **CI** (`.github/workflows/ci.yml`) roda Pint, `tsc`/build e os testes contra
  Postgres a cada push e PR. `board.yml` move os cards conforme issues e PRs.
- Versões: agente `lancador` (SemVer + `CHANGELOG.md` + tag + Release).
