# Auditoria Hotmart Agosto 2026

CSV: `C:\Users\Emerson\Downloads\sales_history_20260825230545_2F5E29F716253618490943717533.csv`
Banco: `hotmart_sales_live`, apenas `sales_channel = hotmart`, período por `transaction_date` em agosto/2026.

## Resumo

- CSV: 311 transações (311 aprovadas).
- Banco: 674 transações Hotmart em agosto (311 aprovadas).
- Diferença de aprovadas (CSV - banco): 0.
- Produtor líquido aprovado CSV: R$ 53474.33.
- Produtor líquido aprovado banco: R$ 53474.33.
- Diferença produtor líquido aprovado (CSV - banco): R$ 0.00.
- Faltando no banco: 0.
- Sobrando no banco: 363.
- Transações existentes nos dois lados com campos divergentes: 0.

## Arquivos gerados

- `divergencias_hotmart_agosto_2026.csv`: tudo que precisa de revisão.
- `faltando_no_banco.csv`: vendas presentes na Hotmart e ausentes no banco.
- `sobrando_no_banco.csv`: vendas no banco que não aparecem no CSV.
- `campos_divergentes.csv`: transações encontradas nos dois lados, mas com valor/status/produto/email diferente.
- `resumo_por_dia.csv`: confronto diário das aprovadas.
