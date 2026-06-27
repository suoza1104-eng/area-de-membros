<?php


if (!function_exists('normalize_match_key')) {
function normalize_match_key($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $map = array('Ã¡'=>'a','Ã '=>'a','Ã¢'=>'a','Ã£'=>'a','Ã¤'=>'a','Ã¥'=>'a','Ã¦'=>'ae','Ã§'=>'c','Ã©'=>'e','Ã¨'=>'e','Ãª'=>'e','Ã«'=>'e','Ã­'=>'i','Ã¬'=>'i','Ã®'=>'i','Ã¯'=>'i','Ã±'=>'n','Ã³'=>'o','Ã²'=>'o','Ã´'=>'o','Ãµ'=>'o','Ã¶'=>'o','Ã¸'=>'o','Ãº'=>'u','Ã¹'=>'u','Ã»'=>'u','Ã¼'=>'u','Ã½'=>'y','Ã¿'=>'y');
    $value = strtr($value, $map);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^a-z0-9]+/i', '', $value);
    return $value !== null ? $value : '';
}
}

function create_attribution_run($pdo, string $type) {
    $stmt = $pdo->prepare("INSERT INTO attribution_runs (run_type, started_at, status) VALUES (:run_type, NOW(), 'running')");
    $stmt->execute(['run_type' => $type]);
    return (int) $pdo->lastInsertId();
}

function finish_attribution_run($pdo, int $runId, string $status, array $stats = [], ?string $message = null) {
    $stmt = $pdo->prepare('UPDATE attribution_runs SET finished_at = NOW(), status = :status, stats_json = :stats_json, message = :message WHERE id = :id');
    $stmt->execute([
        'id' => $runId,
        'status' => $status,
        'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'message' => $message,
    ]);
}

function source_table_columns($pdo, string $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $cols = [];
    while ($row = $stmt->fetch()) {
        $cols[] = (string)($row['Field'] ?? '');
    }
    $cache[$table] = $cols;
    return $cols;
}

function column_exists_in($cols, string $name) {
    return in_array($name, $cols, true);
}

function safe_string($value, int $maxLen = 255) {
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return mb_substr($value, 0, $maxLen, 'UTF-8');
}

function sale_date_value($row) {
    $value = $row['transaction_date'] ?? $row['payment_confirmed_at'] ?? null;
    if (!$value) {
        return date('Y-m-d H:i:s');
    }
    return (string)$value;
}

function upsert_attribution_lead($pdo, array $row) {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $sql = "INSERT INTO attribution_leads (
            source_user_id, lead_name, lead_name_norm, lead_email, lead_email_norm,
            lead_phone_raw, lead_phone_norm, turma_codigo, created_at,
            utm_source, utm_source_norm, utm_campaign_group, utm_campaign_group_norm,
            utm_campaign_name, utm_campaign_name_norm, utm_ad_name, utm_ad_name_norm,
            utm_term, utm_term_norm, imported_at, updated_at
        ) VALUES (
            :source_user_id, :lead_name, :lead_name_norm, :lead_email, :lead_email_norm,
            :lead_phone_raw, :lead_phone_norm, :turma_codigo, :created_at,
            :utm_source, :utm_source_norm, :utm_campaign_group, :utm_campaign_group_norm,
            :utm_campaign_name, :utm_campaign_name_norm, :utm_ad_name, :utm_ad_name_norm,
            :utm_term, :utm_term_norm, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            lead_name = VALUES(lead_name),
            lead_name_norm = VALUES(lead_name_norm),
            lead_email = VALUES(lead_email),
            lead_email_norm = VALUES(lead_email_norm),
            lead_phone_raw = VALUES(lead_phone_raw),
            lead_phone_norm = VALUES(lead_phone_norm),
            turma_codigo = VALUES(turma_codigo),
            created_at = VALUES(created_at),
            utm_source = VALUES(utm_source),
            utm_source_norm = VALUES(utm_source_norm),
            utm_campaign_group = VALUES(utm_campaign_group),
            utm_campaign_group_norm = VALUES(utm_campaign_group_norm),
            utm_campaign_name = VALUES(utm_campaign_name),
            utm_campaign_name_norm = VALUES(utm_campaign_name_norm),
            utm_ad_name = VALUES(utm_ad_name),
            utm_ad_name_norm = VALUES(utm_ad_name_norm),
            utm_term = VALUES(utm_term),
            utm_term_norm = VALUES(utm_term_norm),
            updated_at = NOW()";
        $stmt = $pdo->prepare($sql);
    }
    $stmt->execute($row);
}

function upsert_attribution_sale($pdo, array $row) {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $sql = "INSERT INTO attribution_sales (
            source_sale_id, transaction_code, sale_status, sale_date, payment_confirmed_at,
            product_code, product_name, price_name, currency, gross_revenue, net_revenue, producer_net,
            buyer_name, buyer_name_norm, buyer_email, buyer_email_norm, buyer_phone_raw, buyer_phone_norm,
            matched_user_id, match_method, utm_source, utm_source_norm, utm_campaign_group, utm_campaign_group_norm,
            utm_campaign_name, utm_campaign_name_norm, utm_ad_name, utm_ad_name_norm, utm_term, utm_term_norm,
            imported_at, updated_at
        ) VALUES (
            :source_sale_id, :transaction_code, :sale_status, :sale_date, :payment_confirmed_at,
            :product_code, :product_name, :price_name, :currency, :gross_revenue, :net_revenue, :producer_net,
            :buyer_name, :buyer_name_norm, :buyer_email, :buyer_email_norm, :buyer_phone_raw, :buyer_phone_norm,
            :matched_user_id, :match_method, :utm_source, :utm_source_norm, :utm_campaign_group, :utm_campaign_group_norm,
            :utm_campaign_name, :utm_campaign_name_norm, :utm_ad_name, :utm_ad_name_norm, :utm_term, :utm_term_norm,
            NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            transaction_code = VALUES(transaction_code),
            sale_status = VALUES(sale_status),
            sale_date = VALUES(sale_date),
            payment_confirmed_at = VALUES(payment_confirmed_at),
            product_code = VALUES(product_code),
            product_name = VALUES(product_name),
            price_name = VALUES(price_name),
            currency = VALUES(currency),
            gross_revenue = VALUES(gross_revenue),
            net_revenue = VALUES(net_revenue),
            producer_net = VALUES(producer_net),
            buyer_name = VALUES(buyer_name),
            buyer_name_norm = VALUES(buyer_name_norm),
            buyer_email = VALUES(buyer_email),
            buyer_email_norm = VALUES(buyer_email_norm),
            buyer_phone_raw = VALUES(buyer_phone_raw),
            buyer_phone_norm = VALUES(buyer_phone_norm),
            matched_user_id = VALUES(matched_user_id),
            match_method = VALUES(match_method),
            utm_source = VALUES(utm_source),
            utm_source_norm = VALUES(utm_source_norm),
            utm_campaign_group = VALUES(utm_campaign_group),
            utm_campaign_group_norm = VALUES(utm_campaign_group_norm),
            utm_campaign_name = VALUES(utm_campaign_name),
            utm_campaign_name_norm = VALUES(utm_campaign_name_norm),
            utm_ad_name = VALUES(utm_ad_name),
            utm_ad_name_norm = VALUES(utm_ad_name_norm),
            utm_term = VALUES(utm_term),
            utm_term_norm = VALUES(utm_term_norm),
            updated_at = NOW()";
        $stmt = $pdo->prepare($sql);
    }
    $stmt->execute($row);
}

function sync_attribution_imports($pdo, PDO $sourcePdo) {
    $runId = create_attribution_run($pdo, 'import');

    try {
        // Reconciliacao incremental: attribution_matches referencia esta
        // tabela e o dashboard deve permanecer legivel durante o cron.
        $leadCols = source_table_columns($sourcePdo, 'users');
        $saleCols = source_table_columns($pdo, 'hotmart_sales_live');

        $leadSelect = [
            'id',
            column_exists_in($leadCols, 'nome') ? 'nome' : "'' AS nome",
            column_exists_in($leadCols, 'email') ? 'email' : "'' AS email",
            column_exists_in($leadCols, 'telefone') ? 'telefone' : "'' AS telefone",
            column_exists_in($leadCols, 'codigo_turma') ? 'codigo_turma' : "'' AS codigo_turma",
            column_exists_in($leadCols, 'created_at') ? 'created_at' : 'NOW() AS created_at',
            column_exists_in($leadCols, 'utm_source') ? 'utm_source' : "'' AS utm_source",
            column_exists_in($leadCols, 'utm_medium') ? 'utm_medium' : "'' AS utm_medium",
            column_exists_in($leadCols, 'utm_campaign') ? 'utm_campaign' : "'' AS utm_campaign",
            column_exists_in($leadCols, 'utm_content') ? 'utm_content' : "'' AS utm_content",
            column_exists_in($leadCols, 'utm_term') ? 'utm_term' : "'' AS utm_term",
        ];
        $leadSql = 'SELECT ' . implode(', ', $leadSelect) . ' FROM users';
        if ($sourcePdo === $pdo) {
            // No banco unificado, UTMs de leads antigos sao imutaveis na
            // pratica. Atualiza novos e os ultimos sete dias; uma carga
            // inicial continua trazendo todos porque a tabela esta vazia.
            $leadSql .= ' WHERE id NOT IN (SELECT source_user_id FROM attribution_leads)'
                . ' OR created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }
        $leadStmt = $sourcePdo->query($leadSql);

        $leadCount = 0;
        $leadErrors = 0;
        while ($row = $leadStmt->fetch()) {
            try {
                upsert_attribution_lead($pdo, [
                    'source_user_id' => (int)$row['id'],
                    'lead_name' => safe_string($row['nome'] ?? '', 255),
                    'lead_name_norm' => safe_string(normalize_match_key($row['nome'] ?? ''), 255),
                    'lead_email' => safe_string($row['email'] ?? '', 255),
                    'lead_email_norm' => safe_string(normalize_email_value($row['email'] ?? ''), 255),
                    'lead_phone_raw' => safe_string($row['telefone'] ?? '', 80),
                    'lead_phone_norm' => safe_string(normalize_phone_value($row['telefone'] ?? ''), 20),
                    'turma_codigo' => safe_string($row['codigo_turma'] ?? '', 50),
                    'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
                    'utm_source' => safe_string($row['utm_source'] ?? '', 255),
                    'utm_source_norm' => safe_string(normalize_match_key($row['utm_source'] ?? ''), 255),
                    'utm_campaign_group' => safe_string($row['utm_medium'] ?? '', 255),
                    'utm_campaign_group_norm' => safe_string(normalize_match_key($row['utm_medium'] ?? ''), 255),
                    'utm_campaign_name' => safe_string($row['utm_campaign'] ?? '', 255),
                    'utm_campaign_name_norm' => safe_string(normalize_match_key($row['utm_campaign'] ?? ''), 255),
                    'utm_ad_name' => safe_string($row['utm_content'] ?? '', 255),
                    'utm_ad_name_norm' => safe_string(normalize_match_key($row['utm_content'] ?? ''), 255),
                    'utm_term' => safe_string($row['utm_term'] ?? '', 255),
                    'utm_term_norm' => safe_string(normalize_match_key($row['utm_term'] ?? ''), 255),
                ]);
                $leadCount++;
            } catch (Throwable $e) {
                $leadErrors++;
                app_log('Erro ao importar lead de atribuiÃ§Ã£o', ['source_user_id' => $row['id'] ?? null, 'error' => $e->getMessage()]);
            }
        }

        $validStatuses = ['Completo', 'Aprovado', 'APPROVED', 'COMPLETE', 'COMPLETED'];
        $in = implode(',', array_fill(0, count($validStatuses), '?'));
        $saleSelect = [
            'id',
            column_exists_in($saleCols, 'transaction_code') ? 'transaction_code' : "'' AS transaction_code",
            column_exists_in($saleCols, 'status') ? 'status' : "'' AS status",
            column_exists_in($saleCols, 'transaction_date') ? 'transaction_date' : 'NULL AS transaction_date',
            column_exists_in($saleCols, 'payment_confirmed_at') ? 'payment_confirmed_at' : 'NULL AS payment_confirmed_at',
            column_exists_in($saleCols, 'product_code') ? 'product_code' : 'NULL AS product_code',
            column_exists_in($saleCols, 'product_name') ? 'product_name' : "'' AS product_name",
            column_exists_in($saleCols, 'price_name') ? 'price_name' : "'' AS price_name",
            column_exists_in($saleCols, 'currency') ? 'currency' : "'BRL' AS currency",
            column_exists_in($saleCols, 'gross_revenue') ? 'gross_revenue' : '0 AS gross_revenue',
            column_exists_in($saleCols, 'net_revenue') ? 'net_revenue' : '0 AS net_revenue',
            column_exists_in($saleCols, 'producer_net') ? 'producer_net' : '0 AS producer_net',
            column_exists_in($saleCols, 'buyer_name') ? 'buyer_name' : "'' AS buyer_name",
            column_exists_in($saleCols, 'buyer_email') ? 'buyer_email' : "'' AS buyer_email",
            column_exists_in($saleCols, 'buyer_phone_raw') ? 'buyer_phone_raw' : "'' AS buyer_phone_raw",
            column_exists_in($saleCols, 'buyer_phone_norm') ? 'buyer_phone_norm' : "'' AS buyer_phone_norm",
            column_exists_in($saleCols, 'matched_user_id') ? 'matched_user_id' : 'NULL AS matched_user_id',
            column_exists_in($saleCols, 'match_method') ? 'match_method' : "'none' AS match_method",
            column_exists_in($saleCols, 'utm_source') ? 'utm_source' : "'' AS utm_source",
            column_exists_in($saleCols, 'utm_medium') ? 'utm_medium' : "'' AS utm_medium",
            column_exists_in($saleCols, 'utm_campaign') ? 'utm_campaign' : "'' AS utm_campaign",
            column_exists_in($saleCols, 'utm_content') ? 'utm_content' : "'' AS utm_content",
            column_exists_in($saleCols, 'utm_term') ? 'utm_term' : "'' AS utm_term",
        ];
        $saleSql = 'SELECT ' . implode(', ', $saleSelect) . ' FROM hotmart_sales_live WHERE status IN (' . $in . ')';
        if (column_exists_in($saleCols, 'webhook_event')) {
            $saleSql .= " OR webhook_event IN ('PURCHASE_APPROVED','PURCHASE_COMPLETE')";
        }
        $saleStmt = $pdo->prepare($saleSql);
        $saleStmt->execute($validStatuses);

        $saleCount = 0;
        $saleErrors = 0;
        while ($row = $saleStmt->fetch()) {
            try {
                upsert_attribution_sale($pdo, [
                    'source_sale_id' => (int)$row['id'],
                    'transaction_code' => safe_string($row['transaction_code'] ?? '', 80),
                    'sale_status' => safe_string($row['status'] ?? '', 50),
                    'sale_date' => sale_date_value($row),
                    'payment_confirmed_at' => $row['payment_confirmed_at'] ?: null,
                    'product_code' => $row['product_code'] !== null ? (string)$row['product_code'] : null,
                    'product_name' => safe_string($row['product_name'] ?? '', 255),
                    'price_name' => safe_string($row['price_name'] ?? '', 255),
                    'currency' => safe_string($row['currency'] ?? 'BRL', 10),
                    'gross_revenue' => value_to_float($row['gross_revenue'] ?? 0),
                    'net_revenue' => value_to_float($row['net_revenue'] ?? 0),
                    'producer_net' => value_to_float($row['producer_net'] ?? 0),
                    'buyer_name' => safe_string($row['buyer_name'] ?? '', 255),
                    'buyer_name_norm' => safe_string(normalize_match_key($row['buyer_name'] ?? ''), 255),
                    'buyer_email' => safe_string($row['buyer_email'] ?? '', 255),
                    'buyer_email_norm' => safe_string(normalize_email_value($row['buyer_email'] ?? ''), 255),
                    'buyer_phone_raw' => safe_string($row['buyer_phone_raw'] ?? '', 80),
                    'buyer_phone_norm' => safe_string(normalize_phone_value($row['buyer_phone_norm'] ?: $row['buyer_phone_raw'] ?: ''), 20),
                    'matched_user_id' => !empty($row['matched_user_id']) ? (int)$row['matched_user_id'] : null,
                    'match_method' => safe_string($row['match_method'] ?? 'none', 50),
                    'utm_source' => safe_string($row['utm_source'] ?? '', 255),
                    'utm_source_norm' => safe_string(normalize_match_key($row['utm_source'] ?? ''), 255),
                    'utm_campaign_group' => safe_string($row['utm_medium'] ?? '', 255),
                    'utm_campaign_group_norm' => safe_string(normalize_match_key($row['utm_medium'] ?? ''), 255),
                    'utm_campaign_name' => safe_string($row['utm_campaign'] ?? '', 255),
                    'utm_campaign_name_norm' => safe_string(normalize_match_key($row['utm_campaign'] ?? ''), 255),
                    'utm_ad_name' => safe_string($row['utm_content'] ?? '', 255),
                    'utm_ad_name_norm' => safe_string(normalize_match_key($row['utm_content'] ?? ''), 255),
                    'utm_term' => safe_string($row['utm_term'] ?? '', 255),
                    'utm_term_norm' => safe_string(normalize_match_key($row['utm_term'] ?? ''), 255),
                ]);
                $saleCount++;
            } catch (Throwable $e) {
                $saleErrors++;
                app_log('Erro ao importar venda de atribuiÃ§Ã£o', [
                    'source_sale_id' => $row['id'] ?? null,
                    'transaction_code' => $row['transaction_code'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $removedSales = $pdo->exec("DELETE axs FROM attribution_sales axs
            LEFT JOIN hotmart_sales_live hs ON hs.id = axs.source_sale_id
              AND (hs.status IN ('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','COMPLETED','PURCHASE_COMPLETE')
                OR hs.webhook_event IN ('PURCHASE_APPROVED','PURCHASE_COMPLETE'))
            WHERE hs.id IS NULL");

        $stats = [
            'leads_imported' => $leadCount,
            'sales_imported' => $saleCount,
            'lead_errors' => $leadErrors,
            'sale_errors' => $saleErrors,
            'sales_removed' => (int)$removedSales,
        ];
        $message = 'ImportaÃ§Ã£o concluÃ­da.';
        if ($saleErrors > 0 || $leadErrors > 0) {
            $message .= ' Alguns registros foram ignorados; veja o log.';
        }
        finish_attribution_run($pdo, $runId, 'success', $stats, $message);
        return $stats;
    } catch (Throwable $e) {
        finish_attribution_run($pdo, $runId, 'error', [], $e->getMessage());
        app_log('Erro fatal na importaÃ§Ã£o de atribuiÃ§Ã£o', ['error' => $e->getMessage()]);
        throw $e;
    }
}

function build_lead_indexes($leads) {
    $byId = [];
    $bySourceUser = [];
    $byPhone = [];
    $byEmail = [];
    $byName = [];

    foreach ($leads as $lead) {
        $id = (int)$lead['id'];
        $byId[$id] = $lead;
        $sourceUserId = (int)($lead['source_user_id'] ?? 0);
        if ($sourceUserId > 0) { $bySourceUser[$sourceUserId] = $lead; }
        $phone = (string)($lead['lead_phone_norm'] ?? '');
        if ($phone !== '') $byPhone[$phone][] = $lead;
        $email = (string)($lead['lead_email_norm'] ?? '');
        if ($email !== '') $byEmail[$email][] = $lead;
        $name = (string)($lead['lead_name_norm'] ?? '');
        if ($name !== '') $byName[$name][] = $lead;
    }

    return compact('byId', 'bySourceUser', 'byPhone', 'byEmail', 'byName');
}

function filter_leads_before_sale($candidates, string $saleDate) {
    $saleTs = strtotime($saleDate);
    return array_values(array_filter($candidates, static function (array $lead) use ($saleTs): bool {
        $leadTs = strtotime((string)($lead['created_at'] ?? ''));
        return $leadTs !== false && $leadTs <= $saleTs;
    }));
}

function choose_lead_for_model($candidates, string $model) {
    if (!$candidates) return null;
    usort($candidates, static function (array $a, array $b): int {
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });
    return $model === 'first_touch' ? $candidates[0] : $candidates[count($candidates) - 1];
}

function upsert_attribution_match($pdo, array $row) {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $sql = "INSERT INTO attribution_matches (
            sale_id, lead_id, attribution_model, match_type, attribution_seconds_diff,
            lead_created_at, sale_date, campaign_group, campaign_group_norm,
            campaign_name, campaign_name_norm, ad_name, ad_name_norm,
            revenue_value, product_name, created_at, updated_at
        ) VALUES (
            :sale_id, :lead_id, :attribution_model, :match_type, :attribution_seconds_diff,
            :lead_created_at, :sale_date, :campaign_group, :campaign_group_norm,
            :campaign_name, :campaign_name_norm, :ad_name, :ad_name_norm,
            :revenue_value, :product_name, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            lead_id = VALUES(lead_id),
            match_type = VALUES(match_type),
            attribution_seconds_diff = VALUES(attribution_seconds_diff),
            lead_created_at = VALUES(lead_created_at),
            sale_date = VALUES(sale_date),
            campaign_group = VALUES(campaign_group),
            campaign_group_norm = VALUES(campaign_group_norm),
            campaign_name = VALUES(campaign_name),
            campaign_name_norm = VALUES(campaign_name_norm),
            ad_name = VALUES(ad_name),
            ad_name_norm = VALUES(ad_name_norm),
            revenue_value = VALUES(revenue_value),
            product_name = VALUES(product_name),
            updated_at = NOW()";
        $stmt = $pdo->prepare($sql);
    }
    $stmt->execute($row);
}

function ensure_manual_attribution_table_attr(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS manual_sale_attributions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_code VARCHAR(80) NOT NULL,
        attribution_model ENUM('first_touch','last_touch') NOT NULL DEFAULT 'last_touch',
        campaign_group VARCHAR(255) DEFAULT NULL,
        campaign_group_norm VARCHAR(255) DEFAULT NULL,
        campaign_name VARCHAR(255) DEFAULT NULL,
        campaign_name_norm VARCHAR(255) DEFAULT NULL,
        ad_name VARCHAR(255) DEFAULT NULL,
        ad_name_norm VARCHAR(255) DEFAULT NULL,
        source_user_id INT(10) UNSIGNED DEFAULT NULL,
        lead_utm_source VARCHAR(255) DEFAULT NULL,
        lead_utm_medium VARCHAR(255) DEFAULT NULL,
        lead_utm_campaign VARCHAR(255) DEFAULT NULL,
        lead_utm_term VARCHAR(255) DEFAULT NULL,
        lead_utm_content VARCHAR(255) DEFAULT NULL,
        assigned_by VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tx_model (transaction_code, attribution_model),
        KEY idx_campaign_group_norm (campaign_group_norm),
        KEY idx_campaign_name_norm (campaign_name_norm),
        KEY idx_ad_name_norm (ad_name_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
}

function fetch_manual_attribution_rows_attr(PDO $pdo): array
{
    ensure_manual_attribution_table_attr($pdo);
    $rows = $pdo->query("SELECT * FROM manual_sale_attributions")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $tx = (string)($row['transaction_code'] ?? '');
        $model = (string)($row['attribution_model'] ?? 'last_touch');
        if ($tx !== '') { $out[$tx . '|' . $model] = $row; }
    }
    return $out;
}

function build_meta_name_lookup(PDO $pdo, int $integrationId): array
{
    $campaigns = []; $adsets = []; $ads = [];
    $stmt = $pdo->prepare("SELECT campaign_name FROM meta_campaign_daily WHERE integration_id = :id AND campaign_name <> '' GROUP BY campaign_name ORDER BY campaign_name");
    $stmt->execute(['id' => $integrationId]);
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string)$row['campaign_name']);
        if ($name !== '') { $campaigns[normalize_match_key($name)] = $name; }
    }
    $stmt = $pdo->prepare("SELECT campaign_name, adset_name FROM meta_adset_daily WHERE integration_id = :id AND campaign_name <> '' AND adset_name <> '' GROUP BY campaign_name, adset_name ORDER BY campaign_name, adset_name");
    $stmt->execute(['id' => $integrationId]);
    foreach ($stmt->fetchAll() as $row) {
        $campaign = trim((string)$row['campaign_name']);
        $adset = trim((string)$row['adset_name']);
        if ($campaign === '' || $adset === '') continue;
        $campaignNorm = normalize_match_key($campaign);
        $adsetNorm = normalize_match_key($adset);
        $adsets[$campaignNorm][$adsetNorm] = $adset;
    }
    $stmt = $pdo->prepare("SELECT campaign_name, adset_name, ad_name FROM meta_ad_daily WHERE integration_id = :id AND campaign_name <> '' AND adset_name <> '' AND ad_name <> '' GROUP BY campaign_name, adset_name, ad_name ORDER BY campaign_name, adset_name, ad_name");
    $stmt->execute(['id' => $integrationId]);
    foreach ($stmt->fetchAll() as $row) {
        $campaign = trim((string)$row['campaign_name']);
        $adset = trim((string)$row['adset_name']);
        $ad = trim((string)$row['ad_name']);
        if ($campaign === '' || $adset === '' || $ad === '') continue;
        $campaignNorm = normalize_match_key($campaign);
        $adsetNorm = normalize_match_key($adset);
        $adNorm = normalize_match_key($ad);
        $ads[$campaignNorm][$adsetNorm][$adNorm] = $ad;
    }
    return ['campaigns' => $campaigns, 'adsets' => $adsets, 'ads' => $ads];
}

function resolve_meta_names_from_lead(array $metaLookup, array $lead): array
{
    $leadCampaign = (string)($lead['utm_campaign_group'] ?? '');
    $leadAdset = (string)($lead['utm_campaign_name'] ?? '');
    $leadAd = (string)($lead['utm_ad_name'] ?? '');

    $campaignNorm = normalize_match_key($leadCampaign);
    if ($campaignNorm === '') {
        return ['matched' => false, 'failure_reason' => 'campanha_vazia'];
    }

    $campaignKey = isset($metaLookup['campaigns'][$campaignNorm])
        ? $campaignNorm
        : best_fuzzy_key_match($campaignNorm, $metaLookup['campaigns'], 92.0);
    if ($campaignKey === '') {
        return ['matched' => false, 'failure_reason' => 'campanha_nao_encontrada'];
    }

    $campaignName = $metaLookup['campaigns'][$campaignKey] ?? $leadCampaign;
    $campaignNameNorm = normalize_match_key($campaignName);

    $result = [
        'matched' => true,
        'match_level' => 'campaign',
        'failure_reason' => '',
        'campaign_group' => $campaignName,
        'campaign_group_norm' => $campaignNameNorm,
        'campaign_name' => '',
        'campaign_name_norm' => '',
        'ad_name' => '',
        'ad_name_norm' => '',
    ];

    $adsetNorm = normalize_match_key($leadAdset);
    if ($adsetNorm === '') {
        return $result;
    }
    if (empty($metaLookup['adsets'][$campaignKey])) {
        $result['failure_reason'] = 'conjunto_nao_encontrado';
        return $result;
    }

    $adsetKey = isset($metaLookup['adsets'][$campaignKey][$adsetNorm])
        ? $adsetNorm
        : best_fuzzy_key_match($adsetNorm, $metaLookup['adsets'][$campaignKey], 92.0);
    if ($adsetKey === '') {
        $result['failure_reason'] = 'conjunto_nao_encontrado';
        return $result;
    }

    $adsetName = $metaLookup['adsets'][$campaignKey][$adsetKey] ?? $leadAdset;
    $result['match_level'] = 'campaign_adset';
    $result['campaign_name'] = $adsetName;
    $result['campaign_name_norm'] = normalize_match_key($adsetName);

    $adNorm = normalize_match_key($leadAd);
    if ($adNorm === '') {
        return $result;
    }
    if (empty($metaLookup['ads'][$campaignKey][$adsetKey])) {
        $result['failure_reason'] = 'anuncio_nao_encontrado';
        return $result;
    }

    $adKey = isset($metaLookup['ads'][$campaignKey][$adsetKey][$adNorm])
        ? $adNorm
        : best_fuzzy_key_match($adNorm, $metaLookup['ads'][$campaignKey][$adsetKey], 92.0);
    if ($adKey === '') {
        $result['failure_reason'] = 'anuncio_nao_encontrado';
        return $result;
    }

    $adName = $metaLookup['ads'][$campaignKey][$adsetKey][$adKey] ?? $leadAd;
    $result['match_level'] = 'campaign_adset_ad';
    $result['ad_name'] = $adName;
    $result['ad_name_norm'] = normalize_match_key($adName);
    return $result;
}

function sync_attribution_matches($pdo, int $integrationId) {
    $runId = create_attribution_run($pdo, 'match');
    try {
        $pdo->exec('TRUNCATE TABLE attribution_matches');
        $leads = $pdo->query('SELECT * FROM attribution_leads ORDER BY created_at ASC, id ASC')->fetchAll();
        $sales = $pdo->query('SELECT * FROM attribution_sales ORDER BY sale_date ASC, id ASC')->fetchAll();
        $indexes = build_lead_indexes($leads);
        $metaNameLookup = build_meta_name_lookup($pdo, $integrationId);
        $manualRows = fetch_manual_attribution_rows_attr($pdo);
        $count = 0;
        foreach ($sales as $sale) {
            $saleDate = (string)$sale['sale_date'];
            $tx = (string)($sale['transaction_code'] ?? '');
            foreach (['first_touch', 'last_touch'] as $model) {
                $manualKey = $tx . '|' . $model;
                if (isset($manualRows[$manualKey])) {
                    $manual = $manualRows[$manualKey];
                    $lead = null;
                    $leadId = 0;
                    if (!empty($manual['source_user_id'])) {
                        $manualUid = (int)$manual['source_user_id'];
                        if (isset($indexes['bySourceUser'][$manualUid])) {
                            $lead = $indexes['bySourceUser'][$manualUid];
                            $leadId = (int)$lead['id'];
                        }
                    }
                    if ($leadId <= 0) { continue; }
                    upsert_attribution_match($pdo, [
                        'sale_id' => (int)$sale['id'],
                        'lead_id' => $leadId,
                        'attribution_model' => $model,
                        'match_type' => 'manual',
                        'attribution_seconds_diff' => 0,
                        'lead_created_at' => $lead ? (string)$lead['created_at'] : $saleDate,
                        'sale_date' => $saleDate,
                        'campaign_group' => (string)($manual['campaign_group'] ?? ''),
                        'campaign_group_norm' => (string)($manual['campaign_group_norm'] ?? normalize_match_key($manual['campaign_group'] ?? '')),
                        'campaign_name' => (string)($manual['campaign_name'] ?? ''),
                        'campaign_name_norm' => (string)($manual['campaign_name_norm'] ?? normalize_match_key($manual['campaign_name'] ?? '')),
                        'ad_name' => (string)($manual['ad_name'] ?? ''),
                        'ad_name_norm' => (string)($manual['ad_name_norm'] ?? normalize_match_key($manual['ad_name'] ?? '')),
                        'revenue_value' => value_to_float($sale['producer_net'] ?? $sale['net_revenue'] ?? 0),
                        'product_name' => (string)($sale['product_name'] ?? ''),
                    ]);
                    $count++;
                    continue;
                }

                $candidates = [];
                $matchType = 'none';
                $matchedUserId = (int)($sale['matched_user_id'] ?? 0);
                if ($matchedUserId > 0 && isset($indexes['bySourceUser'][$matchedUserId])) {
                    $lead = $indexes['bySourceUser'][$matchedUserId];
                    if (strtotime((string)$lead['created_at']) <= strtotime($saleDate)) {
                        $candidates = [$lead];
                        $matchType = 'matched_user_id';
                    }
                }
                if (!$candidates) {
                    $phone = (string)($sale['buyer_phone_norm'] ?? '');
                    if ($phone !== '' && isset($indexes['byPhone'][$phone])) {
                        $candidates = filter_leads_before_sale($indexes['byPhone'][$phone], $saleDate);
                        $matchType = $candidates ? 'phone' : 'none';
                    }
                }
                if (!$candidates) {
                    $email = (string)($sale['buyer_email_norm'] ?? '');
                    if ($email !== '' && isset($indexes['byEmail'][$email])) {
                        $candidates = filter_leads_before_sale($indexes['byEmail'][$email], $saleDate);
                        $matchType = $candidates ? 'email' : 'none';
                    }
                }
                if (!$candidates) { continue; }

                $lead = choose_lead_for_model($candidates, $model);
                if (!$lead) { continue; }
                $resolved = resolve_meta_names_from_lead($metaNameLookup, $lead);
                if (empty($resolved['matched'])) { continue; }
                $diff = strtotime($saleDate) - strtotime((string)$lead['created_at']);
                upsert_attribution_match($pdo, [
                    'sale_id' => (int)$sale['id'],
                    'lead_id' => (int)$lead['id'],
                    'attribution_model' => $model,
                    'match_type' => $matchType,
                    'attribution_seconds_diff' => $diff > 0 ? $diff : 0,
                    'lead_created_at' => (string)$lead['created_at'],
                    'sale_date' => $saleDate,
                    'campaign_group' => (string)$resolved['campaign_group'],
                    'campaign_group_norm' => (string)$resolved['campaign_group_norm'],
                    'campaign_name' => (string)$resolved['campaign_name'],
                    'campaign_name_norm' => (string)$resolved['campaign_name_norm'],
                    'ad_name' => (string)$resolved['ad_name'],
                    'ad_name_norm' => (string)$resolved['ad_name_norm'],
                    'revenue_value' => value_to_float($sale['producer_net'] ?? $sale['net_revenue'] ?? 0),
                    'product_name' => (string)($sale['product_name'] ?? ''),
                ]);
                $count++;
            }
        }
        $stats = ['match_rows' => $count];
        finish_attribution_run($pdo, $runId, 'success', $stats, 'AtribuiÃ§Ã£o concluÃ­da.');
        return $stats;
    } catch (Throwable $e) {
        finish_attribution_run($pdo, $runId, 'error', [], $e->getMessage());
        app_log('Erro fatal no match de atribuiÃ§Ã£o', ['error' => $e->getMessage()]);
        throw $e;
    }
}

function build_meta_lookup($pdo, int $integrationId, string $startDate) {
    $lookup = [];
    $configs = [
        ['table' => 'meta_campaign_daily', 'level' => 'campaign'],
        ['table' => 'meta_adset_daily', 'level' => 'campaign_adset'],
        ['table' => 'meta_ad_daily', 'level' => 'campaign_adset_ad'],
    ];
    foreach ($configs as $cfg) {
        $stmt = $pdo->prepare("SELECT * FROM {$cfg['table']} WHERE integration_id = :integration_id AND report_date >= :start_date");
        $stmt->execute(['integration_id' => $integrationId, 'start_date' => $startDate]);
        while ($row = $stmt->fetch()) {
            $date = (string)$row['report_date'];
            $campaignGroupNorm = normalize_match_key($row['campaign_name'] ?? '');
            $campaignNameNorm = normalize_match_key($row['adset_name'] ?? '');
            $adNameNorm = normalize_match_key($row['ad_name'] ?? '');
            if ($cfg['level'] === 'campaign') {
                $keys = [$date . '|campaign|' . $campaignGroupNorm];
            } elseif ($cfg['level'] === 'campaign_adset') {
                $keys = [$date . '|campaign_adset|' . $campaignGroupNorm . '|' . $campaignNameNorm];
            } else {
                $keys = [$date . '|campaign_adset_ad|' . $campaignGroupNorm . '|' . $campaignNameNorm . '|' . $adNameNorm];
            }
            foreach ($keys as $key) {
                if (!isset($lookup[$key])) {
                    $lookup[$key] = ['spend'=>0.0,'impressions'=>0,'reach'=>0,'clicks'=>0,'frequency_sum'=>0.0,'frequency_rows'=>0,'cpm_sum'=>0.0,'cpm_rows'=>0,'cpc_sum'=>0.0,'cpc_rows'=>0,'ctr_sum'=>0.0,'ctr_rows'=>0];
                }
                $lookup[$key]['spend'] += value_to_float($row['spend'] ?? 0);
                $lookup[$key]['impressions'] += value_to_int($row['impressions'] ?? 0);
                $lookup[$key]['reach'] += value_to_int($row['reach'] ?? 0);
                $lookup[$key]['clicks'] += value_to_int($row['clicks'] ?? 0);
                $lookup[$key]['frequency_sum'] += value_to_float($row['frequency'] ?? 0);
                $lookup[$key]['frequency_rows']++;
                $lookup[$key]['cpm_sum'] += value_to_float($row['cpm'] ?? 0);
                $lookup[$key]['cpm_rows']++;
                $lookup[$key]['cpc_sum'] += value_to_float($row['cpc'] ?? 0);
                $lookup[$key]['cpc_rows']++;
                $lookup[$key]['ctr_sum'] += value_to_float($row['ctr'] ?? 0);
                $lookup[$key]['ctr_rows']++;
            }
        }
    }
    return $lookup;
}

function meta_metrics_from_lookup($lookup, string $date, string $campaignGroupNorm, string $campaignNameNorm, string $adNameNorm) {
    $keys = [
        $date . '|campaign_adset_ad|' . $campaignGroupNorm . '|' . $campaignNameNorm . '|' . $adNameNorm,
        $date . '|campaign_adset|' . $campaignGroupNorm . '|' . $campaignNameNorm,
        $date . '|campaign|' . $campaignGroupNorm,
    ];
    foreach ($keys as $key) {
        if (!isset($lookup[$key])) continue;
        $m = $lookup[$key];
        return [
            'spend' => $m['spend'],
            'impressions' => $m['impressions'],
            'reach' => $m['reach'],
            'clicks' => $m['clicks'],
            'frequency' => $m['frequency_rows'] > 0 ? $m['frequency_sum'] / $m['frequency_rows'] : 0.0,
            'cpm' => $m['cpm_rows'] > 0 ? $m['cpm_sum'] / $m['cpm_rows'] : 0.0,
            'cpc' => $m['cpc_rows'] > 0 ? $m['cpc_sum'] / $m['cpc_rows'] : 0.0,
            'ctr' => $m['ctr_rows'] > 0 ? $m['ctr_sum'] / $m['ctr_rows'] : 0.0,
        ];
    }
    return ['spend'=>0.0,'impressions'=>0,'reach'=>0,'clicks'=>0,'frequency'=>0.0,'cpm'=>0.0,'cpc'=>0.0,'ctr'=>0.0];
}

function upsert_attribution_daily($pdo, array $row) {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $sql = "INSERT INTO attribution_campaign_daily (
            report_date, attribution_model, campaign_group, campaign_group_norm,
            campaign_name, campaign_name_norm, ad_name, ad_name_norm,
            meta_spend, impressions, reach, clicks, frequency, cpm, cpc, ctr,
            leads, attributed_sales, attributed_revenue, cpl, cac, roas,
            created_at, updated_at
        ) VALUES (
            :report_date, :attribution_model, :campaign_group, :campaign_group_norm,
            :campaign_name, :campaign_name_norm, :ad_name, :ad_name_norm,
            :meta_spend, :impressions, :reach, :clicks, :frequency, :cpm, :cpc, :ctr,
            :leads, :attributed_sales, :attributed_revenue, :cpl, :cac, :roas,
            NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            meta_spend = VALUES(meta_spend),
            impressions = VALUES(impressions),
            reach = VALUES(reach),
            clicks = VALUES(clicks),
            frequency = VALUES(frequency),
            cpm = VALUES(cpm),
            cpc = VALUES(cpc),
            ctr = VALUES(ctr),
            leads = VALUES(leads),
            attributed_sales = VALUES(attributed_sales),
            attributed_revenue = VALUES(attributed_revenue),
            cpl = VALUES(cpl),
            cac = VALUES(cac),
            roas = VALUES(roas),
            updated_at = NOW()";
        $stmt = $pdo->prepare($sql);
    }
    $stmt->execute($row);
}

function sync_attribution_daily($pdo, $integrationId, $daysBack = null) {
    $runId = create_attribution_run($pdo, 'daily');
    try {
        $daysBack = $daysBack !== null ? (int)$daysBack : (int)app_config('attribution', 'sync_days_back');
        if ($daysBack < 3) { $daysBack = 3; }
        $startDate = date('Y-m-d', strtotime('-' . $daysBack . ' days'));
        $pdo->prepare('DELETE FROM attribution_campaign_daily WHERE report_date >= :start_date')->execute(['start_date' => $startDate]);
        $metaLookup = build_meta_lookup($pdo, $integrationId, $startDate);
        $leadStmt = $pdo->prepare('SELECT DATE(created_at) AS report_date, utm_campaign_group, utm_campaign_group_norm, utm_campaign_name, utm_campaign_name_norm, utm_ad_name, utm_ad_name_norm, COUNT(*) AS leads FROM attribution_leads WHERE created_at >= :start_date GROUP BY DATE(created_at), utm_campaign_group_norm, utm_campaign_name_norm, utm_ad_name_norm, utm_campaign_group, utm_campaign_name, utm_ad_name');
        $leadStmt->execute(['start_date' => $startDate . ' 00:00:00']);
        $leadRows = $leadStmt->fetchAll();
        $saleStmt = $pdo->prepare('SELECT DATE(m.sale_date) AS report_date, m.attribution_model, m.campaign_group, m.campaign_group_norm, m.campaign_name, m.campaign_name_norm, m.ad_name, m.ad_name_norm, COUNT(*) AS attributed_sales, SUM(m.revenue_value) AS attributed_revenue FROM attribution_matches m WHERE m.sale_date >= :start_date GROUP BY DATE(m.sale_date), m.attribution_model, m.campaign_group_norm, m.campaign_name_norm, m.ad_name_norm, m.campaign_group, m.campaign_name, m.ad_name');
        $saleStmt->execute(['start_date' => $startDate . ' 00:00:00']);
        $saleRows = $saleStmt->fetchAll();
        $bucket = [];
        foreach ($leadRows as $row) {
            foreach (['first_touch', 'last_touch'] as $model) {
                $key = implode('|', [$row['report_date'], $model, $row['utm_campaign_group_norm'] ?: '', $row['utm_campaign_name_norm'] ?: '', $row['utm_ad_name_norm'] ?: '']);
                if (!isset($bucket[$key])) {
                    $bucket[$key] = [
                        'report_date' => $row['report_date'], 'attribution_model' => $model,
                        'campaign_group' => (string)($row['utm_campaign_group'] ?? ''), 'campaign_group_norm' => (string)($row['utm_campaign_group_norm'] ?? ''),
                        'campaign_name' => (string)($row['utm_campaign_name'] ?? ''), 'campaign_name_norm' => (string)($row['utm_campaign_name_norm'] ?? ''),
                        'ad_name' => (string)($row['utm_ad_name'] ?? ''), 'ad_name_norm' => (string)($row['utm_ad_name_norm'] ?? ''),
                        'leads' => 0, 'attributed_sales' => 0, 'attributed_revenue' => 0.0,
                    ];
                }
                $bucket[$key]['leads'] += (int)$row['leads'];
            }
        }
        foreach ($saleRows as $row) {
            $key = implode('|', [$row['report_date'], $row['attribution_model'], $row['campaign_group_norm'] ?: '', $row['campaign_name_norm'] ?: '', $row['ad_name_norm'] ?: '']);
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'report_date' => $row['report_date'], 'attribution_model' => $row['attribution_model'],
                    'campaign_group' => (string)($row['campaign_group'] ?? ''), 'campaign_group_norm' => (string)($row['campaign_group_norm'] ?? ''),
                    'campaign_name' => (string)($row['campaign_name'] ?? ''), 'campaign_name_norm' => (string)($row['campaign_name_norm'] ?? ''),
                    'ad_name' => (string)($row['ad_name'] ?? ''), 'ad_name_norm' => (string)($row['ad_name_norm'] ?? ''),
                    'leads' => 0, 'attributed_sales' => 0, 'attributed_revenue' => 0.0,
                ];
            }
            $bucket[$key]['attributed_sales'] += (int)$row['attributed_sales'];
            $bucket[$key]['attributed_revenue'] += value_to_float($row['attributed_revenue'] ?? 0);
        }
        $rows = 0;
        foreach ($bucket as $row) {
            $meta = meta_metrics_from_lookup($metaLookup, (string)$row['report_date'], (string)$row['campaign_group_norm'], (string)$row['campaign_name_norm'], (string)$row['ad_name_norm']);
            $spend = value_to_float($meta['spend']); $leads = (int)$row['leads']; $sales = (int)$row['attributed_sales']; $revenue = value_to_float($row['attributed_revenue']);
            upsert_attribution_daily($pdo, [
                'report_date' => (string)$row['report_date'], 'attribution_model' => (string)$row['attribution_model'],
                'campaign_group' => (string)$row['campaign_group'], 'campaign_group_norm' => (string)$row['campaign_group_norm'],
                'campaign_name' => (string)$row['campaign_name'], 'campaign_name_norm' => (string)$row['campaign_name_norm'],
                'ad_name' => (string)$row['ad_name'], 'ad_name_norm' => (string)$row['ad_name_norm'],
                'meta_spend' => $spend, 'impressions' => value_to_int($meta['impressions']), 'reach' => value_to_int($meta['reach']), 'clicks' => value_to_int($meta['clicks']),
                'frequency' => value_to_float($meta['frequency']), 'cpm' => value_to_float($meta['cpm']), 'cpc' => value_to_float($meta['cpc']), 'ctr' => value_to_float($meta['ctr']),
                'leads' => $leads, 'attributed_sales' => $sales, 'attributed_revenue' => $revenue,
                'cpl' => $leads > 0 ? $spend / $leads : 0.0, 'cac' => $sales > 0 ? $spend / $sales : 0.0, 'roas' => $spend > 0 ? $revenue / $spend : 0.0,
            ]);
            $rows++;
        }
        $stats = ['daily_rows' => $rows];
        finish_attribution_run($pdo, $runId, 'success', $stats, 'ConsolidaÃ§Ã£o diÃ¡ria concluÃ­da.');
        return $stats;
    } catch (Throwable $e) {
        finish_attribution_run($pdo, $runId, 'error', [], $e->getMessage());
        app_log('Erro fatal na consolidaÃ§Ã£o de atribuiÃ§Ã£o', ['error' => $e->getMessage()]);
        throw $e;
    }
}

function sync_full_attribution($pdo, PDO $sourcePdo, $integrationId, $daysBack) {
    $import = sync_attribution_imports($pdo, $sourcePdo);
    $matches = sync_attribution_matches($pdo, (int)$integrationId);
    $daily = sync_attribution_daily($pdo, $integrationId, $daysBack);
    return array('import' => $import, 'matches' => $matches, 'daily' => $daily);
}
