# Estudo de integração de pagamentos — Firepay

Atualizado em: 04/07/2026

## Estado da implementação

Primeira etapa implantada no código em 04/07/2026:

- opção `FIREPAY` na tela **Entrada (Webhooks)**;
- recebimento protegido pelo token individual gerado nessa tela;
- armazenamento integral do payload e histórico dos recebimentos;
- tabela normalizada `payment_sales`, separada por provedor e transação;
- idempotência por `provider + external_transaction_id`;
- associação com usuário existente por telefone e, depois, e-mail;
- processamento financeiro de `status: paid` como `APPROVED`;
- espelhamento de vendas pagas nos relatórios atuais com canal `firepay`;
- preservação, sem aprovação automática, de qualquer status ainda desconhecido.

Nesta etapa a integração não cria matrícula nem libera acesso automaticamente. Essa consequência será adicionada depois que a relação entre `integration_id`, produto e turma for validada com eventos reais.

## Objetivo

Integrar a Firepay ao sistema sem substituir a Hotmart. As duas plataformas devem alimentar uma visão única de vendas, mantendo a identificação da origem de cada transação e permitindo que relatórios, filtros, automações e concessão de acesso considerem Hotmart + Firepay.

A entrada da Firepay será feita por webhook configurado na tela **Entrada (Webhooks)**, mas o processamento financeiro deve usar um adaptador específico para Firepay. O webhook genérico atual pode continuar responsável por cadastro, matrícula e tags; ele não deve ser a única fonte do registro financeiro.

Documentação informada: https://fpy.gitbook.io/firepay/configuracoes/integracoes/webhook

## Dados confirmados no payload

| Campo Firepay | Significado | Destino normalizado sugerido |
|---|---|---|
| `id` | Identificação da transação | `external_transaction_id` |
| `status` | Status do pagamento | `provider_status` + `status` normalizado |
| `checkout_id` | Identificador do checkout | `external_checkout_id` |
| `type` | Tipo do pedido, como `main` | `transaction_type` |
| `price_currency` | Moeda, como `BRL` | `currency` |
| `price` | Valor total, em centavos | `gross_amount` |
| `product_price` | Valor-base do produto, em centavos | `product_amount` |
| `interest_fee` | Juros, em centavos | `interest_amount` |
| `installments` | Quantidade de parcelas | `installments` |
| `payment_method` | Método de pagamento | `payment_method` |
| `payment_gateway` | Gateway usado pela Firepay | `payment_gateway` |
| `tenant_id` | Identificação da loja/empresa | `provider_account_id` |
| `product.id` | ID do produto na Firepay | `external_product_id` |
| `product.name` | Nome do produto | `product_name` |
| `product.slug` | Slug do produto | `product_slug` |
| `product.integration_id` | Código de integração | `integration_id` |
| `product.integration_delivery_type` | Tipo da entrega integrada | `integration_delivery_type` |
| `product.turmas` | Turmas informadas no produto | referência para matrícula |
| `origin.description` | Descrição da origem | `origin_description` |
| `origin.slug` | Identificador da origem/link | `origin_slug` |
| `client.name` | Nome do comprador | `buyer_name` |
| `client.email` | E-mail do comprador | `buyer_email` |
| `client.phone` | Telefone do comprador | `buyer_phone` |
| `client.document` | Documento do comprador | `buyer_document` |
| `link` | Link do checkout/carrinho | `checkout_url` |
| `order_bumps` | Itens adicionais da compra | tabela/JSON de itens da transação |
| `presented_orderbump` | Order bump apresentado | metadado comercial |
| `presented_upsell` | Upsell apresentado | metadado comercial |

Os campos `formatted_price`, `formatted_product_price` e `formatted_interest_fee` são apenas representações para exibição. Cálculos e relatórios devem usar os valores inteiros em centavos.

## Normalização proposta

Cada venda deve ter, no mínimo:

- `provider`: `hotmart` ou `firepay`;
- `external_transaction_id`;
- `external_event_id`, quando disponibilizado;
- status original do provedor e status interno normalizado;
- datas do evento, criação, aprovação, cancelamento e reembolso;
- produto, oferta/checkout e código de integração;
- valores em centavos e moeda;
- método, gateway e parcelas;
- comprador e usuário interno relacionado;
- dados de origem/atribuição;
- payload original do webhook para auditoria.

A unicidade da transação deve ser composta por:

```text
(provider, external_transaction_id)
```

Isso evita colisão caso Hotmart e Firepay utilizem o mesmo número de transação.

Para o gateway:

- venda Hotmart: `payment_gateway = hotmart`, salvo se futuramente a Hotmart fornecer um gateway mais específico;
- venda Firepay: usar exatamente o valor recebido em `payment_gateway`, mantendo também o valor original no payload.

## Status internos

Os relatórios não devem depender diretamente dos textos particulares de cada plataforma. A proposta é converter os retornos para:

- `APPROVED` — venda paga/aprovada;
- `PENDING` — aguardando pagamento ou análise;
- `CANCELED` — compra cancelada;
- `REFUNDED` — valor reembolsado;
- `CHARGEBACK` — contestação/estorno;
- `ABANDONED` — carrinho abandonado.

No exemplo recebido, `paid` será mapeado para `APPROVED`. Os demais valores Firepay ainda precisam ser confirmados com payloads/documentação específica. Carrinho abandonado é evento comercial, não venda, e não deve compor faturamento.

## Processamento seguro do webhook

Fluxo recomendado:

1. receber a requisição em um endpoint exclusivo da Firepay;
2. validar autenticação/assinatura ou um segredo forte na URL/cabeçalho;
3. salvar imediatamente o corpo original e os cabeçalhos relevantes;
4. responder rapidamente com HTTP de sucesso;
5. processar o evento com idempotência;
6. criar ou atualizar a transação normalizada;
7. relacionar o comprador ao usuário interno por e-mail/telefone, sem criar duplicidade;
8. aplicar matrícula, cancelamento ou outras consequências conforme o evento;
9. registrar sucesso ou erro para rastreabilidade e reprocessamento.

Receber novamente o mesmo evento não pode duplicar venda, faturamento, usuário ou matrícula.

## Order bump

O payload contém o valor principal e uma lista `order_bumps`. A implementação deve preservar os itens individualmente, além do total da transação. Antes de definir como eles entram nos relatórios, precisamos confirmar se:

- `price` já inclui todos os order bumps;
- cada bump pode liberar um produto/acesso próprio;
- cancelamentos e reembolsos podem ocorrer por item;
- `order_bump` e `order_bumps` sempre representam os mesmos dados ou têm usos diferentes.

Até essa confirmação, o valor confiável para o total cobrado é `price`, sem somar novamente os bumps.

## Pontos ainda não confirmados pela documentação recebida

Antes de ativar a integração em produção, obter ou capturar exemplos reais de:

- venda aprovada;
- pagamento pendente (PIX/boleto/cartão, se aplicável);
- compra cancelada;
- reembolso;
- chargeback;
- abandono de carrinho;
- order bump e upsell;
- evento reenviado pela Firepay.

Também é necessário confirmar com a Firepay:

- lista completa de eventos e valores possíveis de `status`;
- como o tipo do evento é enviado (campo, cabeçalho ou configuração do endpoint);
- autenticação ou assinatura do webhook;
- política de tentativas e intervalos de reenvio;
- existência de ID único do evento;
- campos de data/hora e fuso horário;
- formato de cancelamentos e reembolsos parciais;
- se o mesmo `id` permanece em todas as mudanças de status;
- resposta HTTP esperada e tempo limite;
- tratamento de PIX/boleto expirado e carrinho abandonado.

## Estratégia de implantação

1. Criar armazenamento bruto de eventos por provedor.
2. Criar a estrutura normalizada de transações e itens.
3. Adaptar a entrada Hotmart atual para alimentar a estrutura comum sem interromper as tabelas existentes.
4. Criar o adaptador Firepay e sua configuração na tela Entrada.
5. Atualizar relatórios para consultar a estrutura comum e permitir filtro por plataforma/gateway.
6. Testar cada evento Firepay em ambiente controlado e comparar payload bruto, transação e efeitos no usuário.
7. Migrar gradualmente os relatórios, mantendo compatibilidade com os dados históricos da Hotmart.

## Payload de referência fornecido

O payload de exemplo desta análise representa uma transação principal (`type: main`), paga (`status: paid`), com cartão, 12 parcelas e order bump. Ele deve ser usado em testes automatizados de contrato, mas não substitui payloads reais dos demais eventos.
