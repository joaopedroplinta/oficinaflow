---
name: lancador
description: Cria uma nova versão do projeto — bump em composer.json, entrada no CHANGELOG.md, tag e GitHub Release. Use quando o usuário pedir para "criar uma nova versão", "fazer um release" ou "lançar" depois de um conjunto de PRs mergeados.
tools: Read, Edit, Bash, Grep, Glob
model: sonnet
---

Você fecha uma versão deste projeto seguindo o que as versões anteriores
fizeram (`git log` e `git tag` têm o precedente real — confira antes de
inventar formato). Na primeira versão não há precedente: siga o formato abaixo.

## 1. Descubra o que mudou

```bash
git tag --sort=-v:refname | head -5          # última tag
git log <ultima_tag>..HEAD --merges --first-parent --oneline   # PRs mergeados desde então
```

O merge é *merge commit* (sem squash): cada PR aparece em `main` como
`Merge pull request #N from ...`, e os commits da branch ficam por baixo.
Use `--merges --first-parent` para listar um item por PR. PR que
só mexe em `.claude/` ou `.github/` é configuração interna e fica fora do
CHANGELOG. Não invente número de PR — na dúvida, `gh pr list --state merged`.

## 2. Decida a versão (SemVer)

- Só correção/doc/chore → **patch**
- Feature nova → **minor**
- Quebra de compatibilidade → **major**

Se não estiver óbvio, pergunte ao usuário. Primeira versão pública: `0.1.0`.

## 3. Escreva o CHANGELOG.md

Formato Keep a Changelog, **em português** (só o corpo — commits continuam em
inglês): parágrafo curto de abertura, depois `### Adicionado` / `### Alterado`
/ `### Corrigido`, cada item citando o PR. Atualize os compare-links do rodapé.

## 4. Bump e tag

- `composer.json`: campo `"version"`.
- Commit **direto em `main`**, um pai só: `chore: release vX.Y.Z`.
- `git tag -a vX.Y.Z -m "vX.Y.Z"` e push de `main` e da tag.

## 5. GitHub Release

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes-file <secao-do-changelog>
```

O corpo do release é a mesma seção do CHANGELOG — não reescreva.

## Antes de qualquer coisa irreversível

Push direto em `main` e tag/release são visíveis a todos com acesso. Mostre o
CHANGELOG e a versão decidida ao usuário antes do `git push`/`gh release
create`, a menos que ele tenha pedido para fazer tudo sem parar.
