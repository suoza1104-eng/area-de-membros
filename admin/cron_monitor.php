<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/cron_manager.php';
proteger_admin();

$menu = 'cron_monitor';
$page_title = 'Monitor de Cron';
$page_subtitle = 'Configuração, redundância e acompanhamento dos agendadores';
$pdo = getPDO();
cron_manager_ensure_tables($pdo);

function cm_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cm_can_access(): bool {
    if (($_SESSION['admin_tipo'] ?? '') !== 'equipe') return true;
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    return !empty($perms['cron_monitor']['acesso']) || !empty($perms['logs']['acesso']);
}

function cm_can_write(): bool {
    if (($_SESSION['admin_tipo'] ?? '') !== 'equipe') return true;
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    return !empty($perms['cron_monitor']['escrever']) || !empty($perms['logs']['escrever']);
}

function cm_payload(PDO $pdo): array {
    $tasks = cron_manager_tasks($pdo);
    $heartbeats = cron_manager_heartbeats($pdo);
    $now = time();

    foreach ($tasks as &$task) {
        $runningUntil = !empty($task['running_until']) ? strtotime((string)$task['running_until']) : false;
        $lastSuccess = !empty($task['last_success_at']) ? strtotime((string)$task['last_success_at']) : false;
        $healthySeconds = (max(1, (int)$task['interval_minutes']) + max(2, (int)$task['fallback_after_minutes'])) * 60;
        if (empty($task['enabled']) || (string)$task['mode'] === 'disabled') {
            $task['health'] = 'disabled';
            $task['health_label'] = 'Desativado';
        } elseif ($runningUntil && $runningUntil > $now) {
            $task['health'] = 'running';
            $task['health_label'] = 'Executando';
        } elseif ((string)$task['last_status'] === 'error') {
            $task['health'] = 'error';
            $task['health_label'] = 'Erro';
        } elseif ($lastSuccess && $lastSuccess >= $now - $healthySeconds) {
            $task['health'] = 'online';
            $task['health_label'] = 'Saudável';
        } elseif (!$lastSuccess) {
            $task['health'] = 'waiting';
            $task['health_label'] = 'Aguardando';
        } else {
            $task['health'] = 'late';
            $task['health_label'] = 'Atrasado';
        }
    }
    unset($task);

    foreach ($heartbeats as &$heartbeat) {
        $seen = strtotime((string)$heartbeat['last_seen_at']) ?: 0;
        $heartbeat['seconds_ago'] = max(0, $now - $seen);
        $heartbeat['online'] = $seen >= $now - 150;
    }
    unset($heartbeat);

    return [
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'tasks' => $tasks,
        'heartbeats' => $heartbeats,
        'runs' => cron_manager_runs($pdo, 100),
    ];
}

if (!cm_can_access()) {
    if (isset($_GET['ajax'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'sem_acesso'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . BASE_URL_ADMIN . '/index.php?sem_acesso=1');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!cm_can_write()) {
        header('Location: cron_monitor.php?error=' . urlencode('Sem permissão para alterar o cron.'));
        exit;
    }
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_task') {
            $taskKey = trim((string)($_POST['task_key'] ?? ''));
            cron_manager_save_task($pdo, $taskKey, $_POST);
            header('Location: cron_monitor.php?saved=1');
            exit;
        }
        if ($action === 'run_now') {
            $taskKey = trim((string)($_POST['task_key'] ?? ''));
            $result = cron_manager_execute($pdo, $taskKey, 'manual', true);
            $message = !empty($result['ok'])
                ? 'Rotina executada com sucesso.'
                : 'A rotina terminou com erro: ' . (string)($result['error'] ?? 'erro desconhecido');
            header('Location: cron_monitor.php?notice=' . urlencode($message));
            exit;
        }
        if ($action === 'rotate_token') {
            cron_manager_rotate_token($pdo);
            header('Location: cron_monitor.php?notice=' . urlencode('Token externo atualizado. Atualize o VPS e o cPanel.'));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: cron_monitor.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(cm_payload($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$token = cron_manager_token($pdo);
$endpoint = rtrim(BASE_URL, '/') . '/cron_dispatcher.php';
$definitions = cron_manager_definitions();
$initial = cm_payload($pdo);
$notice = trim((string)($_GET['notice'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
if (isset($_GET['saved'])) $notice = 'Configuração da rotina salva.';

require __DIR__ . '/_header.php';
?>

<style>
.cm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
.cm-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}
.cm-agent-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px}
.cm-agent{display:flex;align-items:center;justify-content:space-between;gap:12px}
.cm-agent-name{font-weight:800;text-transform:uppercase;font-size:13px}
.cm-help{font-size:11px;color:var(--muted);line-height:1.5}
.cm-status{display:inline-flex;align-items:center;gap:7px;padding:4px 9px;border:1px solid;border-radius:999px;font-size:11px;font-weight:800}
.cm-status:before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor}
.cm-status.online{color:#86efac;border-color:rgba(34,197,94,.28);background:rgba(34,197,94,.10)}
.cm-status.running{color:#93c5fd;border-color:rgba(56,189,248,.28);background:rgba(56,189,248,.10)}
.cm-status.late,.cm-status.waiting{color:#fde68a;border-color:rgba(245,158,11,.28);background:rgba(245,158,11,.10)}
.cm-status.error{color:#fca5a5;border-color:rgba(239,68,68,.28);background:rgba(239,68,68,.10)}
.cm-status.disabled{color:#94a3b8;border-color:rgba(148,163,184,.22);background:rgba(148,163,184,.08)}
.cm-task-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.cm-task-title{font-size:15px;font-weight:800}.cm-task-key{font:10px monospace;color:var(--muted)}
.cm-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.cm-field label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px}
.cm-field input,.cm-field select{width:100%;background:#07101f;border:1px solid var(--border-light);color:var(--text);border-radius:8px;padding:8px}
.cm-actions{display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap}
.cm-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.cm-meta b{display:block;font-size:11px;color:var(--text);margin-top:2px;word-break:break-word}
.cm-table{width:100%;border-collapse:collapse;font-size:11px}
.cm-table th,.cm-table td{padding:9px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
.cm-table th{position:sticky;top:0;background:var(--bg-card);color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.05em}
.cm-code{background:#050b15;border:1px solid var(--border);border-radius:8px;padding:10px;font:11px monospace;color:#93c5fd;overflow:auto;white-space:pre-wrap;word-break:break-all}
.cm-secret{filter:blur(5px);transition:.15s}.cm-secret:hover,.cm-secret:focus{filter:none}
.cm-notice{padding:11px 13px;border-radius:9px;margin-bottom:14px;font-size:12px}
.cm-notice.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#86efac}
.cm-notice.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
@media(max-width:900px){.cm-grid,.cm-agent-grid{grid-template-columns:1fr}}
@media(max-width:560px){.cm-fields,.cm-meta{grid-template-columns:1fr}}
</style>

<?php if ($notice !== ''): ?><div class="cm-notice ok"><?= cm_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cm-notice err"><?= cm_h($error) ?></div><?php endif; ?>

<div class="cm-agent-grid">
    <?php foreach (['vps' => 'Agente VPS', 'hosting' => 'Cron da hospedagem'] as $source => $label):
        $heartbeat = $initial['heartbeats'][$source] ?? null;
        $online = $heartbeat && !empty($heartbeat['online']);
    ?>
    <div class="cm-card cm-agent">
        <div>
            <div class="cm-agent-name"><?= cm_h($label) ?></div>
            <div class="cm-help" id="agent-<?= cm_h($source) ?>-detail">
                <?= $heartbeat ? 'Último contato: ' . cm_h((string)$heartbeat['last_seen_at']) : 'Nenhum contato recebido' ?>
            </div>
        </div>
        <span id="agent-<?= cm_h($source) ?>-status" class="cm-status <?= $online ? 'online' : 'error' ?>">
            <?= $online ? 'Online' : 'Offline' ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<div class="cm-card" style="margin-bottom:16px">
    <div class="cm-task-head">
        <div>
            <div class="cm-task-title">Conexão dos agentes</div>
            <div class="cm-help">O token é sigiloso. Passe o mouse para revelar e atualize ambos os agentes após gerar um novo.</div>
        </div>
        <?php if (cm_can_write()): ?>
        <form method="post" onsubmit="return confirm('Gerar um novo token? Os agentes atuais deixarão de funcionar até serem atualizados.')">
            <input type="hidden" name="action" value="rotate_token">
            <button class="btn btn-ghost btn-sm" type="submit">Gerar novo token</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="cm-help">Endpoint</div>
    <div class="cm-code"><?= cm_h($endpoint) ?></div>
    <div class="cm-help" style="margin-top:8px">Token</div>
    <div class="cm-code cm-secret" tabindex="0"><?= cm_h($token) ?></div>
    <div class="cm-help" style="margin-top:8px">Comando único para substituir os cron jobs da hospedagem</div>
    <div class="cm-code">* * * * * for task in whatsapp_ai reagendamentos_live lives_turma agendamentos_retorno; do /usr/bin/curl -fsS -H "X-Cron-Token: <?= cm_h($token) ?>" --data "source=hosting&amp;task=$task" "<?= cm_h($endpoint) ?>" &gt;/dev/null 2&gt;&amp;1 &amp; done; wait</div>
</div>

<div class="cm-grid" id="cm-tasks">
<?php foreach ($initial['tasks'] as $task): ?>
    <div class="cm-card" data-task-card="<?= cm_h((string)$task['task_key']) ?>">
        <div class="cm-task-head">
            <div>
                <div class="cm-task-title"><?= cm_h((string)$task['label']) ?></div>
                <div class="cm-task-key"><?= cm_h((string)$task['task_key']) ?></div>
                <div class="cm-help"><?= cm_h((string)$task['description']) ?></div>
            </div>
            <span class="cm-status <?= cm_h((string)$task['health']) ?>" data-task-status><?= cm_h((string)$task['health_label']) ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="task_key" value="<?= cm_h((string)$task['task_key']) ?>">
            <div class="cm-fields">
                <div class="cm-field">
                    <label>Modo</label>
                    <select name="mode" <?= !cm_can_write() ? 'disabled' : '' ?>>
                        <option value="disabled" <?= $task['mode']==='disabled'?'selected':'' ?>>Desativado</option>
                        <option value="vps" <?= $task['mode']==='vps'?'selected':'' ?>>Somente VPS</option>
                        <option value="hosting" <?= $task['mode']==='hosting'?'selected':'' ?>>Somente hospedagem</option>
                        <option value="redundant" <?= $task['mode']==='redundant'?'selected':'' ?>>Redundância</option>
                    </select>
                </div>
                <div class="cm-field">
                    <label>Principal na redundância</label>
                    <select name="primary_source" <?= !cm_can_write() ? 'disabled' : '' ?>>
                        <option value="vps" <?= $task['primary_source']==='vps'?'selected':'' ?>>VPS</option>
                        <option value="hosting" <?= $task['primary_source']==='hosting'?'selected':'' ?>>Hospedagem</option>
                    </select>
                </div>
                <div class="cm-field">
                    <label>Intervalo (min)</label>
                    <input type="number" min="1" max="1440" name="interval_minutes" value="<?= (int)$task['interval_minutes'] ?>" <?= !cm_can_write() ? 'disabled' : '' ?>>
                </div>
                <div class="cm-field">
                    <label>Fallback após (min)</label>
                    <input type="number" min="1" max="1440" name="fallback_after_minutes" value="<?= (int)$task['fallback_after_minutes'] ?>" <?= !cm_can_write() ? 'disabled' : '' ?>>
                </div>
            </div>
            <?php if (cm_can_write()): ?>
            <div class="cm-actions">
                <button class="btn btn-primary btn-sm" type="submit">Salvar</button>
            </div>
            <?php endif; ?>
        </form>
        <?php if (cm_can_write()): ?>
        <form method="post" class="cm-actions" onsubmit="return confirm('Executar esta rotina agora?')">
            <input type="hidden" name="action" value="run_now">
            <input type="hidden" name="task_key" value="<?= cm_h((string)$task['task_key']) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Executar agora</button>
        </form>
        <?php endif; ?>
        <div class="cm-meta">
            <div class="cm-help">Último sucesso<b data-last-success><?= cm_h((string)($task['last_success_at'] ?: 'Nunca')) ?></b></div>
            <div class="cm-help">Última origem<b data-last-source><?= cm_h((string)($task['last_source'] ?: '—')) ?></b></div>
            <div class="cm-help">Duração<b data-last-duration><?= $task['last_duration_ms'] !== null ? (int)$task['last_duration_ms'] . ' ms' : '—' ?></b></div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="cm-card">
    <div class="cm-task-head">
        <div>
            <div class="cm-task-title">Histórico de execuções</div>
            <div class="cm-help">Atualização automática a cada 10 segundos. O secundário aparece como fallback quando assume uma rotina.</div>
        </div>
        <div id="cm-refresh-label" class="cm-help">Servidor: <?= cm_h((string)$initial['server_time']) ?></div>
    </div>
    <div style="overflow:auto;max-height:560px">
        <table class="cm-table">
            <thead><tr><th>Início</th><th>Rotina</th><th>Origem</th><th>Tipo</th><th>Status</th><th>Duração</th><th>Resultado</th></tr></thead>
            <tbody id="cm-runs">
            <?php foreach ($initial['runs'] as $run): ?>
                <tr>
                    <td><?= cm_h((string)$run['started_at']) ?></td>
                    <td><?= cm_h((string)$run['task_key']) ?></td>
                    <td><?= cm_h((string)$run['source']) ?></td>
                    <td><?= cm_h((string)$run['trigger_type']) ?></td>
                    <td><?= cm_h((string)$run['status']) ?></td>
                    <td><?= $run['duration_ms'] !== null ? (int)$run['duration_ms'] . ' ms' : '—' ?></td>
                    <td><?= cm_h(substr((string)($run['error_message'] ?: $run['output_text'] ?: ''), 0, 180)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    async function refresh(){
        try{
            const response = await fetch('cron_monitor.php?ajax=1&t=' + Date.now(), {cache:'no-store', credentials:'same-origin'});
            const data = await response.json();
            if(!data.ok) throw new Error(data.error || 'Falha');
            document.getElementById('cm-refresh-label').textContent = 'Servidor: ' + data.server_time;

            ['vps','hosting'].forEach(source => {
                const heartbeat = data.heartbeats[source];
                const status = document.getElementById('agent-' + source + '-status');
                const detail = document.getElementById('agent-' + source + '-detail');
                status.className = 'cm-status ' + (heartbeat && heartbeat.online ? 'online' : 'error');
                status.textContent = heartbeat && heartbeat.online ? 'Online' : 'Offline';
                detail.textContent = heartbeat
                    ? 'Último contato: ' + heartbeat.last_seen_at + ' · ' + heartbeat.last_result
                    : 'Nenhum contato recebido';
            });

            data.tasks.forEach(task => {
                const card = document.querySelector('[data-task-card="' + task.task_key + '"]');
                if(!card) return;
                const status = card.querySelector('[data-task-status]');
                status.className = 'cm-status ' + task.health;
                status.textContent = task.health_label;
                card.querySelector('[data-last-success]').textContent = task.last_success_at || 'Nunca';
                card.querySelector('[data-last-source]').textContent = task.last_source || '—';
                card.querySelector('[data-last-duration]').textContent = task.last_duration_ms === null ? '—' : task.last_duration_ms + ' ms';
            });

            document.getElementById('cm-runs').innerHTML = data.runs.map(run =>
                '<tr><td>'+esc(run.started_at)+'</td><td>'+esc(run.task_key)+'</td><td>'+esc(run.source)+'</td>'+
                '<td>'+esc(run.trigger_type)+'</td><td>'+esc(run.status)+'</td>'+
                '<td>'+(run.duration_ms === null ? '—' : esc(run.duration_ms)+' ms')+'</td>'+
                '<td>'+esc(String(run.error_message || run.output_text || '').slice(0,180))+'</td></tr>'
            ).join('');
        }catch(error){
            document.getElementById('cm-refresh-label').textContent = 'Falha ao atualizar: ' + error.message;
        }
    }
    setInterval(refresh, 10000);
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
