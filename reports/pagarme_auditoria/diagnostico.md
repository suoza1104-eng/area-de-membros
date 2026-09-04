# Auditoria Pagar.me

Fonte: `C:\Users\Emerson\Downloads\a83f4373-a537-42d5-9d59-2e6cd82a8fbb.zip`

Chave de confronto: `Charge_ID` do CSV contra `payment_sales.external_transaction_id = pagarme:<Charge_ID>`.

## Resumo

- Relatorio Pagar.me: 45 transacoes.
- Banco `payment_sales` com `provider='pagarme'`: 190 transacoes.
- Relatorio aprovado: 38 vendas, R$ 4.590,33 bruto.
- Banco aprovado: 41 vendas, R$ 4.781,42 bruto.
- Diferenca aprovada: banco tem 3 vendas e R$ 191,09 a mais que o relatorio.

## Divergencias encontradas

- 3 transacoes do relatorio nao existem no banco:
  - `pagarme:ch_QJ2e5GDIZ5TkOdA3` - `APPROVED` - R$ 0,35
  - `pagarme:ch_ZLj1vADFgup9z74V` - `CANCELED` - R$ 478,56
  - `pagarme:ch_2nNpXKrSdxsM1eL4` - `CANCELED` - R$ 478,56
- 1 transacao esta com status errado no banco:
  - `pagarme:ch_y6JZLgPU4tpOaB15`: relatorio `CANCELED`, banco `PENDING`.
- 4 aprovadas existem no banco mas nao aparecem no relatorio:
  - `pagarme:ch_DkQ5ZLguOsMRv6ly` e `pagarme:in_zVoPdJSVeUkN0JWD`
  - `pagarme:ch_L78gmr9F7wI8dkbR` e `pagarme:in_drBOnjjSYXtmKOWL`

## Causa provavel

- O banco esta gravando eventos `invoice.paid` como vendas separadas, alem do `charge.paid`, gerando duplicidade em alguns casos.
- O normalizador de status tratava `order.created` como `PENDING` antes de verificar `provider_status=failed`, deixando uma falha como pendente.

## Ajuste aplicado no codigo

- `app/pagarme.php`: estados finais negativos (`failed`, `canceled`, `refunded`, `chargeback`) agora sao avaliados antes de estados pendentes/criados. Isso evita novas cobranças falhadas ficando como `PENDING`.

## Pendencias de banco

Ainda nao alterei os dados historicos. Para alinhar totalmente:

- Atualizar `pagarme:ch_y6JZLgPU4tpOaB15` de `PENDING` para `CANCELED`.
- Remover ou ignorar contabilmente os registros `invoice.paid` duplicados, mantendo apenas `charge.paid` como venda.
- Investigar se as 3 transacoes faltantes nao chegaram por webhook ou se o relatorio exportado usa um recorte diferente.
