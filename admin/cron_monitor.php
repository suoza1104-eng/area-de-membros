<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$menu = 'cron_monitor';
$page_title = 'Monitor de Cron';
$page_subtitle = 'Acompanhamento em tempo real das execuções recebidas pelo servidor';

function cron_monitor_can_access(): bool {
    if (($_SESSION['admin_tipo'] ?? '') !== 'equipe') return true;
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    return !empty($perms['cron_monitor']['acesso']) || !empty($perms['logs']['acesso']);
}

function cron_monitor_data(): array {
    $logFile = __DIR__ . '/../uploads/cron_diagnostico/teste_cron_execucoes.log';
    $rows = [];
    $now = time();

    if (is_file($logFile) && is_readable($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -500);

        foreach ($lines as $line) {
            if (!preg_match(
                '/^(?<local>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| utc=(?<utc>[^|]+) \| intervalo=(?<intervalo>[^|]+) \| pid=(?<pid>\d+) \| sapi=(?<sapi>.+)$/',
                trim($line),
                $m
            )) {
                continue;
            }
            $timestamp = strtotime($m['local'] . ' America/Sao_Paulo') ?: 0;
            $intervalSeconds = preg_match('/^(\d+)s$/', trim($m['intervalo']), $im)
                ? (int)$im[1]
                : null;
            $rows[] = [
                'local' => $m['local'],
                'utc' => trim($m['utc']),
                'timestamp' => $timestamp,
                'interval_seconds' => $intervalSeconds,
                'pid' => (int)$m['pid'],
                'sapi' => trim($m['sapi']),
            ];
        }
    }

    $last = $rows ? $rows[count($rows) - 1] : null;
    $secondsSinceLast = $last ? max(0, $now - (int)$last['timestamp']) : null;
    $intervals = array_values(array_filter(
        array_column($rows, 'interval_seconds'),
        static fn($v) => $v !== null && $v > 0
    ));
    $recentIntervals = array_slice($intervals, -30);
    $average = $recentIntervals
        ? (int)round(array_sum($recentIntervals) / count($recentIntervals))
        : null;
    $delayed = count(array_filter($recentIntervals, static fn($v) => $v > 90));

    if (!$last) {
        $status = 'waiting';
        $statusLabel = 'Aguardando primeira execução';
    } elseif ($secondsSinceLast <= 90) {
        $status = 'online';
        $statusLabel = 'Funcionando';
    } elseif ($secondsSinceLast <= 180) {
        $status = 'delayed';
        $statusLabel = 'Atrasado';
    } else {
        $status = 'offline';
        $statusLabel = 'Sem execução recente';
    }

    return [
        'ok' => true,
        'status' => $status,
        'status_label' => $statusLabel,
        'server_now' => date('Y-m-d H:i:s', $now),
        'last_execution' => $last,
        'seconds_since_last' => $secondsSinceLast,
        'average_interval_seconds' => $average,
        'delayed_intervals' => $delayed,
        'total_records' => count($rows),
        'log_exists' => is_file($logFile),
        'log_updated_at' => is_file($logFile) ? date('Y-m-d H:i:s', (int)filemtime($logFile)) : null,
        'records' => array_reverse(array_slice($rows, -100)),
    ];
}

if (!cron_monitor_can_access()) {
    if (isset($_GET['ajax'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'sem_acesso'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . BASE_URL_ADMIN . '/index.php?sem_acesso=1');
    exit;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(cron_monitor_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/_header.php';
?>

<style>
.cm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.cm-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}
.cm-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:7px}
.cm-value{font-size:24px;font-weight:800;color:var(--text)}
.cm-help{font-size:11px;color:var(--muted);margin-top:5px}
.cm-status{display:inline-flex;align-items:center;gap:8px;font-size:15px;font-weight:800}
.cm-dot{width:10px;height:10px;border-radius:50%;background:var(--muted)}
.cm-status.online{color:#86efac}.cm-status.online .cm-dot{background:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.12)}
.cm-status.delayed{color:#fde68a}.cm-status.delayed .cm-dot{background:#f59e0b}
.cm-status.offline{color:#fca5a5}.cm-status.offline .cm-dot{background:#ef4444}
.cm-status.waiting{color:#93c5fd}.cm-status.waiting .cm-dot{background:#38bdf8}
.cm-table{width:100%;border-collapse:collapse;font-size:12px}
.cm-table th,.cm-table td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left}
.cm-table th{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;background:var(--bg-card)}
.cm-ok{color:#86efac}.cm-warn{color:#fde68a}.cm-bad{color:#fca5a5}
.cm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.cm-code{font-family:monospace;background:#060c18;border:1px solid var(--border);border-radius:8px;padding:10px;color:#93c5fd;font-size:12px;overflow:auto}
@media(max-width:900px){.cm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.cm-grid{grid-template-columns:1fr}}
</style>

<div class="cm-toolbar">
    <div>
        <div style="font-size:18px;font-weight:800">Recebimentos do cron de teste</div>
        <div style="font-size:12px;color:var(--muted)">Atualização automática a cada 5 segundos. Cadência esperada: uma execução por minuto.</div>
    </div>
    <div id="cm-refresh-label" style="font-size:11px;color:var(--muted)">Carregando…</div>
</div>

<div class="cm-grid">
    <div class="cm-card">
        <div class="cm-label">Estado</div>
        <div id="cm-status" class="cm-status waiting"><span class="cm-dot"></span><span>Aguardando</span></div>
        <div id="cm-server-now" class="cm-help">Servidor: —</div>
    </div>
    <div class="cm-card">
        <div class="cm-label">Última execução</div>
        <div id="cm-last" class="cm-value" style="font-size:18px">—</div>
        <div id="cm-last-ago" class="cm-help">Nenhuma execução registrada</div>
    </div>
    <div class="cm-card">
        <div class="cm-label">Intervalo médio</div>
        <div id="cm-average" class="cm-value">—</div>
        <div class="cm-help">Média das últimas 30 execuções</div>
    </div>
    <div class="cm-card">
        <div class="cm-label">Registros / atrasos</div>
        <div id="cm-counts" class="cm-value">0 / 0</div>
        <div class="cm-help">Intervalos acima de 90 segundos</div>
    </div>
</div>

<div class="cm-card" style="margin-bottom:16px">
    <div class="cm-label">Comando configurado no cPanel</div>
    <div class="cm-code">* * * * * /usr/bin/curl -fsS "https://professoremersonleite.com/area_membros/cron/teste_cron.php" &gt;/dev/null 2&gt;&amp;1</div>
</div>

<div class="cm-card">
    <div class="cm-toolbar">
        <div>
            <div style="font-size:15px;font-weight:800">Histórico recente</div>
            <div id="cm-log-info" class="cm-help">Procurando arquivo de log…</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" id="cm-refresh">Atualizar agora</button>
    </div>
    <div style="overflow:auto;max-height:520px">
        <table class="cm-table">
            <thead><tr><th>Horário</th><th>Intervalo</th><th>Situação</th><th>PID</th><th>Execução</th></tr></thead>
            <tbody id="cm-body"><tr><td colspan="5">Carregando…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
(function(){
    const el = id => document.getElementById(id);
    let busy = false;
    function intervalClass(seconds) {
        if (seconds === null) return '';
        if (seconds <= 75) return 'cm-ok';
        if (seconds <= 90) return 'cm-warn';
        return 'cm-bad';
    }
    function ago(seconds) {
        if (seconds === null) return 'Nenhuma execução registrada';
        if (seconds < 60) return 'há ' + seconds + ' segundos';
        const min = Math.floor(seconds / 60);
        return 'há ' + min + ' minuto' + (min === 1 ? '' : 's');
    }
    async function refresh() {
        if (busy) return;
        busy = true;
        el('cm-refresh-label').textContent = 'Atualizando…';
        try {
            const response = await fetch('cron_monitor.php?ajax=1&t=' + Date.now(), {cache:'no-store', credentials:'same-origin'});
            const data = await response.json();
            if (!data.ok) throw new Error(data.error || 'Falha ao consultar');

            const status = el('cm-status');
            status.className = 'cm-status ' + data.status;
            status.querySelector('span:last-child').textContent = data.status_label;
            el('cm-server-now').textContent = 'Servidor: ' + data.server_now;
            el('cm-last').textContent = data.last_execution ? data.last_execution.local : '—';
            el('cm-last-ago').textContent = ago(data.seconds_since_last);
            el('cm-average').textContent = data.average_interval_seconds === null ? '—' : data.average_interval_seconds + 's';
            el('cm-counts').textContent = data.total_records + ' / ' + data.delayed_intervals;
            el('cm-log-info').textContent = data.log_exists
                ? 'Log atualizado em ' + data.log_updated_at + ' · exibindo até 100 registros'
                : 'O arquivo de log ainda não foi criado pelo cron.';

            const body = el('cm-body');
            body.innerHTML = '';
            if (!data.records.length) {
                body.innerHTML = '<tr><td colspan="5">Nenhuma execução recebida até agora.</td></tr>';
            } else {
                data.records.forEach(row => {
                    const seconds = row.interval_seconds;
                    const situation = seconds === null ? 'Primeira execução' : (seconds <= 90 ? 'Normal' : 'Atrasada');
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + row.local + '</td>' +
                        '<td class="' + intervalClass(seconds) + '">' + (seconds === null ? '—' : seconds + 's') + '</td>' +
                        '<td class="' + intervalClass(seconds) + '">' + situation + '</td>' +
                        '<td>' + row.pid + '</td>' +
                        '<td>' + row.sapi + '</td>';
                    body.appendChild(tr);
                });
            }
            el('cm-refresh-label').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
        } catch (error) {
            el('cm-refresh-label').textContent = 'Falha: ' + error.message;
        } finally {
            busy = false;
        }
    }
    el('cm-refresh').addEventListener('click', refresh);
    refresh();
    setInterval(refresh, 5000);
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
