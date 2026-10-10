# Roteiro de demonstração

[Voltar ao README](../README.md)

Use uma instalação local com os dados originais do seed. Nos comandos Artisan
abaixo, acrescente `DB_PORT=5433` se estiver usando `npm run start:local`.

O `php artisan migrate --seed` cria quatro ordens de exemplo: **em diagnóstico**, **aprovada sem estoque** (aguardando peças), **orçamento revisado** (V1 aprovada e substituída, V2 aguardando aprovação) e **reparo pronto**. Roteiro de poucos minutos (uma ordem completa e uma exceção):

1. Entre como **atendimento** e abra a ordem da **Carla Souza** (orçamento revisado): mostre as duas versões, a aprovação da V1 preservada no histórico e a linha do tempo. Explique: a aprovação antiga **não** vale para a V2.
2. Registre a aprovação da V2 (nome, canal e data). A ordem passa a _Aprovada_.
3. **Exceção:** abra a ordem do **João Pereira**. Ela está aprovada, mas o aviso _aguardando peças_ mostra que a tela ainda não chegou. Clique em **Reservar peças**: o erro lista o que falta e **nada** é reservado.
4. Vá a **Estoque** → _Tela Galaxy A54_ → **Registrar entrada** (1 unidade). Volte à ordem e reserve de novo: agora passa.
5. Entre como **técnico** e **inicie o reparo** (as peças reservadas são baixadas do estoque), **conclua** informando o trabalho executado.
6. Volte ao **atendimento**, **entregue** o equipamento e mostre a linha do tempo completa e as movimentações da peça (consumo ligado à reserva).
7. **Como o banco protege a consistência:** rode `php artisan test tests/Feature/Concurrency` e explique o cenário das duas conexões disputando a última peça (só uma vence, sem saldo negativo nem reserva parcial).
