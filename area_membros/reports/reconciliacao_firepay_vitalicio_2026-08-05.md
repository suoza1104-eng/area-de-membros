# Reconciliacao Firepay vitalicio - 2026-08-05

Data da reconciliacao: 2026-08-06
Origem: comparacao manual com painel Firepay informado pelo admin.

## Acessos vitalicios liberados

| Comprador | Email | User ID | Turma | Transacao | Origem do grant |
|---|---:|---:|---:|---:|---|
| Ola! Posso ter mais informacoes sobre isso? | josiascairesdasilvacaires@gmail.com | 61064 | 020826 | firepay:255 | firepay_manual_reconciliation |
| Silvano | silvanobernado@hotmail.com | 61106 | 020826 | firepay:252 | firepay |
| Ricard | ricardoaborges76@gmail.com | 56959 | 250726 | firepay:236 | firepay_manual_reconciliation |
| Alderivan Pereira da Silva | alderivanp7@gmail.com | 60974 | 020826 | firepay:223 | firepay |
| Sinesio da silva castro | sinesioc83@gmail.com | 56021 | 250726 | firepay:203 | firepay_manual_reconciliation |
| Cristian Martins | cristianhmartins@gmail.com | 58307 | 290726 | firepay:190 | firepay_manual_reconciliation |

## Ajustes aplicados

- Criados grants pagos em `course_lifetime_access` para as transacoes Firepay que nao tinham liberacao paga.
- Marcados como `paid`/`APPROVED` no banco os registros Firepay informados como finalizados no painel, quando ainda estavam como `waiting`.
- Mantidos como `firepay` os grants que ja tinham sido liberados automaticamente antes da reconciliacao.
- Atualizados os `lifetime_offer_codes` das turmas `020826`, `250726` e `290726` para aceitar os codigos Firepay `checkout:4`, `product:5`, `integration:GERAL` e `turma:GERAL`.

## Fora do escopo

- Fernando lima (`inova.lima.r@gmail.com`) e GEOMARIO ALVES SOBRINHO (`geomarioalves90@gmail.com`) nao foram tratados como compra de acesso vitalicio neste ajuste, pois os produtos visiveis no print nao eram o produto "Iniciacao em Montagem de Quadros VITALICIO".
