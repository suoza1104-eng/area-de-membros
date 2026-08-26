<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/payment_events.php';

proteger_admin();
$pdo = getPDO();
$menu = 'integracoes';
$page_title = 'Integrações';
$page_subtitle = 'Webhooks, Hub, SuperFuncionário, ManyChat e logs centralizados';

function int_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function int_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function int_pretty_json(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $json = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE
        ? (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        : $raw;
}
function int_dt_br(?string $dt): string {
    $dt = trim((string)$dt);
    if ($dt === '') return '';
    try { return (new DateTimeImmutable($dt))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $dt; }
}
function int_add_log(array &$rows, array $row): void {
    $row['created_at'] = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
    $rows[] = $row;
}
function int_filter_base(string $alias, string $dateCol, array &$params, string $de, string $ate): array {
    $where = [];
    if ($de !== '') { $where[] = "$alias.$dateCol >= :{$alias}_de"; $params[":{$alias}_de"] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = "$alias.$dateCol <= :{$alias}_ate"; $params[":{$alias}_ate"] = $ate . ' 23:59:59'; }
    return $where;
}

$tab = (string)($_GET['tab'] ?? 'logs');
if (!in_array($tab, ['overview','webhooks','hub','superfuncionario','manychat','meta','logs'], true)) $tab = 'logs';

$source = (string)($_GET['source'] ?? 'todos');
$status = (string)($_GET['status'] ?? 'todos');
$evento = trim((string)($_GET['evento'] ?? ''));
$aluno = trim((string)($_GET['aluno'] ?? ''));
$de = trim((string)($_GET['de'] ?? date('Y-m-d', strtotime('-7 days'))));
$ate = trim((string)($_GET['ate'] ?? date('Y-m-d')));
$limit = max(50, min(1000, (int)($_GET['limit'] ?? 300)));

$stats = [
    'webhooks' => int_table_exists($pdo, 'webhooks') ? (int)$pdo->query("SELECT COUNT(*) FROM webhooks")->fetchColumn() : 0,
    'hub' => int_table_exists($pdo, 'integration_events') ? (int)$pdo->query("SELECT COUNT(*) FROM integration_events")->fetchColumn() : 0,
    'sf' => int_table_exists($pdo, 'superfuncionario_rules') ? (int)$pdo->query("SELECT COUNT(*) FROM superfuncionario_rules")->fetchColumn() : 0,
    'manychat' => int_table_exists($pdo, 'manychat_rules') ? (int)$pdo->query("SELECT COUNT(*) FROM manychat_rules")->fetchColumn() : 0,
    'payment_events' => int_table_exists($pdo, 'student_payment_events') ? (int)$pdo->query("SELECT COUNT(*) FROM student_payment_events")->fetchColumn() : 0,
];

$rows = [];

if ($tab === 'logs') {
    if (($source === 'todos' || $source === 'pagamentos') && int_table_exists($pdo, 'student_payment_events')) {
        $params = [];
        $where = int_filter_base('spe', 'last_seen_at', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'spe.event_code LIKE :spe_evento'; $params[':spe_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') {
            $where[] = '(u.nome LIKE :spe_aluno OR u.email LIKE :spe_aluno OR spe.buyer_email LIKE :spe_aluno OR spe.buyer_phone LIKE :spe_aluno OR spe.user_id = :spe_user_id OR spe.transaction_code LIKE :spe_aluno)';
            $params[':spe_aluno'] = '%' . $aluno . '%';
            $params[':spe_user_id'] = ctype_digit($aluno) ? (int)$aluno : 0;
        }
        if ($status === 'ok') $where[] = 'spe.triggered_at IS NOT NULL';
        elseif ($status === 'deduplicado') $where[] = 'spe.triggered_at IS NULL';
        $sql = 'SELECT spe.*,u.nome user_nome,u.email user_email FROM student_payment_events spe LEFT JOIN users u ON u.id=spe.user_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY spe.last_seen_at DESC,spe.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $triggered = trim((string)($r['triggered_at'] ?? '')) !== '';
                int_add_log($rows, [
                    'source' => 'pagamentos',
                    'created_at' => (string)$r['last_seen_at'],
                    'evento' => (string)$r['event_code'],
                    'status' => $triggered ? 'disparou' : 'registrado',
                    'ok' => $triggered,
                    'aluno' => trim((string)($r['user_nome'] ?? '')) ?: trim((string)($r['buyer_name'] ?? '')),
                    'email' => trim((string)($r['user_email'] ?? '')) ?: trim((string)($r['buyer_email'] ?? '')),
                    'destino' => strtoupper((string)$r['provider']),
                    'summary' => trim((string)$r['transaction_code']) . ' | ' . number_format(((int)$r['gross_amount_cents']) / 100, 2, ',', '.') . ' | ' . (string)$r['normalized_status'],
                    'payload' => (string)($r['raw_payload_json'] ?? ''),
                    'response' => (string)($r['metadata_json'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {}
    }

    if (($source === 'todos' || $source === 'webhooks') && int_table_exists($pdo, 'webhook_logs')) {
        $params = [];
        $where = int_filter_base('wl', 'created_at', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'wl.evento LIKE :wl_evento'; $params[':wl_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') {
            $where[] = '(u.nome LIKE :wl_aluno OR u.email LIKE :wl_aluno OR wl.payload_json LIKE :wl_aluno OR wl.user_id = :wl_user_id)';
            $params[':wl_aluno'] = '%' . $aluno . '%';
            $params[':wl_user_id'] = ctype_digit($aluno) ? (int)$aluno : 0;
        }
        if ($status === 'ok') $where[] = "(wl.response_status >= 200 AND wl.response_status < 300 AND COALESCE(wl.error_message,'') = '')";
        elseif ($status === 'erro') $where[] = "(wl.response_status IS NULL OR wl.response_status < 200 OR wl.response_status >= 300 OR COALESCE(wl.error_message,'') <> '')";
        $sql = 'SELECT wl.*,u.nome user_nome,u.email user_email,w.nome webhook_nome,w.url webhook_url FROM webhook_logs wl LEFT JOIN users u ON u.id=wl.user_id LEFT JOIN webhooks w ON w.id=wl.webhook_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY wl.created_at DESC,wl.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $ok = is_numeric($r['response_status'] ?? null) && (int)$r['response_status'] >= 200 && (int)$r['response_status'] < 300 && trim((string)($r['error_message'] ?? '')) === '';
                int_add_log($rows, [
                    'source' => 'webhooks',
                    'created_at' => (string)$r['created_at'],
                    'evento' => (string)$r['evento'],
                    'status' => (string)($r['response_status'] ?? '-'),
                    'ok' => $ok,
                    'aluno' => (string)($r['user_nome'] ?? ''),
                    'email' => (string)($r['user_email'] ?? ''),
                    'destino' => trim((string)($r['webhook_nome'] ?? '')) ?: trim((string)($r['webhook_url'] ?? '')),
                    'summary' => trim((string)($r['error_message'] ?? '')) ?: substr(trim((string)($r['response_body'] ?? '')), 0, 160),
                    'payload' => (string)($r['payload_json'] ?? ''),
                    'response' => (string)($r['response_body'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {}
    }

    if (($source === 'todos' || $source === 'superfuncionario') && int_table_exists($pdo, 'superfuncionario_logs')) {
        $params = [];
        $where = int_filter_base('sl', 'created_at', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'sl.evento LIKE :sl_evento'; $params[':sl_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') { $where[] = 'sl.request_json LIKE :sl_aluno'; $params[':sl_aluno'] = '%' . $aluno . '%'; }
        if ($status === 'ok') $where[] = 'sl.ok = 1';
        elseif ($status === 'erro') $where[] = 'sl.ok = 0';
        $sql = 'SELECT sl.*,sr.nome rule_nome FROM superfuncionario_logs sl LEFT JOIN superfuncionario_rules sr ON sr.id=sl.rule_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sl.created_at DESC,sl.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                int_add_log($rows, [
                    'source' => 'superfuncionario',
                    'created_at' => (string)$r['created_at'],
                    'evento' => (string)$r['evento'],
                    'status' => (string)($r['http_status'] ?? '-'),
                    'ok' => (int)($r['ok'] ?? 0) === 1,
                    'aluno' => '',
                    'email' => '',
                    'destino' => trim((string)($r['rule_nome'] ?? 'SuperFuncionário')),
                    'summary' => trim((string)($r['error_text'] ?? '')) ?: substr(trim((string)($r['response_text'] ?? '')), 0, 160),
                    'payload' => (string)($r['request_json'] ?? ''),
                    'response' => (string)($r['response_text'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {}
    }

    if (($source === 'todos' || $source === 'manychat') && int_table_exists($pdo, 'manychat_logs')) {
        $params = [];
        $where = int_filter_base('ml', 'created_at', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'ml.evento LIKE :ml_evento'; $params[':ml_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') { $where[] = '(ml.request_json LIKE :ml_aluno OR ml.subscriber_id = :ml_subscriber)'; $params[':ml_aluno'] = '%' . $aluno . '%'; $params[':ml_subscriber'] = $aluno; }
        if ($status === 'ok') $where[] = 'ml.ok = 1';
        elseif ($status === 'erro') $where[] = 'ml.ok = 0';
        $sql = 'SELECT ml.*,mr.nome rule_nome FROM manychat_logs ml LEFT JOIN manychat_rules mr ON mr.id=ml.rule_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ml.created_at DESC,ml.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                int_add_log($rows, [
                    'source' => 'manychat',
                    'created_at' => (string)$r['created_at'],
                    'evento' => (string)$r['evento'],
                    'status' => (string)($r['http_status'] ?? '-'),
                    'ok' => (int)($r['ok'] ?? 0) === 1,
                    'aluno' => '',
                    'email' => (string)($r['subscriber_id'] ?? ''),
                    'destino' => trim((string)($r['rule_nome'] ?? 'ManyChat')) . ' / ' . trim((string)($r['action'] ?? '')),
                    'summary' => trim((string)($r['error_text'] ?? '')) ?: substr(trim((string)($r['response_text'] ?? '')), 0, 160),
                    'payload' => (string)($r['request_json'] ?? ''),
                    'response' => (string)($r['response_text'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {}
    }

    if (($source === 'todos' || $source === 'hub') && int_table_exists($pdo, 'integration_events')) {
        $params = [];
        $where = int_filter_base('ie', 'received_at', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'ie.event_name LIKE :ie_evento'; $params[':ie_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') { $where[] = '(ie.contact_email LIKE :ie_aluno OR ie.contact_phone LIKE :ie_aluno OR ie.transaction_code LIKE :ie_aluno OR ie.raw_payload_json LIKE :ie_aluno)'; $params[':ie_aluno'] = '%' . $aluno . '%'; }
        $sql = 'SELECT ie.*,src.name source_name,d.name destination_name,del.status delivery_status,del.prepared_payload_json FROM integration_events ie LEFT JOIN integration_sources src ON src.id=ie.source_id LEFT JOIN integration_deliveries del ON del.event_id=ie.id LEFT JOIN integration_destinations d ON d.id=del.destination_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ie.received_at DESC,ie.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                int_add_log($rows, [
                    'source' => 'hub',
                    'created_at' => (string)$r['received_at'],
                    'evento' => (string)$r['event_name'],
                    'status' => (string)($r['delivery_status'] ?? 'recebido'),
                    'ok' => true,
                    'aluno' => '',
                    'email' => (string)($r['contact_email'] ?? ''),
                    'destino' => trim((string)($r['source_name'] ?? 'Hub')) . ' -> ' . trim((string)($r['destination_name'] ?? 'sem rota')),
                    'summary' => (string)($r['transaction_code'] ?? ''),
                    'payload' => (string)($r['raw_payload_json'] ?? ''),
                    'response' => (string)($r['prepared_payload_json'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {}
    }

    if (($source === 'todos' || $source === 'entrada') && int_table_exists($pdo, 'inbound_webhook_recebimentos')) {
        $params = [];
        $where = int_filter_base('r', 'recebido_em', $params, $de, $ate);
        if ($evento !== '') { $where[] = 'w.evento LIKE :r_evento'; $params[':r_evento'] = '%' . $evento . '%'; }
        if ($aluno !== '') { $where[] = '(r.payload_raw LIKE :r_aluno OR r.user_id = :r_user_id)'; $params[':r_aluno'] = '%' . $aluno . '%'; $params[':r_user_id'] = ctype_digit($aluno) ? (int)$aluno : 0; }
        if ($status === 'ok') $where[] = "r.status = 'processado'";
        elseif ($status === 'erro') $where[] = "r.status = 'erro'";
        $sql = 'SELECT r.*,w.nome webhook_nome,w.evento FROM inbound_webhook_recebimentos r LEFT JOIN inbound_webhooks w ON w.id=r.webhook_id ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.recebido_em DESC,r.id DESC LIMIT :lim';
        try {
            $st = $pdo->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                int_add_log($rows, [
                    'source' => 'entrada',
                    'created_at' => (string)$r['recebido_em'],
                    'evento' => (string)($r['evento'] ?? 'WEBHOOK_RECEBIDO'),
                    'status' => (string)$r['status'],
                    'ok' => (string)$r['status'] === 'processado',
                    'aluno' => (string)($r['user_id'] ?? ''),
                    'email' => '',
                    'destino' => (string)($r['webhook_nome'] ?? 'Entrada'),
                    'summary' => (string)($r['erro_msg'] ?? ''),
                    'payload' => (string)($r['payload_raw'] ?? ''),
                    'response' => '',
                ]);
            }
        } catch (Throwable $e) {}
    }

    usort($rows, static fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    $rows = array_slice($rows, 0, $limit);
}

include __DIR__ . '/_header.php';
?>
<style>
.int-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}.int-tabs a{padding:9px 12px;border:1px solid var(--border);border-radius:8px;color:var(--muted);text-decoration:none;background:var(--bg-card);font-size:13px}.int-tabs a.active{color:#fff;border-color:rgba(250,204,21,.45);background:rgba(250,204,21,.12)}.int-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}.int-card{border:1px solid var(--border);background:var(--bg-card);border-radius:8px;padding:16px}.int-card small{display:block;color:var(--muted);font-size:11px;text-transform:uppercase}.int-card strong{display:block;font-size:26px;margin-top:6px}.int-panel{border:1px solid var(--border);background:var(--bg-card);border-radius:8px;padding:18px;margin-bottom:16px}.int-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.int-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);background:rgba(255,255,255,.05);border-radius:8px;padding:9px 12px;color:var(--text);text-decoration:none;font-weight:700;font-size:13px;cursor:pointer}.int-btn.primary{background:var(--primary);color:#111;border-color:var(--primary)}.int-filters{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:10px;align-items:end}.int-filters label{display:block;color:var(--muted);font-size:11px;font-weight:700;margin-bottom:4px;text-transform:uppercase}.int-filters input,.int-filters select{width:100%;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);padding:9px}.int-table{width:100%;border-collapse:collapse;table-layout:fixed}.int-table th,.int-table td{border-bottom:1px solid var(--border);padding:10px 8px;text-align:left;vertical-align:top;font-size:12px}.int-table th{color:var(--muted);font-size:10px;text-transform:uppercase}.int-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:800;background:rgba(148,163,184,.12);color:#cbd5e1}.int-badge.ok{background:rgba(34,197,94,.14);color:#86efac}.int-badge.err{background:rgba(239,68,68,.14);color:#fca5a5}.int-muted{color:var(--muted)}.int-summary{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.int-details{margin-top:7px}.int-details pre{white-space:pre-wrap;max-height:280px;overflow:auto;background:#050914;border:1px solid var(--border);border-radius:8px;padding:10px;font-size:11px}.int-empty{text-align:center;color:var(--muted);padding:34px;border:1px dashed var(--border);border-radius:8px}@media(max-width:1100px){.int-grid{grid-template-columns:repeat(2,1fr)}.int-filters{grid-template-columns:repeat(2,1fr)}.int-table{min-width:980px}.int-scroll{overflow:auto}}@media(max-width:650px){.int-grid,.int-filters{grid-template-columns:1fr}}
</style>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Integrações</h1>
      <p class="page-subtitle">Webhooks, Hub, SuperFuncionário, ManyChat, Meta Ads e logs das integrações.</p>
    </div>
  </div>

  <nav class="int-tabs">
    <?php foreach (['overview'=>'Visão geral','webhooks'=>'Webhooks','hub'=>'Hub de Integrações','superfuncionario'=>'SuperFuncionário','manychat'=>'ManyChat','meta'=>'META (Anúncios)','logs'=>'Logs'] as $key => $label): ?>
      <a class="<?= $tab === $key ? 'active' : '' ?>" href="integracoes.php?tab=<?= int_h($key) ?>"><?= int_h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'overview'): ?>
    <div class="int-grid">
      <div class="int-card"><small>Webhooks</small><strong><?= (int)$stats['webhooks'] ?></strong></div>
      <div class="int-card"><small>Eventos no Hub</small><strong><?= (int)$stats['hub'] ?></strong></div>
      <div class="int-card"><small>Regras SuperFuncionário</small><strong><?= (int)$stats['sf'] ?></strong></div>
      <div class="int-card"><small>Regras ManyChat</small><strong><?= (int)$stats['manychat'] ?></strong></div>
      <div class="int-card"><small>Meta BMs Integradas</small><strong><?= (int)$stats['meta'] ?></strong></div>
    </div>
    <div class="int-panel">
      <h2>Seções</h2>
      <div class="int-actions">
        <a class="int-btn" href="integracoes.php?tab=webhooks">Webhooks</a>
        <a class="int-btn" href="integracoes.php?tab=hub">Hub de Integrações</a>
        <a class="int-btn" href="integracoes.php?tab=superfuncionario">SuperFuncionário</a>
        <a class="int-btn" href="integracoes.php?tab=manychat">ManyChat</a>
        <a class="int-btn" href="integracoes.php?tab=meta">META (Anúncios)</a>
        <a class="int-btn primary" href="integracoes.php?tab=logs">Logs</a>
      </div>
    </div>
  <?php elseif ($tab === 'meta'): ?>
    <?php
      $metaIntegrations = int_table_exists($pdo, 'meta_integrations')
        ? $pdo->query("SELECT * FROM meta_integrations ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []
        : [];
      $editMetaId = (int)($_GET['edit_meta'] ?? 0);
      $editingMeta = null;
      if ($editMetaId > 0) {
          foreach ($metaIntegrations as $m) {
              if ((int)$m['id'] === $editMetaId) { $editingMeta = $m; break; }
          }
      }
      $totalMetaCamps = int_table_exists($pdo, 'meta_campaign_daily') ? (int)$pdo->query("SELECT COUNT(DISTINCT campaign_id) FROM meta_campaign_daily")->fetchColumn() : 0;
      $activeMetaBms = count(array_filter($metaIntegrations, fn($m) => ($m['status'] ?? '') === 'active'));
      $lastSyncMeta = $pdo->query("SELECT MAX(last_sync_at) FROM meta_integrations")->fetchColumn() ?: null;
    ?>

    <?php if (!empty($msgOk)): ?>
      <div class="int-panel" style="border-color:#22c55e;color:#86efac;background:rgba(34,197,94,0.1);font-weight:600;padding:12px 16px;margin-bottom:16px;">
        ✅ <?= int_h($msgOk) ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($msgError)): ?>
      <div class="int-panel" style="border-color:#ef4444;color:#fca5a5;background:rgba(239,68,68,0.1);font-weight:600;padding:12px 16px;margin-bottom:16px;">
        ❌ <?= int_h($msgError) ?>
      </div>
    <?php endif; ?>

    <div class="int-grid">
      <div class="int-card"><small>Business Managers (BMs)</small><strong><?= count($metaIntegrations) ?></strong></div>
      <div class="int-card"><small>Contas / BMs Ativas</small><strong style="color:#86efac;"><?= $activeMetaBms ?></strong></div>
      <div class="int-card"><small>Campanhas Sincronizadas</small><strong><?= $totalMetaCamps ?></strong></div>
      <div class="int-card"><small>Último Sincronismo Geral</small><strong style="font-size:16px;"><?= $lastSyncMeta ? int_h(int_dt_br((string)$lastSyncMeta)) : 'Nenhum' ?></strong></div>
    </div>

    <div class="int-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
          <h2 style="margin:0;font-size:18px;">Integrações Meta Ads (Graph API)</h2>
          <p class="int-muted" style="margin:4px 0 0;font-size:12px;">Cadastre e gerencie múltiplas Business Managers (BMs) e Contas de Anúncios da Meta para sincronização contínua de campanhas, conjuntos de anúncios e UTMs.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <?php if ($metaIntegrations): ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="sync_meta_now">
              <button class="int-btn primary" type="submit" title="Executa a sincronização via Graph API para todas as BMs ativas">⚡ Sincronizar Todas Agora</button>
            </form>
          <?php endif; ?>
          <button class="int-btn" type="button" onclick="document.getElementById('metaFormContainer').style.display = document.getElementById('metaFormContainer').style.display === 'none' ? 'block' : 'none'">+ Nova BM / Conta Meta</button>
        </div>
      </div>

      <!-- FORMULÁRIO DE NOVA / EDIÇÃO DE BM -->
      <div id="metaFormContainer" style="display: <?= $editingMeta || empty($metaIntegrations) ? 'block' : 'none' ?>; background:#081020; border:1px solid var(--border); border-radius:10px; padding:18px; margin-bottom:20px;">
        <h3 style="margin:0 0 14px;font-size:14px;color:#fff;"><?= $editingMeta ? '✏️ Editar BM / Conta Meta: ' . int_h($editingMeta['name']) : '➕ Cadastrar Nova Business Manager (BM) / Conta Meta' ?></h3>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
          <input type="hidden" name="action" value="save_meta">
          <input type="hidden" name="meta_id" value="<?= (int)($editingMeta['id'] ?? 0) ?>">
          
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Nome da BM / Conta</label>
            <input type="text" name="meta_name" placeholder="Ex: BM 2 - Escala Digital" value="<?= int_h((string)($editingMeta['name'] ?? '')) ?>" required style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">ID da Conta de Anúncios (act_...)</label>
            <input type="text" name="meta_ad_account_id" placeholder="act_123456789012345" value="<?= int_h((string)($editingMeta['ad_account_id'] ?? '')) ?>" required style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Meta Access Token (Graph API)</label>
            <input type="password" name="meta_access_token" placeholder="<?= !empty($editingMeta['access_token']) ? 'Manter token atual' : 'EAAB...' ?>" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Meta App ID (Opcional)</label>
            <input type="text" name="meta_app_id" placeholder="123456789" value="<?= int_h((string)($editingMeta['app_id'] ?? '')) ?>" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Meta App Secret (Opcional)</label>
            <input type="password" name="meta_app_secret" placeholder="<?= !empty($editingMeta['app_secret']) ? 'Manter segredo atual' : 'App Secret' ?>" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Intervalo Sync (Minutos)</label>
            <input type="number" min="5" max="1440" name="meta_sync_interval" value="<?= (int)($editingMeta['sync_interval_minutes'] ?? 30) ?>" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
          </div>
          <div>
            <label style="display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;">Status da Integração</label>
            <select name="meta_status" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);">
              <option value="active" <?= ($editingMeta['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>🟢 Ativa (Sincronizando)</option>
              <option value="inactive" <?= ($editingMeta['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>⚪ Inativa (Pausada)</option>
            </select>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="int-btn primary" type="submit"><?= $editingMeta ? 'Salvar Alterações' : 'Cadastrar BM Meta' ?></button>
            <?php if ($editingMeta): ?><a class="int-btn" href="integracoes.php?tab=meta">Cancelar</a><?php endif; ?>
          </div>
        </form>
      </div>

      <!-- CARDS DE BMS E CONTAS CADASTRADAS -->
      <?php if (!$metaIntegrations): ?>
        <div class="int-empty">Nenhuma Business Manager ou Conta Meta cadastrada ainda. Clique no botão acima para adicionar a primeira BM!</div>
      <?php else: ?>
        <div style="display:grid;gap:12px;">
          <?php foreach ($metaIntegrations as $m): 
            $isActive = ($m['status'] ?? '') === 'active';
            $hasErr = !empty($m['last_error_message']);
          ?>
            <div style="border:1px solid <?= $hasErr ? '#ef4444' : 'var(--border)' ?>; background:#071020; border-radius:10px; padding:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
              <div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                  <strong style="font-size:15px;color:#fff;"><?= int_h($m['name']) ?></strong>
                  <span class="int-badge <?= $isActive ? 'ok' : '' ?>"><?= $isActive ? '🟢 Ativa' : '⚪ Inativa' ?></span>
                  <span class="int-badge" style="font-family:monospace;"><?= int_h($m['ad_account_id']) ?></span>
                </div>
                <div style="margin-top:6px;font-size:12px;color:var(--muted);">
                  <span>Intervalo: a cada <?= (int)($m['sync_interval_minutes'] ?? 30) ?> min</span> | 
                  <span>Último Sincronismo: <?= !empty($m['last_sync_at']) ? int_h(int_dt_br((string)$m['last_sync_at'])) : 'Pendente' ?></span>
                  <?php if (!empty($m['app_id'])): ?> | <span>App ID: <?= int_h($m['app_id']) ?></span><?php endif; ?>
                </div>
                <?php if ($hasErr): ?>
                  <div style="margin-top:6px;font-size:11px;color:#fca5a5;background:rgba(239,68,68,0.1);padding:6px 10px;border-radius:6px;">
                    ⚠️ Último erro de comunicação: <?= int_h(mb_strimwidth((string)$m['last_error_message'], 0, 150, '...')) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="sync_meta_now">
                  <input type="hidden" name="meta_id" value="<?= (int)$m['id'] ?>">
                  <button class="int-btn primary" style="padding:6px 10px;font-size:11px;" type="submit" title="Sincronizar esta BM agora com a API Graph">⚡ Sincronizar Agora</button>
                </form>

                <a class="int-btn" style="padding:6px 10px;font-size:11px;" href="integracoes.php?tab=meta&edit_meta=<?= (int)$m['id'] ?>">✏️ Editar</a>

                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="toggle_meta_status">
                  <input type="hidden" name="meta_id" value="<?= (int)$m['id'] ?>">
                  <button class="int-btn" style="padding:6px 10px;font-size:11px;" type="submit"><?= $isActive ? '⏸️ Pausar' : '▶️ Ativar' ?></button>
                </form>

                <form method="post" style="margin:0;" onsubmit="return confirm('Deseja excluir a integração com a BM \'<?= int_h($m['name']) ?>\'?')">
                  <input type="hidden" name="action" value="delete_meta">
                  <input type="hidden" name="meta_id" value="<?= (int)$m['id'] ?>">
                  <button class="int-btn" style="padding:6px 10px;font-size:11px;color:#f87171;border-color:#ef4444;" type="submit">🗑️ Excluir</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php elseif ($tab !== 'logs'): ?>
    <?php
      $sections = [
        'webhooks' => ['title'=>'Webhooks', 'text'=>'Configuração dos webhooks de saída, payloads e eventos.', 'url'=>'webhooks.php', 'primary'=>'Abrir Webhooks'],
        'hub' => ['title'=>'Hub de Integrações', 'text'=>'Fontes, rotas em modo sombra e entregas preparadas.', 'url'=>'integration_hub.php', 'primary'=>'Abrir Hub'],
        'superfuncionario' => ['title'=>'SuperFuncionário', 'text'=>'Credenciais, regras, referências, live por turma e logs da integração.', 'url'=>'superfuncionario.php', 'primary'=>'Abrir SuperFuncionário'],
        'manychat' => ['title'=>'ManyChat', 'text'=>'Credenciais, regras, referências e logs da integração.', 'url'=>'manychat.php', 'primary'=>'Abrir ManyChat'],
      ];
      $s = $sections[$tab];
    ?>
    <div class="int-panel">
      <h2><?= int_h($s['title']) ?></h2>
      <p class="int-muted"><?= int_h($s['text']) ?></p>
      <div class="int-actions">
        <a class="int-btn primary" href="<?= int_h($s['url']) ?>"><?= int_h($s['primary']) ?></a>
        <a class="int-btn" href="integracoes.php?tab=logs&source=<?= $tab === 'hub' ? 'hub' : ($tab === 'webhooks' ? 'webhooks' : int_h($tab)) ?>">Ver logs desta seção</a>
      </div>
    </div>
  <?php else: ?>
    <div class="int-grid">
      <div class="int-card"><small>Eventos de pagamento</small><strong><?= (int)$stats['payment_events'] ?></strong></div>
      <div class="int-card"><small>Webhooks</small><strong><?= (int)$stats['webhooks'] ?></strong></div>
      <div class="int-card"><small>Eventos no Hub</small><strong><?= (int)$stats['hub'] ?></strong></div>
      <div class="int-card"><small>Logs carregados</small><strong><?= count($rows) ?></strong></div>
    </div>
    <form class="int-panel int-filters" method="get">
      <input type="hidden" name="tab" value="logs">
      <div><label>Tipo</label><select name="source"><option value="todos">Todos</option><?php foreach (['pagamentos'=>'Pagamentos','webhooks'=>'Webhooks','superfuncionario'=>'SuperFuncionário','manychat'=>'ManyChat','hub'=>'Hub','entrada'=>'Entrada webhook'] as $k=>$v): ?><option value="<?= int_h($k) ?>" <?= $source===$k?'selected':'' ?>><?= int_h($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Status</label><select name="status"><option value="todos">Todos</option><option value="ok" <?= $status==='ok'?'selected':'' ?>>OK/disparou</option><option value="erro" <?= $status==='erro'?'selected':'' ?>>Erro</option><option value="deduplicado" <?= $status==='deduplicado'?'selected':'' ?>>Registrado sem disparo</option></select></div>
      <div><label>Evento</label><input name="evento" value="<?= int_h($evento) ?>" placeholder="PAGAMENTO_APROVADO"></div>
      <div><label>Aluno/Email/Transação</label><input name="aluno" value="<?= int_h($aluno) ?>" placeholder="email, uid, telefone"></div>
      <div><label>De</label><input type="date" name="de" value="<?= int_h($de) ?>"></div>
      <div><label>Até</label><input type="date" name="ate" value="<?= int_h($ate) ?>"></div>
      <div><label>Limite</label><input type="number" name="limit" min="50" max="1000" value="<?= (int)$limit ?>"></div>
      <div><button class="int-btn primary" type="submit">Filtrar</button></div>
    </form>
    <div class="int-panel int-scroll">
      <?php if (!$rows): ?>
        <div class="int-empty">Nenhum log encontrado para o filtro.</div>
      <?php else: ?>
        <table class="int-table">
          <thead><tr><th style="width:135px">Data</th><th style="width:115px">Tipo</th><th style="width:175px">Evento</th><th style="width:90px">Status</th><th style="width:180px">Aluno</th><th>Destino / resumo</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= int_h(int_dt_br((string)$r['created_at'])) ?></td>
              <td><span class="int-badge"><?= int_h((string)$r['source']) ?></span></td>
              <td><?= int_h((string)$r['evento']) ?></td>
              <td><span class="int-badge <?= !empty($r['ok']) ? 'ok' : 'err' ?>"><?= int_h((string)$r['status']) ?></span></td>
              <td><strong><?= int_h((string)$r['aluno']) ?></strong><br><span class="int-muted"><?= int_h((string)$r['email']) ?></span></td>
              <td>
                <div><strong><?= int_h((string)$r['destino']) ?></strong></div>
                <div class="int-summary int-muted"><?= int_h((string)$r['summary']) ?></div>
                <?php if (trim((string)$r['payload']) !== '' || trim((string)$r['response']) !== ''): ?>
                  <details class="int-details"><summary>Detalhes</summary>
                    <?php if (trim((string)$r['payload']) !== ''): ?><strong>Payload</strong><pre><?= int_h(int_pretty_json((string)$r['payload'])) ?></pre><?php endif; ?>
                    <?php if (trim((string)$r['response']) !== ''): ?><strong>Resposta / metadata</strong><pre><?= int_h(int_pretty_json((string)$r['response'])) ?></pre><?php endif; ?>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
