# VPS do professoremersonleite.site

Este documento registra o acesso usado pelo Codex para publicar e manter o relay Firepay no dominio `professoremersonleite.site`.

## SSH

Alias local configurado em `C:\Users\Emerson\.ssh\config`:

```sshconfig
Host firepay-site
    HostName professoremersonleite.site
    User root
    Port 22022
    IdentityFile C:/Users/Emerson/.ssh/codex_firepay_site_bridge
    IdentitiesOnly yes
```

Teste de conexao:

```bash
ssh firepay-site 'hostname; pwd'
```

A chave publica instalada no servidor esta versionada em:

```text
infra/firepay-site/codex_firepay_site_bridge.pub
```

Instalador da chave, caso precise reinstalar em outro usuario do servidor:

```bash
curl -fsSL https://raw.githubusercontent.com/suoza1104-eng/area-de-membros/main/infra/firepay-site/install_codex_key.sh | sh
```

## Caminhos no servidor

Raiz publicada do dominio:

```text
/home/professoremerson/professoremersonleite.site
```

Relay Firepay:

```text
/home/professoremerson/professoremersonleite.site/firepay_bridge_site.php
```

Banco SQLite e dados locais do relay:

```text
/home/professoremerson/professoremersonleite.site/firepay_relay_data/firepay_relay.sqlite
```

## Publicar arquivo

Sempre validar sintaxe local antes:

```bash
C:\xampp\php\php.exe -l firepay_bridge_site.php
```

Fazer backup remoto e subir:

```bash
ssh firepay-site 'cp /home/professoremerson/professoremersonleite.site/firepay_bridge_site.php /home/professoremerson/professoremersonleite.site/firepay_bridge_site.php.bak.$(date +%Y%m%d%H%M%S)'
scp firepay_bridge_site.php firepay-site:/home/professoremerson/professoremersonleite.site/firepay_bridge_site.php
ssh firepay-site 'php -l /home/professoremerson/professoremersonleite.site/firepay_bridge_site.php'
```

Validar no ar:

```bash
curl -fsSL https://professoremersonleite.site/firepay_bridge_site.php
```

A versao nova do mini sistema responde com `"relay":true`.

## URLs

Recepcao:

```text
https://professoremersonleite.site/firepay_bridge_site.php
```

Dashboard:

```text
https://professoremersonleite.site/firepay_bridge_site.php?dashboard=1
```

Configuracao:

```text
https://professoremersonleite.site/firepay_bridge_site.php?config=1
```

Processar fila manualmente:

```text
https://professoremersonleite.site/firepay_bridge_site.php?process_queue=1
```

## Cron

Cron instalado no root:

```cron
* * * * * /usr/local/bin/php /home/professoremerson/professoremersonleite.site/firepay_bridge_site.php process_queue >/dev/null 2>&1
```

Verificar:

```bash
ssh firepay-site 'crontab -l'
```

## Consultas rapidas

Ultimas entradas e status de fila:

```bash
ssh firepay-site "sqlite3 /home/professoremerson/professoremersonleite.site/firepay_relay_data/firepay_relay.sqlite 'SELECT i.id,i.received_at,i.transaction_id,i.firepay_status,i.buyer_email,q.status,q.attempts,q.last_http_status,q.sent_at FROM inbound_logs i LEFT JOIN dispatch_queue q ON q.inbound_id=i.id ORDER BY i.id DESC LIMIT 20;'"
```

Processar fila via SSH:

```bash
ssh firepay-site '/usr/local/bin/php /home/professoremerson/professoremersonleite.site/firepay_bridge_site.php process_queue'
```
