<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/date_redirects.php';
proteger_admin();

$pdo = getPDO();
date_redirects_ensure_schema($pdo);
date_redirects_seed($pdo);

if (empty($_SESSION['date_redirects_csrf'])) {
    $_SESSION['date_redirects_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['date_redirects_csrf'];
$message = '';
$error = '';
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    if (empty($perms['date_redirects']) && !empty($perms['disparos'])) {
        $perms['date_redirects'] = $perms['disparos'];
    }
    $canWrite = !empty($perms['date_redirects']['escrever']);
}

function dr_check_csrf(string $csrf): void
{
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sessao expirada. Recarregue a pagina.');
    }
}

function dr_redirect(string $query = ''): void
{
    header('Location: date_redirects.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function dr_datetime_input(?string $value): string
{
    if (!$value) return '';
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function dr_click_chart_series(PDO $pdo, ?int $redirectorId = null, int $days = 365): array
{
    $days = max(1, min(730, $days));
    $start = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');
    $end = new DateTimeImmutable('today');
    $rowsByDate = [];
    $params = ['start' => $start->format('Y-m-d 00:00:00')];
    $where = 'clicked_at >= :start';
    if ($redirectorId !== null && $redirectorId > 0) {
        $where .= ' AND redirector_id = :rid';
        $params['rid'] = $redirectorId;
    }
    $st = $pdo->prepare("
        SELECT DATE(clicked_at) period, COUNT(*) clicks
          FROM date_redirect_clicks
         WHERE {$where}
         GROUP BY DATE(clicked_at)
         ORDER BY period ASC
    ");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rowsByDate[(string)$row['period']] = (int)$row['clicks'];
    }

    $series = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        $series[] = [
            'date' => $date,
            'label' => $cursor->format('d/m'),
            'clicks' => $rowsByDate[$date] ?? 0,
        ];
    }
    return $series;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        dr_check_csrf($csrf);
        if (!$canWrite) throw new RuntimeException('Seu usuario nao tem permissao de escrita.');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome do redirecionador.');
            $slug = date_redirects_unique_slug($pdo, $name);
            $pdo->prepare("INSERT INTO date_redirectors (name, slug, status) VALUES (:name, :slug, 'active')")
                ->execute(['name' => $name, 'slug' => $slug]);
            dr_redirect('id=' . (int)$pdo->lastInsertId() . '&created=1');
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Redirecionador nao informado.');

        if ($action === 'update_redirector') {
            $name = trim((string)($_POST['name'] ?? ''));
            $slugRaw = trim((string)($_POST['slug'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome.');
            $slug = $slugRaw !== '' ? date_redirects_slugify($slugRaw) : date_redirects_unique_slug($pdo, $name, $id);
            if ($slug === '') throw new RuntimeException('Slug invalido.');
            $st = $pdo->prepare('SELECT id FROM date_redirectors WHERE slug = :slug AND id <> :id LIMIT 1');
            $st->execute(['slug' => $slug, 'id' => $id]);
            if ($st->fetchColumn()) throw new RuntimeException('Este slug ja esta em uso.');
            $pdo->prepare('UPDATE date_redirectors SET name = :name, slug = :slug WHERE id = :id AND deleted_at IS NULL')
                ->execute(['name' => $name, 'slug' => $slug, 'id' => $id]);
            dr_redirect('id=' . $id . '&saved=1');
        }

        if ($action === 'toggle') {
            $pdo->prepare("UPDATE date_redirectors SET status = IF(status='active','paused','active') WHERE id = :id AND deleted_at IS NULL")
                ->execute(['id' => $id]);
            dr_redirect('status=1');
        }

        if ($action === 'delete') {
            $pdo->prepare('UPDATE date_redirectors SET deleted_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);
            dr_redirect('deleted=1');
        }

        if ($action === 'clone') {
            $st = $pdo->prepare('SELECT * FROM date_redirectors WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $st->execute(['id' => $id]);
            $src = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$src) throw new RuntimeException('Redirecionador nao encontrado.');
            $name = (string)$src['name'] . ' (copia)';
            $slug = date_redirects_unique_slug($pdo, $name);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO date_redirectors (name, slug, status) VALUES (:name, :slug, 'active')")
                ->execute(['name' => $name, 'slug' => $slug]);
            $newId = (int)$pdo->lastInsertId();
            $links = $pdo->prepare('SELECT label, url, starts_at, sort_order FROM date_redirect_links WHERE redirector_id = :id ORDER BY starts_at, id');
            $links->execute(['id' => $id]);
            $ins = $pdo->prepare('INSERT INTO date_redirect_links (redirector_id, label, url, starts_at, sort_order) VALUES (:rid, :label, :url, :starts_at, :sort_order)');
            foreach ($links->fetchAll(PDO::FETCH_ASSOC) ?: [] as $link) {
                $ins->execute([
                    'rid' => $newId,
                    'label' => $link['label'],
                    'url' => $link['url'],
                    'starts_at' => $link['starts_at'],
                    'sort_order' => $link['sort_order'],
                ]);
            }
            $pdo->commit();
            dr_redirect('id=' . $newId . '&cloned=1');
        }

        if ($action === 'save_links') {
            $linkIds = $_POST['link_id'] ?? [];
            $labels = $_POST['label'] ?? [];
            $urls = $_POST['url'] ?? [];
            $starts = $_POST['starts_at'] ?? [];
            $delete = array_flip(array_map('intval', $_POST['delete_link'] ?? []));
            $seen = [];
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE date_redirect_links SET label = :label, url = :url, starts_at = :starts_at, sort_order = :sort_order WHERE id = :id AND redirector_id = :rid');
            $ins = $pdo->prepare('INSERT INTO date_redirect_links (redirector_id, label, url, starts_at, sort_order) VALUES (:rid, :label, :url, :starts_at, :sort_order)');
            $del = $pdo->prepare('DELETE FROM date_redirect_links WHERE id = :id AND redirector_id = :rid');
            foreach ($urls as $idx => $urlRaw) {
                $linkId = (int)($linkIds[$idx] ?? 0);
                if (isset($delete[$linkId]) && $linkId > 0) {
                    $del->execute(['id' => $linkId, 'rid' => $id]);
                    continue;
                }
                $url = trim((string)$urlRaw);
                $label = trim((string)($labels[$idx] ?? ''));
                $startsAtRaw = (string)($starts[$idx] ?? '');
                if ($url === '' && $startsAtRaw === '') continue;
                if (!date_redirects_valid_url($url)) throw new RuntimeException('URL invalida: ' . $url);
                $startsAt = date_redirects_parse_datetime($startsAtRaw);
                if ($label === '') $label = 'Link ' . ((int)$idx + 1);
                $params = [
                    'rid' => $id,
                    'label' => $label,
                    'url' => $url,
                    'starts_at' => $startsAt,
                    'sort_order' => (int)$idx + 1,
                ];
                if ($linkId > 0) {
                    $params['id'] = $linkId;
                    $upd->execute($params);
                    $seen[] = $linkId;
                } else {
                    $ins->execute($params);
                }
            }
            $pdo->commit();
            dr_redirect('id=' . $id . '&saved=1');
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

if (isset($_GET['created'])) $message = 'Redirecionador criado.';
if (isset($_GET['saved'])) $message = 'Alteracoes salvas.';
if (isset($_GET['cloned'])) $message = 'Redirecionador clonado.';
if (isset($_GET['deleted'])) $message = 'Redirecionador apagado.';
if (isset($_GET['status'])) $message = 'Status atualizado.';

$editId = (int)($_GET['id'] ?? 0);
$edit = null;
$links = [];
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM date_redirectors WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $st->execute(['id' => $editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($edit) {
        $st = $pdo->prepare("
            SELECT l.*, COALESCE(c.clicks, 0) clicks
              FROM date_redirect_links l
              LEFT JOIN (
                SELECT link_id, COUNT(*) clicks
                  FROM date_redirect_clicks
                 WHERE redirector_id = :rid AND link_id IS NOT NULL
                 GROUP BY link_id
              ) c ON c.link_id = l.id
             WHERE l.redirector_id = :rid
             ORDER BY l.starts_at ASC, l.sort_order ASC, l.id ASC
        ");
        $st->execute(['rid' => $editId]);
        $links = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$redirectors = $pdo->query("
    SELECT r.*,
           (SELECT COUNT(*) FROM date_redirect_links l WHERE l.redirector_id = r.id) link_count,
           (SELECT MIN(starts_at) FROM date_redirect_links l WHERE l.redirector_id = r.id) first_at,
           (SELECT MAX(starts_at) FROM date_redirect_links l WHERE l.redirector_id = r.id) last_at
      FROM date_redirectors r
     WHERE r.deleted_at IS NULL
     ORDER BY r.updated_at DESC, r.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$chartSeries = dr_click_chart_series($pdo, $edit ? (int)$edit['id'] : null, 365);

$menu = 'date_redirects';
$page_title = 'Redirecionadores por Data';
include __DIR__ . '/_header.php';
?>
<style>
.dr-shell{max-width:1120px;margin:0 auto}
.dr-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}
.dr-title h1{font-size:22px;line-height:1.1;margin:0;color:var(--text)}
.dr-title p{margin:6px 0 0;color:var(--muted);font-size:13px}
.dr-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dr-msg,.dr-error{border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:14px;background:var(--success-dim);color:var(--success);font-size:13px}
.dr-error{background:var(--danger-dim);color:var(--danger)}
.dr-card,.dr-editor{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:18px;box-shadow:var(--shadow);margin-bottom:14px}
.dr-shell input,.dr-shell select{height:36px;width:100%;background:#101827!important;color:var(--text)!important;border:1px solid var(--border-light)!important;border-radius:6px!important;padding:0 10px!important;outline:none;box-shadow:none!important}
.dr-shell input:focus{border-color:rgba(250,204,21,.55)!important;box-shadow:0 0 0 3px rgba(250,204,21,.09)!important}
.dr-shell input[type="checkbox"]{width:auto;height:auto;padding:0!important}
.dr-create{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end}
.dr-list{display:grid;gap:12px}
.dr-row{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:16px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px}
.dr-row-main{display:flex;align-items:center;gap:14px;min-width:0}
.dr-icon{width:40px;height:40px;border-radius:8px;background:var(--primary-dim);color:var(--primary);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.dr-icon svg{width:20px;height:20px}
.dr-name{font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dr-linkline{display:flex;gap:7px;align-items:center;color:var(--muted);font-size:12px;min-width:0;margin-top:3px}
.dr-linkline code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}
.dr-stat{font-size:12px;color:var(--muted);text-align:right;min-width:100px}
.dr-stat strong{display:block;color:var(--text);font-size:16px}
.dr-menu{position:relative}
.dr-menu-panel{display:none;position:absolute;right:0;top:calc(100% + 7px);z-index:20;width:220px;background:var(--bg-card);border:1px solid var(--border-light);border-radius:8px;padding:6px;box-shadow:var(--shadow-lg)}
.dr-menu.open .dr-menu-panel{display:block}
.dr-menu-panel a,.dr-menu-panel button{width:100%;display:flex;gap:8px;align-items:center;background:transparent;color:var(--text);padding:9px 10px;border-radius:6px;font-size:13px;text-decoration:none;text-align:left}
.dr-menu-panel a:hover,.dr-menu-panel button:hover{background:var(--bg-hover);text-decoration:none}
.dr-menu-panel form{margin:0}
.dr-danger{color:var(--danger)!important}
.dr-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:999px;padding:4px 9px;font-size:12px;color:var(--muted)}
.dr-pill.ok{color:var(--success);background:var(--success-dim);border-color:rgba(34,197,94,.25)}
.dr-pill.warn{color:var(--warning);background:var(--warning-dim);border-color:rgba(245,158,11,.25)}
.dr-copy{font-size:12px}
.dr-empty{color:var(--muted);text-align:center;padding:30px}
.dr-editor-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:14px}
.dr-editor-title{font-weight:800;color:var(--text);font-size:15px}
.dr-editor-sub{color:var(--muted);font-size:12px;margin-top:3px}
.dr-config{display:grid;grid-template-columns:minmax(0,1fr) 220px auto;gap:10px;align-items:end;border-bottom:1px solid var(--border);padding-bottom:16px;margin-bottom:16px}
.dr-field label{display:block;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.dr-link-row .dr-field label{display:none}
.dr-public-url{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:12px;min-width:0;margin-top:10px}
.dr-public-url code{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.dr-link-table{display:grid;gap:8px}
.dr-link-head,.dr-link-row{display:grid;grid-template-columns:54px minmax(220px,1fr) 210px 52px;gap:10px;align-items:center}
.dr-link-head{padding:0 8px 2px;color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.06em}
.dr-link-row{border:1px solid var(--border);border-radius:8px;padding:10px;background:rgba(255,255,255,.025)}
.dr-link-row.is-delete{opacity:.48;border-color:rgba(239,68,68,.35);background:var(--danger-dim)}
.dr-link-label{color:var(--muted);font-size:12px;text-align:center}
.dr-link-label strong{display:block;color:var(--text);font-size:12px}
.dr-trash{width:36px;height:36px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;background:var(--danger)!important;color:white!important;border:0!important;padding:0!important}
.dr-trash svg{width:16px;height:16px}
.dr-trash input{display:none}
.dr-trash:hover{filter:brightness(1.08)}
.dr-footer-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.dr-chart-card{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px;box-shadow:var(--shadow);margin-bottom:14px}
.dr-chart-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:10px}
.dr-chart-title{font-weight:800;color:var(--text);font-size:15px}
.dr-chart-sub{color:var(--muted);font-size:12px;margin-top:3px}
.dr-chart-controls{display:flex;align-items:center;gap:7px;flex-wrap:wrap;justify-content:flex-end}
.dr-seg{display:inline-flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#0b1322}
.dr-seg button{height:30px;padding:0 11px;border:0;border-right:1px solid var(--border);border-radius:0;background:transparent;color:var(--muted);font-size:11px;font-weight:700}
.dr-seg button:last-child{border-right:0}
.dr-seg button.active{background:var(--primary);color:#111827}
.dr-chart-box{height:285px;position:relative;min-width:0}
.dr-chart-empty{display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:var(--muted);font-size:13px;text-align:center}
.dr-chart-box.is-empty .dr-chart-empty{display:flex}
@media(max-width:900px){.dr-head,.dr-row{display:block}.dr-stat{text-align:left;margin:12px 0}.dr-create,.dr-config,.dr-link-head,.dr-link-row{grid-template-columns:1fr}.dr-link-head{display:none}.dr-editor-head{display:block}.dr-footer-actions{justify-content:flex-start}.dr-trash{width:100%}}
@media(max-width:700px){.dr-chart-head{display:block}.dr-chart-controls{justify-content:flex-start;margin-top:12px}.dr-chart-box{height:240px}.dr-seg button{padding:0 9px}}
</style>

<div class="dr-shell">
  <div class="dr-head">
    <div class="dr-title">
      <h1>Seus Redirecionadores por Data</h1>
      <p>Configure um link geral que muda o destino automaticamente conforme data e hora.</p>
    </div>
    <?php if(!$edit): ?>
    <form method="post" class="dr-actions">
      <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
      <input type="hidden" name="action" value="create">
      <input name="name" placeholder="Nome do novo redirecionador" required <?=$canWrite?'':'disabled'?>>
      <button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>+ Criar novo</button>
    </form>
    <?php else: ?>
    <a href="date_redirects.php" class="btn btn-ghost">Voltar para lista</a>
    <?php endif; ?>
  </div>

  <?php if($message): ?><div class="dr-msg"><?=date_redirects_h($message)?></div><?php endif; ?>
  <?php if($error): ?><div class="dr-error"><?=date_redirects_h($error)?></div><?php endif; ?>

  <section class="dr-chart-card">
    <div class="dr-chart-head">
      <div>
        <div class="dr-chart-title">Cliques por data</div>
        <div class="dr-chart-sub"><?= $edit ? 'Historico deste redirecionador.' : 'Historico consolidado de todos os redirecionadores.' ?></div>
      </div>
      <div class="dr-chart-controls" aria-label="Filtros do grafico de cliques">
        <div class="dr-seg" data-dr-chart-window>
          <button type="button" data-days="7">7d</button>
          <button type="button" data-days="30">30d</button>
          <button type="button" data-days="90" class="active">90d</button>
          <button type="button" data-days="365">365d</button>
        </div>
        <div class="dr-seg" data-dr-chart-group>
          <button type="button" data-group="day" class="active">Dia</button>
          <button type="button" data-group="week">Semana</button>
          <button type="button" data-group="month">Mes</button>
        </div>
      </div>
    </div>
    <div class="dr-chart-box" id="drClicksChartBox">
      <canvas id="drClicksChart"></canvas>
      <div class="dr-chart-empty">Nenhum clique registrado no periodo selecionado.</div>
    </div>
  </section>

  <?php if(!$edit): ?>
    <div class="dr-list">
      <?php foreach($redirectors as $r): $publicUrl = date_redirects_public_url((string)$r['slug']); ?>
      <div class="dr-row">
        <div class="dr-row-main">
          <div class="dr-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 3L4 14h7l-1 7 9-11h-7l1-7z"/></svg>
          </div>
          <div style="min-width:0">
            <div class="dr-name"><?=date_redirects_h($r['name'])?> <span class="dr-pill <?=(string)$r['status']==='active'?'ok':'warn'?>"><?=(string)$r['status']==='active'?'Ativo':'Pausado'?></span></div>
            <div class="dr-linkline">
              <button type="button" class="btn btn-ghost btn-xs dr-copy" data-copy="<?=date_redirects_h($publicUrl)?>">Copiar</button>
              <code><?=date_redirects_h($publicUrl)?></code>
            </div>
          </div>
        </div>
        <div class="dr-stat">Total de cliques <strong><?=(int)$r['clicks_total']?></strong></div>
        <div class="dr-menu">
          <button type="button" class="btn btn-ghost dr-menu-btn">Acoes</button>
          <div class="dr-menu-panel">
            <a href="date_redirects.php?id=<?=(int)$r['id']?>">Editar redirecionador</a>
            <button type="button" data-copy="<?=date_redirects_h($publicUrl)?>">Copiar link geral</button>
            <a href="<?=date_redirects_h($publicUrl)?>" target="_blank">Abrir link</a>
            <form method="post">
              <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button name="action" value="toggle" <?=$canWrite?'':'disabled'?>><?=(string)$r['status']==='active'?'Pausar':'Ativar'?> redirecionador</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button name="action" value="clone" <?=$canWrite?'':'disabled'?>>Clonar redirecionador</button>
            </form>
            <form method="post" onsubmit="return confirm('Apagar este redirecionador?')">
              <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
              <input type="hidden" name="id" value="<?=(int)$r['id']?>">
              <button class="dr-danger" name="action" value="delete" <?=$canWrite?'':'disabled'?>>Apagar redirecionador</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$redirectors): ?><div class="dr-card dr-empty">Nenhum redirecionador criado.</div><?php endif; ?>
    </div>
  <?php else: $publicUrl = date_redirects_public_url((string)$edit['slug']); ?>
    <section class="dr-editor">
      <div class="dr-editor-head">
        <div>
          <div class="dr-editor-title">Editar Redirecionador <?=date_redirects_h((string)$edit['name'])?></div>
          <div class="dr-editor-sub">
            <span class="dr-pill <?=(string)$edit['status']==='active'?'ok':'warn'?>"><?=(string)$edit['status']==='active'?'Ativo':'Pausado'?></span>
            <span class="dr-pill"><?=(int)$edit['clicks_total']?> cliques</span>
            <span class="dr-pill"><?=count($links)?> links</span>
          </div>
        </div>
        <form method="post" class="dr-actions">
          <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
          <input type="hidden" name="id" value="<?=(int)$edit['id']?>">
          <button class="btn btn-ghost" name="action" value="toggle" <?=$canWrite?'':'disabled'?>><?=(string)$edit['status']==='active'?'Pausar':'Ativar'?></button>
          <a href="date_redirects.php" class="btn btn-ghost">Voltar para lista</a>
        </form>
      </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
          <input type="hidden" name="action" value="update_redirector">
          <input type="hidden" name="id" value="<?=(int)$edit['id']?>">
          <div class="dr-config">
            <div class="dr-field">
              <label>Nome</label>
              <input name="name" value="<?=date_redirects_h($edit['name'])?>" required <?=$canWrite?'':'disabled'?>>
            </div>
            <div class="dr-field">
              <label>Slug do link</label>
              <input name="slug" value="<?=date_redirects_h($edit['slug'])?>" required <?=$canWrite?'':'disabled'?>>
            </div>
            <button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>Salvar</button>
          </div>
          <div class="dr-actions">
            <button type="button" class="btn btn-primary" id="addDateLink" <?=$canWrite?'':'disabled'?>>+ Adicionar mais um link</button>
            <button type="button" class="btn btn-ghost" data-copy="<?=date_redirects_h($publicUrl)?>">Copiar link</button>
            <a class="btn btn-ghost" href="<?=date_redirects_h($publicUrl)?>" target="_blank">Testar</a>
          </div>
          <div class="dr-public-url"><span>Link geral</span><code><?=date_redirects_h($publicUrl)?></code></div>
        </form>

        <form method="post" id="linksForm" style="margin-top:16px">
          <input type="hidden" name="csrf" value="<?=date_redirects_h($csrf)?>">
          <input type="hidden" name="action" value="save_links">
          <input type="hidden" name="id" value="<?=(int)$edit['id']?>">
          <div class="dr-link-table">
            <div class="dr-link-head"><span>Link</span><span>URL</span><span>A partir de</span><span>Excluir</span></div>
            <div id="dateLinks">
            <?php foreach($links as $link): ?>
            <div class="dr-link-row">
              <input type="hidden" name="link_id[]" value="<?=(int)$link['id']?>">
              <div class="dr-link-label">
                <strong><?=date_redirects_h($link['label'])?></strong>
                <?=(int)$link['clicks']?> cliques
              </div>
                <div class="dr-field">
                  <label><?=date_redirects_h($link['label'])?> · <?=(int)$link['clicks']?> cliques</label>
                  <input name="url[]" value="<?=date_redirects_h($link['url'])?>" required <?=$canWrite?'':'disabled'?>>
                  <input type="hidden" name="label[]" value="<?=date_redirects_h($link['label'])?>">
                </div>
                <div class="dr-field">
                  <label>Data e hora</label>
                  <input type="datetime-local" name="starts_at[]" value="<?=date_redirects_h(dr_datetime_input((string)$link['starts_at']))?>" required <?=$canWrite?'':'disabled'?>>
                </div>
                <label class="dr-trash" title="Apagar link">
                  <input type="checkbox" name="delete_link[]" value="<?=(int)$link['id']?>" <?=$canWrite?'':'disabled'?>>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
                  </svg>
                </label>
            </div>
            <?php endforeach; ?>
          </div>
          </div>
          <div class="dr-footer-actions">
            <button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>Salvar links</button>
          </div>
        </form>
      </section>
  <?php endif; ?>
</div>

<template id="dateLinkTemplate">
  <div class="dr-link-row">
    <input type="hidden" name="link_id[]" value="0">
    <div class="dr-link-label"><strong>Novo</strong> 0 cliques</div>
    <div class="dr-field">
      <input name="url[]" placeholder="https://..." required>
      <input type="hidden" name="label[]" value="Novo link">
    </div>
    <div class="dr-field">
      <input type="datetime-local" name="starts_at[]" required>
    </div>
    <span></span>
  </div>
</template>

<script>
document.addEventListener('click', async function(e) {
  const menuBtn = e.target.closest('.dr-menu-btn');
  document.querySelectorAll('.dr-menu.open').forEach(m => { if (!menuBtn || !m.contains(menuBtn)) m.classList.remove('open'); });
  if (menuBtn) {
    menuBtn.closest('.dr-menu').classList.toggle('open');
    return;
  }
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    try {
      await navigator.clipboard.writeText(copy.getAttribute('data-copy'));
      const old = copy.textContent;
      copy.textContent = 'Copiado';
      setTimeout(() => copy.textContent = old, 1200);
    } catch (err) {
      prompt('Copie o link:', copy.getAttribute('data-copy'));
    }
  }
  const trash = e.target.closest('.dr-trash');
  if (trash) {
    setTimeout(function() {
      const row = trash.closest('.dr-link-row');
      const input = trash.querySelector('input[type="checkbox"]');
      if (row && input) row.classList.toggle('is-delete', input.checked);
    }, 0);
  }
});
const addDateLink = document.getElementById('addDateLink');
if (addDateLink) {
  addDateLink.addEventListener('click', function() {
    const tpl = document.getElementById('dateLinkTemplate');
    document.getElementById('dateLinks').appendChild(tpl.content.cloneNode(true));
  });
}

(function() {
  const rawRows = <?= json_encode($chartSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const canvas = document.getElementById('drClicksChart');
  const box = document.getElementById('drClicksChartBox');
  if (!canvas || !window.Chart) return;

  const state = { days: 90, group: 'day' };
  const fmt = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' });
  const fmtMonth = new Intl.DateTimeFormat('pt-BR', { month: '2-digit', year: '2-digit' });
  const parseDate = value => {
    const parts = String(value).split('-').map(Number);
    return new Date(parts[0], (parts[1] || 1) - 1, parts[2] || 1);
  };
  const isoDate = date => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  };
  const startOfWeek = date => {
    const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const day = copy.getDay() || 7;
    copy.setDate(copy.getDate() - day + 1);
    return copy;
  };
  const bucketFor = row => {
    const date = parseDate(row.date);
    if (state.group === 'week') {
      const start = startOfWeek(date);
      return { key: isoDate(start), label: fmt.format(start) };
    }
    if (state.group === 'month') {
      const start = new Date(date.getFullYear(), date.getMonth(), 1);
      return { key: isoDate(start), label: fmtMonth.format(start) };
    }
    return { key: row.date, label: row.label };
  };
  const visibleRows = () => {
    const end = rawRows.length ? parseDate(rawRows[rawRows.length - 1].date) : new Date();
    const start = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    start.setDate(start.getDate() - state.days + 1);
    return rawRows.filter(row => parseDate(row.date) >= start);
  };
  const aggregateRows = () => {
    const buckets = new Map();
    visibleRows().forEach(row => {
      const bucket = bucketFor(row);
      const current = buckets.get(bucket.key) || { label: bucket.label, clicks: 0 };
      current.clicks += Number(row.clicks || 0);
      buckets.set(bucket.key, current);
    });
    return Array.from(buckets.values());
  };

  const chart = new Chart(canvas, {
    type: 'bar',
    data: { labels: [], datasets: [{ label: 'Cliques', data: [], backgroundColor: '#facc15', borderRadius: 6, maxBarThickness: 48 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => 'Cliques: ' + Number(ctx.raw || 0).toLocaleString('pt-BR')
          }
        }
      },
      scales: {
        x: { ticks: { color: '#94a3b8', maxRotation: 0, autoSkip: true }, grid: { color: 'rgba(255,255,255,.04)' } },
        y: { beginAtZero: true, ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(255,255,255,.06)' } }
      }
    }
  });

  function renderChart() {
    const rows = aggregateRows();
    const total = rows.reduce((sum, row) => sum + row.clicks, 0);
    box.classList.toggle('is-empty', total === 0);
    chart.data.labels = rows.map(row => row.label);
    chart.data.datasets[0].data = rows.map(row => row.clicks);
    chart.update();
  }

  document.querySelectorAll('[data-dr-chart-window] button').forEach(btn => {
    btn.addEventListener('click', () => {
      state.days = Number(btn.dataset.days || 90);
      btn.parentElement.querySelectorAll('button').forEach(item => item.classList.toggle('active', item === btn));
      renderChart();
    });
  });
  document.querySelectorAll('[data-dr-chart-group] button').forEach(btn => {
    btn.addEventListener('click', () => {
      state.group = btn.dataset.group || 'day';
      btn.parentElement.querySelectorAll('button').forEach(item => item.classList.toggle('active', item === btn));
      renderChart();
    });
  });
  renderChart();
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
