# Gestao de Grupos WhatsApp

Este documento registra a ideia, o escopo e o andamento do modulo central para gestao profissional de grupos de WhatsApp usando a Evolution API ja integrada ao sistema.

## Objetivo

Criar uma pagina unica no admin para operar campanhas de grupos de WhatsApp com numeros conectados por QR Code ou pairing code, campanhas com grupos e links, mensagens programadas, logs, monitoramento, regras de palavras-chave e controles operacionais para reduzir falhas e uso agressivo dos numeros.

## Base existente

O sistema ja possui:

- Integracao com Evolution API em `app/evolution_api.php`.
- Cadastro de instancias em `whatsapp_instances`.
- Grupos detectados em `whatsapp_groups`.
- Participantes sincronizados em `whatsapp_group_members`.
- Webhook publico em `public/whatsapp_webhook.php`.
- Monitor de eventos em `admin/whatsapp_monitor.php`.
- Configuracoes de WhatsApp e IA em `admin/whatsapp_config.php`.
- Envio de texto via `/message/sendText/{instance}`.
- Eventos de entrada/saida/remocao de participantes.
- Lista de fraude, numeros confiaveis, remocao e notificacoes.

## Recursos suportados pela Evolution API

Recursos confirmados pela documentacao e/ou pelo codigo atual:

- Conectar instancia por QR Code.
- Conectar instancia por pairing code quando ha telefone informado.
- Consultar estado da conexao.
- Configurar webhook por instancia.
- Receber `GROUP_PARTICIPANTS_UPDATE` e `MESSAGES_UPSERT`.
- Enviar texto.
- Enviar midia: imagem, documento e video.
- Enviar audio.
- Enviar localizacao.
- Enviar contato.
- Enviar reacao.
- Enviar enquete.
- Criar grupo.
- Buscar informacoes do grupo.
- Sincronizar grupos.
- Sincronizar participantes.
- Remover/adicionar/promover/rebaixar participantes.
- Abrir/fechar grupo com `not_announcement` e `announcement`.
- Travar/destravar edicao do grupo com `locked` e `unlocked`.

Recursos que devem ser validados em grupo controlado na versao instalada:

- Alterar foto do grupo.
- Alterar titulo do grupo.
- Alterar descricao do grupo.
- Video redondo.
- Confirmacao pelo numero espiao cruzando mensagem enviada com payload recebido.

## Principios operacionais

Como a integracao atual usa Baileys/WhatsApp Web nao oficial, o modulo deve assumir que desconexoes e restricoes podem ocorrer. O objetivo nao e burlar limites da plataforma, mas operar com consistencia:

- Enviar apenas para grupos/campanhas com opt-in claro.
- Usar limites por minuto, hora e dia.
- Impedir rajadas agressivas.
- Usar fila com status e logs.
- Pausar automaticamente instancia com muitas falhas.
- Priorizar numeros conectados e habilitados.
- Registrar motivo de cada falha.
- Usar numeros reserva apenas como continuidade operacional.
- Exibir diagnostico claro de QR, pairing code, estado e ultimo erro.

## Pagina planejada

Pagina: `admin/whatsapp_grupos.php`

Secoes:

- Visao geral: cards, graficos simples, status das filas e saude dos numeros.
- Numeros conectados: cadastro, QR/pairing code, status, funcao operacional e limites.
- Campanhas: criar, editar, clonar, ativar, pausar e excluir.
- Grupos e links: grupos importados/detectados, maximo de leads por grupo, link publico e rotacao.
- Acoes programadas: texto, midia, audio, documento, video, localizacao, contato, reacao, botoes/lista quando suportado, enquete, abrir/fechar grupo, alterar titulo/descricao/foto.
- Palavras-chave: mensagem recebida com termo configurado gera gatilho para automacoes.
- Logs: filtros por campanha, grupo, numero, status, tipo de acao e periodo.
- Saude e seguranca: limites, cooldown, pausas por erro, numeros em risco e recomendacoes.

## Tabelas do modulo

Previstas/implantadas:

- `whatsapp_group_campaigns`
- `whatsapp_group_campaign_groups`
- `whatsapp_group_scheduled_actions`
- `whatsapp_group_action_logs`
- `whatsapp_group_keyword_rules`
- `whatsapp_group_connection_logs`

## Worker

Worker previsto: `cron/processar_whatsapp_grupos.php`

Responsabilidades:

- Buscar acoes programadas vencidas.
- Selecionar instancia conectada, habilitada e com funcao adequada.
- Respeitar limites e cooldown.
- Executar chamada Evolution API.
- Gravar log auditavel.
- Atualizar status da acao.
- Pausar campanha ou instancia quando houver erro recorrente.

## Integracao futura com automacoes centrais

Eventos desejados para a tabela central de gatilhos:

- `WHATSAPP_GRUPO_CAMPANHA_ENTROU`
- `WHATSAPP_GRUPO_CAMPANHA_LOTOU`
- `WHATSAPP_GRUPO_MSG_ENVIADA`
- `WHATSAPP_GRUPO_MSG_FALHOU`
- `WHATSAPP_GRUPO_PALAVRA_CHAVE`
- `WHATSAPP_GRUPO_NUMERO_DESCONECTOU`
- `WHATSAPP_GRUPO_NUMERO_RECONECTOU`

## Andamento

### 2026-07-19

- Ideia consolidada em documento.
- Confirmado que a Evolution API atual suporta o nucleo do projeto.
- Definida abordagem de pagina unica sem desmontar as telas existentes.
- Criado backend `app/whatsapp_groups.php` com tabelas, campanhas, grupos, acoes, logs, palavras-chave e envio via Evolution API.
- Criada pagina `admin/whatsapp_grupos.php` com secoes de visao geral, numeros, campanhas, grupos, programacoes, palavras-chave, logs e saude.
- Criado worker `cron/processar_whatsapp_grupos.php`.
- Criado endpoint protegido `public/cron_whatsapp_grupos.php`.
- Criado redirecionador publico `public/whatsapp_group_join.php`.
- Integrado processamento de palavras-chave ao webhook `public/whatsapp_webhook.php`.
- Adicionados gatilhos de grupos ao catalogo central de automacoes.
- Proxima etapa: testar em grupo controlado os endpoints de titulo, descricao, foto, audio e video redondo na versao Evolution API instalada.
