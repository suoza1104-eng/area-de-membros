# Fluxos de notificações push

## Organização do painel

A central de notificações foi dividida em áreas para evitar mistura entre operação e configuração:

- **Visão geral**: cards de dispositivos e gráficos de evolução/distribuição das entregas;
- **Campanhas**: disparos imediatos ou agendados e audiência congelada;
- **Automações**: fluxos, versões, métricas e rastreabilidade por aluno;
- **Dispositivos**: instalações, permissões, plataforma e último acesso;
- **Logs**: entregas técnicas, falhas e cliques;
- **Configurações**: Firebase, identidade do app, eventos, tags e simuladores.

Os gráficos usam os eventos técnicos registrados em `push_delivery_logs`. “Aceita” continua significando aceitação pelo Firebase, não confirmação garantida de visualização no aparelho.

Este documento registra o escopo aprovado para transformar a tela de notificações do aplicativo em um construtor visual de automações. A implementação será incremental para manter o envio atual funcionando e evitar sobrecarga na hospedagem.

## Objetivo

Permitir que administradores criem fluxos visuais acionados por eventos da área de membros. Cada aluno percorre os blocos do fluxo individualmente; quando não existe um próximo bloco, a execução termina.

O envio manual de teste continuará disponível durante todas as etapas.

## Progresso da implementação

- [x] Etapa 1 — organização da tela, configurações na engrenagem e identidade do aplicativo.
- [x] Etapa 2 — cadastro, listagem, clonagem, pausa, exclusão, editor visual e publicação versionada.
- [x] Etapa 3 — motor assíncrono, condições, esperas e execução das integrações.
- [ ] Etapa 4 — métricas por bloco, observabilidade e testes de concorrência/volume.

O motor da etapa 3 captura somente eventos novos. A publicação de um fluxo não faz processamento retroativo de alunos ou eventos anteriores. Por segurança de implantação, o motor global começa pausado e deve ser habilitado na engrenagem da tela de notificações depois que os fluxos publicados forem revisados.

### Convenção do bloco de integração

- SuperFuncionário e ManyChat: o campo `Destino/regra/fluxo` recebe o código do evento que possui uma regra ativa na integração.
- Webhook: o campo pode receber o ID numérico de um webhook específico ou um código de evento configurado nos webhooks globais.
- O campo de payload aceita JSON; quando não for JSON válido, o conteúdo é enviado como texto em `flow_payload`.
- Cada disparo recebe `push_flow.idempotency_key` para permitir deduplicação no destino quando a integração oferecer esse recurso.

## Etapa 1 — organização e identidade do aplicativo

- Manter os KPIs no início da tela.
- Criar um cabeçalho operacional com acesso às configurações por engrenagem.
- Retirar Firebase, eventos, tags e personalização do popup do corpo principal.
- Exibir essas configurações em um painel lateral, fechado por padrão.
- Preservar o simulador, dispositivos, logs e teste manual de push.
- Adicionar upload do ícone do aplicativo.
- Recomendar PNG ou WebP quadrado, 512 x 512 px, sem texto pequeno e com margem de segurança ao redor da marca.
- Validar tipo, tamanho do arquivo, dimensões mínimas e proporção quadrada.
- Usar o ícone enviado no manifesto instalável, favicon e imagem principal das notificações.
- Preservar o ícone monocromático atual como `badge` das notificações Android.

## Etapa 2 — cadastro e editor visual

### Listagem

- Botão `Criar novo fluxo` abaixo dos KPIs.
- Lista com nome, estado, data de modificação e indicadores básicos.
- Ações para editar, pausar/ativar, clonar e excluir.
- Exclusão protegida por confirmação e bloqueada quando houver execução ativa, salvo cancelamento explícito.

### Editor

- Tela exclusiva para cada fluxo.
- Canvas com arrastar e soltar, pan pelo fundo, zoom pela roda do mouse e controles de zoom.
- Conexões visuais entre blocos.
- Exclusão de conexão por ação exibida ao passar o mouse sobre a linha.
- Painel lateral para editar o bloco selecionado.
- Validação antes de salvar/publicar.
- Rascunho separado da versão publicada para não alterar execuções que já começaram.
- Histórico mínimo de versões para auditoria e recuperação.

### Blocos previstos

- Gatilho inicial.
- Condição com saídas `sim` e `não`.
- Espera.
- Ação de tag.
- Integração externa.
- Notificação push.

## Etapa 3 — motor de execução

### Gatilhos

- Inscrição e reinscrição em turma/curso.
- Aplicativo instalado.
- Notificações autorizadas.
- Demais eventos já expostos a SuperFuncionário, ManyChat e webhooks.
- Filtros específicos do gatilho, como turma ou curso.

#### Gatilho “X tempo antes da live”

- Configuração por turma, valor de antecedência e unidade em minutos, horas ou dias.
- O cron compara a antecedência com `turmas.data_live` a cada minuto.
- Quando o horário é atingido, todos os alunos associados à turma entram individualmente no fluxo.
- A entrada considera tanto a turma atual do aluno quanto o histórico de inscrições.
- Os candidatos são enfileirados em lotes de até 500 para não gerar pico de processamento.
- A execução é deduplicada por fluxo, turma, data da live, antecedência e aluno, inclusive quando uma nova versão é publicada depois que parte da turma já entrou.
- Se a data da live mudar, o lote antigo é marcado como substituído e a nova data gera outro lote.
- Se o fluxo estiver pausado, novos alunos não são enfileirados até sua reativação, desde que a live ainda não tenha começado.
- O evento disponibiliza `codigo_turma`, `data_live`, `live_at`, `antecedencia_valor` e `antecedencia_unidade` para os blocos seguintes.
- Títulos, mensagens e links do bloco push aceitam `{{nome}}`, `{{email}}`, `{{telefone}}`, `{{turma}}`, `{{codigo_turma}}`, `{{data_live}}`, `{{hora_live}}`, `{{codigo_live}}` e `{{link_live}}`.

### Condições

- Possui/não possui tag.
- Está/não está inscrito em turma.
- Clicou/não clicou no bloco push anterior da mesma execução.
- Comparações de campos suportados do aluno e do evento.
- Grupos `E` e `OU`, com validação de operadores por tipo de campo.

### Esperas

- Minutos, horas ou dias; segundos não serão suportados.
- Faixa de horário opcional para continuação.
- Datas armazenadas de forma consistente e apresentadas no fuso `America/Sao_Paulo`.
- Retomada pelo cron sem manter processos PHP abertos.

### Ações e integrações

- Adicionar tag e remover tag.
- SuperFuncionário, ManyChat e webhooks.
- Configuração de regra/fluxo/tag, payload e variáveis permitidas.
- Notificação push com título, texto e link interno ou HTTPS externo autorizado.
- Prévia aproximada da notificação recolhida e expandida no Android antes da publicação.
- Continuação automática ao próximo bloco após sucesso ou conforme política de erro configurada.

### Persistência

O banco deverá separar:

- definição e versões dos fluxos;
- blocos e conexões;
- execução de cada aluno;
- fila de etapas prontas ou agendadas;
- tentativas e erros;
- eventos de entrega e clique.

## Etapa 4 — métricas, logs e robustez

- Quantidade processada por bloco push.
- Aceitos pelo Firebase, falhas, tokens inválidos e cliques.
- Taxas de entrega técnica, erro e clique com denominadores explícitos.
- Histórico de passagem de cada aluno pelos blocos.
- Log de configuração inválida, integração externa e falha do motor.
- Filtros por fluxo, versão, bloco, período, status e aluno.
- Retentativa manual controlada quando for segura.
- Testes de concorrência, duplicidade, retomada e volume.

> O Firebase confirma que aceitou a mensagem, mas nem sempre confirma visualização no aparelho. A interface deve chamar essa métrica de `aceita pelo Firebase`, e não de leitura garantida.

## Campanhas push

- Campanhas podem ser imediatas ou agendadas e selecionar um ou mais fluxos publicados.
- A audiência é congelada na criação usando filtros de busca, turma, tag, progresso e autorização push.
- Cada combinação de campanha, aluno, fluxo e versão possui chave única para impedir duplicidade.
- A entrada usa o evento técnico `CAMPANHA_PUSH`, mas inicia a versão selecionada diretamente no gatilho; eventos comportamentais não são falsificados.
- Todos os blocos do fluxo são executados, inclusive tags e integrações externas, e ficam visíveis antes da confirmação.
- O cron cria as entradas em lotes e o motor existente processa os blocos, esperas e retentativas.
- O painel separa alunos selecionados, alunos com push, execuções concluídas/em andamento, erros de fluxo, aceitações Firebase, erros de entrega e cliques.
- Pausar ou cancelar impede novas entradas; execuções que já entraram no fluxo seguem a versão imutável selecionada.

## Proteções de desempenho e segurança

- Execução assíncrona em fila no banco.
- Cron em lotes pequenos, inicialmente entre 50 e 100 etapas por rodada.
- Tempo máximo por rodada para não esgotar recursos da hospedagem.
- Índices para status e `next_run_at`, evitando varredura integral das tabelas.
- Claim atômico de cada item e lease com expiração para impedir processamento simultâneo.
- Chaves de idempotência por fluxo, versão, aluno, evento e bloco.
- Retentativas com espera crescente e limite máximo.
- Limites por integração e por minuto.
- Timeout curto para chamadas externas.
- Estado de erro definitivo após esgotar tentativas.
- Limite de blocos por fluxo e de passos por execução.
- Detecção de ciclos; ciclos só serão aceitos futuramente com limite explícito.
- Links push restritos a destinos internos ou lista autorizada.
- Payloads e segredos nunca serão expostos no navegador ou nos logs.
- Publicação transacional: um fluxo inválido nunca fica ativo parcialmente.
- Métricas agregadas incrementalmente, sem recalcular todo o histórico a cada abertura.
- Política de retenção/arquivamento para logs antigos.
- Monitor de fila pendente, falhas, duração do cron e último processamento.
- Botão de pausa global do motor sem interromper o painel administrativo.

## Critérios para publicação de um fluxo

- Exatamente um gatilho inicial.
- Todos os blocos alcançáveis a partir do gatilho.
- Conexões obrigatórias preenchidas.
- Condições com as duas saídas tratadas ou encerramento explícito.
- Campos obrigatórios e integrações válidos.
- Nenhum segredo dentro do JSON visual do fluxo.
- Nenhum ciclo ilimitado.
- Versão imutável criada no momento da publicação.

## Estratégia de implantação

Cada etapa será publicada separadamente e deverá preservar compatibilidade com o envio manual existente. O motor começará desativado por padrão e será habilitado somente após migrações, validação do cron e teste de um fluxo controlado. O tamanho dos lotes será aumentado apenas com base em tempo de execução, memória, erros e crescimento da fila.
