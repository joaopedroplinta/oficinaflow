# Regras — autorização, validação e segredos

## Autorização

- Toda ação passa por **Policy** (`$this->authorize()` ou `can:` na rota).
  Nunca `if ($user->role === ...)` solto em controller ou Action.
- Dois papéis fixos: `admin` (atendimento) e `technician`. Técnico consulta
  ordens e registra diagnóstico, início e conclusão; admin cuida de estoque,
  orçamentos, decisões, cancelamento e entrega.
- Usuário `active = false` não autentica.
- A permissão é checada **no servidor**. Esconder botão no React é conveniência.

## Validação

- Sempre em **Form Request**; validação só no front não conta.
- Regras iguais às constraints do banco (tamanho, unique, `quantity > 0`).
- O servidor **calcula** `total_cents`; total vindo do cliente é ignorado.
- Preço e descrição do orçamento são snapshot copiado do catálogo pelo
  servidor — o cliente não dita o preço de uma peça de catálogo.

## Mass assignment

- `#[Fillable]` explícito em todo model. Campos que só o sistema define
  (`status`, `current_quote_id`, `total_cents`, `reserved_quantity`, `on_hand`,
  `version`) ficam **fora**. Saldo de estoque muda só por Action
  (`receipts`, reserva, consumo) — nunca `PATCH /parts`.

## Auditoria

Toda transição relevante gera linha em `work_order_events` com autor, tipo,
`from_status`/`to_status` e `metadata`. Decisão de orçamento registra nome do
cliente, canal e data.

## Segredos

- `.env` não é lido nem editado por aqui (negado em `settings.json`).
- Credencial nunca em código, seed, teste, issue ou commit.
- Seed de demonstração usa a senha fictícia só em `local`/`testing`; em qualquer outro
  ambiente exige `DEMO_PASSWORD` (o seed recusa rodar sem ela).
- Login: 5 falhas por e-mail + IP em 1 minuto bloqueiam com 429 (só as falhas contam).
