<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/metrics.php';

$pdo = getPDO();
metrics_ensure_schema($pdo);

$models = ['first_touch', 'last_touch'];
$metaLookup = build_meta_name_lookup($pdo, 0);
$stmt = $pdo->query("
    SELECT axs.*, al.id AS lead_id, al.created_at AS lead_created_at,
           al.utm_campaign_group, al.utm_campaign_group_norm,
           al.utm_campaign_name, al.utm_campaign_name_norm,
           al.utm_ad_name, al.utm_ad_name_norm
      FROM attribution_sales axs
      JOIN attribution_leads al ON al.source_user_id = axs.matched_user_id
     WHERE axs.matched_user_id IS NOT NULL
       AND axs.matched_user_id > 0
       AND NOT EXISTS (
           SELECT 1
             FROM attribution_matches am
            WHERE am.sale_id = axs.id
              AND am.attribution_model = 'last_touch'
       )
     ORDER BY axs.sale_date ASC, axs.id ASC
");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$checked = count($rows);
$created = 0;
$skipped = [
    'lead_after_sale' => 0,
    'campaign_unresolved' => 0,
];

foreach ($rows as $row) {
    $leadCreatedAt = (string)($row['lead_created_at'] ?? '');
    $saleDate = (string)($row['sale_date'] ?? '');
    if (strtotime($leadCreatedAt) === false || strtotime($saleDate) === false || strtotime($leadCreatedAt) > strtotime($saleDate)) {
        $skipped['lead_after_sale']++;
        continue;
    }

    $resolved = resolve_meta_names_from_lead($metaLookup, $row);
    if (empty($resolved['matched'])) {
        $skipped['campaign_unresolved']++;
        continue;
    }

    $diff = strtotime($saleDate) - strtotime($leadCreatedAt);
    foreach ($models as $model) {
        upsert_attribution_match($pdo, [
            'sale_id' => (int)$row['id'],
            'lead_id' => (int)$row['lead_id'],
            'attribution_model' => $model,
            'match_type' => 'matched_user_id',
            'attribution_seconds_diff' => $diff > 0 ? $diff : 0,
            'lead_created_at' => $leadCreatedAt,
            'sale_date' => $saleDate,
            'campaign_group' => (string)$resolved['campaign_group'],
            'campaign_group_norm' => (string)$resolved['campaign_group_norm'],
            'campaign_name' => (string)$resolved['campaign_name'],
            'campaign_name_norm' => (string)$resolved['campaign_name_norm'],
            'ad_name' => (string)$resolved['ad_name'],
            'ad_name_norm' => (string)$resolved['ad_name_norm'],
            'integration_id' => $resolved['integration_id'] ?? null,
            'ad_account_name' => (string)($resolved['ad_account_name'] ?? ''),
            'revenue_value' => value_to_float($row['producer_net'] ?? $row['net_revenue'] ?? 0),
            'product_name' => (string)($row['product_name'] ?? ''),
        ]);
        $created++;
    }
}

echo json_encode([
    'checked_sales' => $checked,
    'created_or_updated_matches' => $created,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
