<?php
// FILE: admin/logs.php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

$menu = 'logs';
$page_title = 'Logs';
$page_subtitle = 'Auditoria de eventos, webhooks, SuperFuncionario e cron';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function logs_dt_br(?string $dt): string {
    if (!$dt) return '';
    try { return (new DateTime($dt))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return (string)$dt; }
}

function logs_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function logs_ensure_live_dispatch_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS live_turma_dispatch_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                turma_id INT NULL,
                turma_codigo VARCHAR(80) NULL,
                planned_at DATETIME NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                total_alunos INT NOT NULL DEFAULT 0,
                elegiveis INT NOT NULL DEFAULT 0,
                sf_ok INT NOT NULL DEFAULT 0,
                sf_fail INT NOT NULL DEFAULT 0,
                webhook_ok INT NOT NULL DEFAULT 0,
                webhook_fail INT NOT NULL DEFAULT 0,
                skipped_json LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'iniciado',
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_live_dispatch_turma (turma_codigo),
                KEY idx_live_dispatch_started (started_at),
                KEY idx_live_dispatch_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}
}

function logs_cleanup_old(PDO $pdo): void {
    $cuts = [
        ['webhook_logs', 'created_at'],
        ['superfuncionario_logs', 'created_at'],
        ['live_turma_dispatch_logs', 'started_at'],
    ];
    foreach ($cuts as [$table, $col]) {
        if (!logs_table_exists($pdo, $table)) continue;
        try { $pdo->exec("DELETE FROM `$table` WHERE `$col` < DATE_SUB(NOW(), INTERVAL 1 YEAR)"); } catch (Throwable $e) {}
    }
}

function logs_pretty_json(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
    return $raw;
}

function logs_event_group(string $evento, string $source): string {
    $e = strtoupper($evento);
    if ($source === 'cron_live') return 'Cron live';
    if (str_starts_with($e, 'LIVE_TURMA')) return 'Live turma';
    if (str_starts_with($e, 'LIVE_REAGEND')) return 'Live reagendada';
    if (str_starts_with($e, 'LIVE_')) return 'Live evento';
    if (str_contains($e, 'CERT')) return 'Certificado';
    if (str_contains($e, 'LOGIN')) return 'Login';
    if (str_contains($e, 'INSCRITO') || str_contains($e, 'REINSCRITO')) return 'Inscricao';
    if (str_contains($e, 'WHATSAPP')) return 'WhatsApp';
    return 'Geral';
}

function logs_guess_turma(string $evento, string $payload): string {
    if (preg_match('/LIVE_TURMA_([A-Za-z0-9_-]+)/', $evento, $m)) return $m[1];
    $data = json_decode($payload, true);
    if (!is_array($data)) return '';
    foreach ([
        ['extra', 'codigo_turma'],
        ['extra', 'turma', 'codigo'],
        ['turma', 'codigo'],
        ['aluno', 'codigo_turma'],
        ['user', 'codigo_turma'],
    ] as $path) {
        $v = $data;
        foreach ($path as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) { $v = null; break; }
            $v = $v[$p];
        }
        if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
    }
    return '';
}

function logs_guess_contact(string $payload): array {
    $out = ['nome' => '', 'email' => '', 'telefone' => '', 'user_id' => 0];
    $data = json_decode($payload, true);
    if (!is_array($data)) return $out;
    $candidates = [$data, (array)($data['user'] ?? []), (array)($data['aluno'] ?? [])];
    foreach ($candidates as $c) {
        if ($out['nome'] === '' && !empty($c['nome'])) $out['nome'] = (string)$c['nome'];
        if ($out['nome'] === '' && !empty($c['first_name'])) $out['nome'] = trim((string)$c['first_name'] . ' ' . (string)($c['last_name'] ?? ''));
        if ($out['email'] === '' && !empty($c['email'])) $out['email'] = (string)$c['email'];
        if ($out['telefone'] === '' && !empty($c['telefone'])) $out['telefone'] = (string)$c['telefone'];
        if ($out['telefone'] === '' && !empty($c['phone'])) $out['telefone'] = (string)$c['phone'];
        if ($out['user_id'] <= 0 && !empty($c['id']) && is_numeric($c['id'])) $out['user_id'] = (int)$c['id'];
    }
    return $out;
}

function logs_skipped_summary(?string $json): string {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '-';
    $labels = [
        'include_tag_table_missing' => 'sem tabela de tags',
        'andamento_zero' => 'andamento zero',
        'tag_excluida' => 'tag excluida',
        'certificado' => 'certificado',
        'compra' => 'compra',
        'live_reagendada' => 'live reagendada',
    ];
    $parts = [];
    foreach ($labels as $key => $label) {
        $n = (int)($data[$key] ?? 0);
        if ($n > 0) $parts[] = $label . ': ' . $n;
    }
    return $parts ? implode(' | ', $parts) : '-';
}

logs_ensure_live_dispatch_table($pdo);
logs_cleanup_old($pdo);

$source = trim((string)($_GET['source'] ?? ''));
$evento = trim((string)($_GET['evento'] ?? ''));
$grupo  = trim((string)($_GET['grupo'] ?? ''));
$turma  = trim((string)($_GET['turma'] ?? ''));
$aluno  = trim((string)($_GET['aluno'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$de     = trim((string)($_GET['de'] ?? ''));
$ate    = trim((string)($_GET['ate'] ?? ''));
$limit  = (int)($_GET['limit'] ?? 300);
if ($limit < 50) $limit = 50;
if ($limit > 2000) $limit = 2000;

$allRows = [];

if (($source === '' || $source === 'webhook') && logs_table_exists($pdo, 'webhook_logs')) {
    $where = [];
    $params = [];
    if ($evento !== '') { $where[] = 'wl.evento LIKE :evento'; $params[':evento'] = '%' . $evento . '%'; }
    if ($turma !== '') { $where[] = '(wl.evento LIKE :turma_evt OR wl.payload_json LIKE :turma_payload)'; $params[':turma_evt'] = '%_' . $turma . '%'; $params[':turma_payload'] = '%' . $turma . '%'; }
    if ($aluno !== '') {
        $where[] = '(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR wl.user_id = :aluno_id OR wl.payload_json LIKE :aluno_payload)';
        $params[':aluno'] = '%' . $aluno . '%';
        $params[':aluno_id'] = ctype_digit($aluno) ? (int)$aluno : 0;
        $params[':aluno_payload'] = '%' . $aluno . '%';
    }
    if ($de !== '') { $where[] = 'wl.created_at >= :de'; $params[':de'] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = 'wl.created_at <= :ate'; $params[':ate'] = $ate . ' 23:59:59'; }
    if ($status === 'ok') $where[] = "(wl.response_status >= 200 AND wl.response_status < 300 AND COALESCE(wl.error_message,'') = '')";
    elseif ($status === 'erro') $where[] = "(wl.response_status IS NULL OR wl.response_status < 200 OR wl.response_status >= 300 OR COALESCE(wl.error_message,'') <> '')";
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $st = $pdo->prepare("
            SELECT wl.*, u.nome AS user_nome, u.email AS user_email, u.telefone AS user_telefone, w.nome AS webhook_nome, w.url AS webhook_url
              FROM webhook_logs wl
              LEFT JOIN users u ON u.id = wl.user_id
              LEFT JOIN webhooks w ON w.id = wl.webhook_id
              $whereSql
          ORDER BY wl.created_at DESC, wl.id DESC
             LIMIT :lim
        ");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $payload = (string)($r['payload_json'] ?? '');
            $event = (string)($r['evento'] ?? '');
            $ok = is_numeric($r['response_status'] ?? null) && (int)$r['response_status'] >= 200 && (int)$r['response_status'] < 300 && trim((string)($r['error_message'] ?? '')) === '';
            $allRows[] = [
                'source' => 'webhook',
                'id' => (int)$r['id'],
                'created_at' => (string)$r['created_at'],
                'evento' => $event,
                'grupo' => logs_event_group($event, 'webhook'),
                'turma' => logs_guess_turma($event, $payload),
                'aluno_nome' => (string)($r['user_nome'] ?? ''),
                'aluno_email' => (string)($r['user_email'] ?? ''),
                'aluno_tel' => (string)($r['user_telefone'] ?? ''),
                'user_id' => (int)($r['user_id'] ?? 0),
                'ok' => $ok,
                'status_label' => (string)($r['response_status'] ?? '-'),
                'destino' => trim((string)($r['webhook_nome'] ?? '')) ?: trim((string)($r['webhook_url'] ?? '')),
                'summary' => trim((string)($r['error_message'] ?? '')) ?: substr(trim((string)($r['response_body'] ?? '')), 0, 160),
                'payload' => $payload,
                'response' => (string)($r['response_body'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

if (($source === '' || $source === 'sf') && logs_table_exists($pdo, 'superfuncionario_logs')) {
    $where = [];
    $params = [];
    if ($evento !== '') { $where[] = 'sl.evento LIKE :evento'; $params[':evento'] = '%' . $evento . '%'; }
    if ($turma !== '') { $where[] = '(sl.evento LIKE :turma_evt OR sl.request_json LIKE :turma_payload)'; $params[':turma_evt'] = '%_' . $turma . '%'; $params[':turma_payload'] = '%' . $turma . '%'; }
    if ($aluno !== '') { $where[] = 'sl.request_json LIKE :aluno'; $params[':aluno'] = '%' . $aluno . '%'; }
    if ($de !== '') { $where[] = 'sl.created_at >= :de'; $params[':de'] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = 'sl.created_at <= :ate'; $params[':ate'] = $ate . ' 23:59:59'; }
    if ($status === 'ok') $where[] = 'sl.ok = 1';
    elseif ($status === 'erro') $where[] = 'sl.ok = 0';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $st = $pdo->prepare("
            SELECT sl.*, sr.nome AS rule_nome
              FROM superfuncionario_logs sl
              LEFT JOIN superfuncionario_rules sr ON sr.id = sl.rule_id
              $whereSql
          ORDER BY sl.created_at DESC, sl.id DESC
             LIMIT :lim
        ");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $payload = (string)($r['request_json'] ?? '');
            $event = (string)($r['evento'] ?? '');
            $contact = logs_guess_contact($payload);
            $allRows[] = [
                'source' => 'sf',
                'id' => (int)$r['id'],
                'created_at' => (string)$r['created_at'],
                'evento' => $event,
                'grupo' => logs_event_group($event, 'sf'),
                'turma' => logs_guess_turma($event, $payload),
                'aluno_nome' => $contact['nome'],
                'aluno_email' => $contact['email'],
                'aluno_tel' => $contact['telefone'],
                'user_id' => $contact['user_id'],
                'ok' => (int)($r['ok'] ?? 0) === 1,
                'status_label' => (string)($r['http_status'] ?? '-'),
                'destino' => trim((string)($r['rule_nome'] ?? 'SuperFuncionario')),
                'summary' => trim((string)($r['error_text'] ?? '')) ?: substr(trim((string)($r['response_text'] ?? '')), 0, 160),
                'payload' => $payload,
                'response' => (string)($r['response_text'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

if (($source === '' || $source === 'cron_live') && logs_table_exists($pdo, 'live_turma_dispatch_logs')) {
    $where = [];
    $params = [];
    if ($evento !== '') { $where[] = "'CRON_LIVE_TURMA' LIKE :evento"; $params[':evento'] = '%' . $evento . '%'; }
    if ($turma !== '') { $where[] = 'turma_codigo LIKE :turma'; $params[':turma'] = '%' . $turma . '%'; }
    if ($aluno !== '') { $where[] = '1=0'; }
    if ($de !== '') { $where[] = 'started_at >= :de'; $params[':de'] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = 'started_at <= :ate'; $params[':ate'] = $ate . ' 23:59:59'; }
    if ($status === 'ok') $where[] = "status = 'concluido'";
    elseif ($status === 'erro') $where[] = "status <> 'concluido'";
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $st = $pdo->prepare("SELECT * FROM live_turma_dispatch_logs $whereSql ORDER BY started_at DESC, id DESC LIMIT :lim");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $payload = json_encode([
                'turma_id' => $r['turma_id'] ?? null,
                'turma_codigo' => $r['turma_codigo'] ?? '',
                'planned_at' => $r['planned_at'] ?? null,
                'started_at' => $r['started_at'] ?? null,
                'finished_at' => $r['finished_at'] ?? null,
                'total_alunos' => (int)($r['total_alunos'] ?? 0),
                'elegiveis' => (int)($r['elegiveis'] ?? 0),
                'sf_ok' => (int)($r['sf_ok'] ?? 0),
                'sf_fail' => (int)($r['sf_fail'] ?? 0),
                'webhook_ok' => (int)($r['webhook_ok'] ?? 0),
                'webhook_fail' => (int)($r['webhook_fail'] ?? 0),
                'skipped' => json_decode((string)($r['skipped_json'] ?? ''), true),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $allRows[] = [
                'source' => 'cron_live',
                'id' => (int)$r['id'],
                'created_at' => (string)$r['started_at'],
                'evento' => 'CRON_LIVE_TURMA',
                'grupo' => 'Cron live',
                'turma' => (string)($r['turma_codigo'] ?? ''),
                'aluno_nome' => '',
                'aluno_email' => '',
                'aluno_tel' => '',
                'user_id' => 0,
                'ok' => (string)($r['status'] ?? '') === 'concluido',
                'status_label' => (string)($r['status'] ?? ''),
                'destino' => 'Cron processar_lives',
                'summary' => 'Total: ' . (int)($r['total_alunos'] ?? 0) . ' | Elegiveis: ' . (int)($r['elegiveis'] ?? 0) . ' | SF OK: ' . (int)($r['sf_ok'] ?? 0) . ' | Excluidos: ' . logs_skipped_summary($r['skipped_json'] ?? null),
                'payload' => $payload ?: '',
                'response' => (string)($r['message'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

if ($grupo !== '') $allRows = array_values(array_filter($allRows, static fn($r) => (string)$r['grupo'] === $grupo));
usort($allRows, static fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
$allRows = array_slice($allRows, 0, $limit);

$kpiTotal = count($allRows);
$kpiOk = count(array_filter($allRows, static fn($r) => !empty($r['ok'])));
$kpiErr = $kpiTotal - $kpiOk;
$groups = array_values(array_unique(array_map(static fn($r) => (string)$r['grupo'], $allRows)));
sort($groups);

include __DIR__ . '/_header.php';
?>

<style>
.logs-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
.logs-help-btn { width:36px; height:36px; border-radius:999px; border:1px solid var(--border); background:rgba(15,23,42,.7); color:var(--text); cursor:pointer; font-weight:900; }
.logs-filters{ display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.logs-filters .field{display:flex;flex-direction:column;gap:4px; min-width:140px;}
.logs-filters .field.wide{min-width:220px;}
.logs-filters label{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;}
.logs-filters input, .logs-filters select{ padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--bg); color:var(--text); }
.logs-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px;}
.logs-kpi{border:1px solid var(--border);border-radius:12px;padding:12px;background:rgba(15,23,42,.35);}
.logs-kpi span{display:block;color:var(--muted);font-size:11px;font-weight:800;text-transform:uppercase;}
.logs-kpi strong{display:block;color:var(--text);font-size:24px;line-height:1.1;margin-top:3px;}
.log-source{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:900;border:1px solid var(--border);}
.log-source.webhook{color:#7dd3fc;background:rgba(56,189,248,.10);border-color:rgba(56,189,248,.25);}
.log-source.sf{color:#c4b5fd;background:rgba(167,139,250,.10);border-color:rgba(167,139,250,.25);}
.log-source.cron_live{color:#fcd34d;background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.25);}
.log-status{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:900;border:1px solid var(--border);}
.log-status.ok{color:#86efac;background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.25);}
.log-status.err{color:#fca5a5;background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.25);}
.log-details summary{cursor:pointer;color:var(--primary);font-weight:900;font-size:12px;}
.log-details pre{white-space:pre-wrap;word-break:break-word;max-height:360px;overflow:auto;background:rgba(2,6,23,.65);border:1px solid var(--border);border-radius:10px;padding:10px;font-size:11px;color:#cbd5e1;}
.log-help{display:none;border:1px solid var(--border);border-radius:12px;padding:12px;margin-top:12px;background:rgba(2,6,23,.45);}
.log-help.open{display:block;}
.log-help-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.log-help-item{border:1px solid var(--border);border-radius:10px;padding:10px;background:rgba(15,23,42,.35);}
.log-help-item b{display:block;margin-bottom:4px;}
@media(max-width:800px){.logs-kpis,.log-help-grid{grid-template-columns:1fr;}.logs-filters .field{min-width:100%;}}
</style>

<div class="card">
    <div class="logs-head">
        <div>
            <div class="topbar-title">Logs</div>
            <div class="text-muted text-sm">Auditoria unificada de eventos, webhooks, SuperFuncionario e cron de live. Logs com mais de 1 ano sao limpos automaticamente.</div>
        </div>
        <button class="logs-help-btn" type="button" onclick="document.getElementById('logsHelp').classList.toggle('open')" title="Descricao dos eventos">?</button>
    </div>
    <div class="log-help" id="logsHelp">
        <div class="log-help-grid">
            <div class="log-help-item"><b>Live turma</b>Disparos coletivos da live da turma. Ex.: LIVE_TURMA ou LIVE_TURMA_300526.</div>
            <div class="log-help-item"><b>Live reagendada</b>Eventos ligados ao reagendamento e lembretes individuais. Ex.: LIVE_REAGENDADA.</div>
            <div class="log-help-item"><b>Inscricao/Login/Certificado</b>Eventos da jornada do aluno dentro da area de membros.</div>
            <div class="log-help-item"><b>Cron live</b>Resumo da execucao da fila: alunos encontrados, elegiveis, enviados, falhas e excluidos por filtro.</div>
        </div>
    </div>
    <div class="logs-kpis">
        <div class="logs-kpi"><span>Total no filtro</span><strong><?= number_format($kpiTotal, 0, ',', '.') ?></strong></div>
        <div class="logs-kpi"><span>Sucesso</span><strong><?= number_format($kpiOk, 0, ',', '.') ?></strong></div>
        <div class="logs-kpi"><span>Erro/Aviso</span><strong><?= number_format($kpiErr, 0, ',', '.') ?></strong></div>
    </div>
</div>

<div class="card">
    <form method="get" class="logs-filters">
        <div class="field">
            <label>Tipo de log</label>
            <select name="source">
                <option value="" <?= $source===''?'selected':'' ?>>Todos</option>
                <option value="webhook" <?= $source==='webhook'?'selected':'' ?>>Webhook</option>
                <option value="sf" <?= $source==='sf'?'selected':'' ?>>SuperFuncionario</option>
                <option value="cron_live" <?= $source==='cron_live'?'selected':'' ?>>Cron live</option>
            </select>
        </div>
        <div class="field">
            <label>Grupo</label>
            <select name="grupo">
                <option value="" <?= $grupo===''?'selected':'' ?>>Todos</option>
                <?php foreach (['Cron live','Live turma','Live reagendada','Live evento','Inscricao','Login','Certificado','WhatsApp','Geral'] as $g): ?>
                    <option value="<?= h($g) ?>" <?= $grupo===$g?'selected':'' ?>><?= h($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field wide"><label>Evento</label><input name="evento" value="<?= h($evento) ?>" placeholder="LIVE_TURMA, CERT_EMITIDO..."></div>
        <div class="field"><label>Turma</label><input name="turma" value="<?= h($turma) ?>" placeholder="300526"></div>
        <div class="field wide"><label>Aluno</label><input name="aluno" value="<?= h($aluno) ?>" placeholder="Nome, email, telefone ou ID"></div>
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="" <?= $status===''?'selected':'' ?>>Todos</option>
                <option value="ok" <?= $status==='ok'?'selected':'' ?>>Sucesso</option>
                <option value="erro" <?= $status==='erro'?'selected':'' ?>>Erro/Aviso</option>
            </select>
        </div>
        <div class="field"><label>De</label><input type="date" name="de" value="<?= h($de) ?>"></div>
        <div class="field"><label>Ate</label><input type="date" name="ate" value="<?= h($ate) ?>"></div>
        <div class="field">
            <label>Qtd.</label>
            <select name="limit">
                <?php foreach ([50,100,300,500,1000,2000] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
        <a class="reset-link" href="logs.php">Limpar</a>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Quando</th>
                    <th>Tipo</th>
                    <th>Evento</th>
                    <th>Turma</th>
                    <th>Aluno</th>
                    <th>Status</th>
                    <th>Resumo</th>
                    <th>Detalhes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$allRows): ?>
                <tr><td colspan="8" class="text-muted" style="text-align:center;padding:28px">Nenhum log encontrado para os filtros atuais.</td></tr>
            <?php endif; ?>
            <?php foreach ($allRows as $idx => $r): ?>
                <tr>
                    <td style="white-space:nowrap">
                        <div class="fw-700"><?= h(logs_dt_br((string)$r['created_at'])) ?></div>
                        <div class="text-xs text-muted">#<?= (int)$r['id'] ?></div>
                    </td>
                    <td><span class="log-source <?= h((string)$r['source']) ?>"><?= h((string)$r['source']) ?></span><div class="text-xs text-muted mt-1"><?= h((string)$r['grupo']) ?></div></td>
                    <td><span class="badge badge-neutral"><?= h((string)$r['evento']) ?></span><div class="text-xs text-muted mt-1"><?= h((string)$r['destino']) ?></div></td>
                    <td class="fw-700"><?= h((string)$r['turma'] ?: '-') ?></td>
                    <td>
                        <?php if ((string)$r['aluno_nome'] !== '' || (string)$r['aluno_email'] !== '' || (int)$r['user_id'] > 0): ?>
                            <div class="fw-700"><?= h((string)$r['aluno_nome'] ?: ('Aluno #' . (int)$r['user_id'])) ?></div>
                            <div class="text-xs text-muted"><?= h((string)$r['aluno_email']) ?> <?= h((string)$r['aluno_tel']) ?></div>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><span class="log-status <?= !empty($r['ok']) ? 'ok' : 'err' ?>"><?= h((string)$r['status_label']) ?></span></td>
                    <td style="max-width:300px;white-space:normal"><?= h((string)$r['summary'] ?: '-') ?></td>
                    <td>
                        <details class="log-details">
                            <summary>Ver</summary>
                            <div class="text-xs text-muted mt-2">Payload</div>
                            <pre><?= h(logs_pretty_json((string)$r['payload'])) ?></pre>
                            <div class="text-xs text-muted mt-2">Resposta / mensagem</div>
                            <pre><?= h(logs_pretty_json((string)$r['response'])) ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
