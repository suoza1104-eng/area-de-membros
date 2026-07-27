<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/meta_form_utms.php';
proteger_admin();

$pdo = getPDO();
mfu_ensure_schema($pdo);

$menu = 'meta_form_utms';
$page_title = 'UTMs Forms Meta';
$notice = '';
$error = '';

function mfu_admin_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mfu_admin_status_label(string $status): string {
    return [
        'updated' => 'Atribuido',
        'already_has_utm' => 'Ja tinha UTM',
        'not_found' => 'Aluno nao encontrado',
        'skipped' => 'Linha incompleta',
        'not_updated' => 'Nao atualizado',
        'not_found_or_has_utm' => 'Legado: nao separado',
    ][$status] ?? $status;
}

function mfu_admin_status_class(string $status): string {
    if ($status === 'updated') return 'ok';
    if ($status === 'already_has_utm') return 'warn';
    if ($status === 'not_found') return 'err';
    return 'muted';
}

function mfu_admin_rows(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'reclassify') {
            $stats = mfu_reclassify_legacy_logs($pdo, 20000);
            $notice = 'Reclassificacao concluida: ' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$match = trim((string)($_GET['match'] ?? ''));
$campaign = trim((string)($_GET['campaign'] ?? ''));

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'l.status = :status';
    $params['status'] = $status;
}
if ($match !== '') {
    $where[] = 'l.match_method = :match';
    $params['match'] = $match;
}
if ($campaign !== '') {
    $where[] = '(l.utm_medium = :campaign OR l.utm_campaign = :campaign)';
    $params['campaign'] = $campaign;
}
if ($search !== '') {
    $where[] = "(
        l.user_name LIKE :q OR l.email LIKE :q OR l.phone_norm LIKE :q OR l.lead_id LIKE :q
        OR l.utm_medium LIKE :q OR l.utm_campaign LIKE :q OR l.utm_content LIKE :q
        OR l.existing_utm_source LIKE :q OR l.existing_utm_medium LIKE :q
        OR l.existing_utm_campaign LIKE :q OR l.existing_utm_content LIKE :q
    )";
    $params['q'] = '%' . $search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totals = mfu_admin_rows($pdo, "SELECT status, COUNT(*) total FROM meta_form_utm_logs l GROUP BY status ORDER BY total DESC");
$totalAll = array_sum(array_map(static fn($r) => (int)$r['total'], $totals));
$totalUpdated = 0;
$totalAlready = 0;
$totalNotFound = 0;
foreach ($totals as $row) {
    if ((string)$row['status'] === 'updated') $totalUpdated = (int)$row['total'];
    if ((string)$row['status'] === 'already_has_utm') $totalAlready = (int)$row['total'];
    if ((string)$row['status'] === 'not_found') $totalNotFound = (int)$row['total'];
}
$totalFailed = max(0, $totalAll - $totalUpdated);

$filteredTotal = (int)(mfu_admin_rows($pdo, "SELECT COUNT(*) total FROM meta_form_utm_logs l {$whereSql}", $params)[0]['total'] ?? 0);
$rows = mfu_admin_rows($pdo, "
    SELECT l.*
      FROM meta_form_utm_logs l
      {$whereSql}
     ORDER BY l.id DESC
     LIMIT 300
", $params);
$campaigns = mfu_admin_rows($pdo, "
    SELECT utm_medium campaign, COUNT(*) total
      FROM meta_form_utm_logs
     WHERE utm_medium IS NOT NULL AND utm_medium <> ''
     GROUP BY utm_medium
     ORDER BY total DESC
     LIMIT 80
");

require __DIR__ . '/_header.php';
?>
<style>
.mfu{display:flex;flex-direction:column;gap:14px}.mfu-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.mfu-head h1{font-size:22px;margin:0}.mfu-head p{font-size:12px;color:var(--muted);margin:4px 0 0}.mfu-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.mfu-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px}.mfu-kpi small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.07em}.mfu-kpi strong{display:block;font-size:25px;margin-top:4px;color:var(--text)}.mfu-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:15px}.mfu-card h2{font-size:15px;margin:0 0 10px}.mfu-filter{display:grid;grid-template-columns:1.2fr repeat(3,minmax(150px,.5fr)) auto;gap:9px;align-items:end}.mfu-filter label{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:4px}.mfu-filter input,.mfu-filter select{width:100%;height:35px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 9px;font-size:11px}.mfu-bars{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mfu-bar{display:grid;grid-template-columns:150px minmax(0,1fr) 58px;gap:10px;align-items:center;margin:9px 0;font-size:11px}.mfu-track{height:9px;background:var(--bg);border-radius:99px;overflow:hidden}.mfu-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#38bdf8,#facc15)}.mfu-fill.ok{background:#22c55e}.mfu-fill.warn{background:#f59e0b}.mfu-fill.err{background:#ef4444}.mfu-scroll{overflow:auto;border:1px solid var(--border);border-radius:10px}.mfu-table{width:100%;min-width:1250px;border-collapse:collapse}.mfu-table th,.mfu-table td{padding:9px;border-bottom:1px solid var(--border);vertical-align:top;font-size:10px}.mfu-table th{position:sticky;top:0;background:#101a2e;color:var(--muted);text-align:left;text-transform:uppercase;font-size:9px;letter-spacing:.05em}.mfu-table tr:hover td{background:var(--bg-hover)}.mfu-pill{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:9px;font-weight:800;border:1px solid}.mfu-pill.ok{color:#86efac;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)}.mfu-pill.warn{color:#fde68a;background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.25)}.mfu-pill.err{color:#fca5a5;background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25)}.mfu-pill.muted{color:#cbd5e1;background:rgba(148,163,184,.08);border-color:rgba(148,163,184,.2)}.mfu-utm{display:grid;gap:2px;min-width:210px}.mfu-utm div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mfu-utm span{color:var(--muted);font-size:9px;text-transform:uppercase}.mfu-msg{padding:10px 12px;border-radius:9px;font-size:11px}.mfu-msg.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#86efac}.mfu-msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#fca5a5}.mfu-empty{padding:24px;text-align:center;color:var(--muted);font-size:12px}.mfu-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}@media(max-width:1100px){.mfu-grid{grid-template-columns:repeat(2,1fr)}.mfu-filter{grid-template-columns:1fr 1fr}.mfu-bars{grid-template-columns:1fr}}@media(max-width:650px){.mfu-grid,.mfu-filter{grid-template-columns:1fr}.mfu-head{flex-direction:column}.mfu-bar{grid-template-columns:100px 1fr 44px}}
</style>

<div class="mfu">
  <div class="mfu-head">
    <div>
      <h1>UTMs Forms Meta</h1>
      <p>Auditoria provisoria das atribuicoes vindas da Google Sheet de formularios Meta.</p>
    </div>
    <form method="post" class="mfu-actions">
      <input type="hidden" name="action" value="reclassify">
      <button class="btn btn-ghost" type="submit">Reclassificar logs antigos</button>
    </form>
  </div>

  <?php if ($notice !== ''): ?><div class="mfu-msg ok"><?= mfu_admin_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="mfu-msg err"><?= mfu_admin_h($error) ?></div><?php endif; ?>

  <div class="mfu-grid">
    <div class="mfu-kpi"><small>Total processado</small><strong><?= number_format($totalAll, 0, ',', '.') ?></strong></div>
    <div class="mfu-kpi"><small>Alunos atribuidos</small><strong><?= number_format($totalUpdated, 0, ',', '.') ?></strong></div>
    <div class="mfu-kpi"><small>Nao atribuidos</small><strong><?= number_format($totalFailed, 0, ',', '.') ?></strong></div>
    <div class="mfu-kpi"><small>Ja tinham UTM / nao encontrados</small><strong><?= number_format($totalAlready + $totalNotFound, 0, ',', '.') ?></strong></div>
  </div>

  <section class="mfu-card">
    <form class="mfu-filter" method="get">
      <div><label>Busca</label><input name="q" value="<?= mfu_admin_h($search) ?>" placeholder="Aluno, e-mail, telefone, campanha, conjunto, anuncio ou lead"></div>
      <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach($totals as $row): $s=(string)$row['status']; ?><option value="<?=mfu_admin_h($s)?>" <?=$status===$s?'selected':''?>><?=mfu_admin_h(mfu_admin_status_label($s))?></option><?php endforeach; ?></select></div>
      <div><label>Chave</label><select name="match"><option value="">Todas</option><option value="phone" <?=$match==='phone'?'selected':''?>>Telefone</option><option value="email" <?=$match==='email'?'selected':''?>>E-mail</option></select></div>
      <div><label>Campanha</label><select name="campaign"><option value="">Todas</option><?php foreach($campaigns as $row): $c=(string)$row['campaign']; ?><option value="<?=mfu_admin_h($c)?>" <?=$campaign===$c?'selected':''?>><?=mfu_admin_h($c)?></option><?php endforeach; ?></select></div>
      <div class="mfu-actions"><button class="btn btn-primary">Filtrar</button><a class="btn btn-ghost" href="meta_form_utms.php">Limpar</a></div>
    </form>
  </section>

  <section class="mfu-card">
    <h2>Falhas e status de atribuicao</h2>
    <div class="mfu-bars">
      <div>
        <?php $maxStatus = max(array_map(static fn($r) => (int)$r['total'], $totals) ?: [1]); foreach ($totals as $row): $s=(string)$row['status']; $n=(int)$row['total']; ?>
          <div class="mfu-bar">
            <span><?= mfu_admin_h(mfu_admin_status_label($s)) ?></span>
            <div class="mfu-track"><div class="mfu-fill <?=mfu_admin_status_class($s)?>" style="width:<?= min(100, $n / max(1, $maxStatus) * 100) ?>%"></div></div>
            <strong><?= number_format($n, 0, ',', '.') ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <div>
        <?php $maxCamp = max(array_map(static fn($r) => (int)$r['total'], $campaigns) ?: [1]); foreach (array_slice($campaigns, 0, 10) as $row): $n=(int)$row['total']; ?>
          <div class="mfu-bar">
            <span title="<?=mfu_admin_h((string)$row['campaign'])?>"><?= mfu_admin_h((string)$row['campaign']) ?></span>
            <div class="mfu-track"><div class="mfu-fill" style="width:<?= min(100, $n / max(1, $maxCamp) * 100) ?>%"></div></div>
            <strong><?= number_format($n, 0, ',', '.') ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mfu-card">
    <h2>Linhas detalhadas <?= $filteredTotal ? '(' . number_format($filteredTotal, 0, ',', '.') . ')' : '' ?></h2>
    <div class="mfu-scroll">
      <table class="mfu-table">
        <thead><tr><th>Linha</th><th>Status</th><th>Aluno / chave</th><th>UTM que seria atribuida</th><th>UTM existente no aluno</th><th>Mensagem</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $s=(string)$row['status']; ?>
          <tr>
            <td><?= (int)$row['sheet_row'] ?><div style="color:var(--muted)"><?= mfu_admin_h((string)$row['lead_id']) ?></div></td>
            <td><span class="mfu-pill <?=mfu_admin_status_class($s)?>"><?= mfu_admin_h(mfu_admin_status_label($s)) ?></span></td>
            <td>
              <strong><?= mfu_admin_h((string)($row['user_name'] ?: ('Aluno #' . (string)$row['user_id']))) ?></strong>
              <div style="color:var(--muted)"><?= mfu_admin_h((string)($row['email'] ?: '-')) ?></div>
              <div style="color:var(--muted)"><?= mfu_admin_h((string)($row['phone_norm'] ?: '-')) ?> · <?= mfu_admin_h((string)($row['match_method'] ?: '-')) ?></div>
            </td>
            <td><div class="mfu-utm"><div><span>Source</span> <?=mfu_admin_h((string)$row['utm_source'])?></div><div><span>Medium</span> <?=mfu_admin_h((string)$row['utm_medium'])?></div><div><span>Campaign</span> <?=mfu_admin_h((string)$row['utm_campaign'])?></div><div><span>Content</span> <?=mfu_admin_h((string)$row['utm_content'])?></div></div></td>
            <td><div class="mfu-utm"><div><span>Source</span> <?=mfu_admin_h((string)($row['existing_utm_source'] ?: '-'))?></div><div><span>Medium</span> <?=mfu_admin_h((string)($row['existing_utm_medium'] ?: '-'))?></div><div><span>Campaign</span> <?=mfu_admin_h((string)($row['existing_utm_campaign'] ?: '-'))?></div><div><span>Content</span> <?=mfu_admin_h((string)($row['existing_utm_content'] ?: '-'))?></div></div></td>
            <td><?= mfu_admin_h((string)$row['message']) ?></td>
            <td><?= mfu_admin_h(date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="mfu-empty">Nenhuma linha encontrada para os filtros.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
