# Implementacao Evolution API - Monitoramento de Grupos WhatsApp

Este documento registra a ideia, a arquitetura planejada e o progresso da implementacao do sistema de numeros administradores/espioes para monitorar grupos de WhatsApp.

Sempre que uma etapa for concluida, este arquivo deve ser atualizado para indicar o que esta pronto, o que foi validado e o que ainda falta.

## Objetivo

Criar uma integracao isolada com Evolution API para conectar um ou mais numeros de WhatsApp via QR Code, usar esses numeros como administradores em grupos e, futuramente, monitorar entradas e saidas de participantes.

Em fases posteriores, o sistema podera verificar uma blacklist e remover automaticamente numeros considerados spam, alem de enviar alertas via webhook/SuperFuncionario.

## Principio de Seguranca

A implementacao deve ser incremental e nao deve comprometer o funcionamento atual da area de membros.

Neste primeiro momento, nao devem ser alterados:

- login da area de membros;
- fluxo de alunos;
- webhooks existentes;
- SuperFuncionario atual;
- banco de dados atual, salvo quando uma fase exigir e for aprovada;
- rotinas de cron existentes;
- arquivos criticos do sistema sem necessidade.

O primeiro teste deve usar um numero secundario de WhatsApp, nunca o numero principal da operacao.

## Arquitetura Planejada

### Camada 1: Evolution API

Servico separado, preferencialmente via Docker, responsavel por:

- criar instancias de WhatsApp;
- gerar QR Code;
- manter sessoes conectadas;
- enviar eventos por webhook;
- executar acoes administrativas, como remover participante de grupo.

### Camada 2: Sistema PHP Atual

O sistema PHP sera responsavel por:

- exibir e gerenciar instancias no painel admin;
- receber webhooks;
- registrar eventos;
- consultar blacklist;
- solicitar remocao de numeros quando necessario;
- enviar alertas para SuperFuncionario/webhooks.

### Camada 3: Banco de Dados

Futuramente deve armazenar:

- instancias WhatsApp;
- grupos monitorados;
- numeros em blacklist;
- logs de eventos;
- fila de processamento de webhooks, se necessario.

## Fases da Implementacao

### Fase 1 - Integrar Evolution API e conectar uma instancia via QR Code

Status: Em andamento

Objetivo:

- subir ou preparar a Evolution API de forma isolada;
- criar uma instancia de teste;
- gerar QR Code;
- conectar um numero de WhatsApp;
- confirmar status conectado.

Escopo permitido:

- criar arquivos de infraestrutura isolados;
- criar documentacao de instalacao;
- testar endpoint da Evolution API;
- nao alterar o funcionamento do sistema atual.

Criterios de conclusao:

- Evolution API acessivel;
- instancia criada com sucesso;
- QR Code retornado pela API;
- numero conectado;
- status da instancia confirmado como conectado.

Observacoes:

- validar se sera usado Docker na VPS;
- validar porta/domino onde a Evolution API ficara disponivel;
- usar numero secundario para teste.
- tela administrativa criada em `admin/whatsapp_monitor.php`;
- camada PHP de integracao criada em `app/evolution_api.php`;
- arquivos Docker de referencia criados em `infra/evolution-api/`;
- ainda falta subir a Evolution API real, configurar URL/API key e escanear o QR Code.

### Fase 2 - Receber webhooks de grupo apenas para log bruto

Status: Pendente

Objetivo:

- criar um endpoint separado para receber payloads da Evolution API;
- salvar o payload bruto;
- confirmar eventos de entrada e saida de participantes em grupos.

Escopo permitido:

- criar endpoint publico isolado;
- criar tabela simples de logs brutos, se aprovado;
- nao executar nenhuma acao automatica em grupos.

Criterios de conclusao:

- webhook recebe eventos da Evolution API;
- payload bruto fica registrado;
- campos principais sao identificados: instancia, grupo, acao e participante;
- eventos reais de grupo foram validados.

### Fase 3 - Modelagem de dados definitiva

Status: Pendente

Objetivo:

- criar tabelas para instancias, grupos, blacklist e logs;
- padronizar numeros de telefone;
- preparar indices para consulta rapida.

Tabelas previstas:

- `whatsapp_instances`;
- `whatsapp_groups`;
- `blacklist_numbers`;
- `group_events_log`;
- `whatsapp_webhook_jobs`, se for necessario processar em fila.

Criterios de conclusao:

- schema revisado;
- migrations ou SQL criados;
- indices definidos;
- impacto no banco atual analisado antes da execucao.

### Fase 4 - Blacklist e auto-remocao

Status: Pendente

Objetivo:

- consultar blacklist quando um numero entrar em grupo;
- remover automaticamente numeros bloqueados;
- registrar logs de acao automatica.

Fluxo esperado:

1. Receber evento de participante adicionado.
2. Normalizar o numero.
3. Verificar blacklist.
4. Se estiver bloqueado, chamar Evolution API para remover o participante.
5. Registrar evento `kick_blacklist`.

Criterios de conclusao:

- blacklist funcional;
- remocao manual validada antes da automatica;
- remocao automatica testada em grupo controlado;
- falhas de API registradas em log.

### Fase 5 - Painel administrativo

Status: Pendente

Objetivo:

- criar telas no admin para gerenciar instancias, blacklist e logs.

Telas previstas:

- instancias WhatsApp;
- status e QR Code;
- grupos monitorados;
- blacklist;
- historico de eventos;
- metricas basicas.

Criterios de conclusao:

- painel acessivel apenas para administradores;
- QR Code exibido com seguranca;
- blacklist editavel;
- logs filtraveis por grupo, numero e data.

### Fase 6 - Alertas SuperFuncionario/Webhook

Status: Pendente

Objetivo:

- avisar quando houver entrada, saida ou expulsao automatica;
- integrar com o fluxo atual do SuperFuncionario sem quebrar o que ja existe.

Criterios de conclusao:

- payload de alerta definido;
- endpoint configuravel;
- erros de envio registrados;
- envio testado com evento real.

## Checklist Geral

- [x] Criar tela administrativa inicial para Evolution API.
- [x] Criar camada PHP para criar instancia, gerar QR e consultar status.
- [x] Criar arquivos de referencia para Evolution API via Docker.
- [ ] Definir ambiente da Evolution API: local, VPS ou subdominio.
- [ ] Confirmar se Docker esta disponivel na VPS.
- [ ] Subir Evolution API isolada.
- [ ] Criar primeira instancia de teste.
- [ ] Gerar e exibir QR Code real.
- [ ] Conectar numero secundario.
- [ ] Confirmar status conectado.
- [ ] Testar eventos de grupo.
- [ ] Criar armazenamento de logs.
- [ ] Criar blacklist.
- [ ] Testar remocao manual.
- [ ] Ativar remocao automatica em grupo controlado.
- [ ] Criar painel administrativo.
- [ ] Integrar alertas com SuperFuncionario.

## Decisoes Pendentes

- Usar Evolution API self-hosted na mesma VPS ou em VPS separada.
- Definir dominio/subdominio para a Evolution API.
- Definir dominio/URL publica para webhook.
- Escolher banco usado pela Evolution API.
- Decidir se o processamento dos webhooks sera direto ou via fila simples.
- Definir se a blacklist sera global, por instancia, por grupo ou mista.

## Riscos Conhecidos

- A conexao via WhatsApp Web/Baileys nao e uma API oficial da Meta.
- O numero usado pode sofrer desconexao, restricao ou bloqueio.
- Mudancas no WhatsApp podem afetar a Evolution API.
- O numero precisa ser administrador nos grupos para remover participantes.
- A remocao automatica deve ser testada com cuidado para evitar expulsao indevida.

## Registro de Progresso

### 2026-06-06

- Documento inicial criado.
- Ideia registrada.
- Fase 1 definida como proximo passo.
- Nenhuma alteracao funcional feita no sistema atual.
- Implementacao inicial da Fase 1 criada.
- Criado `app/evolution_api.php` para comunicacao com Evolution API.
- Criado `admin/whatsapp_monitor.php` para configurar URL/API key, criar instancia, gerar QR e consultar status.
- Criados arquivos `infra/evolution-api/docker-compose.yml`, `.env.example` e `README.md`.
- Adicionado item "WhatsApp Monitor" no menu administrativo.
- Fase 1 ainda nao concluida: falta executar a Evolution API em ambiente real e validar QR/conexao com numero secundario.
