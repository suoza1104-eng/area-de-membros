# IA WhatsApp - Andamento da Implantacao

Este documento registra o que ja foi implantado na integracao de IA para monitoramento dos grupos de WhatsApp, como testar e quais sao os proximos passos planejados.

## Objetivo

Criar uma central de IA para observar grupos monitorados pelo numero espiao/admin, empacotar mensagens recentes, analisar com OpenAI e sugerir intervencoes da equipe tecnica.

A implementacao atual prioriza seguranca operacional: a IA analisa e sugere, mas acoes sensiveis passam por aprovacao manual.

## Fase 1 - Captura, Configuracao e Analise

Status: implantada.

Arquivos principais:

- `app/whatsapp_ai.php`
- `admin/whatsapp_ai.php`
- `cron/processar_whatsapp_ai.php`
- `public/cron_whatsapp_ai.php`
- `public/whatsapp_webhook.php`
- `admin/_header.php`

O que foi entregue:

- Tela admin `IA WhatsApp` no menu de Integracoes.
- Configuracao de:
  - ativar/desativar IA;
  - API key OpenAI;
  - modelo de IA;
  - intervalo de empacotamento;
  - horario de funcionamento;
  - limite de mensagens por pacote;
  - limite de tokens;
  - quantidade de contextos anteriores;
  - prompt orientativo;
  - criterios adicionais.
- Captura passiva de mensagens comuns recebidas no webhook do WhatsApp.
- Ignora grupos marcados como ignorados em `whatsapp_groups.is_ignored`.
- Empacota mensagens por grupo.
- Envia pacote para OpenAI.
- Salva:
  - resumo;
  - categoria;
  - nivel;
  - resposta sugerida;
  - contexto para o proximo pacote;
  - uso de tokens quando retornado pela API;
  - erro, se houver.
- Cron protegido por token.

Tabelas criadas:

- `whatsapp_ai_messages`
- `whatsapp_ai_batches`
- `whatsapp_ai_contexts`
- `whatsapp_ai_runs`

## Fase 2 - Revisao Manual e Acoes Pendentes

Status: implantada.

O que foi entregue:

- Tabela `whatsapp_ai_actions`.
- Quando a IA retorna `precisa_intervencao = true`, o sistema cria acoes pendentes.
- Tela com `Fila de revisao manual`.
- Cada item mostra:
  - grupo;
  - pacote;
  - resumo;
  - categoria;
  - nivel;
  - mensagens originais do pacote;
  - acao sugerida;
  - alvo identificado, quando existir.
- Acoes manuais:
  - aprovar;
  - ignorar;
  - resolver pacote.
- Historico de acoes processadas.

Tipos de acao suportados:

- `send_group_message`: envia mensagem no grupo via Evolution, somente apos aprovacao.
- `apply_tag`: aplica tag no aluno identificado.
- `trigger_webhook`: dispara evento/webhook para aluno identificado.
- `internal_alert`: registra alerta/manual.

Observacao: a IA nao executa acao sozinha. Tudo passa por aprovacao humana.

## Melhoria de Midia - Imagem e Audio

Status: implantada, dependente do payload enviado pela Evolution.

O que foi entregue:

- Captura de imagens mesmo sem legenda.
- Captura de audios mesmo sem texto.
- Imagens entram na analise visual da OpenAI quando o payload contem URL publica ou base64.
- Audios sao transcritos antes da analise quando o payload contem URL/base64 acessivel.
- Se a midia nao for acessivel, o pacote ainda informa que houve midia:
  - exemplo: `[enviou uma imagem sem legenda]`;
  - exemplo: `[enviou um audio sem transcricao]`.
- Campo de configuracao para modelo de transcricao.
- Contador de midias capturadas na tela.
- Status de transcricao nas ultimas mensagens.

Modelo padrao de transcricao:

- `gpt-4o-mini-transcribe`

Limitacao importante:

- A analise real da imagem/audio depende da Evolution enviar URL publica, URL acessivel pelo servidor ou base64 da midia no webhook.
- Se a Evolution enviar apenas o evento sem arquivo acessivel, o sistema registra a existencia da midia, mas nao consegue analisar o conteudo.

## Como Testar

1. Acessar `Admin > Integracoes > IA WhatsApp`.
2. Conferir:
   - IA ativa;
   - API key configurada;
   - horario atual dentro da janela ativa;
   - intervalo baixo para teste, por exemplo `1 minuto`;
   - grupo nao ignorado.
3. Enviar mensagem de texto em grupo monitorado.
4. Recarregar a tela e verificar `Ultimas mensagens capturadas`.
5. Aguardar o intervalo ou clicar em `Processar agora`.
6. Verificar `Ultimos pacotes analisados`.
7. Se houver intervencao, verificar `Fila de revisao manual`.
8. Testar aprovar/ignorar uma acao.
9. Para midia:
   - enviar imagem com legenda;
   - enviar imagem sem legenda;
   - enviar audio;
   - verificar se aparece em `Ultimas mensagens capturadas`;
   - verificar se audio ficou `transcrita`, `processing` ou `error`.

## Sinais de Problema

Mensagem nao aparece em `Ultimas mensagens capturadas`:

- A Evolution provavelmente nao esta enviando eventos de mensagens comuns para `public/whatsapp_webhook.php`.
- Pode estar enviando apenas eventos de entrada/saida de participantes.

Mensagem aparece, mas nao gera pacote:

- IA desligada;
- fora do horario configurado;
- intervalo ainda nao passou;
- grupo ignorado;
- cron nao configurado;
- clique manual em `Processar agora` ainda nao feito.

Pacote com `error`:

- API key invalida;
- modelo invalido;
- problema de cURL no PHP;
- erro na chamada OpenAI;
- payload de imagem/audio inacessivel.

Audio nao transcreve:

- Evolution nao enviou URL/base64;
- URL exige autenticacao;
- servidor nao consegue baixar o arquivo;
- formato nao suportado;
- erro no modelo de transcricao.

Imagem nao e analisada visualmente:

- Evolution nao enviou URL/base64;
- URL nao e publica/acessivel;
- base64 veio incompleto;
- modelo configurado nao aceita entrada visual.

## Cron

A tela `IA WhatsApp` gera uma URL parecida com:

```text
https://SEU_DOMINIO/cron_whatsapp_ai.php?token=TOKEN
```

Recomendacao:

- configurar no cron do servidor a cada 1 minuto;
- o proprio sistema respeita o intervalo configurado na tela.

## Prompt e Saida Esperada

A IA deve retornar JSON com:

```json
{
  "precisa_intervencao": true,
  "nivel": "baixo|medio|alto|critico",
  "categoria": "duvida_tecnica|interesse_compra|baixo_calao|elogio|suporte|conversa_normal|risco",
  "resumo": "Resumo curto do pacote.",
  "resposta_sugerida": "Texto sugerido para a equipe enviar.",
  "acoes": [
    {
      "tipo": "apply_tag",
      "telefone": "5511999999999",
      "tag": "INTERESSE_COMPRA_WHATSAPP"
    }
  ],
  "novo_contexto": "Resumo compacto para os proximos pacotes."
}
```

Tipos de acao recomendados para o prompt:

- `send_group_message`
- `apply_tag`
- `trigger_webhook`
- `internal_alert`

## Proximas Etapas Possiveis

### Fase 3 - Automacoes Seguras

Ainda nao implantada.

Ideia:

- Permitir autoexecucao apenas de acoes de baixo risco.
- Exemplo:
  - aplicar tag automaticamente;
  - criar alerta interno;
  - disparar webhook de lead quente.
- Manter envio de mensagem no grupo com aprovacao manual.

### Fase 4 - Respostas Automaticas Controladas

Ainda nao implantada.

Ideia:

- Liberar resposta automatica por grupo e por categoria.
- Configurar cooldown por grupo/aluno.
- Limite diario de respostas.
- Bloqueio automatico para categorias sensiveis.
- Permitir respostas apenas em horarios definidos.

### Fase 5 - Acoes Administrativas no Grupo

Ainda nao implantada.

Ideia:

- Fechar/restringir conversas do grupo.
- Marcar participante.
- Responder mencionando aluno.
- Remover ou silenciar, se a Evolution/API permitir.

Recomendacao:

- deixar esta fase por ultimo;
- exigir permissao especifica;
- registrar auditoria completa.

## Cuidados de Seguranca

- A API key da OpenAI fica salva em `settings`.
- Ideal futuro: criptografar a chave ou mover para variavel de ambiente.
- Toda acao aprovada fica registrada em `whatsapp_ai_actions`.
- Mensagens de grupo sao dados sensiveis; evitar expor logs publicamente.
- Nao habilitar resposta automatica antes de validar custo, qualidade e comportamento do prompt.

## Estado Atual Resumido

Implantado:

- captura de mensagens;
- analise por IA;
- contexto incremental;
- fila manual;
- aprovacao de acoes;
- historico;
- imagens;
- audios com transcricao quando possivel.

Pendente:

- confirmar se Evolution esta enviando mensagens comuns e midias com URL/base64;
- configurar cron no servidor;
- testar em grupo real;
- calibrar prompt;
- decidir se a Fase 3 tera autoexecucao de tags/webhooks.

