<?php
// FILE: admin/logs.php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

$menu = 'logs';
$page_title = 'Logs';
$page_subtitle = 'Acompanhamento de webhooks e eventos';

include __DIR__ . '/_header.php';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dt_br(?string $dt): string {
    if (!$dt) return '';
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return (string)$dt;
    }
}

// -------------------------
// Filtros
// -------------------------
$evento = trim((string)($_GET['evento'] ?? ''));
$de     = trim((string)($_GET['de'] ?? ''));    // YYYY-MM-DD
$ate    = trim((string)($_GET['ate'] ?? ''));   // YYYY-MM-DD
$status = trim((string)($_GET['status'] ?? '')); // '', 'ok', 'erro'
$limit  = (int)($_GET['limit'] ?? 200);
if ($limit < 50) $limit = 50;
if ($limit > 2000) $limit = 2000;

$where = [];
$params = [];

if ($evento !== '') {
    $where[] = "wl.evento LIKE :evento";
    $params[':evento'] = '%' . $evento . '%';
}

if ($de !== '') {
    $where[] = "wl.created_at >= :de";
    $params[':de'] = $de . " 00:00:00";
}
if ($ate !== '') {
    $where[] = "wl.created_at <= :ate";
    $params[':ate'] = $ate . " 23:59:59";
}

if ($status === 'ok') {
    $where[] = "(wl.response_status >= 200 AND wl.response_status < 300 AND (wl.error_message IS NULL OR wl.error_message = ''))";
} elseif ($status === 'erro') {
    $where[] = "((wl.response_status IS NULL) OR (wl.response_status < 200) OR (wl.response_status >= 300) OR (wl.error_message IS NOT NULL AND wl.error_message <> ''))";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// -------------------------
// Lista principal
// -------------------------
$sqlWh = "
    SELECT
        wl.*,
        u.nome      AS user_nome,
        u.email     AS user_email,
        u.telefone  AS user_telefone,
        w.nome      AS webhook_nome,
        w.url       AS webhook_url
    FROM webhook_logs wl
    LEFT JOIN users u ON u.id = wl.user_id
    LEFT JOIN webhooks w ON w.id = wl.webhook_id
    $whereSql
    ORDER BY wl.id DESC
    LIMIT :lim
";
$stWh  = $pdo->prepare($sqlWh);
foreach ($params as $k => $v) {
    $stWh->bindValue($k, $v);
}
$stWh->bindValue(':lim', $limit, PDO::PARAM_INT);
$stWh->execute();
$whLogs = $stWh->fetchAll(PDO::FETCH_ASSOC) ?: [];

// -------------------------
// Helpers de linha (andamento)
// -------------------------
function extrair_andamento(?string $payloadJson): string {
    if (!$payloadJson) return '-';
    $arr = json_decode($payloadJson, true);
    if (!is_array($arr)) return '-';

    $keys = ['andamento','progress','porcentagem','percent','percentual','pct','concluido','conclusao'];
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr)) {
            $v = $arr[$k];
            if (is_numeric($v)) {
                $n = (float)$v;
                if ($n <= 1 && $n > 0) $n = $n * 100;
                $n = (int)round($n);
                return $n . '%';
            }
            if (is_string($v) && $v !== '') return $v;
        }
    }
    return '-';
}
?>

<style>
/* ajustes leves só desta tela */
.logs-filters{
  display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
}
.logs-filters .field{display:flex;flex-direction:column;gap:4px;}
.logs-filters label{font-size:12px;opacity:.75;}
.logs-filters input, .logs-filters select{
  padding:6px 10px; border-radius:10px; border:1px solid #1f2937; background:transparent; color:inherit;
}
.btn-small{
  padding:7px 12px; border-radius:999px; border:none; cursor:pointer; font-weight:700;
}
.btn-outline{
  background:transparent; border:1px solid #1f2937;
}
.badge{
  display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; border:1px solid #1f2937;
}
.badge.ok{ border-color: rgba(34,197,94,.6); color:#bbf7d0; background: rgba(34,197,94,.12); }
.badge.err{ border-color: rgba(239,68,68,.6); color:#fecaca; background: rgba(239,68,68,.12); }
.muted{opacity:.75; font-size:12px;}
.small{font-size:12px;}
.table-wrap{overflow:auto;}
/* modal */
.modal-backdrop{
  position:fixed; inset:0; background:rgba(0,0,0,.65); display:none; align-items:center; justify-content:center; padding:16px; z-index:9999;
}
.modal{
  width:min(980px, 96vw); max-height: 90vh; overflow:auto;
  background: #0b1220; border:1px solid #1f2937; border-radius:14px; padding:14px;
}
.modal h3{margin:0 0 10px;}
.modal pre{
  white-space:pre-wrap; word-break:break-word;
  background: rgba(2,6,23,.75); border:1px solid #1f2937;
  padding:10px; border-radius:12px; font-size:12px; line-height:1.35;
}
.modal .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;}
.modal .close{cursor:pointer; border:1px solid #1f2937; background:transparent; color:inherit; border-radius:999px; padding:6px 10px;}
</style>

<div class="card">
  <div class="topbar" style="margin-bottom:10px;">
    <div>
      <div style="font-size:18px;font-weight:800;">Logs</div>
      <div class="muted">Eventos e disparos de webhooks (com filtro por data/status/evento)</div>
    </div>
  </div>

  <form method="get" class="logs-filters">
    <div class="field">
      <label>Evento</label>
      <input type="text" name="evento" value="<?= h($evento) ?>" placeholder="INSCRITO, LIVE_TURMA_...">
    </div>
    <div class="field">
      <label>De (data)</label>
      <input type="date" name="de" value="<?= h($de) ?>">
    </div>
    <div class="field">
      <label>Até (data)</label>
      <input type="date" name="ate" value="<?= h($ate) ?>">
    </div>
    <div class="field">
      <label>Status</label>
      <select name="status">
        <option value="" <?= $status===''?'selected':'' ?>>Todos</option>
        <option value="ok" <?= $status==='ok'?'selected':'' ?>>Sucesso</option>
        <option value="erro" <?= $status==='erro'?'selected':'' ?>>Erro</option>
      </select>
    </div>
    <div class="field">
      <label>Quantidade</label>
      <select name="limit">
        <?php foreach ([50,100,200,500,1000,2000] as $n): ?>
          <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn-small" type="submit">Filtrar</button>
    <a class="btn-small btn-outline" href="logs.php">Limpar</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Hora</th>
        <th>Evento</th>
        <th>Aluno</th>
        <th>Andamento</th>
        <th>Status</th>
        <th>Detalhes</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$whLogs): ?>
        <tr><td colspan="6" class="muted">Nenhum registro encontrado.</td></tr>
      <?php else: ?>
        <?php foreach ($whLogs as $w): ?>
          <?php
            $dt = (string)($w['created_at'] ?? '');
            $dtFmt = dt_br($dt);
            $dataPart = $dtFmt ? substr($dtFmt, 0, 10) : '';
            $horaPart = $dtFmt ? substr($dtFmt, 11) : '';

            $nome = trim((string)($w['user_nome'] ?? ''));
            $email = trim((string)($w['user_email'] ?? ''));
            $tel = trim((string)($w['user_telefone'] ?? ''));
            $userId = (int)($w['user_id'] ?? 0);

            $andamento = extrair_andamento((string)($w['payload_json'] ?? ''));
            $statusCode = $w['response_status'];
            $isOk = is_numeric($statusCode) && (int)$statusCode >= 200 && (int)$statusCode < 300 && empty($w['error_message']);
            $badgeCls = $isOk ? 'badge ok' : 'badge err';
            $badgeTxt = $statusCode !== null && $statusCode !== '' ? (string)$statusCode : '—';

            $webhookUrl = trim((string)($w['webhook_url'] ?? ''));
            $webhookNome = trim((string)($w['webhook_nome'] ?? ''));

            $id = (int)($w['id'] ?? 0);
          ?>
          <tr>
            <td style="white-space:nowrap;">
              <div><?= h($dataPart) ?></div>
              <div class="muted"><?= h($horaPart) ?></div>
            </td>

            <td>
              <div style="font-weight:800;"><?= h((string)($w['evento'] ?? '')) ?></div>
              <?php if ($webhookUrl !== ''): ?>
                <div class="muted small" style="max-width:520px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= $webhookNome ? '<b>'.h($webhookNome).'</b> — ' : '' ?>
                  <?= h($webhookUrl) ?>
                </div>
              <?php endif; ?>
            </td>

            <td style="min-width:260px;">
              <?php if ($nome !== ''): ?>
                <div style="font-weight:800;"><?= h($nome) ?></div>
                <div class="muted small"><?= h($email) ?><?= $tel ? ' ' . h($tel) : '' ?></div>
              <?php else: ?>
                <div style="font-weight:800;">ID <?= (int)$userId ?></div>
              <?php endif; ?>
            </td>

            <td><?= h($andamento) ?></td>

            <td><span class="<?= h($badgeCls) ?>"><?= h($badgeTxt) ?></span></td>

            <td>
              <button type="button" class="btn-small btn-outline" data-open-modal="<?= $id ?>">Ver</button>

              <script type="application/json" id="payload-<?= $id ?>"><?= json_encode([
                'id' => $id,
                'created_at' => (string)($w['created_at'] ?? ''),
                'evento' => (string)($w['evento'] ?? ''),
                'user_id' => (int)($w['user_id'] ?? 0),
                'webhook_id' => (int)($w['webhook_id'] ?? 0),
                'webhook_nome' => $webhookNome,
                'webhook_url' => $webhookUrl,
                'response_status' => $w['response_status'],
                'error_message' => (string)($w['error_message'] ?? ''),
                'payload_json' => (string)($w['payload_json'] ?? ''),
                'response_body' => (string)($w['response_body'] ?? ''),
              ], JSON_UNESCAPED_UNICODE) ?></script>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop" id="modalBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="row">
      <h3 id="modalTitle">Detalhes</h3>
      <button class="close" type="button" id="modalClose">Fechar</button>
    </div>

    <div class="card" style="margin:10px 0 12px;">
      <div class="muted" id="modalMeta"></div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 1fr; gap:12px;">
      <div>
        <div class="muted" style="margin-bottom:6px;"><b>Payload</b></div>
        <pre id="modalPayload"></pre>
      </div>
      <div>
        <div class="muted" style="margin-bottom:6px;"><b>Resposta</b></div>
        <pre id="modalResponse"></pre>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const backdrop = document.getElementById('modalBackdrop');
  const closeBtn = document.getElementById('modalClose');
  const metaEl = document.getElementById('modalMeta');
  const payloadEl = document.getElementById('modalPayload');
  const respEl = document.getElementById('modalResponse');
  const titleEl = document.getElementById('modalTitle');

  function openModal(data){
    titleEl.textContent = 'Detalhes do Log #' + (data.id ?? '');
    const meta = [
      'Evento: ' + (data.evento ?? ''),
      'Data: ' + (data.created_at ?? ''),
      'User ID: ' + (data.user_id ?? ''),
      'Webhook: ' + (data.webhook_nome ? (data.webhook_nome + ' — ') : '') + (data.webhook_url ?? ''),
      'Status: ' + (data.response_status ?? '—'),
      (data.error_message ? ('Erro: ' + data.error_message) : '')
    ].filter(Boolean).join(' | ');
    metaEl.textContent = meta;

    try {
      const pj = data.payload_json ? JSON.parse(data.payload_json) : null;
      payloadEl.textContent = pj ? JSON.stringify(pj, null, 2) : (data.payload_json || '');
    } catch(e){
      payloadEl.textContent = data.payload_json || '';
    }
    respEl.textContent = data.response_body || '';

    backdrop.style.display = 'flex';
    backdrop.setAttribute('aria-hidden', 'false');
  }

  function closeModal(){
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');
    payloadEl.textContent = '';
    respEl.textContent = '';
    metaEl.textContent = '';
  }

  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('[data-open-modal]');
    if (!btn) return;
    const id = btn.getAttribute('data-open-modal');
    const script = document.getElementById('payload-' + id);
    if (!script) return;
    try {
      const data = JSON.parse(script.textContent || '{}');
      openModal(data);
    } catch(e) {}
  });

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function(ev){
    if (ev.target === backdrop) closeModal();
  });
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') closeModal();
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
