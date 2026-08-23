<?php
declare(strict_types=1);

require_once __DIR__ . '/automation_catalog.php';

function payment_events_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    automation_triggers_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_payment_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        provider VARCHAR(30) NOT NULL,
        event_code VARCHAR(100) NOT NULL,
        generic_event_code VARCHAR(100) NOT NULL,
        event_fingerprint CHAR(64) NOT NULL,
        transaction_code VARCHAR(140) NOT NULL,
        provider_transaction_id VARCHAR(120) NULL,
        provider_status VARCHAR(80) NULL,
        normalized_status VARCHAR(30) NOT NULL,
        payment_method VARCHAR(80) NULL,
        currency VARCHAR(10) NULL,
        gross_amount_cents BIGINT NOT NULL DEFAULT 0,
        net_amount_cents BIGINT NOT NULL DEFAULT 0,
        fee_amount_cents BIGINT NOT NULL DEFAULT 0,
        installments INT UNSIGNED NULL,
        product_name VARCHAR(255) NULL,
        product_code VARCHAR(120) NULL,
        checkout_id VARCHAR(120) NULL,
        checkout_url VARCHAR(1000) NULL,
        pix_qrcode VARCHAR(2048) NULL,
        pix_qrcode_url VARCHAR(1000) NULL,
        pix_expires_at DATETIME NULL,
        boleto_url VARCHAR(1000) NULL,
        boleto_line VARCHAR(255) NULL,
        buyer_name VARCHAR(255) NULL,
        buyer_email VARCHAR(255) NULL,
        buyer_phone VARCHAR(80) NULL,
        buyer_document VARCHAR(80) NULL,
        metadata_json LONGTEXT NULL,
        raw_payload_json LONGTEXT NULL,
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        triggered_at DATETIME NULL,
        trigger_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_student_payment_event (event_fingerprint),
        KEY idx_student_payment_transaction (provider, transaction_code),
        KEY idx_student_payment_user (user_id, last_seen_at),
        KEY idx_student_payment_event (event_code, last_seen_at),
        KEY idx_student_payment_status (normalized_status, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function payment_event_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function payment_event_first_value(array $data, array $keys): string
{
    foreach ($keys as $key) {
        $value = payment_event_find_recursive($data, $key);
        if ($value !== '') return $value;
    }
    return '';
}

function payment_event_find_recursive(array $data, string $key): string
{
    foreach ($data as $k => $value) {
        if (strcasecmp((string)$k, $key) === 0 && is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
        if (is_array($value)) {
            $found = payment_event_find_recursive($value, $key);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function payment_event_datetime_or_null(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    try { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; }
}

function payment_event_codes(string $provider, string $status): array
{
    $status = strtoupper(trim($status));
    $generic = [
        'APPROVED' => 'PAGAMENTO_APROVADO',
        'PENDING' => 'PAGAMENTO_AGUARDANDO',
        'REFUNDED' => 'PAGAMENTO_REEMBOLSADO',
        'CHARGEBACK' => 'PAGAMENTO_CHARGEBACK',
        'CANCELED' => 'PAGAMENTO_CANCELADO',
        'ABANDONED' => 'CARRINHO_ABANDONADO',
    ][$status] ?? '';
    if ($generic === '') return [];
    return [$generic];
}

function payment_event_compact_payload(array $row): array
{
    $keys = [
        'gateway','evento','status','transaction_code','provider_transaction_id','payment_method','currency',
        'valor_bruto','valor_liquido','taxa','gross_amount_cents','net_amount_cents','fee_amount_cents',
        'installments','product_name','product_code','checkout_id','checkout_url','pix_qrcode','pix_qrcode_url',
        'pix_expires_at','boleto_url','boleto_line','buyer_name','buyer_email','buyer_phone','buyer_document',
        'payment_event_id','occurred_at','aluno_identificado','metadata',
    ];
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') $out[$key] = $row[$key];
    }
    return $out;
}

function payment_event_business_identity(array $data): string
{
    $parts = [
        strtolower(trim((string)($data['product_code'] ?? ''))),
        strtolower(trim((string)($data['product_name'] ?? ''))),
        strtolower(trim((string)($data['checkout_id'] ?? ''))),
        strtolower(trim((string)($data['buyer_email'] ?? ''))),
        (string)(int)($data['gross_amount_cents'] ?? 0),
    ];
    $parts = array_values(array_filter($parts, static fn($value) => $value !== '' && $value !== '0'));
    return $parts ? hash('sha256', implode('|', $parts)) : '';
}

function payment_event_trigger_payload(array $data, array $metadata, int $eventId, string $provider, string $status, string $eventCode, string $transactionCode): array
{
    return payment_event_compact_payload([
        'gateway'=>$provider,
        'evento'=>$eventCode,
        'status'=>$status,
        'transaction_code'=>$transactionCode,
        'provider_transaction_id'=>$data['provider_transaction_id'] ?? '',
        'payment_method'=>$data['payment_method'] ?? '',
        'currency'=>$data['currency'] ?? 'BRL',
        'valor_bruto'=>((int)($data['gross_amount_cents'] ?? 0)) / 100,
        'valor_liquido'=>((int)($data['net_amount_cents'] ?? 0)) / 100,
        'taxa'=>((int)($data['fee_amount_cents'] ?? 0)) / 100,
        'gross_amount_cents'=>(int)($data['gross_amount_cents'] ?? 0),
        'net_amount_cents'=>(int)($data['net_amount_cents'] ?? 0),
        'fee_amount_cents'=>(int)($data['fee_amount_cents'] ?? 0),
        'installments'=>$data['installments'] ?? null,
        'product_name'=>$data['product_name'] ?? '',
        'product_code'=>$data['product_code'] ?? '',
        'checkout_id'=>$data['checkout_id'] ?? '',
        'checkout_url'=>$data['checkout_url'] ?? '',
        'pix_qrcode'=>$data['pix_qrcode'] ?? '',
        'pix_qrcode_url'=>$data['pix_qrcode_url'] ?? '',
        'pix_expires_at'=>$data['pix_expires_at'] ?? '',
        'boleto_url'=>$data['boleto_url'] ?? '',
        'boleto_line'=>$data['boleto_line'] ?? '',
        'buyer_name'=>$data['buyer_name'] ?? '',
        'buyer_email'=>$data['buyer_email'] ?? '',
        'buyer_phone'=>$data['buyer_phone'] ?? '',
        'buyer_document'=>$data['buyer_document'] ?? '',
        'payment_event_id'=>$eventId,
        'occurred_at'=>$data['occurred_at'] ?? date('Y-m-d H:i:s'),
        'aluno_identificado'=>(int)($data['user_id'] ?? 0) > 0 ? 1 : 0,
        'metadata'=>$metadata,
    ]);
}

function payment_event_capture_unmatched_automation(PDO $pdo, string $eventCode, array $extra): int
{
    try {
        require_once __DIR__ . '/automation_flows.php';
        automation_flows_ensure_schema($pdo);
        $flows = $pdo->query("SELECT f.id flow_id,f.current_version_id,v.graph_json FROM automation_flows f JOIN automation_flow_versions v ON v.id=f.current_version_id WHERE f.status='active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$flows) return 0;

        $payload = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $source = hash('sha256', 'payment_unmatched|' . strtoupper($eventCode) . '|' . ($extra['transaction_code'] ?? '') . '|' . ($extra['payment_event_id'] ?? '') . '|' . $payload);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT IGNORE INTO automation_flow_events(event_code,user_id,source_key,payload_json) VALUES(:e,0,:s,:p)')
            ->execute(['e'=>$eventCode,'s'=>$source,'p'=>$payload]);
        $st = $pdo->prepare('SELECT id FROM automation_flow_events WHERE source_key=:s');
        $st->execute(['s'=>$source]);
        $eventId = (int)$st->fetchColumn();
        if ($eventId <= 0) { $pdo->rollBack(); return 0; }

        $matched = 0;
        foreach ($flows as $flow) {
            $graph = json_decode((string)$flow['graph_json'], true) ?: [];
            $trigger = null;
            foreach (($graph['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') === 'trigger') { $trigger = $node; break; }
            }
            if (!$trigger || !automation_flow_trigger_matches($trigger, ['id'=>0], $extra, $eventCode, (int)$flow['flow_id'], (int)$flow['current_version_id'])) continue;
            $run = $pdo->prepare("INSERT IGNORE INTO automation_flow_runs(flow_id,version_id,event_id,user_id,status) VALUES(:f,:v,:e,0,'running')");
            $run->execute(['f'=>$flow['flow_id'],'v'=>$flow['current_version_id'],'e'=>$eventId]);
            if ($run->rowCount() !== 1) continue;
            $runId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,channel,status,available_at,input_json) VALUES(:r,:n,:c,'queued',NOW(),'{}')")
                ->execute(['r'=>$runId,'n'=>(string)$trigger['id'],'c'=>automation_flow_job_channel($trigger)]);
            $matched++;
        }
        $pdo->prepare('UPDATE automation_flow_events SET matched_flows=:m WHERE id=:id')->execute(['m'=>$matched,'id'=>$eventId]);
        $pdo->commit();
        return $matched;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log('payment_event_capture_unmatched_automation: ' . $e->getMessage());
        return 0;
    }
}

function payment_event_has_recent_business_trigger(PDO $pdo, int $userId, string $genericEvent, int $grossCents, string $businessIdentity): bool
{
    if ($userId < 1 || $genericEvent === '' || $businessIdentity === '') return false;
    $since = date('Y-m-d H:i:s', time() - 86400);
    $stmt = $pdo->prepare("SELECT metadata_json FROM student_payment_events
        WHERE user_id=:user_id
          AND generic_event_code=:event
          AND gross_amount_cents=:gross
          AND triggered_at IS NOT NULL
          AND last_seen_at>=:since
        ORDER BY triggered_at DESC
        LIMIT 20");
    $stmt->execute([
        ':user_id'=>$userId,
        ':event'=>$genericEvent,
        ':gross'=>$grossCents,
        ':since'=>$since,
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (is_array($metadata) && hash_equals((string)($metadata['business_identity'] ?? ''), $businessIdentity)) return true;
    }
    return false;
}

function payment_event_register(PDO $pdo, array $data): array
{
    payment_events_ensure_schema($pdo);

    $provider = strtolower(trim((string)($data['provider'] ?? '')));
    $status = strtoupper(trim((string)($data['normalized_status'] ?? 'UNKNOWN')));
    $transactionCode = trim((string)($data['transaction_code'] ?? ''));
    if ($provider === '' || $transactionCode === '') return ['registered'=>0,'triggered'=>0,'events'=>[]];

    $raw = $data['raw_payload'] ?? null;
    $rawJson = is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : (is_string($raw) ? $raw : null);
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $businessIdentity = payment_event_business_identity($data);
    if ($businessIdentity !== '') $metadata['business_identity'] = $businessIdentity;
    $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
    $genericEvent = payment_event_codes($provider, $status)[0] ?? '';
    if ($genericEvent === '') return ['registered'=>0,'triggered'=>0,'events'=>[]];

    $registered = 0;
    $triggered = 0;
    $events = [];
    foreach (payment_event_codes($provider, $status) as $eventCode) {
        $fingerprint = hash('sha256', $provider . '|' . $transactionCode . '|' . $eventCode);
        $check = $pdo->prepare("SELECT id,triggered_at FROM student_payment_events WHERE event_fingerprint=:fingerprint LIMIT 1");
        $check->execute([':fingerprint'=>$fingerprint]);
        $existing = $check->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare("INSERT INTO student_payment_events
            (user_id,provider,event_code,generic_event_code,event_fingerprint,transaction_code,provider_transaction_id,provider_status,normalized_status,
             payment_method,currency,gross_amount_cents,net_amount_cents,fee_amount_cents,installments,product_name,product_code,
             checkout_id,checkout_url,pix_qrcode,pix_qrcode_url,pix_expires_at,boleto_url,boleto_line,buyer_name,buyer_email,
             buyer_phone,buyer_document,metadata_json,raw_payload_json,first_seen_at,last_seen_at)
            VALUES (:user_id,:provider,:event,:generic,:fingerprint,:transaction,:provider_transaction,:provider_status,:status,:payment_method,
             :currency,:gross,:net,:fee,:installments,:product_name,:product_code,:checkout_id,:checkout_url,:pix_qrcode,
             :pix_qrcode_url,:pix_expires_at,:boleto_url,:boleto_line,:buyer_name,:buyer_email,:buyer_phone,:buyer_document,
             :metadata,:raw,NOW(),NOW())
            ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),provider_transaction_id=VALUES(provider_transaction_id),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),payment_method=VALUES(payment_method),
             currency=VALUES(currency),gross_amount_cents=VALUES(gross_amount_cents),net_amount_cents=VALUES(net_amount_cents),
             fee_amount_cents=VALUES(fee_amount_cents),installments=VALUES(installments),product_name=VALUES(product_name),
             product_code=VALUES(product_code),checkout_id=VALUES(checkout_id),checkout_url=VALUES(checkout_url),
             pix_qrcode=VALUES(pix_qrcode),pix_qrcode_url=VALUES(pix_qrcode_url),pix_expires_at=VALUES(pix_expires_at),
             boleto_url=VALUES(boleto_url),boleto_line=VALUES(boleto_line),buyer_name=VALUES(buyer_name),
             buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_document=VALUES(buyer_document),
             metadata_json=VALUES(metadata_json),raw_payload_json=VALUES(raw_payload_json),last_seen_at=NOW()");
        $stmt->execute([
            ':user_id'=>(int)($data['user_id'] ?? 0) ?: null,
            ':provider'=>$provider,
            ':event'=>$eventCode,
            ':generic'=>$genericEvent,
            ':fingerprint'=>$fingerprint,
            ':transaction'=>$transactionCode,
            ':provider_transaction'=>trim((string)($data['provider_transaction_id'] ?? '')) ?: null,
            ':provider_status'=>trim((string)($data['provider_status'] ?? '')) ?: null,
            ':status'=>$status,
            ':payment_method'=>trim((string)($data['payment_method'] ?? '')) ?: null,
            ':currency'=>trim((string)($data['currency'] ?? '')) ?: 'BRL',
            ':gross'=>(int)($data['gross_amount_cents'] ?? 0),
            ':net'=>(int)($data['net_amount_cents'] ?? 0),
            ':fee'=>(int)($data['fee_amount_cents'] ?? 0),
            ':installments'=>(int)($data['installments'] ?? 0) ?: null,
            ':product_name'=>trim((string)($data['product_name'] ?? '')) ?: null,
            ':product_code'=>trim((string)($data['product_code'] ?? '')) ?: null,
            ':checkout_id'=>trim((string)($data['checkout_id'] ?? '')) ?: null,
            ':checkout_url'=>trim((string)($data['checkout_url'] ?? '')) ?: null,
            ':pix_qrcode'=>trim((string)($data['pix_qrcode'] ?? '')) ?: null,
            ':pix_qrcode_url'=>trim((string)($data['pix_qrcode_url'] ?? '')) ?: null,
            ':pix_expires_at'=>payment_event_datetime_or_null((string)($data['pix_expires_at'] ?? '')),
            ':boleto_url'=>trim((string)($data['boleto_url'] ?? '')) ?: null,
            ':boleto_line'=>trim((string)($data['boleto_line'] ?? '')) ?: null,
            ':buyer_name'=>trim((string)($data['buyer_name'] ?? '')) ?: null,
            ':buyer_email'=>trim((string)($data['buyer_email'] ?? '')) ?: null,
            ':buyer_phone'=>trim((string)($data['buyer_phone'] ?? '')) ?: null,
            ':buyer_document'=>trim((string)($data['buyer_document'] ?? '')) ?: null,
            ':metadata'=>$metadataJson,
            ':raw'=>$rawJson,
        ]);

        $idStmt = $pdo->prepare("SELECT id FROM student_payment_events WHERE event_fingerprint=:fingerprint LIMIT 1");
        $idStmt->execute([':fingerprint'=>$fingerprint]);
        $eventId = (int)$idStmt->fetchColumn();
        $isNew = !$existing;
        if ($isNew) $registered++;
        $events[] = $eventCode;

        $userId = (int)($data['user_id'] ?? 0);
        $alreadyTriggeredBusiness = payment_event_has_recent_business_trigger(
            $pdo,
            $userId,
            $genericEvent,
            (int)($data['gross_amount_cents'] ?? 0),
            $businessIdentity
        );

        if ($isNew && !$alreadyTriggeredBusiness && $eventId > 0) {
            $extra = payment_event_trigger_payload($data, $metadata, $eventId, $provider, $status, $eventCode, $transactionCode);
            if ($userId > 0 && function_exists('capturar_fluxos_automacao')) {
                capturar_fluxos_automacao($eventCode, $userId, $extra);
            } elseif ($userId <= 0) {
                payment_event_capture_unmatched_automation($pdo, $eventCode, $extra);
                if (function_exists('_disparar_webhooks_sync')) {
                    _disparar_webhooks_sync($eventCode, null, $extra);
                }
            }
            $pdo->prepare("UPDATE student_payment_events SET triggered_at=NOW(),trigger_count=trigger_count+1 WHERE id=:id")
                ->execute([':id'=>$eventId]);
            $triggered++;
        }
    }

    return ['registered'=>$registered,'triggered'=>$triggered,'events'=>$events];
}

function payment_event_register_from_sale(PDO $pdo, array $sale): array
{
    $raw = json_decode((string)($sale['raw_payload_json'] ?? ''), true);
    if (!is_array($raw)) $raw = [];
    return payment_event_register($pdo, [
        'provider'=>$sale['provider'] ?? '',
        'normalized_status'=>$sale['normalized_status'] ?? '',
        'transaction_code'=>$sale['external_transaction_id'] ?? '',
        'provider_transaction_id'=>preg_replace('/^[a-z]+:/', '', (string)($sale['external_transaction_id'] ?? '')),
        'provider_status'=>$sale['provider_status'] ?? '',
        'payment_method'=>$sale['payment_method'] ?? '',
        'currency'=>$sale['currency'] ?? 'BRL',
        'gross_amount_cents'=>(int)($sale['gross_amount_cents'] ?? 0),
        'net_amount_cents'=>(int)($sale['net_amount_cents'] ?? 0),
        'fee_amount_cents'=>(int)($sale['fee_amount_cents'] ?? 0),
        'installments'=>(int)($sale['installments'] ?? 0),
        'product_name'=>$sale['product_name'] ?? '',
        'product_code'=>$sale['external_product_id'] ?? '',
        'checkout_id'=>$sale['external_checkout_id'] ?? '',
        'checkout_url'=>$sale['checkout_url'] ?? '',
        'buyer_name'=>$sale['buyer_name'] ?? '',
        'buyer_email'=>$sale['buyer_email'] ?? '',
        'buyer_phone'=>$sale['buyer_phone'] ?? '',
        'buyer_document'=>$sale['buyer_document'] ?? '',
        'user_id'=>(int)($sale['matched_user_id'] ?? 0),
        'raw_payload'=>$raw,
        'metadata'=>[
            'source'=>'payment_sales_backfill',
            'match_method'=>$sale['match_method'] ?? '',
        ],
        'occurred_at'=>$sale['last_received_at'] ?? date('Y-m-d H:i:s'),
        'pix_qrcode'=>payment_event_first_value($raw, ['qr_code','pix_qr_code','pix_code','qrcode','copy_paste']),
        'pix_qrcode_url'=>payment_event_first_value($raw, ['qr_code_url','pix_qr_code_url','pix_url','qrcode_url']),
        'pix_expires_at'=>payment_event_first_value($raw, ['expires_at','expiration_date','pix_expires_at']),
        'boleto_url'=>payment_event_first_value($raw, ['boleto_url','pdf','url']),
        'boleto_line'=>payment_event_first_value($raw, ['line','digitable_line','barcode']),
    ]);
}
