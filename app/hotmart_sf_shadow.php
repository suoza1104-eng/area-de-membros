<?php
declare(strict_types=1);

/** Prepara Hotmart -> SuperFuncionário sem realizar qualquer chamada HTTP. */
function hotmart_sf_shadow_default_fields(): array
{
    return [
        ['source'=>'event','field_name'=>'RV_EVENTO','transform'=>'upper'],
        ['source'=>'data.purchase.status','field_name'=>'RV_STATUS','transform'=>'upper'],
        ['source'=>'data.purchase.transaction','field_name'=>'RV_TRANSACAO','transform'=>'trim'],
        ['source'=>'data.product.name','field_name'=>'RV_PRODUTO','transform'=>'trim'],
        ['source'=>'data.purchase.price.value','field_name'=>'RV_VALOR','transform'=>'trim'],
        ['source'=>'data.purchase.price.currency_value','field_name'=>'RV_MOEDA','transform'=>'upper'],
        ['source'=>'data.purchase.payment.type','field_name'=>'RV_TIPO_PAGAMENTO','transform'=>'upper'],
        ['source'=>'data.purchase.payment.installments_number','field_name'=>'RV_PARCELAS','transform'=>'trim'],
        ['source'=>'data.offer.code|data.purchase.offer.code','field_name'=>'RV_CODIGO_OFERTA','transform'=>'trim'],
        ['source'=>'data.offer.name|data.purchase.offer.name','field_name'=>'RV_NOME_OFERTA','transform'=>'trim'],
        ['source'=>'data.purchase.business_model','field_name'=>'RV_MODELO_NEGOCIO','transform'=>'trim'],
        ['source'=>'data.purchase.invoice_by','field_name'=>'RV_FATURADO_POR','transform'=>'trim'],
        ['source'=>'data.purchase.approved_date','field_name'=>'RV_DATA_APROVACAO','transform'=>'trim'],
        ['source'=>'data.checkout_country.name|data.purchase.checkout_country.name','field_name'=>'RV_PAIS_CHECKOUT','transform'=>'trim'],
        ['source'=>'data.affiliate','field_name'=>'RV_AFILIADO','transform'=>'lower'],
        ['source'=>'data.subscription.status','field_name'=>'RV_STATUS_ASSINATURA','transform'=>'upper'],
        ['source'=>'data.subscription.subscriber.code','field_name'=>'RV_CODIGO_ASSINANTE','transform'=>'trim'],
        ['source'=>'data.subscription.plan.name','field_name'=>'RV_NOME_PLANO','transform'=>'trim'],
        ['source'=>'data.purchase.order_bump.is_order_bump','field_name'=>'RV_E_ORDER_BUMP','transform'=>'lower'],
        ['source'=>'data.purchase.order_bump.parent_purchase_transaction','field_name'=>'RV_TRANSACAO_PAI','transform'=>'trim'],
        ['source'=>'data.module.name','field_name'=>'RV_NOME_MODULO','transform'=>'trim'],
        ['source'=>'data.purchase.payment.billet_barcode','field_name'=>'RV_CODIGO_BARRAS','transform'=>'trim'],
        ['source'=>'data.purchase.payment.billet_url','field_name'=>'RV_LINK_BOLETO','transform'=>'trim'],
        ['source'=>'data.purchase.payment.pix_code','field_name'=>'RV_CODIGO_PIX','transform'=>'trim'],
        ['source'=>'data.purchase.payment.pix_qrcode','field_name'=>'RV_LINK_PIX','transform'=>'trim'],
        ['source'=>'data.purchase.payment.pix_expiration_date','field_name'=>'RV_EXPIRACAO_PIX','transform'=>'trim'],
        ['source'=>'data.purchase.date_next_charge','field_name'=>'RV_PROXIMA_COBRANCA','transform'=>'trim'],
        ['source'=>'data.purchase.recurrence_number','field_name'=>'RV_NUMERO_RECORRENCIA','transform'=>'trim'],
        ['source'=>'data.purchase.payment.acquirer_response_message|data.purchase.payment.gateway_response_message|data.purchase.payment.reason|data.purchase.payment.message|data.purchase.payment.refusal_reason','field_name'=>'RV_MOTIVO_RECUSA','transform'=>'trim'],
        ['source'=>'data.purchase.payment.acquirer_response_code|data.purchase.payment.gateway_response_code','field_name'=>'RV_CODIGO_RECUSA','transform'=>'trim'],
    ];
}

function hotmart_sf_shadow_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotmart_sf_shadow_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(100) NOT NULL,
        event_name VARCHAR(100) NOT NULL,
        transaction_code VARCHAR(80) NULL,
        contact_email VARCHAR(255) NULL,
        contact_phone VARCHAR(30) NULL,
        payload_json LONGTEXT NOT NULL,
        status ENUM('shadow','ready','sent','failed','cancelled') NOT NULL DEFAULT 'shadow',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        UNIQUE KEY uq_hotmart_sf_shadow_event (event_id),
        KEY idx_hotmart_sf_shadow_status (status),
        KEY idx_hotmart_sf_shadow_event_name (event_name),
        KEY idx_hotmart_sf_shadow_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hotmart_sf_shadow_config(): array
{
    $fields = json_decode((string)(get_setting('hotmart_sf_shadow_fields_json', '') ?: ''), true);
    if (!is_array($fields)) $fields = hotmart_sf_shadow_default_fields();
    $captureSetting = get_setting('hotmart_sf_shadow_capture_enabled', '1');
    return [
        'capture_enabled'=>(int)($captureSetting === null ? '1' : $captureSetting),
        'fixed_tag'=>trim((string)(get_setting('hotmart_sf_shadow_fixed_tag', 'RV_ENTRADA_WEBHOOK') ?: 'RV_ENTRADA_WEBHOOK')),
        'flow_id'=>trim((string)(get_setting('hotmart_sf_shadow_flow_id', '1762470934357') ?: '1762470934357')),
        'event_tag_prefix'=>trim((string)(get_setting('hotmart_sf_shadow_event_prefix', 'RV_') ?: 'RV_')),
        'order_bump_prefix'=>trim((string)(get_setting('hotmart_sf_shadow_order_bump_prefix', 'RV_ORDER_BUMP_') ?: 'RV_ORDER_BUMP_')),
        'fields'=>$fields,
    ];
}

function hotmart_sf_shadow_pick(array $payload, string $paths)
{
    foreach (explode('|', $paths) as $path) {
        $cur = $payload;
        foreach (explode('.', trim($path)) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) { $cur = null; break; }
            $cur = $cur[$part];
        }
        if ($cur !== null && $cur !== '' && (is_scalar($cur) || is_bool($cur))) return $cur;
    }
    return null;
}

function hotmart_sf_shadow_transform($value, string $transform): string
{
    if (is_bool($value)) $value = $value ? 'true' : 'false';
    $value = trim((string)$value);
    if ($transform === 'upper') return mb_strtoupper($value, 'UTF-8');
    if ($transform === 'lower') return mb_strtolower($value, 'UTF-8');
    return $value;
}

function hotmart_sf_shadow_phone(array $payload): string
{
    $raw = (string)(hotmart_sf_shadow_pick($payload, 'data.buyer.checkout_phone|data.buyer.phone') ?? '');
    $code = preg_replace('/\D+/', '', (string)(hotmart_sf_shadow_pick($payload, 'data.buyer.checkout_phone_code') ?? ''));
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';
    if ($code !== '' && strpos($digits, $code) !== 0 && strlen($digits) <= 11) $digits = $code . $digits;
    if (strpos($digits, '55') !== 0 && strlen($digits) >= 10 && strlen($digits) <= 11) $digits = '55' . $digits;
    if (preg_match('/^55(\d{2})(\d{8})$/', $digits, $m)) $digits = '55' . $m[1] . '9' . $m[2];
    return '+' . $digits;
}

function hotmart_sf_shadow_build_payload(array $hotmartPayload, ?array $config = null): array
{
    $config = $config ?? hotmart_sf_shadow_config();
    $event = mb_strtoupper(trim((string)($hotmartPayload['event'] ?? '')), 'UTF-8');
    $name = trim((string)(hotmart_sf_shadow_pick($hotmartPayload, 'data.buyer.name|data.subscriber.name') ?? ''));
    $email = mb_strtolower(trim((string)(hotmart_sf_shadow_pick($hotmartPayload, 'data.buyer.email|data.subscriber.email') ?? '')), 'UTF-8');
    $phone = hotmart_sf_shadow_phone($hotmartPayload);
    $actions = [];
    if ($config['fixed_tag'] !== '') $actions[] = ['action'=>'add_tag','tag_name'=>$config['fixed_tag']];
    if ($config['flow_id'] !== '' && ctype_digit($config['flow_id'])) $actions[] = ['action'=>'send_flow','flow_id'=>(int)$config['flow_id']];
    if ($event !== '') $actions[] = ['action'=>'add_tag','tag_name'=>mb_strtoupper($config['event_tag_prefix'] . $event, 'UTF-8')];
    $orderBump = hotmart_sf_shadow_pick($hotmartPayload, 'data.purchase.order_bump.is_order_bump');
    if ($orderBump !== null) $actions[] = ['action'=>'add_tag','tag_name'=>mb_strtoupper($config['order_bump_prefix'] . hotmart_sf_shadow_transform($orderBump, 'lower'), 'UTF-8')];
    foreach ($config['fields'] as $field) {
        if (!is_array($field)) continue;
        $source = trim((string)($field['source'] ?? ''));
        $fieldName = trim((string)($field['field_name'] ?? ''));
        if ($source === '' || $fieldName === '') continue;
        $value = hotmart_sf_shadow_pick($hotmartPayload, $source);
        if ($value === null) continue;
        $value = hotmart_sf_shadow_transform($value, (string)($field['transform'] ?? 'trim'));
        if ($value !== '') $actions[] = ['action'=>'set_field_value','field_name'=>$fieldName,'value'=>$value];
    }
    return array_filter(['email'=>$email ?: null,'phone'=>$phone ?: null,'first_name'=>$name ?: null,'actions'=>$actions], static fn($value) => $value !== null);
}

function hotmart_sf_shadow_stage(PDO $pdo, array $hotmartPayload): bool
{
    $config = hotmart_sf_shadow_config();
    if ((int)$config['capture_enabled'] !== 1) return false;
    hotmart_sf_shadow_ensure_schema($pdo);
    $eventId = trim((string)($hotmartPayload['id'] ?? ''));
    if ($eventId === '') $eventId = hash('sha256', json_encode($hotmartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $event = mb_strtoupper(trim((string)($hotmartPayload['event'] ?? 'UNKNOWN')), 'UTF-8');
    $transaction = trim((string)(hotmart_sf_shadow_pick($hotmartPayload, 'data.purchase.transaction') ?? ''));
    $body = hotmart_sf_shadow_build_payload($hotmartPayload, $config);
    if (empty($body['email']) && empty($body['phone'])) return false;
    $stmt = $pdo->prepare("INSERT INTO hotmart_sf_shadow_outbox
        (event_id,event_name,transaction_code,contact_email,contact_phone,payload_json,status,created_at,updated_at)
        VALUES (:id,:event,:tx,:email,:phone,:payload,'shadow',NOW(),NOW())
        ON DUPLICATE KEY UPDATE event_name=VALUES(event_name),transaction_code=VALUES(transaction_code),
        contact_email=VALUES(contact_email),contact_phone=VALUES(contact_phone),payload_json=VALUES(payload_json),updated_at=NOW()");
    $stmt->execute(['id'=>$eventId,'event'=>$event,'tx'=>$transaction ?: null,'email'=>$body['email'] ?? null,'phone'=>$body['phone'] ?? null,'payload'=>json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return true;
}
