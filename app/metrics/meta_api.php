<?php


function meta_graph_url($path) {
    $base = rtrim((string) app_config('meta', 'graph_base_url'), '/');
    $version = trim((string) app_config('meta', 'graph_version'), '/');
    $path = '/' . ltrim($path, '/');
    return $base . '/' . $version . $path;
}

function meta_api_get($path, array $params = []) {
    $url = meta_graph_url($path) . '?' . http_build_query($params);
    $timeout = (int) app_config('meta', 'sync_timeout_seconds');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException('Erro cURL Meta API: ' . $error);
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta invÃ¡lida da Meta API. HTTP ' . $httpCode . '. Body: ' . substr((string) $body, 0, 1000));
    }

    if (isset($decoded['error'])) {
        $message = $decoded['error']['message'] ?? 'Erro desconhecido';
        $code = $decoded['error']['code'] ?? 0;
        $subcode = $decoded['error']['error_subcode'] ?? 0;
        throw new RuntimeException(sprintf('Meta API error %s/%s: %s', $code, $subcode, $message));
    }

    return $decoded;
}

function meta_test_connection($accessToken, string $adAccountId) {
    $adAccountId = normalize_account_id($adAccountId);

    $account = meta_api_get('/' . $adAccountId, [
        'fields' => 'id,account_id,name,account_status,currency,timezone_name',
        'access_token' => $accessToken,
    ]);

    $insights = meta_api_get('/' . $adAccountId . '/insights', [
        'level' => 'account',
        'fields' => 'account_id,account_name,spend,impressions,reach,frequency,clicks,cpm,cpc,ctr',
        'date_preset' => 'today',
        'time_increment' => 1,
        'limit' => 1,
        'access_token' => $accessToken,
    ]);

    return [
        'account' => $account,
        'sample_insights' => $insights['data'][0] ?? null,
    ];
}

function meta_fetch_insights($accessToken, string $adAccountId, string $level, string $since, string $until) {
    $adAccountId = normalize_account_id($adAccountId);

    $fields = [
        'account_id',
        'account_name',
        'campaign_id',
        'campaign_name',
        'objective',
        'buying_type',
        'adset_id',
        'adset_name',
        'ad_id',
        'ad_name',
        'spend',
        'impressions',
        'reach',
        'frequency',
        'clicks',
        'unique_clicks',
        'inline_link_clicks',
        'ctr',
        'cpc',
        'cpm',
        'actions',
        'cost_per_action_type',
        'purchase_roas',
        'date_start',
        'date_stop',
    ];

    $all = [];
    $after = null;

    do {
        $params = [
            'level' => $level,
            'fields' => implode(',', $fields),
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'time_increment' => (int) app_config('meta', 'default_time_increment'),
            'limit' => 250,
            'access_token' => $accessToken,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        $response = meta_api_get('/' . $adAccountId . '/insights', $params);
        $data = $response['data'] ?? [];

        foreach ($data as $row) {
            $all[] = $row;
        }

        $after = $response['paging']['cursors']['after'] ?? null;
    } while ($after);

    return $all;
}

function meta_fetch_status_map($accessToken, string $adAccountId, string $level) {
    $adAccountId = normalize_account_id($adAccountId);

    $endpoint = null;
    if ($level === 'campaign') {
        $endpoint = '/' . $adAccountId . '/campaigns';
    } elseif ($level === 'adset') {
        $endpoint = '/' . $adAccountId . '/adsets';
    } elseif ($level === 'ad') {
        $endpoint = '/' . $adAccountId . '/ads';
    }

    if ($endpoint === null) {
        return [];
    }

    $all = [];
    $after = null;

    do {
        $params = [
            'fields' => 'id,name,status,effective_status,objective,buying_type,campaign_id,adset_id',
            'limit' => 500,
            'access_token' => $accessToken,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        $response = meta_api_get($endpoint, $params);

        foreach (($response['data'] ?? []) as $item) {
            $all[(string) ($item['id'] ?? '')] = $item;
        }

        $after = $response['paging']['cursors']['after'] ?? null;
    } while ($after);

    return $all;
}

function upsert_meta_account_daily($pdo, int $integrationId, array $row) {
    $actions = $row['actions'] ?? [];
    $roas = $row['purchase_roas'] ?? [];

    $sql = "INSERT INTO meta_account_daily (
        integration_id, report_date, account_id, account_name,
        spend, impressions, reach, frequency, clicks, unique_clicks,
        inline_link_clicks, outbound_clicks, landing_page_views,
        ctr, cpc, cpm, leads, purchases, purchase_value, purchase_roas,
        raw_actions_json, raw_cost_per_action_json, raw_purchase_roas_json,
        created_at, updated_at
    ) VALUES (
        :integration_id, :report_date, :account_id, :account_name,
        :spend, :impressions, :reach, :frequency, :clicks, :unique_clicks,
        :inline_link_clicks, :outbound_clicks, :landing_page_views,
        :ctr, :cpc, :cpm, :leads, :purchases, :purchase_value, :purchase_roas,
        :raw_actions_json, :raw_cost_per_action_json, :raw_purchase_roas_json,
        NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        account_name = VALUES(account_name),
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        reach = VALUES(reach),
        frequency = VALUES(frequency),
        clicks = VALUES(clicks),
        unique_clicks = VALUES(unique_clicks),
        inline_link_clicks = VALUES(inline_link_clicks),
        outbound_clicks = VALUES(outbound_clicks),
        landing_page_views = VALUES(landing_page_views),
        ctr = VALUES(ctr),
        cpc = VALUES(cpc),
        cpm = VALUES(cpm),
        leads = VALUES(leads),
        purchases = VALUES(purchases),
        purchase_value = VALUES(purchase_value),
        purchase_roas = VALUES(purchase_roas),
        raw_actions_json = VALUES(raw_actions_json),
        raw_cost_per_action_json = VALUES(raw_cost_per_action_json),
        raw_purchase_roas_json = VALUES(raw_purchase_roas_json),
        updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'integration_id' => $integrationId,
        'report_date' => $row['date_start'] ?? date('Y-m-d'),
        'account_id' => (string) ($row['account_id'] ?? ''),
        'account_name' => $row['account_name'] ?? null,
        'spend' => (float) ($row['spend'] ?? 0),
        'impressions' => (int) ($row['impressions'] ?? 0),
        'reach' => (int) ($row['reach'] ?? 0),
        'frequency' => (float) ($row['frequency'] ?? 0),
        'clicks' => (int) ($row['clicks'] ?? 0),
        'unique_clicks' => (int) ($row['unique_clicks'] ?? 0),
        'inline_link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
        'outbound_clicks' => extract_action_value($actions, ['outbound_click', 'outbound_clicks']),
        'landing_page_views' => extract_action_value($actions, ['landing_page_view']),
        'ctr' => (float) ($row['ctr'] ?? 0),
        'cpc' => (float) ($row['cpc'] ?? 0),
        'cpm' => (float) ($row['cpm'] ?? 0),
        'leads' => extract_action_value($actions, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']),
        'purchases' => extract_action_value($actions, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
        'purchase_value' => extract_action_decimal($actions, ['omni_purchase', 'purchase']),
        'purchase_roas' => extract_action_decimal($roas, ['omni_purchase', 'purchase']),
        'raw_actions_json' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_cost_per_action_json' => json_encode($row['cost_per_action_type'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_purchase_roas_json' => json_encode($roas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function upsert_meta_campaign_daily($pdo, int $integrationId, array $row, array $statusMap = []) {
    $campaignId = (string) ($row['campaign_id'] ?? '');
    $status = $statusMap[$campaignId] ?? [];
    $actions = $row['actions'] ?? [];
    $roas = $row['purchase_roas'] ?? [];

    $sql = "INSERT INTO meta_campaign_daily (
        integration_id, report_date, account_id, campaign_id, campaign_name, objective, buying_type,
        status, effective_status,
        spend, impressions, reach, frequency, clicks, unique_clicks,
        inline_link_clicks, outbound_clicks, landing_page_views,
        ctr, cpc, cpm, leads, purchases, purchase_value, purchase_roas,
        raw_actions_json, raw_cost_per_action_json, raw_purchase_roas_json,
        created_at, updated_at
    ) VALUES (
        :integration_id, :report_date, :account_id, :campaign_id, :campaign_name, :objective, :buying_type,
        :status, :effective_status,
        :spend, :impressions, :reach, :frequency, :clicks, :unique_clicks,
        :inline_link_clicks, :outbound_clicks, :landing_page_views,
        :ctr, :cpc, :cpm, :leads, :purchases, :purchase_value, :purchase_roas,
        :raw_actions_json, :raw_cost_per_action_json, :raw_purchase_roas_json,
        NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        campaign_name = VALUES(campaign_name),
        objective = VALUES(objective),
        buying_type = VALUES(buying_type),
        status = VALUES(status),
        effective_status = VALUES(effective_status),
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        reach = VALUES(reach),
        frequency = VALUES(frequency),
        clicks = VALUES(clicks),
        unique_clicks = VALUES(unique_clicks),
        inline_link_clicks = VALUES(inline_link_clicks),
        outbound_clicks = VALUES(outbound_clicks),
        landing_page_views = VALUES(landing_page_views),
        ctr = VALUES(ctr),
        cpc = VALUES(cpc),
        cpm = VALUES(cpm),
        leads = VALUES(leads),
        purchases = VALUES(purchases),
        purchase_value = VALUES(purchase_value),
        purchase_roas = VALUES(purchase_roas),
        raw_actions_json = VALUES(raw_actions_json),
        raw_cost_per_action_json = VALUES(raw_cost_per_action_json),
        raw_purchase_roas_json = VALUES(raw_purchase_roas_json),
        updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'integration_id' => $integrationId,
        'report_date' => $row['date_start'] ?? date('Y-m-d'),
        'account_id' => (string) ($row['account_id'] ?? ''),
        'campaign_id' => $campaignId,
        'campaign_name' => $row['campaign_name'] ?? null,
        'objective' => $row['objective'] ?? ($status['objective'] ?? null),
        'buying_type' => $row['buying_type'] ?? ($status['buying_type'] ?? null),
        'status' => $status['status'] ?? null,
        'effective_status' => $status['effective_status'] ?? null,
        'spend' => (float) ($row['spend'] ?? 0),
        'impressions' => (int) ($row['impressions'] ?? 0),
        'reach' => (int) ($row['reach'] ?? 0),
        'frequency' => (float) ($row['frequency'] ?? 0),
        'clicks' => (int) ($row['clicks'] ?? 0),
        'unique_clicks' => (int) ($row['unique_clicks'] ?? 0),
        'inline_link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
        'outbound_clicks' => extract_action_value($actions, ['outbound_click', 'outbound_clicks']),
        'landing_page_views' => extract_action_value($actions, ['landing_page_view']),
        'ctr' => (float) ($row['ctr'] ?? 0),
        'cpc' => (float) ($row['cpc'] ?? 0),
        'cpm' => (float) ($row['cpm'] ?? 0),
        'leads' => extract_action_value($actions, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']),
        'purchases' => extract_action_value($actions, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
        'purchase_value' => extract_action_decimal($actions, ['omni_purchase', 'purchase']),
        'purchase_roas' => extract_action_decimal($roas, ['omni_purchase', 'purchase']),
        'raw_actions_json' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_cost_per_action_json' => json_encode($row['cost_per_action_type'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_purchase_roas_json' => json_encode($roas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function upsert_meta_adset_daily($pdo, int $integrationId, array $row, array $statusMap = []) {
    $adsetId = (string) ($row['adset_id'] ?? '');
    $status = $statusMap[$adsetId] ?? [];
    $actions = $row['actions'] ?? [];
    $roas = $row['purchase_roas'] ?? [];

    $sql = "INSERT INTO meta_adset_daily (
        integration_id, report_date, account_id, campaign_id, campaign_name, adset_id, adset_name,
        status, effective_status,
        spend, impressions, reach, frequency, clicks, unique_clicks,
        inline_link_clicks, outbound_clicks, landing_page_views,
        ctr, cpc, cpm, leads, purchases, purchase_value, purchase_roas,
        raw_actions_json, raw_cost_per_action_json, raw_purchase_roas_json,
        created_at, updated_at
    ) VALUES (
        :integration_id, :report_date, :account_id, :campaign_id, :campaign_name, :adset_id, :adset_name,
        :status, :effective_status,
        :spend, :impressions, :reach, :frequency, :clicks, :unique_clicks,
        :inline_link_clicks, :outbound_clicks, :landing_page_views,
        :ctr, :cpc, :cpm, :leads, :purchases, :purchase_value, :purchase_roas,
        :raw_actions_json, :raw_cost_per_action_json, :raw_purchase_roas_json,
        NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        campaign_name = VALUES(campaign_name),
        adset_name = VALUES(adset_name),
        status = VALUES(status),
        effective_status = VALUES(effective_status),
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        reach = VALUES(reach),
        frequency = VALUES(frequency),
        clicks = VALUES(clicks),
        unique_clicks = VALUES(unique_clicks),
        inline_link_clicks = VALUES(inline_link_clicks),
        outbound_clicks = VALUES(outbound_clicks),
        landing_page_views = VALUES(landing_page_views),
        ctr = VALUES(ctr),
        cpc = VALUES(cpc),
        cpm = VALUES(cpm),
        leads = VALUES(leads),
        purchases = VALUES(purchases),
        purchase_value = VALUES(purchase_value),
        purchase_roas = VALUES(purchase_roas),
        raw_actions_json = VALUES(raw_actions_json),
        raw_cost_per_action_json = VALUES(raw_cost_per_action_json),
        raw_purchase_roas_json = VALUES(raw_purchase_roas_json),
        updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'integration_id' => $integrationId,
        'report_date' => $row['date_start'] ?? date('Y-m-d'),
        'account_id' => (string) ($row['account_id'] ?? ''),
        'campaign_id' => (string) ($row['campaign_id'] ?? ''),
        'campaign_name' => $row['campaign_name'] ?? null,
        'adset_id' => $adsetId,
        'adset_name' => $row['adset_name'] ?? null,
        'status' => $status['status'] ?? null,
        'effective_status' => $status['effective_status'] ?? null,
        'spend' => (float) ($row['spend'] ?? 0),
        'impressions' => (int) ($row['impressions'] ?? 0),
        'reach' => (int) ($row['reach'] ?? 0),
        'frequency' => (float) ($row['frequency'] ?? 0),
        'clicks' => (int) ($row['clicks'] ?? 0),
        'unique_clicks' => (int) ($row['unique_clicks'] ?? 0),
        'inline_link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
        'outbound_clicks' => extract_action_value($actions, ['outbound_click', 'outbound_clicks']),
        'landing_page_views' => extract_action_value($actions, ['landing_page_view']),
        'ctr' => (float) ($row['ctr'] ?? 0),
        'cpc' => (float) ($row['cpc'] ?? 0),
        'cpm' => (float) ($row['cpm'] ?? 0),
        'leads' => extract_action_value($actions, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']),
        'purchases' => extract_action_value($actions, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
        'purchase_value' => extract_action_decimal($actions, ['omni_purchase', 'purchase']),
        'purchase_roas' => extract_action_decimal($roas, ['omni_purchase', 'purchase']),
        'raw_actions_json' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_cost_per_action_json' => json_encode($row['cost_per_action_type'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_purchase_roas_json' => json_encode($roas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function upsert_meta_ad_daily($pdo, int $integrationId, array $row, array $statusMap = []) {
    $adId = (string) ($row['ad_id'] ?? '');
    $status = $statusMap[$adId] ?? [];
    $actions = $row['actions'] ?? [];
    $roas = $row['purchase_roas'] ?? [];

    $sql = "INSERT INTO meta_ad_daily (
        integration_id, report_date, account_id, campaign_id, campaign_name, adset_id, adset_name, ad_id, ad_name,
        status, effective_status,
        spend, impressions, reach, frequency, clicks, unique_clicks,
        inline_link_clicks, outbound_clicks, landing_page_views,
        ctr, cpc, cpm, leads, purchases, purchase_value, purchase_roas,
        raw_actions_json, raw_cost_per_action_json, raw_purchase_roas_json,
        created_at, updated_at
    ) VALUES (
        :integration_id, :report_date, :account_id, :campaign_id, :campaign_name, :adset_id, :adset_name, :ad_id, :ad_name,
        :status, :effective_status,
        :spend, :impressions, :reach, :frequency, :clicks, :unique_clicks,
        :inline_link_clicks, :outbound_clicks, :landing_page_views,
        :ctr, :cpc, :cpm, :leads, :purchases, :purchase_value, :purchase_roas,
        :raw_actions_json, :raw_cost_per_action_json, :raw_purchase_roas_json,
        NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        campaign_name = VALUES(campaign_name),
        adset_name = VALUES(adset_name),
        ad_name = VALUES(ad_name),
        status = VALUES(status),
        effective_status = VALUES(effective_status),
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        reach = VALUES(reach),
        frequency = VALUES(frequency),
        clicks = VALUES(clicks),
        unique_clicks = VALUES(unique_clicks),
        inline_link_clicks = VALUES(inline_link_clicks),
        outbound_clicks = VALUES(outbound_clicks),
        landing_page_views = VALUES(landing_page_views),
        ctr = VALUES(ctr),
        cpc = VALUES(cpc),
        cpm = VALUES(cpm),
        leads = VALUES(leads),
        purchases = VALUES(purchases),
        purchase_value = VALUES(purchase_value),
        purchase_roas = VALUES(purchase_roas),
        raw_actions_json = VALUES(raw_actions_json),
        raw_cost_per_action_json = VALUES(raw_cost_per_action_json),
        raw_purchase_roas_json = VALUES(raw_purchase_roas_json),
        updated_at = NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'integration_id' => $integrationId,
        'report_date' => $row['date_start'] ?? date('Y-m-d'),
        'account_id' => (string) ($row['account_id'] ?? ''),
        'campaign_id' => (string) ($row['campaign_id'] ?? ''),
        'campaign_name' => $row['campaign_name'] ?? null,
        'adset_id' => (string) ($row['adset_id'] ?? ''),
        'adset_name' => $row['adset_name'] ?? null,
        'ad_id' => $adId,
        'ad_name' => $row['ad_name'] ?? null,
        'status' => $status['status'] ?? null,
        'effective_status' => $status['effective_status'] ?? null,
        'spend' => (float) ($row['spend'] ?? 0),
        'impressions' => (int) ($row['impressions'] ?? 0),
        'reach' => (int) ($row['reach'] ?? 0),
        'frequency' => (float) ($row['frequency'] ?? 0),
        'clicks' => (int) ($row['clicks'] ?? 0),
        'unique_clicks' => (int) ($row['unique_clicks'] ?? 0),
        'inline_link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
        'outbound_clicks' => extract_action_value($actions, ['outbound_click', 'outbound_clicks']),
        'landing_page_views' => extract_action_value($actions, ['landing_page_view']),
        'ctr' => (float) ($row['ctr'] ?? 0),
        'cpc' => (float) ($row['cpc'] ?? 0),
        'cpm' => (float) ($row['cpm'] ?? 0),
        'leads' => extract_action_value($actions, ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']),
        'purchases' => extract_action_value($actions, ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase']),
        'purchase_value' => extract_action_decimal($actions, ['omni_purchase', 'purchase']),
        'purchase_roas' => extract_action_decimal($roas, ['omni_purchase', 'purchase']),
        'raw_actions_json' => json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_cost_per_action_json' => json_encode($row['cost_per_action_type'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_purchase_roas_json' => json_encode($roas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function create_sync_run($pdo, int $integrationId, string $scope, string $dateFrom, string $dateTo) {
    $stmt = $pdo->prepare("INSERT INTO meta_sync_runs (integration_id, scope, date_from, date_to, started_at, status) VALUES (:integration_id, :scope, :date_from, :date_to, NOW(), 'running')");
    $stmt->execute([
        'integration_id' => $integrationId,
        'scope' => $scope,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    return (int) $pdo->lastInsertId();
}

function finish_sync_run($pdo, int $syncRunId, string $status, int $rowsUpserted, ?string $message = null) {
    $stmt = $pdo->prepare('UPDATE meta_sync_runs SET finished_at = NOW(), status = :status, rows_upserted = :rows_upserted, message = :message WHERE id = :id');
    $stmt->execute([
        'status' => $status,
        'rows_upserted' => $rowsUpserted,
        'message' => $message,
        'id' => $syncRunId,
    ]);
}

function sync_meta_level($pdo, array $integration, string $level, string $since, string $until) {
    $integrationId = (int) $integration['id'];
    $accessToken = (string) $integration['access_token'];
    $adAccountId = (string) $integration['ad_account_id'];

    $syncRunId = create_sync_run($pdo, $integrationId, $level, $since, $until);

    try {
        $statusMap = $level === 'account' ? [] : meta_fetch_status_map($accessToken, $adAccountId, $level);
        $rows = meta_fetch_insights($accessToken, $adAccountId, $level, $since, $until);
        $count = 0;

        foreach ($rows as $row) {
            switch ($level) {
                case 'account':
                    upsert_meta_account_daily($pdo, $integrationId, $row);
                    break;
                case 'campaign':
                    upsert_meta_campaign_daily($pdo, $integrationId, $row, $statusMap);
                    break;
                case 'adset':
                    upsert_meta_adset_daily($pdo, $integrationId, $row, $statusMap);
                    break;
                case 'ad':
                    upsert_meta_ad_daily($pdo, $integrationId, $row, $statusMap);
                    break;
            }
            $count++;
        }

        $pdo->prepare('UPDATE meta_integrations SET last_sync_at = NOW(), last_success_sync_at = NOW(), last_error_at = NULL, last_error_message = NULL WHERE id = :id')
            ->execute(['id' => $integrationId]);

        finish_sync_run($pdo, $syncRunId, 'success', $count, 'SincronizaÃ§Ã£o concluÃ­da com sucesso.');

        return [
            'ok' => true,
            'scope' => $level,
            'rows' => $count,
            'sync_run_id' => $syncRunId,
        ];
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE meta_integrations SET last_sync_at = NOW(), last_error_at = NOW(), last_error_message = :message WHERE id = :id')
            ->execute([
                'id' => $integrationId,
                'message' => mb_substr($e->getMessage(), 0, 65000),
            ]);

        finish_sync_run($pdo, $syncRunId, 'error', 0, $e->getMessage());
        throw $e;
    }
}

