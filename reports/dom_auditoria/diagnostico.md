# Auditoria DOM Pagamentos

Fonte: `C:\Users\Emerson\Downloads\4a80f28b9c7d3c90dffb7467e7507fa79995.csv`

Chave de confronto: `id_transaction/order_id` da planilha contra `payment_sales.external_transaction_id`, aceitando a forma com prefixo `dom:<id>` e sem prefixo.

## Resumo

- Planilha DOM: 157 transacoes unicas.
- Banco `payment_sales` com `provider='dom'`: 150 transacoes.
- Planilha aprovada: 119 vendas, R$ 11.104,29 bruto, R$ 10.072,71 liquido.
- Banco aprovado: 114 vendas, R$ 11.050,39 bruto, R$ 10.023,65 liquido.
- Diferenca aprovada: planilha tem 5 vendas, R$ 53,90 bruto e R$ 49,06 liquido a mais.

## Divergencias

- 7 transacoes da planilha nao existem no banco:
  - 5 `APPROVED`
  - 2 `CANCELED`
- Nao ha divergencias de status/valor entre transacoes que existem nos dois lados.
- Nao ha transacoes extras no banco fora da planilha, considerando esta chave.
- Existe 1 transacao duplicada na planilha (`dc13f28a-39c1-4509-aef2-b6d62795f241`), mas a segunda linha e item adicional com status/valor vazios e total zero; foi ignorada na contabilidade.

## Conclusao

O banco DOM esta consistente para as transacoes que existem nos dois lados. A correcao necessaria e inserir as 7 transacoes ausentes, especialmente as 5 aprovadas que explicam a diferenca de faturamento.

Arquivos:

- `resumo.json`
- `faltando_no_banco.csv`
- `campos_divergentes.csv`
- `divergencias_dom.csv`
