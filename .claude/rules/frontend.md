# Regras — React SPA e UI

## Dados

- Todo acesso à API passa por `resources/js/api.ts` (CSRF, JSON, `ApiError`).
  Nada de `fetch` espalhado em página.
- A API é a fonte de verdade. Validação do front é conveniência; o `422` do
  Laravel (`errors`) é mostrado no campo certo.
- Erro `409` com `code` (`QUOTE_SUPERSEDED`, `INSUFFICIENT_STOCK`,
  `INVALID_TRANSITION`) vira mensagem em português explicando o que fazer —
  e recarrega o dado se a tela estava desatualizada.
- Ações dependentes de orçamento enviam o `quote_id` que a tela exibe.

## Tipagem

- `strict`. Sem `any`. Tipos das respostas em `resources/js/types/`,
  espelhando os Resources do backend. Use `type`, não `interface`.
- `npm run build` roda `tsc --noEmit`; deve passar antes de abrir PR.

## Estados obrigatórios

Toda tela que lista ou carrega algo trata os três:

- **Vazio** — mensagem com a próxima ação ("Nenhuma ordem ainda. Abrir ordem").
- **Carregando** — skeleton/spinner; botão de envio desabilitado enquanto envia.
- **Erro** — mensagem em português dizendo o que fazer, nunca stack trace.

## Idioma

- Tudo que o usuário lê é **português**: rótulo, botão, erro, título, estado
  vazio. Enum nunca aparece cru (`awaiting_approval` → "Aguardando aprovação").
- Código, variável, componente, comentário e commit em inglês.
- Dinheiro exibido em BRL a partir de centavos inteiros; datas em
  `America/Sao_Paulo`.

## Ações que mudam efeito

Antes de ação com efeito colateral (revisar orçamento libera reservas; cancelar
libera peças), a interface **explica o efeito** e pede confirmação.

## Pendência de peças

"Aprovada mas aguardando peças" é indicador na tela, **não** um estado novo
da ordem.

## Celular

- Mobile-first: classe base para o celular, `md:`/`lg:` para subir.
- Tabela larga dentro de `overflow-x-auto`; a página nunca rola na horizontal.
- Alvo de toque confortável em botão de ação.

## Acessibilidade

- `<label>` associado a todo input; erro de campo com `aria-describedby`.
- Nunca comunicar estado só por cor — texto ou ícone junto.
- Foco visível; não remover `outline` sem substituto.
