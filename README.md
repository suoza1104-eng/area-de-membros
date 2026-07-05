# Area de Membros - Guia tecnico e prompt de contexto

Este projeto e uma area de membros em PHP puro, com painel administrativo, area publica para alunos, integracoes por webhook, automacoes de live, certificados, importacao de vendas Hotmart e analises de funil.

Use este arquivo como contexto inicial para qualquer desenvolvedor ou IA que precise mexer no sistema com seguranca.

## Prompt recomendado para IA

Copie e cole este bloco quando for pedir ajuda a uma IA sobre o projeto:

```text
Voce esta trabalhando em uma area de membros PHP puro, sem framework, com MySQL via PDO. O projeto tem tres camadas principais:

1. app/
   - config.php: configura timezone, sessao, BASE_URL, BASE_URL_ADMIN, credenciais do banco e getPDO().
   - funcoes.php: funcoes compartilhadas de autenticacao, busca de usuarios, tags, settings, magic links, disparo de webhooks e SuperFuncionario.
   - webhook_dispatcher.php: monta payloads e envia webhooks de saida configurados no admin.
   - superfuncionario_dispatcher.php: integra com SuperFuncionario, cria tabelas proprias e dispara regras por evento.
   - integration_hub.php: recebe eventos externos, normaliza payloads e prepara entregas independentes por destino.
   - certificado_pdf.php: gera PDF de certificado.

2. public/
   - login.php/logout.php: login do aluno, remember token, magic link, last_login_at e redirecionamento.
   - trilha.php: tela principal do aluno com aulas, progresso, live da turma, certificado e cursos recomendados.
   - aula.php: detalhe/player de aula e conclusao.
   - api_concluir_aula.php: marca aula como completed em lesson_progress.
   - certificado.php/verificar_certificado.php: validacao de progresso, senha, emissao e verificacao publica do certificado.
   - api_inscrever.php: cria/atualiza users a partir de inscricao, define turma atual pela janela, grava inscricao_logs, adiciona tags e dispara webhooks.
   - live_webhook.php: endpoint publico para eventos de live, grava live_event_recebimentos, identifica/cria usuario e dispara LIVE_ACESSOU/LIVE_OFERTA/LIVE_COMPRA.
   - inbound_webhook.php: endpoint publico de entrada para acoes externas como inscrito, login, aula vista, trilha concluida, certificado e tag.
   - reagendar_live.php/api_reagendar_live.php: fluxo publico para aluno escolher nova turma/live.
   - api_click_botao.php: registra cliques de botoes e dispara tags/webhooks.

3. admin/
   - index.php: login admin e dashboard. Calcula KPIs, funis, filtros por data/turma, funil de live, compras reais via hotmart_sales e comparativo por turma.
   - _header.php/_footer.php: layout admin, sidebar, permissoes de equipe e design system.
   - alunos.php/aluno_editar.php: listagem, filtros, tags, progresso, dados e edicao de alunos.
   - aulas.php: CRUD de aulas, ordem, slug, thumb, video e flag conta_para_conclusao.
   - turmas.php: CRUD de turmas, codigo, janelas, data da live, senha do certificado e reset de disparo.
   - webhooks.php: webhooks de saida e configuracao de disparo de live por turma.
   - live_events.php: cadastro de eventos de live e tokens para live_webhook.php.
   - inbound_webhooks.php: cadastro de webhooks de entrada e tokens para inbound_webhook.php.
   - integration_hub.php: painel de fontes, rotas, mapeamentos e logs do Hub de Integrações.
   - superfuncionario.php: credenciais, regras e configuracoes de live por turma para SuperFuncionario.
   - disparos.php: disparos em lote com filtros de publico e execucao por batch.
   - certificado_config.php/certificado_preview.php: layout, senha, videos, CTA e preview do certificado.
   - cursos_recomendados.php: CRUD de ofertas exibidas para o aluno.
   - config_app.php/settings_aparencia*.php: identidade visual e textos globais.
   - import_vendas_hotmart.php: importa CSV da Hotmart para hotmart_sales, fazendo match com users por telefone/email.
   - vendas_analytics.php: analise de vendas, leads, UTM, turmas, reembolso, lag lead->compra e tabela de transacoes.
   - monitor_inscricoes.php/monitor_inscricoes_data.php: monitor de usuarios/inscricoes.
   - equipe.php: usuarios administrativos e permissoes por tela.
   - logs.php: visualizacao de logs de webhooks/sistema.

Regras importantes:
- Nao alterar credenciais ou tokens reais em respostas. Se precisar documentar, usar placeholders.
- Antes de editar, ler o arquivo alvo e preservar mudancas existentes do usuario.
- Validar PHP com `C:\xampp\php\php.exe -l caminho\arquivo.php` no Windows local.
- Quando mexer em deploy, versionar apenas arquivos da tarefa e nao incluir alteracoes nao relacionadas.
- Antes de cada deploy, incrementar `APP_VERSION` em `app/config.php` (V1, V2, V3...) para confirmar visualmente no sidebar do admin que a versao publicada atualizou.
- O deploy padrao usa GitHub `main`; no cPanel, o servidor deve atualizar com `git pull origin main` ou receber os arquivos por File Manager/FTP mantendo os mesmos caminhos.
```

## Visao geral do funcionamento

O sistema usa PHP procedural com PDO. As paginas incluem `app/config.php` ou `app/funcoes.php`, que iniciam sessao, configuram ambiente e retornam a conexao MySQL com `getPDO()`.

O mesmo banco remoto e usado em local e producao. O que muda por ambiente sao as URLs base:

- Local: `BASE_URL` e `BASE_URL_ADMIN` apontam para localhost.
- Producao: apontam para o dominio publico em `/area_membros/public` e `/area_membros/admin`.

As tabelas sao criadas ou ajustadas parcialmente pelas proprias telas quando necessario. Varias telas usam `CREATE TABLE IF NOT EXISTS` e `ALTER TABLE` defensivo para manter compatibilidade com bancos antigos.

## Estrutura de pastas

```text
admin/   Painel administrativo.
app/     Configuracoes, funcoes compartilhadas e dispatchers.
cron/    Rotinas executadas por agendamento.
public/  Area publica/aluno e endpoints externos.
uploads/ Arquivos enviados pelo admin.
vendor/  Dependencias PHP, incluindo geracao de PDF.
```

## Fluxos principais

### 1. Inscricao de aluno

Endpoint principal: `public/api_inscrever.php`.

Fluxo:

1. Recebe dados de inscricao.
2. Normaliza telefone e identifica turma ativa pela janela configurada em `turmas`.
3. Procura usuario existente por email.
4. Se existir, atualiza dados e turma quando aplicavel.
5. Se nao existir, cria registro em `users`.
6. Grava evento em `inscricao_logs`, com UTM, turma, `is_novo` e data.
7. Adiciona tags como `INSCRITO_TURMA_{codigo}` ou `REINSCRITO_TURMA_{codigo}`.
8. Dispara webhooks e regras de SuperFuncionario via `disparar_webhooks()`.

Tabelas mais envolvidas:

- `users`
- `turmas`
- `inscricao_logs`
- `tags`
- `user_tags`
- `webhook_logs`

### 2. Login e sessao do aluno

Arquivos:

- `public/login.php`
- `public/logout.php`
- `app/funcoes.php`

Fluxo:

1. Login por email/senha, magic link ou remember token.
2. Ao primeiro login, atualiza `users.last_login_at`.
3. Define `$_SESSION['aluno_id']` e dados basicos do aluno.
4. Redireciona para `public/trilha.php` ou para `next`.

`proteger_aluno()` bloqueia paginas internas quando nao ha sessao.

### 3. Trilha, aulas e progresso

Arquivos:

- `public/trilha.php`
- `public/aula.php`
- `public/api_concluir_aula.php`
- `admin/aulas.php`

Fluxo:

1. Admin cria aulas em `admin/aulas.php`.
2. Aulas ativas aparecem na trilha por `ordem`.
3. Ao concluir uma aula, `api_concluir_aula.php` grava ou atualiza `lesson_progress` com `status = 'completed'`.
4. O dashboard, alunos e certificado leem `lesson_progress` para calcular progresso.

Tabelas:

- `lessons`
- `lesson_progress`

### 4. Certificado

Arquivos:

- `admin/certificado_config.php`
- `admin/certificado_preview.php`
- `public/certificado.php`
- `public/verificar_certificado.php`
- `app/certificado_pdf.php`

Fluxo:

1. Admin configura layout, textos, senha e CTA do certificado.
2. Aluno acessa certificado apos concluir as aulas que contam para conclusao.
3. Dependendo da configuracao, o sistema exige senha fixa, senha por turma ou composicao por partes.
4. Ao emitir, grava `certificates`, gera `codigo_uid`, cria PDF e salva `pdf_url`.
5. A verificacao publica consulta `certificates.codigo_uid`.

Tabelas:

- `certificate_config`
- `certificates`
- `turmas`
- `lesson_progress`

### 5. Turmas, live e reagendamento

Arquivos:

- `admin/turmas.php`
- `admin/webhooks.php`
- `admin/superfuncionario.php`
- `cron/processar_lives.php`
- `public/reagendar_live.php`
- `public/api_reagendar_live.php`

Fluxo:

1. Admin cria turmas com `codigo`, janela de inscricao e data da live.
2. Inscricoes entram na turma ativa ou no codigo configurado.
3. `cron/processar_lives.php` procura turmas com `live_disparo_data <= agora` e `live_disparada = 0`.
4. Para cada aluno elegivel da turma, dispara webhook de live e/ou SuperFuncionario.
5. Ao finalizar, marca `turmas.live_disparada = 1`.
6. Reagendamento permite trocar `codigo_turma`, `turma_codigo`, `turma_live_at` e historico relacionado.

### 6. Eventos de live

Arquivos:

- `admin/live_events.php`
- `public/live_webhook.php`
- `admin/index.php`

Tipos de evento:

- `acessou`: aluno acessou a live.
- `oferta`: aluno chegou ate a oferta.
- `compra`: no contexto de live significa clique no botao/CTA de compra, nao compra real.
- `custom`: evento generico.

Fluxo:

1. Admin cria evento em `admin/live_events.php`.
2. O sistema gera token.
3. Ferramenta externa chama `public/live_webhook.php?token=...`.
4. Endpoint grava `live_event_recebimentos`.
5. Tenta identificar usuario por email/telefone; se configurado, cria usuario.
6. Dispara evento interno `LIVE_ACESSOU`, `LIVE_OFERTA` ou `LIVE_COMPRA`.

Observacao importante: `LIVE_COMPRA` e clique no CTA, nao venda real. No dashboard esta metrica aparece como `Clicaram CTA`.

### 7. Webhooks de entrada

Arquivos:

- `admin/inbound_webhooks.php`
- `public/inbound_webhook.php`

Serve para sistemas externos atualizarem o aluno dentro da area.

Acoes suportadas:

- `INSCRITO`
- `PRIMEIRO_LOGIN`
- `VIU_AULA`
- `CONCLUIU_TRILHA`
- `CERT_EMITIDO`
- `TAG_CUSTOM`

O admin define mapeamento do payload para campos como nome, email, telefone e oferta.

### 8. Webhooks de saida

Arquivos:

- `admin/webhooks.php`
- `app/webhook_dispatcher.php`
- `app/funcoes.php`

Fluxo:

1. Admin cadastra webhook por evento.
2. Alguma acao chama `disparar_webhooks($evento, $user_id, $extra)`.
3. Dispatcher monta payload com usuario, turma, codigo_live, data_live e extras.
4. Envia HTTP e grava `webhook_logs`.

Eventos comuns:

- `INSCRITO`
- `REINSCRITO`
- `PRIMEIRO_LOGIN`
- `ASSISTIU_ALGUMA_AULA`
- `CONCLUIU_TRILHA`
- `CERT_EMITIDO`
- `LIVE_ACESSOU`
- `LIVE_OFERTA`
- `LIVE_COMPRA`

### 9. SuperFuncionario

Arquivos:

- `admin/superfuncionario.php`
- `app/superfuncionario_dispatcher.php`

Funciona como uma camada paralela aos webhooks comuns.

O admin configura:

- base URL
- token
- endpoint padrao
- regras por evento
- tags
- flows
- campos extras
- configuracao especifica por turma para disparo de live

Logs ficam em `superfuncionario_logs`.

### 10. Disparos em lote

Arquivo: `admin/disparos.php`.

Permite criar campanhas com filtros, como:

- turma
- tags de SuperFuncionario
- tags do sistema
- periodo de inscricao
- ultimo cadastro
- quantidade de inscricoes
- possui ou nao certificado
- recebeu ou nao algum evento

Os disparos sao executados por batches AJAX, gravando `disparo_execucoes`.

### 11. Vendas e analytics

Arquivos:

- `admin/import_vendas_hotmart.php`
- `admin/vendas_analytics.php`
- `admin/index.php`

Fluxo de vendas:

1. Admin importa CSV da Hotmart.
2. O importador grava `hotmart_sales`.
3. Faz match com `users` por telefone normalizado e depois por email.
4. Salva `matched_user_id`, `match_method`, UTM, produto, status e valores.

`vendas_analytics.php` calcula:

- vendas por dimensao UTM
- lucro por UTM
- leads por UTM
- conversao
- receita por turma
- reembolsos
- faturamento por semana/mes
- lag entre inscricao e compra
- organicos sem match

Status considerados venda real no dashboard:

- `Aprovado`
- `Completo`

### 12. Dashboard admin

Arquivo: `admin/index.php`.

Responsabilidades:

- Login admin e login de equipe.
- Filtros por data inicial, data final e uma ou mais turmas.
- KPIs de alunos, certificados, logins, aulas vistas, conclusao e frequencia media.
- Cards adicionais:
  - `Conversao em vendas`: compradores reais / alunos filtrados.
  - `Showup`: alunos que acessaram a live / alunos filtrados.
- Graficos de novos vs reinscritos.
- Inscricoes por dia.
- Estagios de progresso.
- Funil de conversao geral.
- Funil de Live:
  - `Acessaram a live`: `live_events.tipo = 'acessou'`.
  - `Ficaram ate a oferta`: `live_events.tipo = 'oferta'`.
  - `Clicaram CTA`: `live_events.tipo = 'compra'`.
  - `Compraram`: compradores reais em `hotmart_sales`.
- Comparativo por turma com metricas de inscritos, logaram, viu aula, live acessou, live oferta, live clicou CTA, compras e certificado.
- Ranking de inscricoes recorrentes.

Alteracao recente aplicada:

- A coluna antiga `Compra` no funil de live foi renomeada para `Clicou CTA`.
- Foi adicionada uma coluna separada `Comprou` para compra real.
- Foi criado filtro superior multi-turma com `turma_id[]`.
- Foram adicionados cards de taxa de conversao em vendas e showup.
- O comparativo por turma ganhou a serie `Compras`.

## Telas administrativas

### `admin/index.php`

Dashboard e login admin. Quando nao ha sessao admin, renderiza formulario de login. Quando logado, renderiza dashboard.

### `admin/_header.php`

Define layout geral do admin, permissao de equipe, sidebar, estilos globais e import do Chart.js.

### `admin/alunos.php`

Lista usuarios com filtros por busca, turma, tags, progresso, certificado e quantidade de inscricoes. Permite acoes rapidas como redefinir senha e email.

### `admin/aluno_editar.php`

Edita dados individuais do aluno, turma, senha e mostra tags.

### `admin/aulas.php`

CRUD de aulas. Campos principais:

- titulo
- slug
- descricao
- video_url
- thumb_url
- ordem
- ativo
- conta_para_conclusao

### `admin/turmas.php`

CRUD de turmas. Campos comuns:

- codigo
- nome
- janela_inicio
- janela_fim
- data_live
- codigo_live
- senha_certificado
- live_disparada

Tambem sincroniza `data_live`/`turma_live_at` em usuarios quando a data da turma muda.

### `admin/webhooks.php`

Cadastro de webhooks de saida e configuracao de disparo de live por turma.

### `admin/live_events.php`

Cadastro dos tokens que recebem eventos de live. Mostra ultimos recebimentos.

### `admin/inbound_webhooks.php`

Cadastro de endpoints de entrada para integracoes externas. Permite clonar, ativar/desativar e ver recebimentos.

### `admin/superfuncionario.php`

Configura credenciais globais, regras por evento e configuracoes especificas de turma para SuperFuncionario.

### `admin/disparos.php`

Construtor de disparos em lote, com filtros e execucao por batch.

### `admin/import_vendas_hotmart.php`

Importa CSV de vendas Hotmart. Requer colunas esperadas como codigo da transacao, status, datas, produto, valores, comprador, email e telefone.

### `admin/vendas_analytics.php`

Analise de vendas e leads. Suporta filtros por periodo, status, UTM, produto, turma e organicidade.

### `admin/certificado_config.php`

Configura visual, senha, mensagens, videos e botao de certificado.

### `admin/cursos_recomendados.php`

Gerencia cursos/ofertas que aparecem na area do aluno.

### `admin/equipe.php`

Cria usuarios administrativos de equipe e define permissao de acesso/escrita por tela.

### `admin/logs.php`

Consulta logs de webhook/sistema.

### `admin/monitor_inscricoes.php`

Monitor de usuarios cadastrados por `api_inscrever.php`, com filtros e visualizacao de payload.

## Telas publicas

### `public/login.php`

Entrada do aluno. Suporta senha, remember token e magic link.

### `public/trilha.php`

Home do aluno com aulas, progresso, live da turma, certificado e cursos pagos.

### `public/aula.php`

Player/detalhe de aula.

### `public/certificado.php`

Emissao de certificado.

### `public/verificar_certificado.php`

Validacao publica por codigo UID.

### `public/reagendar_live.php`

Tela publica para aluno selecionar nova turma/data de live.

## Endpoints externos

### `public/api_inscrever.php`

Entrada para novas inscricoes.

### `public/live_webhook.php`

Entrada de eventos de live.

### `public/inbound_webhook.php`

Entrada generica de automacoes externas.

### `public/api_click_botao.php`

Registro de clique em botao e disparo de tags/webhooks.

### `public/api_concluir_aula.php`

Marca aula como concluida.

### `public/api_reagendar_live.php`

Atualiza turma/data de live do aluno.

## Banco de dados - tabelas importantes

Principais tabelas usadas:

```text
users                         Alunos.
lessons                       Aulas.
lesson_progress               Progresso por aluno/aula.
certificates                  Certificados emitidos.
certificate_config            Configuracao do certificado.
app_config                    Configuracao visual/conteudo da area.
settings                      Chave/valor para configuracoes gerais.
turmas                        Turmas, janelas e lives.
inscricao_logs                Historico de inscricoes/reinscricoes.
tags                          Tags do sistema.
user_tags                     Tags aplicadas em alunos.
webhooks                      Webhooks de saida.
webhook_logs                  Logs de webhooks.
live_events                   Configuracao de eventos de live.
live_event_recebimentos       Recebimentos dos eventos de live.
inbound_webhooks              Configuracao dos webhooks de entrada.
inbound_webhook_recebimentos  Logs dos webhooks de entrada.
hotmart_sales                 Vendas importadas da Hotmart.
recommended_courses           Cursos recomendados/ofertas.
admin_equipe                  Usuarios administrativos de equipe.
superfuncionario_config       Configuracao global do SuperFuncionario.
superfuncionario_rules        Regras do SuperFuncionario.
superfuncionario_logs         Logs do SuperFuncionario.
disparos                      Campanhas/disparos em lote.
disparo_execucoes             Execucoes dos disparos.
magic_links                   Tokens de login por link.
remember_tokens               Tokens de lembrar login.
system_logs                   Logs internos.
```

## Validacao local antes de subir

No Windows local, use o PHP do XAMPP:

```powershell
C:\xampp\php\php.exe -l admin\index.php
```

Para validar varios arquivos alterados:

```powershell
C:\xampp\php\php.exe -l admin\index.php
C:\xampp\php\php.exe -l public\api_inscrever.php
C:\xampp\php\php.exe -l app\funcoes.php
```

Antes de commit:

```powershell
git status --short
git diff -- caminho\do\arquivo.php
```

## Deploy pelo GitHub e cPanel

O fluxo recomendado e versionar no GitHub e atualizar o servidor pelo Git do cPanel ou terminal.

### 1. Preparar localmente

1. Validar sintaxe PHP dos arquivos alterados.
2. Conferir que nao ha arquivos nao relacionados no commit.
3. Fazer commit apenas dos arquivos da tarefa.

```powershell
git status --short
git add admin\index.php
git commit -m "Descreva a alteracao"
git push origin main
```

### 2. Atualizar no cPanel usando Terminal

No cPanel:

1. Acesse `Terminal`.
2. Entre na pasta onde o projeto esta publicado. Normalmente sera algo como:

```bash
cd ~/public_html/area_membros
```

3. Confira se a pasta e um repositorio Git:

```bash
git status
```

4. Se estiver tudo certo, atualize:

```bash
git pull origin main
```

5. Se o projeto estiver em uma subpasta diferente, primeiro localize:

```bash
find ~/public_html -maxdepth 4 -name .git -type d
```

Depois entre na pasta correta e rode o `git pull`.

### 3. Atualizar no cPanel usando Git Version Control

Se o cPanel tiver "Git Version Control":

1. Abra `Git Version Control`.
2. Selecione o repositorio do projeto.
3. Clique em `Manage`.
4. Use a opcao de `Pull or Deploy`.
5. Verifique se a branch selecionada e `main`.
6. Confirme o deploy.

### 4. Atualizar manualmente pelo File Manager

Use este caminho apenas se o Git nao estiver configurado no servidor.

1. No local, compacte somente os arquivos alterados mantendo a estrutura de pastas.
2. Exemplo para uma alteracao em `admin/index.php`, o ZIP deve conter:

```text
admin/index.php
```

3. No cPanel, abra `File Manager`.
4. Va ate a pasta publicada, provavelmente `public_html/area_membros`.
5. Faca backup do arquivo antigo.
6. Envie o ZIP.
7. Extraia por cima mantendo os caminhos.
8. Acesse o admin e teste a tela alterada.

### 5. Checklist pos-deploy

Depois do deploy:

1. Abrir `/area_membros/admin/index.php`.
2. Logar como admin.
3. Testar filtros por data.
4. Testar filtro com uma turma.
5. Testar filtro com varias turmas.
6. Conferir funil de live:
   - Acessaram a live.
   - Ficaram ate a oferta.
   - Clicaram CTA.
   - Compraram.
7. Conferir cards:
   - Conversao em vendas.
   - Showup.
8. Abrir `/area_membros/admin/vendas_analytics.php` para garantir que a tabela `hotmart_sales` continua acessivel.
9. Verificar logs de erro do cPanel se algo quebrar.

## Cuidados de seguranca

Nao colocar em README, prompt ou chat:

- senha real do banco
- token GitHub
- token de webhook
- credenciais de SuperFuncionario
- senha admin real

Melhoria recomendada:

- mover credenciais de `app/config.php` para variaveis de ambiente ou arquivo fora do repositorio;
- trocar senha admin padrao;
- rotacionar tokens expostos em remotes, chats ou arquivos;
- restringir permissao de escrita em pastas sensiveis;
- manter backup do banco antes de qualquer alteracao em massa.

## Boas praticas ao alterar o projeto

1. Ler o arquivo inteiro ou a secao relevante antes de editar.
2. Preservar padroes locais: PHP procedural, PDO, funcoes auxiliares existentes.
3. Evitar refatoracoes grandes se a tarefa for pontual.
4. Usar `apply_patch` ou editor controlado para mudancas pequenas.
5. Validar com `php -l`.
6. Testar no navegador.
7. Commitar somente arquivos relacionados.
8. Fazer deploy e testar em producao.

## Troubleshooting

### Tela branca em producao

1. Conferir log de erros do cPanel.
2. Rodar `php -l` no arquivo alterado.
3. Verificar se o servidor tem a mesma versao de PHP esperada.
4. Conferir se alguma tabela/coluna nao existe.

### Dashboard mostra compra zerada

1. Verificar se ha registros em `hotmart_sales`.
2. Confirmar se `status` esta como `Aprovado` ou `Completo`.
3. Conferir se `matched_user_id` esta preenchido.
4. Validar se os filtros de data/turma nao estao excluindo os alunos.

### Live mostra clique mas nao compra

Isso e esperado quando houve clique no CTA (`live_events.tipo = 'compra'`) mas ainda nao existe venda aprovada/completa em `hotmart_sales`.

### Multi-turma nao filtra como esperado

1. Verificar se `users` usa `codigo_turma` ou `turma_id`.
2. Confirmar se as turmas selecionadas possuem alunos.
3. Conferir se as datas do filtro incluem o periodo de cadastro.

### Webhook nao dispara

1. Conferir se o webhook esta ativo.
2. Verificar `webhook_logs`.
3. Conferir evento configurado.
4. Confirmar se o payload externo contem email/telefone quando for inbound/live.

## Ultima mudanca documentada

Alteracao documentada:

```text
Dashboard: ranking de inscricoes Top 5 e ranking de entradas em grupos
```

Resumo:

- Limita o ranking de inscricoes do dashboard ao Top 5 por padrao, com expansao para ver os demais.
- Adiciona ranking de alunos que mais entraram em grupos de WhatsApp.
- Mostra total de entradas e quantidade de grupos diferentes por aluno.
- Permite abrir o historico do aluno no ranking de grupos, exibindo entradas, saidas, grupo, telefone, participante, autor e status de grupo ignorado.
