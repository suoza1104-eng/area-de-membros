# Evolution API isolada

Arquivos de referencia para subir a Evolution API sem alterar a aplicacao PHP atual.

## Subir

1. Copie `.env.example` para `.env`.
2. Troque `POSTGRES_PASSWORD` e `AUTHENTICATION_API_KEY`.
3. Rode:

```bash
docker compose up -d
```

4. No painel PHP, acesse `admin/whatsapp_monitor.php` e configure:

- URL base: `http://IP_DA_VPS:8080` ou o subdominio/proxy configurado.
- API key: o mesmo valor de `AUTHENTICATION_API_KEY`.

## Observacoes

- Esta configuracao deixa webhooks globais desligados na Fase 1.
- O objetivo inicial e apenas criar uma instancia, gerar QR Code e confirmar conexao.
- Em producao, coloque a Evolution API atras de HTTPS/proxy e nao exponha a porta 8080 publicamente sem controle de acesso.
