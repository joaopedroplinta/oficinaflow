# Changelog

Mudanças notáveis deste projeto, por versão. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); o projeto segue
[SemVer](https://semver.org/lang/pt-BR/) — enquanto a major for `0`, um minor
pode trazer mudança que quebra compatibilidade.

## [Não lançado]

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

[Não lançado]: https://github.com/joaopedroplinta/oficinaflow/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/joaopedroplinta/oficinaflow/releases/tag/v0.1.0
