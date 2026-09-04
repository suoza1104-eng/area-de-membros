# Diagnostico raiz - Hotmart agosto/2026

## Achados

- A tabela `hotmart_webhook_events` tem eventos Hotmart somente ate `2026-06-27 00:32:11`.
- Em agosto/2026 nao ha nenhum evento gravado em `hotmart_webhook_events`.
- As 53 transacoes que a Hotmart exportou como `Completo` e o sistema mostra como `Pendente` nao possuem evento de webhook no banco.
- Essas transacoes foram atualizadas por `CSV_RECONCILE`, nao por `PURCHASE_COMPLETE`.
- O setting `metrics_hotmart_hottok` nao existe hoje na tabela `settings`. A rota `public/hotmart_metrics_webhook.php` retorna `503` quando esse token esta vazio, antes de gravar o evento.

## Causa provavel

O webhook nativo da Hotmart parou de entrar no sistema depois de `2026-06-27`. Sem o webhook `PURCHASE_COMPLETE`, vendas aprovadas no inicio de agosto continuam como `Pendente` no banco, mesmo depois de passarem a garantia e aparecerem como `Completo` no CSV da Hotmart.

## Impacto

- Faturamento/lucro devem considerar `Aprovado` e `Completo`, uma vez por `transaction_code`.
- O dashboard ja usa `transaction_code` unico em `hotmart_sales_live` e considera status aprovados/completos, entao nao deve duplicar quando o status muda.
- O problema atual e que 53 transacoes nao mudaram para status contabilizavel, somando `R$ 9.725,10` de produtor liquido fora da contabilidade aprovada.

## Ajuste aplicado no codigo

- `public/hotmart_metrics_webhook.php`: `PURCHASE_COMPLETE` agora e normalizado como `COMPLETE`, nao como `APPROVED`.
- `app/metrics/hotmart_sales_helper.php`: status `COMPLETED` e `PURCHASE_COMPLETED` foram adicionados aos status validos.

## Proximas acoes operacionais

- Reconfigurar o HOTTOK em `admin/config_app.php`.
- Confirmar na Hotmart que a URL ativa e `https://professoremersonleite.com/area_membros/public/hotmart_metrics_webhook.php`.
- Reprocessar/conciliar o CSV atual para atualizar as 53 transacoes de `Pendente` para `Completo`.
