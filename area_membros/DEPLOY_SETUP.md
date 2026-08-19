# Deploy automático (GitHub → HostGator)

Um `git push` sozinho **não** atualiza o site. É preciso um gatilho no servidor.
Há duas formas. A **opção A (cron)** é a mais simples e robusta.

---

## Opção A — Cron (recomendada, sem segredo, ~1–2 min de atraso)

No cPanel → **Cron Jobs**, adicione um job com intervalo de 2 minutos
(`*/2 * * * *`) e o comando:

```
/bin/bash /home1/prof2543/repositories/area-de-membros/deploy.sh >/dev/null 2>&1
```

Pronto. A cada commit no `main`, em até 2 minutos o site atualiza sozinho.
O `deploy.sh` só age quando existe commit novo (não faz nada se já está atualizado).

---

## Opção B — Webhook (deploy instantâneo)

1. **Crie o arquivo de segredo no servidor** (cPanel → File Manager), em:
   `/home1/prof2543/.deploy_secret`
   com o segredo (uma linha só). O segredo é gerado/fornecido fora do repositório
   — nunca é versionado aqui. (de preferência, deixe permissão 600)

2. **Instale o script no site uma vez:** cPanel → Git Version Control →
   *Update from Remote* + *Deploy HEAD Commit*. Isso publica o `public/_deploy.php`.

3. **Crie o webhook no GitHub** apontando para
   `https://professoremersonleite.com/area_membros/public/_deploy.php`
   (content-type `application/json`, secret = o mesmo do passo 1, evento *push*).
   — Isto pode ser feito automaticamente pelo assistente via `gh`.

A partir daí, cada push no `main` dispara o deploy na hora.
O log fica em `/home1/prof2543/repositories/area-de-membros/deploy.log`.

> Se o PHP do plano tiver `exec()` desabilitado, o webhook não consegue rodar
> `git`/`rsync` — nesse caso use a Opção A (cron).
