# E-mail marketing com Amazon SES

Documento de arquitetura e implantação do módulo de campanhas e automações de e-mail da área de membros.

## Implementado em 06/07/2026

- seção **E-mail Marketing** no menu e permissão própria para membros da equipe;
- visão geral do canal e resumo exclusivo no dashboard administrativo;
- biblioteca de modelos com histórico imutável de versões;
- editor visual/HTML com blocos de título, texto, botão, imagem, divisor, espaço e descadastro;
- sanitização básica de HTML, geração de texto puro, variáveis de aluno e inclusão obrigatória do descadastro;
- campanhas imediatas ou agendadas, filtros por busca/turma/tag, prévia e congelamento da audiência;
- nova verificação de supressão imediatamente antes de cada envio;
- painel de contatos e supressões com bloqueio manual e ingestão automática de bounce, complaint e unsubscribe;
- editor visual de automações no mesmo padrão do push, com canvas, pan, zoom, conexões, gatilho, condição, temporizador, e-mail, tag, integração e encerramento;
- catálogo único de gatilhos compartilhado por push e e-mail: alterações futuras são feitas em `app/automation_catalog.php`;
- condições com múltiplas regras combinadas por E/OU e saídas obrigatórias SIM/NÃO;
- condições por tag, turma, endereço, elegibilidade, entrega, abertura, clique, link específico, bounce, complaint, descadastro e volume de engajamento;
- versões publicadas de automações separadas do rascunho;
- motor assíncrono de automações com captura dos eventos da plataforma, runs individuais por aluno, jobs com lease, retentativas, temporizadores e idempotência por bloco de e-mail;
- worker `cron/processar_emails.php`, registrado no gerenciador de cron;
- integração direta com SES API v2 assinada por AWS Signature V4, sem expor credenciais no painel;
- endpoint `public/email_ses_webhook.php` com segredo obrigatório, limite de payload, validação criptográfica da assinatura SNS e confirmação segura da assinatura;
- persistência e deduplicação de mensagens/eventos, métricas por campanha e por modelo;
- motor desativado por padrão até concluir a configuração AWS.

### Arquivos principais

- `app/email_marketing.php`: schema, templates, audiências, campanhas, fila, SES e eventos;
- `app/automation_catalog.php`: fonte compartilhada dos gatilhos de push e e-mail;
- `app/email_flow_engine.php`: captura e execução assíncrona das automações;
- `admin/email_dashboard.php`: indicadores detalhados;
- `admin/email_campanhas.php`: criação e operação das campanhas;
- `admin/email_modelos.php` e `admin/email_editor.php`: biblioteca e editor;
- `admin/email_fluxos.php` e `admin/email_fluxo.php`: automações;
- `admin/email_contatos.php`: supressões;
- `admin/email_config.php`: configuração não secreta;
- `cron/processar_emails.php`: processamento assíncrono;
- `public/email_ses_webhook.php`: eventos SNS/SES.

### Estado desta entrega

As telas, tabelas, campanhas, envio SES, ingestão de eventos e execução automática dos grafos estão operacionais. O motor somente captura novos eventos quando estiver ativo; publicar um fluxo não processa alunos ou eventos retroativamente.

## Decisão de produto

O módulo deve ocupar uma seção própria do menu: **E-mail marketing**. Uma única página deixaria criação, operação e análise misturadas. A seção terá:

1. **Visão geral** (`email_dashboard.php`): saúde do canal, entregas, rejeições, bounces, reclamações, descadastros, aberturas e cliques.
2. **Campanhas** (`email_campanhas.php`): disparos manuais imediatos ou agendados para audiência filtrada e congelada.
3. **Automações** (`email_fluxos.php` e `email_fluxo.php`): lista e editor visual dos fluxos.
4. **Modelos** (`email_modelos.php` e `email_editor.php`): biblioteca e editor de e-mails.
5. **Contatos e supressões** (`email_contatos.php`): consentimento, preferências, descadastros, bounces e reclamações.
6. **Configurações**: painel lateral aberto pela engrenagem, com remetentes, domínio, SES, limites e webhooks.

O dashboard administrativo principal exibirá apenas um resumo do canal e um atalho para a visão geral. As análises detalhadas ficam dentro de E-mail marketing.

## Princípios obrigatórios

- Enviar somente para alunos com base legal/consentimento aplicável e nunca para endereços comprados ou raspados.
- O estado local de supressão é soberano: um contato bloqueado localmente nunca entra na fila, mesmo que ainda não esteja suprimido no SES.
- Descadastro, reclamação e hard bounce bloqueiam novos envios de marketing imediatamente.
- E-mails transacionais e de marketing usam categorias, configuration sets e, preferencialmente, subdomínios separados.
- Nenhum envio em massa acontece dentro de uma requisição web. A tela cria filas; workers/cron enviam lotes.
- Todo envio tem chave de idempotência e apenas um destinatário por chamada ao SES.
- O motor inicia globalmente pausado e com limite conservador.
- A audiência de uma campanha é congelada antes da confirmação final e passa novamente pela supressão imediatamente antes do envio.
- Métricas mostram denominadores e nomes tecnicamente corretos. `SEND` é aceito para processamento, não entrega garantida.
- Segredos AWS nunca são exibidos no navegador, gravados no grafo do fluxo ou registrados em logs.

## Arquitetura AWS recomendada

```text
Painel PHP -> MySQL (campanha/fluxo/fila) -> worker cron -> SES API v2
                                                         |
                                                         v
                         SES Configuration Set -> SNS -> endpoint webhook PHP
                                                         |
                                                         v
                         eventos idempotentes -> MySQL -> métricas/supressões
```

### Componentes

- **Amazon SES API v2** para envio. Preferir API ao SMTP pela tipagem, tags, configuração por mensagem e tratamento de erros.
- **SES Configuration Set** dedicado, por exemplo `area-membros-marketing`.
- **Amazon SNS** como destino dos eventos SES e webhook HTTPS autenticado na plataforma. Uma alternativa futura é EventBridge/SQS quando o volume justificar worker desacoplado.
- **SES Contact List + Topics** para gestão nativa de assinatura, sincronizada com o banco local.
- **Account-level suppression list** habilitada para `BOUNCE` e `COMPLAINT`.
- **Custom MAIL FROM** e domínio de rastreamento HTTPS configurados quando disponíveis na região.
- CloudWatch para alarmes operacionais; o banco local permanece como fonte das telas e da jornada de cada aluno.

O SES publica `SEND`, `REJECT`, `BOUNCE`, `COMPLAINT`, `DELIVERY`, `OPEN`, `CLICK`, `RENDERING_FAILURE`, `DELIVERY_DELAY` e `SUBSCRIPTION`. Referência: <https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_EventDestination.html>.

## Preparação da conta AWS

1. Escolher uma região SES única e registrar a região na configuração da aplicação.
2. Verificar o domínio remetente, não apenas um endereço.
3. Publicar os registros DKIM fornecidos pelo SES.
4. Configurar SPF no domínio de MAIL FROM e uma política DMARC. Começar com monitoramento e endurecer a política após validar alinhamento e relatórios.
5. Solicitar saída do sandbox apresentando origem da lista, fluxo de opt-in, tratamento de bounce/complaint e processo de descadastro.
6. Habilitar supressão de conta para bounce e complaint.
7. Criar a contact list e topics, inicialmente `marketing`, `avisos_de_aulas` e `novidades`.
8. Criar o configuration set e habilitar rastreamento de abertura/clique somente onde necessário.
9. Criar tópico SNS e assinatura HTTPS para o webhook público da plataforma.
10. Configurar alarmes de bounce, complaint, falha do webhook, fila acumulada e ausência de processamento.
11. Fazer warm-up gradual. O limite da aplicação deve ser menor ou igual ao limite retornado pela conta SES.

### IAM

Criar um usuário/role exclusivo para a aplicação. Permitir apenas as ações SES necessárias, restritas à região, identidades e configuration sets utilizados. Não usar chaves de root nem chaves de outro sistema.

Configuração exclusivamente por ambiente:

```dotenv
AWS_REGION=sa-east-1
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
SES_CONFIGURATION_SET=area-membros-marketing
SES_CONTACT_LIST=area-membros
SES_DEFAULT_TOPIC=marketing
SES_FROM_EMAIL=contato@envios.exemplo.com.br
SES_FROM_NAME=Professor Emerson
SES_REPLY_TO=suporte@exemplo.com.br
SES_SNS_TOPIC_ARN=...
SES_WEBHOOK_SECRET=...
EMAIL_ENGINE_ENABLED=0
EMAIL_MAX_PER_MINUTE=10
EMAIL_BATCH_SIZE=25
```

No servidor AWS, preferir credenciais temporárias por IAM Role. Em hospedagem externa, usar segredo fora do diretório público, permissões mínimas e rotação. O arquivo `.env` não entra no Git.

## Envio pelo SES

Usar `SendEmail` da API v2, um destinatário por chamada. Parâmetros mínimos:

- `FromEmailAddress` e `ReplyToAddresses`;
- `Destination.ToAddresses` com um único endereço;
- `Content.Simple.Subject`, `Body.Html` e `Body.Text`, ou conteúdo raw quando realmente necessário;
- `ConfigurationSetName`;
- `EmailTags`: `environment`, `campaign_id`, `flow_id`, `flow_version_id`, `email_id`, `message_kind`;
- `ListManagementOptions.ContactListName` e `TopicName` para marketing.

Salvar o `MessageId` retornado pelo SES junto ao envio local. Tags não devem conter e-mail, nome, telefone ou outros dados pessoais.

O HTML final deve conter a versão texto, endereço/identificação do remetente e o placeholder `{{amazonSESUnsubscribeUrl}}`. Quando `ListManagementOptions` é usado, o SES gera o descadastro e os cabeçalhos apropriados; essa mecânica requer envio individual. Referência: <https://docs.aws.amazon.com/ses/latest/dg/sending-email-subscription-management.html>.

### Anexos

O editor aceita anexos somente com lista de extensões permitidas, verificação de MIME, nome normalizado e limite total conservador configurável. O tamanho deve considerar o aumento da codificação MIME/base64. Arquivos ficam fora da pasta pública e são lidos pelo worker. Bloquear executáveis, scripts, arquivos com senha e conteúdo suspeito. Para arquivos grandes, preferir botão com URL assinada e expiração em vez de anexo.

## Editor de e-mail

O editor terá três colunas:

- esquerda: blocos arrastáveis `texto`, `título`, `imagem`, `botão`, `divisor`, `espaço`, `colunas`, `redes sociais`, `anexo/link` e `descadastro`;
- centro: canvas responsivo com edição direta, reordenação e seleção;
- direita: propriedades do bloco, estilos, espaçamento, alinhamento, URL e comportamento do link.

Recursos obrigatórios:

- modo desktop e celular;
- assunto, preheader e nome do remetente;
- desfazer/refazer e salvamento automático de rascunho;
- escolher modelo ou começar em branco;
- “Salvar como modelo” sempre disponível para criação do zero;
- edição alternativa de HTML completo e CSS compatível com e-mail;
- sanitização do HTML e CSS, remoção de scripts, formulários, iframes e handlers JavaScript;
- CSS inline na renderização final;
- imagens com upload validado, texto alternativo, dimensões e compressão;
- links com destino na mesma página ou nova página apenas como preferência no HTML; clientes de e-mail podem ignorar `target`;
- variáveis permitidas, por exemplo `{{nome}}`, `{{email}}`, `{{turma}}`, `{{link_area_membros}}`;
- rodapé de marketing e bloco de descadastro obrigatórios e não removíveis na validação final;
- envio de teste para uma pequena allowlist, marcado como teste e sem alterar métricas da campanha;
- prévia com dados fictícios e verificação de links, variáveis ausentes, assunto, versão texto, peso e risco de spam.

Templates guardam JSON estruturado, HTML compilado e texto puro. O JSON é editável; a versão publicada é imutável para preservar auditoria.

## Campanhas manuais

Fluxo de criação:

1. Nome interno e objetivo.
2. E-mail/modelo e remetente.
3. Audiência com inclusão e exclusão por busca, turma, tag, progresso, datas, eventos, status de consentimento e engajamento.
4. Exclusão automática de descadastrados, complaints, hard bounces, endereços inválidos e alunos sem e-mail.
5. Prévia com total selecionado, total elegível e motivos das exclusões.
6. Envio de teste.
7. Envio imediato ou agendamento no fuso `America/Sao_Paulo`.
8. Confirmação final mostrando assunto, remetente, audiência e velocidade.

A lista de destinatários é congelada ao criar a campanha. Pausar impede novos envios; cancelar não recupera mensagens já aceitas pelo SES.

## Automações

Reutilizar os conceitos sólidos do motor push — grafo versionado, jobs, leases, idempotência e esperas — mas não misturar tabelas ou execução dos dois canais nesta primeira versão.

### Blocos

- gatilho;
- condição com saídas `sim` e `não`;
- espera;
- e-mail;
- ação de tag;
- integração;
- encerramento.

O bloco e-mail permite escolher um modelo ou criar/editar um e-mail dentro do fluxo. Um e-mail criado ali pode ser salvo como modelo. Na publicação, o bloco aponta para uma revisão imutável do conteúdo.

### Condições de e-mail

- enviado/aceito pelo SES;
- entregue;
- entrega atrasada;
- rejeitado;
- hard bounce;
- reclamação de spam;
- aberto pelo menos uma vez;
- clicou em qualquer link;
- clicou em link específico;
- não abriu/não clicou após período configurado;
- descadastrou-se do tópico ou de tudo;
- quantidade de envios, entregas, aberturas ou cliques em uma janela;
- último engajamento antes/depois de uma data;
- contato está elegível para marketing.

Uma condição negativa como “não abriu” só pode ser avaliada depois de um bloco de espera. Aberturas são aproximações: bloqueio de imagens, cache e proteções de privacidade podem omitir ou gerar aberturas automáticas. Cliques também podem ser produzidos por scanners de segurança. Decisões críticas não devem depender apenas desses sinais.

## Webhook e processamento de eventos

Criar `public/email_ses_webhook.php` com estas regras:

- aceitar somente HTTPS;
- validar assinatura e tipo da mensagem SNS conforme a documentação AWS, sem confiar em URL de certificado arbitrária;
- suportar confirmação de assinatura de forma controlada;
- limitar tamanho e método da requisição;
- persistir o payload mínimo necessário;
- deduplicar por identificador estável do evento mais tipo/data;
- responder rapidamente e processar efeitos de forma assíncrona;
- nunca registrar conteúdo completo do e-mail nem dados pessoais desnecessários.

Mapeamento local:

| Evento SES | Estado/ação local |
|---|---|
| `SEND` | aceito para processamento |
| `DELIVERY` | entregue ao servidor destinatário |
| `DELIVERY_DELAY` | atraso temporário; manter observação |
| `OPEN` | registrar primeira e total de aberturas |
| `CLICK` | registrar URL normalizada, primeira e total de cliques |
| `BOUNCE` permanente | suprimir imediatamente |
| `COMPLAINT` | suprimir imediatamente e elevar alerta |
| `REJECT` | falha definitiva |
| `RENDERING_FAILURE` | falha definitiva do conteúdo/variáveis |
| `SUBSCRIPTION` | sincronizar preferência e impedir novo marketing |

## Persistência sugerida

- `email_settings`: configuração não secreta e limites.
- `email_senders`: identidades e estado de verificação.
- `email_templates` e `email_template_versions`.
- `email_flows`, `email_flow_versions`, `email_flow_runs`, `email_flow_jobs`.
- `email_campaigns`, `email_campaign_recipients`.
- `email_messages`: um registro por destinatário, conteúdo/revisão e `ses_message_id`.
- `email_events`: eventos SES deduplicados.
- `email_link_events`: cliques por URL/código do link.
- `email_contact_preferences`: consentimento, topics e origem/data.
- `email_suppressions`: motivo, fonte, data e possibilidade de reversão.
- `email_daily_metrics`: agregados para dashboard.

Não criar tabelas automaticamente ao abrir páginas administrativas em produção. Usar migrações explícitas, versionadas e reversíveis.

## Métricas

### Dashboard do canal

- selecionados, elegíveis, enviados, aceitos pelo SES e entregues;
- entrega atrasada, rejeição, rendering failure, bounce e complaint;
- descadastros e contatos atualmente suprimidos;
- aberturas únicas/totais e cliques únicos/totais;
- taxa de entrega = entregues / aceitos;
- taxa de bounce = bounces / aceitos;
- taxa de complaint = complaints / entregues;
- taxa de abertura = destinatários com abertura / entregues;
- CTR = destinatários com clique / entregues;
- CTOR = destinatários com clique / destinatários com abertura;
- evolução diária e comparação com período anterior;
- saúde da fila, idade do item mais antigo, velocidade e último worker.

Cada campanha, fluxo, bloco de e-mail e revisão de conteúdo mostra seus próprios indicadores. A interface deve sinalizar amostra pequena e não comparar taxas com denominadores diferentes.

## Proteções anti-spam e anti-bloqueio

- double opt-in onde aplicável e registro de origem, data, IP e texto de consentimento;
- limpeza de endereços inválidos antes do envio, sem “validadores” que façam enumeração abusiva;
- limite diário e por minuto abaixo da quota SES;
- redução automática de velocidade diante de bounce, complaint ou throttling;
- circuit breaker: pausar marketing automaticamente ao ultrapassar limites internos conservadores;
- bloqueio imediato de complaint, hard bounce e unsubscribe;
- soft bounce com tentativas limitadas e intervalo crescente;
- retentativa apenas para erros transitórios e throttling, com jitter e máximo definido;
- horários silenciosos e limite de frequência por contato;
- teste progressivo por lotes e possibilidade de interromper campanha;
- domínio, remetente e conteúdo consistentes; evitar encurtadores de URL e anexos desnecessários;
- monitoramento de DKIM, SPF, DMARC, reputação e quotas;
- auditoria de criação, edição, publicação, pausa, cancelamento e exportação;
- permissão de equipe separada para visualizar, editar, testar, publicar e disparar.

## Ordem de implantação

### Fase 1 — fundação segura

- AWS, domínio, IAM, SDK, migrations, configurações e webhook SES;
- contatos, preferências, supressões e dashboard operacional básico;
- envio individual de teste para allowlist.

### Fase 2 — modelos e campanhas

- editor, versões, prévia e teste;
- filtros, congelamento de audiência, agendamento, fila e métricas por campanha/e-mail.

### Fase 3 — automações

- editor visual e motor de fluxo;
- waits e condições de entrega, abertura, clique, bounce, complaint e descadastro.

### Fase 4 — robustez

- testes de carga e concorrência;
- alarmes, circuit breaker, retenção, agregações e exportações;
- testes reais em Gmail, Outlook, Apple Mail e clientes móveis.

## Critérios para liberar produção

- domínio verificado, DKIM/SPF/DMARC validados e conta fora do sandbox;
- webhook recebe e deduplica todos os tipos de evento habilitados;
- unsubscribe de rodapé e one-click testados e sincronizados localmente;
- hard bounce e complaint bloqueiam o contato antes de qualquer próximo job;
- worker é idempotente, respeita quota, pausa global e retentativas;
- nenhum segredo aparece em HTML, logs ou banco de configuração comum;
- testes de template não contaminam métricas de produção;
- campanhas exigem prévia, teste e confirmação explícita;
- restore de banco e procedimento de incidente documentados;
- envio piloto realizado primeiro com audiência pequena e engajada.

## Referências AWS

- Eventos e destinos: <https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_EventDestination.html>
- Gestão de assinatura e one-click unsubscribe: <https://docs.aws.amazon.com/ses/latest/dg/sending-email-subscription-management.html>
- Contact lists e topics: <https://docs.aws.amazon.com/ses/latest/dg/sending-email-list-management.html>
- Formato dos eventos: <https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-firehose-contents.html>
