<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

if (!function_exists('app_log')) {
    function app_log($message, array $context = []): void
    {
        try {
            log_sistema('info', 'metricas_negocio', (string)$message, $context);
        } catch (Throwable $e) {
            error_log('[metricas_negocio] ' . (string)$message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }
}

/**
 * Camada de compatibilidade para os motores de Meta e atribuicao validados no
 * projeto de referencia. Todo dado operacional permanece no banco principal.
 */
if (!function_exists('app_config')) {
    function app_config($section = null, ?string $key = null)
    {
        $config = [
            'meta' => [
                'graph_version' => 'v23.0',
                'graph_base_url' => 'https://graph.facebook.com',
                'default_time_increment' => 1,
                'default_sync_days_back' => 3,
                'sync_timeout_seconds' => 120,
            ],
            'attribution' => [
                'sync_days_back' => 400,
            ],
        ];
        if ($section === null) return $config;
        if ($key === null) return $config[$section] ?? null;
        return $config[$section][$key] ?? null;
    }
}

if (!function_exists('starts_with_value')) {
    function starts_with_value($haystack, string $needle): bool
    {
        return $needle === '' || substr((string)$haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('normalize_account_id')) {
    function normalize_account_id($value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        return starts_with_value($value, 'act_') ? $value : 'act_' . preg_replace('/\D+/', '', $value);
    }
}

if (!function_exists('extract_action_value')) {
    function extract_action_value($actions, array $types): int
    {
        foreach ((array)$actions as $item) {
            if (in_array((string)($item['action_type'] ?? ''), $types, true)) {
                return (int)round((float)($item['value'] ?? 0));
            }
        }
        return 0;
    }
}

if (!function_exists('extract_action_decimal')) {
    function extract_action_decimal($actions, array $types): float
    {
        foreach ((array)$actions as $item) {
            if (in_array((string)($item['action_type'] ?? ''), $types, true)) {
                return (float)($item['value'] ?? 0);
            }
        }
        return 0.0;
    }
}

if (!function_exists('normalize_text_value')) {
    function normalize_text_value($value): string
    {
        $value = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '') return '';
        if (strpos($value, '%') !== false) $value = rawurldecode($value);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) $value = $ascii;
        }
        $value = strtolower($value);
        return (string)preg_replace('/[^a-z0-9]+/', '', $value);
    }
}

if (!function_exists('normalize_text_soft_value')) {
    function normalize_text_soft_value($value): string
    {
        $value = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($value, '%') !== false) $value = rawurldecode($value);
        $value = str_replace(['+','_','-','/','\\','|',':',';',',','.','[',']','(',')','{','}','#','&','?','=','!','@','"',"'"], ' ', $value);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) $value = $ascii;
        }
        $value = strtolower($value);
        $value = (string)preg_replace('/[^a-z0-9\s]+/', ' ', $value);
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }
}

if (!function_exists('normalized_similarity_score')) {
    function normalized_similarity_score($a, $b): float
    {
        $aHard=normalize_text_value($a);$bHard=normalize_text_value($b);
        if($aHard===''||$bHard==='')return 0.0;
        if($aHard===$bHard)return 100.0;
        if(strpos($aHard,$bHard)!==false||strpos($bHard,$aHard)!==false)return 92.0;
        $maxLen=max(strlen($aHard),strlen($bHard));
        if($maxLen<=0||abs(strlen($aHard)-strlen($bHard))>max(6,(int)floor($maxLen*.45)))return 0.0;
        $score=0.0;$aTokens=array_unique(array_filter(explode(' ',normalize_text_soft_value($a))));$bTokens=array_unique(array_filter(explode(' ',normalize_text_soft_value($b))));
        if($aTokens&&$bTokens){$intersection=count(array_intersect($aTokens,$bTokens));$union=count(array_unique(array_merge($aTokens,$bTokens)));if($union>0)$score=max($score,$intersection/$union*100);$score=max($score,$intersection/max(count($aTokens),count($bTokens))*100);}
        if($maxLen<=80&&function_exists('similar_text')){similar_text($aHard,$bHard,$pct);$score=max($score,(float)$pct);}
        return $score;
    }
}

if (!function_exists('best_fuzzy_key_match')) {
    function best_fuzzy_key_match($needle, array $candidates, $threshold=82.0): string
    {
        $needleNorm=normalize_text_value($needle);if($needleNorm===''||!$candidates)return '';
        if(isset($candidates[$needleNorm]))return $needleNorm;
        $best='';$bestScore=0.0;
        foreach($candidates as $key=>$value){$candidate=is_string($key)?$key:normalize_text_value($value);if($candidate==='')continue;$score=normalized_similarity_score($needleNorm,$candidate);if($score>$bestScore){$bestScore=$score;$best=$candidate;}}
        return $bestScore>=(float)$threshold?$best:'';
    }
}

if (!function_exists('normalize_email_value')) {
    function normalize_email_value($value): string
    {
        return strtolower(trim((string)$value));
    }
}

if (!function_exists('normalize_phone_value')) {
    function normalize_phone_value($value): string
    {
        $digits = (string)preg_replace('/\D+/', '', (string)$value);
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') $digits = substr($digits, -11);
        return $digits;
    }
}

if (!function_exists('value_to_float')) {
    function value_to_float($value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

if (!function_exists('value_to_int')) {
    function value_to_int($value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}

require_once __DIR__ . '/metrics/meta_api.php';
require_once __DIR__ . '/metrics/attribution.php';
require_once __DIR__ . '/metrics/hotmart_sales_helper.php';

function metrics_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function metrics_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $schemaVersion = '3';
    try {
        if ((string)get_setting('metrics_schema_version', '') === $schemaVersion) {
            $ready = true;
            return;
        }
    } catch (Throwable $e) { /* primeira instalacao */ }
    $sql = (string)file_get_contents(__DIR__ . '/metrics/schema.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
    foreach ([
        "ALTER TABLE hotmart_sales_live ADD COLUMN payment_type VARCHAR(40) NULL AFTER price_name",
        "ALTER TABLE hotmart_sales_live ADD COLUMN installments_number INT UNSIGNED NULL AFTER payment_type",
        "ALTER TABLE hotmart_sales_live ADD COLUMN sale_origin VARCHAR(100) NULL AFTER installments_number",
        "ALTER TABLE hotmart_sales_live ADD COLUMN sales_channel VARCHAR(40) NOT NULL DEFAULT 'hotmart' AFTER sale_origin",
        "ALTER TABLE hotmart_sales_live ADD KEY idx_hotmart_live_payment (payment_type)",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (Throwable $e) { /* idempotente */ }
    }
    try {
        $pdo->exec("UPDATE hotmart_sales_live
                       SET payment_type = COALESCE(payment_type, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_payload_json, '$.data.purchase.payment.type')), 'null')),
                           installments_number = COALESCE(installments_number, CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_payload_json, '$.data.purchase.payment.installments_number')), 'null') AS UNSIGNED)),
                           sale_origin = COALESCE(sale_origin, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_payload_json, '$.data.purchase.origin.src')), 'null'))
                     WHERE raw_payload_json IS NOT NULL
                       AND (payment_type IS NULL OR installments_number IS NULL OR sale_origin IS NULL)");
    } catch (Throwable $e) {
        app_log('Falha ao normalizar pagamento Hotmart', ['error' => $e->getMessage()]);
    }
    try { set_setting('metrics_schema_version', $schemaVersion); } catch (Throwable $e) { /* sem settings */ }
    $ready = true;
}

/** Espelha a tabela operacional no ledger consolidado sem apagar payloads ricos. */
function metrics_refresh_hotmart_ledger(PDO $pdo): int
{
    $sql = "INSERT INTO hotmart_sales_live (
                transaction_code,status,transaction_date,payment_confirmed_at,
                product_code,product_name,price_code,price_name,currency,
                gross_revenue,net_revenue,producer_net,buyer_name,buyer_email,
                buyer_phone_raw,buyer_phone_norm,matched_user_id,match_method,
                utm_source,utm_medium,utm_campaign,utm_term,utm_content,imported_at,updated_at
            )
            SELECT transaction_code,status,transaction_date,payment_confirmed_at,
                product_code,product_name,price_code,price_name,currency,
                COALESCE(gross_revenue,0),COALESCE(net_revenue,0),COALESCE(producer_net,0),
                buyer_name,buyer_email,buyer_phone_raw,buyer_phone_norm,matched_user_id,match_method,
                utm_source,utm_medium,utm_campaign,utm_term,utm_content,
                COALESCE(imported_at,NOW()),COALESCE(updated_at,NOW())
            FROM hotmart_sales
            ON DUPLICATE KEY UPDATE
                status=VALUES(status), transaction_date=VALUES(transaction_date),
                payment_confirmed_at=VALUES(payment_confirmed_at), product_code=VALUES(product_code),
                product_name=VALUES(product_name), price_code=VALUES(price_code), price_name=VALUES(price_name),
                currency=VALUES(currency), gross_revenue=VALUES(gross_revenue),
                net_revenue=VALUES(net_revenue), producer_net=VALUES(producer_net),
                buyer_name=VALUES(buyer_name), buyer_email=VALUES(buyer_email),
                buyer_phone_raw=VALUES(buyer_phone_raw), buyer_phone_norm=VALUES(buyer_phone_norm),
                matched_user_id=VALUES(matched_user_id), match_method=VALUES(match_method),
                utm_source=VALUES(utm_source), utm_medium=VALUES(utm_medium),
                utm_campaign=VALUES(utm_campaign), utm_term=VALUES(utm_term),
                utm_content=VALUES(utm_content), updated_at=VALUES(updated_at)";
    return $pdo->exec($sql);
}

function metrics_active_integration(PDO $pdo): ?array
{
    $row = $pdo->query("SELECT * FROM meta_integrations WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function metrics_sync_all(PDO $pdo, int $daysBack = 3, bool $syncMeta = true): array
{
    metrics_ensure_schema($pdo);
    $ledger = metrics_refresh_hotmart_ledger($pdo);
    $integration = metrics_active_integration($pdo);
    $meta = [];
    if ($syncMeta && $integration && !empty($integration['access_token']) && !empty($integration['ad_account_id'])) {
        $since = date('Y-m-d', strtotime('-' . max(0, $daysBack - 1) . ' days'));
        $until = date('Y-m-d');
        foreach (['account','campaign','adset','ad'] as $scope) {
            $meta[] = sync_meta_level($pdo, $integration, $scope, $since, $until);
        }
    }
    $attribution = null;
    if ($integration) {
        $attribution = sync_full_attribution($pdo, $pdo, (int)$integration['id'], max(3, $daysBack));
    }
    return ['ledger_rows' => $ledger, 'meta' => $meta, 'attribution' => $attribution];
}

function metrics_period(string $preset, ?string $from = null, ?string $to = null): array
{
    $today = new DateTimeImmutable('today');
    if ($preset === 'today') {
        $start = $today; $end = $today;
    } elseif ($preset === 'custom' && $from && $to) {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
    } elseif ($preset === 'month') {
        $start = $today->modify('first day of this month'); $end = $today;
    } elseif ($preset === 'quarter') {
        $month = (int)$today->format('n');
        $startMonth = ((int)floor(($month - 1) / 3) * 3) + 1;
        $start = $today->setDate((int)$today->format('Y'), $startMonth, 1); $end = $today;
    } elseif ($preset === 'year') {
        $start = $today->setDate((int)$today->format('Y'), 1, 1); $end = $today;
    } else {
        $days = in_array((int)$preset, [7,30,90,365], true) ? (int)$preset : 30;
        $start = $today->modify('-' . ($days - 1) . ' days'); $end = $today;
    }
    if ($end < $start) [$start, $end] = [$end, $start];
    $length = (int)$start->diff($end)->days + 1;
    $previousEnd = $start->modify('-1 day');
    $previousStart = $previousEnd->modify('-' . ($length - 1) . ' days');
    return [
        'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $length,
        'previous_start' => $previousStart->format('Y-m-d'), 'previous_end' => $previousEnd->format('Y-m-d'),
    ];
}

function metrics_delta(float $current, float $previous): ?float
{
    if (abs($previous) < 0.000001) return $current == 0.0 ? 0.0 : null;
    return (($current - $previous) / abs($previous)) * 100;
}
