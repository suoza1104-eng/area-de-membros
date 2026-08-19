<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/whatsapp_groups.php';

$pdo = getPDO();
whatsapp_groups_ensure_tables($pdo);

$rawSlug = trim((string)($_GET['c'] ?? $_GET['campanha'] ?? ''));
if ($rawSlug === '') {
    http_response_code(404);
    echo 'Campanha nao encontrada.';
    exit;
}
$slug = whatsapp_groups_slug($rawSlug);

$st = $pdo->prepare("SELECT * FROM whatsapp_group_campaigns WHERE slug=:slug AND status='active' LIMIT 1");
$st->execute([':slug' => $slug]);
$campaign = $st->fetch(PDO::FETCH_ASSOC);
if (!$campaign) {
    http_response_code(404);
    echo 'Campanha indisponivel.';
    exit;
}

$maxDefault = (int)($campaign['max_leads_per_group'] ?? 0);
$st = $pdo->prepare("
    SELECT *
      FROM whatsapp_group_campaign_groups
     WHERE campaign_id=:campaign_id
       AND is_active=1
       AND COALESCE(invite_url,'') <> ''
       AND (
            COALESCE(max_members,0)=0
            OR current_members < max_members
       )
     ORDER BY is_current DESC, id ASC
     LIMIT 1
");
$st->execute([':campaign_id' => (int)$campaign['id']]);
$group = $st->fetch(PDO::FETCH_ASSOC);

if (!$group && $maxDefault > 0) {
    $st = $pdo->prepare("
        SELECT *
          FROM whatsapp_group_campaign_groups
         WHERE campaign_id=:campaign_id
           AND is_active=1
           AND COALESCE(invite_url,'') <> ''
           AND current_members < :max_default
         ORDER BY is_current DESC, id ASC
         LIMIT 1
    ");
    $st->execute([':campaign_id' => (int)$campaign['id'], ':max_default' => $maxDefault]);
    $group = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$group) {
    http_response_code(409);
    echo 'Nenhum grupo disponivel no momento.';
    exit;
}

try {
    $pdo->prepare("
        UPDATE whatsapp_group_campaigns
           SET total_entries=total_entries+1, updated_at=NOW()
         WHERE id=:id
         LIMIT 1
    ")->execute([':id' => (int)$campaign['id']]);
    $pdo->prepare("
        UPDATE whatsapp_group_campaign_groups
           SET current_members=current_members+1, last_synced_at=NOW(), updated_at=NOW()
         WHERE id=:id
         LIMIT 1
    ")->execute([':id' => (int)$group['id']]);
    disparar_evento_webhooks($pdo, 'WHATSAPP_GRUPO_CAMPANHA_ENTROU', [], [
        'campaign_id' => (int)$campaign['id'],
        'campaign_slug' => (string)$campaign['slug'],
        'group_id' => (string)$group['group_id'],
        'invite_url' => (string)$group['invite_url'],
        'origem' => 'whatsapp_group_join',
    ]);
    $limit = (int)($group['max_members'] ?? 0) ?: (int)($campaign['max_leads_per_group'] ?? 0);
    if ($limit > 0 && ((int)$group['current_members'] + 1) >= $limit) {
        disparar_evento_webhooks($pdo, 'WHATSAPP_GRUPO_CAMPANHA_LOTOU', [], [
            'campaign_id' => (int)$campaign['id'],
            'campaign_slug' => (string)$campaign['slug'],
            'group_id' => (string)$group['group_id'],
            'limit' => $limit,
            'origem' => 'whatsapp_group_join',
        ]);
    }
} catch (Throwable $e) {}

header('Location: ' . (string)$group['invite_url'], true, 302);
exit;
