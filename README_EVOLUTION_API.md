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

Status: Concluida

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

- Evolution API instalada em VPS HostGator com AlmaLinux 9.8;
- Docker e Docker Compose instalados e validados;
- API acessivel em `http://69.6.215.67:8080`;
- Evolution API validada na versao `2.3.7`;
- instancia `monitor01` criada com integracao `WHATSAPP-BAILEYS`;
- QR Code gerado pelo Evolution Manager;
- numero secundario conectado;
- status final validado em `/instance/connectionState/monitor01` com `state=open`;
- usar numero secundario para teste.
- tela administrativa criada em `admin/whatsapp_monitor.php`;
- camada PHP de integracao criada em `app/evolution_api.php`;
- arquivos Docker de referencia criados em `infra/evolution-api/`;

Dados operacionais atuais:

- VPS: HostGator;
- OS: AlmaLinux 9.8;
- pasta da Evolution API na VPS: `/opt/evolution-api`;
- containers: `evolution_api`, `evolution_postgres`, `evolution_redis`;
- URL base para o painel PHP: `http://69.6.215.67:8080`;
- API key: definida em `/opt/evolution-api/.env` na variavel `AUTHENTICATION_API_KEY`; nao expor em codigo, prints ou logs;
- timeout sugerido no painel PHP: `30` segundos, podendo usar `60` em caso de lentidao.

Cuidados validados:

- nao ativar webhooks nesta fase;
- nao ativar blacklist ou remocao automatica nesta fase;
- nao usar o numero principal do WhatsApp;
- nao rodar `docker compose down -v`, pois isso pode apagar volumes/dados/sessao;
- para reiniciar sem apagar dados, usar `cd /opt/evolution-api && docker compose restart`.

### Fase 2 - Receber webhooks de grupo apenas para log bruto

Status: Concluida

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

Implementado ate agora:

- endpoint criado em `public/whatsapp_webhook.php`;
- tabela de logs brutos criada como `whatsapp_webhook_raw_logs`;
- tela `admin/whatsapp_monitor.php` exibe URL do webhook, botao para configurar webhook na Evolution e ultimos payloads recebidos;
- configuracao usa `POST /webhook/set/{instance}`;
- evento configurado: `GROUP_PARTICIPANTS_UPDATE`;
- payload de configuracao ajustado para Evolution API 2.3.7/Foundation usando objeto `webhook` com `enabled`, `url`, `events`, `headers`, `base64`;
- token secreto do webhook salvo em `settings.evolution_webhook_token`;
- nenhuma acao automatica e executada.

Ainda falta validar em grupo real:

- validado em grupo real com eventos recebidos;
- eventos `group-participants.update` registrados;
- acoes `add` e `remove` identificadas;
- campos principais confirmados: instancia, grupo, acao e participante;
- observacao importante: quando o participante vem como `@lid`, este nao deve ser tratado como telefone real; o telefone correto vem de `data.participants[0].phoneNumber`, exemplo `5511948642358@s.whatsapp.net`.

### Fase 3 - Eventos operacionais, tags e gatilhos

Status: Concluida

Objetivo:

- interpretar eventos tecnicos da Evolution API em eventos operacionais;
- cruzar o telefone do participante com alunos cadastrados;
- aplicar tags automaticas no aluno;
- disparar webhooks e regras do SuperFuncionario usando o mecanismo atual do sistema.

Eventos implantados:

- `WHATSAPP_GRUPO_ENTROU`: participante entrou no grupo;
- `WHATSAPP_GRUPO_SAIU`: participante saiu por conta propria (`action=remove` e `author` igual ao participante);
- `WHATSAPP_GRUPO_REMOVIDO_ADMIN`: participante foi removido por outra pessoa/admin (`action=remove` e `author` diferente do participante).

Tags aplicadas automaticamente:

- `WHATSAPP_GRUPO_ENTROU`;
- `WHATSAPP_GRUPO_SAIU`;
- `WHATSAPP_GRUPO_REMOVIDO_ADMIN`.

Payload extra enviado para webhooks/SuperFuncionario:

- `extra.telefone`;
- `extra.group_id`;
- `extra.participant_id`;
- `extra.author_id`;
- `extra.action_original`;
- `extra.tipo_interpretado`;
- `extra.payload_log_id`;
- `extra.origem`.

Implementado:

- `app/evolution_api.php` normaliza telefone, participante, autor e tipo de evento;
- `public/whatsapp_webhook.php` grava os campos normalizados e aciona tags/gatilhos quando o token e valido;
- `admin/whatsapp_monitor.php` mostra evento interpretado, telefone limpo, aluno encontrado e status do gatilho;
- `admin/webhooks.php` lista os novos eventos para configuracao;
- `admin/superfuncionario.php` lista os novos eventos e campos `extra.*` para regras e campos personalizados.

Comportamento de seguranca:

- se o token do webhook for invalido, o payload e registrado, mas nenhum gatilho e disparado;
- se o telefone nao cruzar com um aluno, o evento fica como `Aluno nao encontrado` e nao dispara webhook/SuperFuncionario;
- nenhuma remocao automatica de participante foi ativada nesta fase.

### Fase 4 - Base operacional e blacklist sem remocao

Status: Concluida

Objetivo:

- criar tabelas operacionais para grupos, eventos normalizados e blacklist;
- cadastrar numeros bloqueados pelo painel;
- detectar entrada de numero blacklistado em grupo monitorado;
- gerar tag e gatilho de alerta sem remover participantes automaticamente.

Tabelas implantadas:

- `whatsapp_groups`: grupos detectados nos webhooks, por `group_id` e instancia;
- `whatsapp_groups.picture_url`: foto/avatar do grupo quando a Evolution API retorna `pictureUrl`;
- `whatsapp_groups.is_ignored`: flag para ignorar grupos que nao devem ser considerados pelo sistema;
- `whatsapp_blacklist_numbers`: numeros bloqueados, motivo, origem e status ativo/inativo;
- `whatsapp_group_events`: historico normalizado ligado ao payload bruto.

Evento implantado:

- `WHATSAPP_BLACKLIST_DETECTADO`: disparado quando um numero ativo na blacklist entra em grupo monitorado e cruza com um aluno cadastrado.

Tag aplicada automaticamente:

- `WHATSAPP_BLACKLIST_DETECTADO`.

Payload extra adicional:

- `extra.blacklist.id`;
- `extra.blacklist.reason`;
- `extra.blacklist.origem`.

Implementado:

- `app/evolution_api.php` cria as tabelas operacionais e registra eventos normalizados;
- `app/evolution_api.php` busca o titulo do grupo pela Evolution API usando `GET /group/findGroupInfos/{instance}?groupJid={group_id}` e salva em `whatsapp_groups.group_name`;
- `app/evolution_api.php` tambem sincroniza titulos e fotos em lote usando `GET /group/fetchAllGroups/{instance}?getParticipants=false` quando o painel solicita atualizacao;
- `admin/whatsapp_monitor.php` permite cadastrar, ativar e desativar numeros na blacklist;
- `admin/whatsapp_monitor.php` exibe grupos detectados, contagem de eventos e batidas de blacklist;
- `admin/whatsapp_monitor.php` exibe o nome do grupo quando ja carregado e oferece botao para atualizar nomes dos grupos detectados;
- `admin/whatsapp_monitor.php` exibe foto do grupo quando disponivel e permite marcar/desmarcar grupos ignorados;
- `admin/webhooks.php` e `admin/superfuncionario.php` listam `WHATSAPP_BLACKLIST_DETECTADO`.

Comportamento de seguranca:

- a deteccao de blacklist nao remove participante;
- grupos novos entram por padrao como considerados (`is_ignored=0`);
- grupos marcados como ignorados continuam com payload bruto registrado, mas nao aplicam tags, blacklist nem webhooks/SuperFuncionario;
- se o numero blacklistado nao cruzar com aluno, o evento fica registrado como `blacklist_detected_no_user`, sem Webhook/SuperFuncionario;
- a remocao manual/automatica segue pendente para uma fase posterior.

Criterios de conclusao:

- tabelas operacionais criadas automaticamente;
- cadastro de blacklist disponivel no painel;
- deteccao em evento `add` registrada;
- alerta por tag/webhook/SuperFuncionario disponivel quando houver aluno identificado.

### Fase 5 - Remocao manual controlada

Status: Pendente

Objetivo:

- permitir remover manualmente, pelo painel, um participante detectado como blacklistado;
- validar endpoint correto da Evolution API;
- confirmar que o numero conectado tem permissao de admin no grupo;
- registrar sucesso/erro da tentativa de remocao.

Fluxo esperado:

1. Detectar entrada de numero blacklistado.
2. Exibir alerta no painel.
3. Operador clica em remover manualmente.
4. Sistema chama Evolution API.
5. Resultado fica registrado em log.

Criterios de conclusao:

- endpoint de remocao validado em grupo controlado;
- permissao de admin confirmada;
- falhas de API registradas em log.

### Fase 6 - Remocao automatica por blacklist

Status: Pendente

Objetivo:

- ativar, por configuracao, remocao automatica de numeros blacklistados;
- limitar a automacao por grupo/instancia;
- manter logs completos da acao.

Criterios de conclusao:

- remocao automatica testada em grupo controlado;
- opcao de liga/desliga disponivel;
- erros e sucessos auditaveis.

### Fase 7 - Painel administrativo completo

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

### Fase 8 - Alertas SuperFuncionario/Webhook

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
- [x] Definir ambiente da Evolution API: VPS HostGator.
- [x] Confirmar se Docker esta disponivel na VPS.
- [x] Subir Evolution API isolada.
- [x] Criar primeira instancia de teste.
- [x] Gerar e exibir QR Code real.
- [x] Conectar numero secundario.
- [x] Confirmar status conectado.
- [x] Testar eventos de grupo.
- [x] Criar armazenamento de logs.
- [x] Interpretar eventos `add`/`remove` em leitura operacional.
- [x] Cruzar telefone do participante com alunos.
- [x] Aplicar tags automaticas de eventos de grupo.
- [x] Disparar Webhooks e SuperFuncionario para eventos de grupo identificados.
- [x] Criar blacklist sem remocao automatica.
- [x] Registrar grupos detectados.
- [x] Registrar eventos normalizados.
- [x] Disparar alerta `WHATSAPP_BLACKLIST_DETECTADO`.
- [ ] Testar remocao manual.
- [ ] Ativar remocao automatica em grupo controlado.
- [x] Criar painel administrativo inicial para blacklist/grupos.
- [ ] Integrar alertas com SuperFuncionario.

## Decisoes Pendentes

- Avaliar se a Evolution API ficara em IP/porta `8080` ou em subdominio com HTTPS.
- Definir dominio/subdominio para a Evolution API, se for sair do acesso por IP.
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
- Fase 1 concluida em VPS HostGator.
- Ambiente validado: AlmaLinux 9.8, Docker `29.5.3`, Docker Compose `v5.1.4`.
- Evolution API rodando em `http://69.6.215.67:8080`, versao `2.3.7`.
- Instancia `monitor01` criada e conectada com numero secundario.
- Status final validado: `state=open`, interpretado como conectado.
- Proxima etapa: Fase 2, criar endpoint para receber webhooks de grupos e registrar payload bruto, ainda sem acoes automaticas.
- Implementacao inicial da Fase 2 criada.
- Criado endpoint `public/whatsapp_webhook.php`.
- Criada tabela `whatsapp_webhook_raw_logs`.
- Tela `admin/whatsapp_monitor.php` atualizada com URL do webhook, configuracao na Evolution e visualizacao de payloads.
- Fase 2 ainda depende de validacao com evento real em grupo de teste.
- Fase 2 validada com payloads reais no painel.
- Eventos recebidos: `group-participants.update`.
- Acoes observadas: `add` e `remove`.
- Campos extraidos com sucesso: instancia, grupo, acao e participante.
- Implementada Fase 3 de eventos operacionais.
- Campos normalizados gravados: telefone limpo, participante, autor e evento interpretado.
- Eventos implantados para tags/webhooks/SuperFuncionario: `WHATSAPP_GRUPO_ENTROU`, `WHATSAPP_GRUPO_SAIU`, `WHATSAPP_GRUPO_REMOVIDO_ADMIN`.
- Painel `admin/whatsapp_monitor.php` passa a exibir aluno encontrado e status do gatilho.
- Telas `admin/webhooks.php` e `admin/superfuncionario.php` passam a listar os eventos de WhatsApp.
- Proxima etapa recomendada: modelagem definitiva/blacklist e somente depois remocao automatica em grupo controlado.
- Implementada Fase 4 de base operacional e blacklist sem remocao.
- Criadas tabelas `whatsapp_groups`, `whatsapp_blacklist_numbers` e `whatsapp_group_events`.
- Painel `admin/whatsapp_monitor.php` passa a cadastrar, ativar e desativar numeros da blacklist.
- Entrada de numero blacklistado em grupo passa a registrar alerta e, quando houver aluno identificado, aplicar tag/disparar `WHATSAPP_BLACKLIST_DETECTADO`.
- Nome/titulo do grupo passa a ser buscado via `findGroupInfos` da Evolution API e salvo em `whatsapp_groups.group_name`.
- Foto do grupo passa a ser salva em `whatsapp_groups.picture_url` quando a Evolution API retornar `pictureUrl`.
- Painel passa a permitir marcar grupos como ignorados; eventos desses grupos ficam registrados, mas nao geram tags/gatilhos.
- Proxima etapa recomendada: remocao manual controlada via Evolution API antes de qualquer remocao automatica.
