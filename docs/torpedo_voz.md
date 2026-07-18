# Torpedo de Voz

Modulo de chamadas de voz integrado inicialmente a Telnyx.

## Arquitetura

- `app/voice_torpedo.php`: schema, provider Telnyx, criacao de chamadas, webhook, eventos normalizados, fila e auditoria.
- `admin/torpedo_voz.php`: pagina administrativa com visao geral, campanhas, numeros, audios, configuracoes, chamadas, logs e bloqueio.
- `public/telnyx_voice_webhook.php`: webhook principal Telnyx.
- `public/telnyx_voice_webhook_failover.php`: webhook failover Telnyx.
- `cron/processar_torpedo_voz.php`: worker de campanhas e destinatarios em fila.
- `admin/automacoes.php`: bloco `Chamada de voz` no editor central.
- `app/automation_catalog.php`: gatilhos de voz centralizados em `automation_triggers`.

## Endpoints Telnyx Usados

- Criar chamada: `POST /v2/calls`.
- Webhooks de voz: recebidos pelos endpoints publicos do projeto.
- Teste de credencial: `GET /v2/phone_numbers?page[size]=1`.

## Configuracao

Configure em `Admin > Torpedo de Voz > Configuracoes`:

- API Key Telnyx.
- Public key Telnyx para validar webhook Ed25519.
- `connection_id`.
- numero padrao de origem em E.164.
- limites de chamada e numeros autorizados para teste.

A API Key e gravada criptografada no banco e nunca e exibida integralmente no frontend.

## Webhooks

Cadastre na Telnyx:

- Principal: `BASE_URL/telnyx_voice_webhook.php`.
- Failover: `BASE_URL/telnyx_voice_webhook_failover.php`.

Se a public key nao estiver configurada, o webhook salva log com `signature_missing` e nao aplica efeitos colaterais.

## Cron

A rotina `torpedo_voz` foi adicionada ao gerenciador central. Ela processa destinatarios pendentes de campanhas em execucao.

Comando direto:

```bash
php cron/processar_torpedo_voz.php
```

Via dispatcher:

```text
public/cron_dispatcher.php?task=torpedo_voz&token=TOKEN
```

## Eventos Internos

Os webhooks Telnyx podem gerar gatilhos centrais:

- `VOICE_CALL_ANSWERED`
- `VOICE_CALL_HUMAN`
- `VOICE_CALL_MACHINE`
- `VOICE_CALL_NOT_ANSWERED`
- `VOICE_CALL_BUSY`
- `VOICE_CALL_REJECTED`
- `VOICE_CALL_FAILED`
- `VOICE_CALL_COMPLETED`
- `VOICE_CALL_AUDIO_COMPLETED`
- `VOICE_CALL_DTMF_RECEIVED`

## Cuidados

- Nao habilite campanha sem testar numero de origem, connection id e webhook.
- Chamada aceita pela API nao significa chamada atendida. Atendimento e conclusao dependem dos webhooks.
- Custos so devem ser exibidos quando vierem do provedor ou de uma conciliacao real.
- Telefones na `voice_suppression_list` sao bloqueados antes da criacao da chamada.
