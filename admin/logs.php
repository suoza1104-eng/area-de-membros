<?php
// FILE: admin/logs.php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

$menu = 'logs';
$page_title = 'Logs';
$page_subtitle = 'Auditoria de eventos, webhooks, SuperFuncionario, Manychat, disparos e cron';

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
                manychat_ok INT NOT NULL DEFAULT 0,
                manychat_fail INT NOT NULL DEFAULT 0,
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
    foreach ([
        'manychat_ok' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_ok INT NOT NULL DEFAULT 0 AFTER sf_fail",
        'manychat_fail' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_fail INT NOT NULL DEFAULT 0 AFTER manychat_ok",
    ] as $col => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM live_turma_dispatch_logs LIKE :c");
            $st->execute([':c' => $col]);
            if (!$st->fetchColumn()) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }
}

function logs_cleanup_old(PDO $pdo): void {
    $cuts = [
        ['webhook_logs', 'created_at'],
        ['superfuncionario_logs', 'created_at'],
        ['manychat_logs', 'created_at'],
        ['disparo_execucoes', 'executado_em'],
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
    if ($source === 'disparo') return 'Disparos';
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
        ['_context', 'extra', 'codigo_turma'],
        ['_context', 'extra', 'turma', 'codigo'],
        ['turma', 'codigo'],
        ['aluno', 'codigo_turma'],
        ['user', 'codigo_turma'],
        ['_context', 'user', 'codigo_turma'],
    ] as $path) {
        $v = $data;
        foreach ($path as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) { $v = null; break; }
            $v = $v[$p];
        }
        if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
    }
    foreach ((array)($data['actions'] ?? []) as $action) {
        if (!is_array($action)) continue;
        $field = strtoupper((string)($action['field_name'] ?? ''));
        $value = trim((string)($action['value'] ?? ''));
        if ($value !== '' && (str_contains($field, 'CODIGO_TURMA') || preg_match('/(^|_)TURMA($|_)/', $field))) {
            return $value;
        }
    }
    return '';
}

function logs_guess_contact(string $payload): array {
    $out = ['nome' => '', 'email' => '', 'telefone' => '', 'user_id' => 0];
    $data = json_decode($payload, true);
    if (!is_array($data)) return $out;
    $candidates = [$data, (array)($data['user'] ?? []), (array)($data['aluno'] ?? []), (array)($data['_context']['user'] ?? [])];
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

function logs_multi_param(string $key): array {
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if ($v !== '') $out[$v] = true;
    }
    return array_keys($out);
}

function logs_add_like_any(array &$where, array &$params, string $expr, string $prefix, array $values): void {
    if (!$values) return;
    $ors = [];
    foreach ($values as $i => $value) {
        $key = ':' . $prefix . $i;
        $ors[] = "$expr LIKE $key";
        $params[$key] = '%' . $value . '%';
    }
    $where[] = '(' . implode(' OR ', $ors) . ')';
}

logs_ensure_live_dispatch_table($pdo);
logs_cleanup_old($pdo);

$source = trim((string)($_GET['source'] ?? ''));
$eventos = logs_multi_param('evento');
$grupo  = trim((string)($_GET['grupo'] ?? ''));
$turmas = logs_multi_param('turma');
$aluno  = trim((string)($_GET['aluno'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$de     = trim((string)($_GET['de'] ?? ''));
$ate    = trim((string)($_GET['ate'] ?? ''));
$limit  = (int)($_GET['limit'] ?? 300);
if ($limit < 50) $limit = 50;
if ($limit > 2000) $limit = 2000;

$eventoOptions = [];
$turmaOptions = [];
try {
    if (logs_table_exists($pdo, 'webhook_logs')) {
        foreach ($pdo->query("SELECT DISTINCT evento FROM webhook_logs WHERE evento IS NOT NULL AND evento <> '' ORDER BY evento ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $eventoOptions[(string)$v] = true;
        }
    }
    if (logs_table_exists($pdo, 'superfuncionario_logs')) {
        foreach ($pdo->query("SELECT DISTINCT evento FROM superfuncionario_logs WHERE evento IS NOT NULL AND evento <> '' ORDER BY evento ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $eventoOptions[(string)$v] = true;
        }
    }
    if (logs_table_exists($pdo, 'manychat_logs')) {
        foreach ($pdo->query("SELECT DISTINCT evento FROM manychat_logs WHERE evento IS NOT NULL AND evento <> '' ORDER BY evento ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $eventoOptions[(string)$v] = true;
        }
    }
    if (logs_table_exists($pdo, 'disparo_execucoes')) {
        $eventoOptions['DISPARO'] = true;
    }
    $eventoOptions['CRON_LIVE_TURMA'] = true;
} catch (Throwable $e) {}
try {
    if (logs_table_exists($pdo, 'turmas')) {
        foreach ($pdo->query("SELECT DISTINCT codigo FROM turmas WHERE codigo IS NOT NULL AND codigo <> '' ORDER BY codigo DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $turmaOptions[(string)$v] = true;
        }
    }
    if (logs_table_exists($pdo, 'live_turma_dispatch_logs')) {
        foreach ($pdo->query("SELECT DISTINCT turma_codigo FROM live_turma_dispatch_logs WHERE turma_codigo IS NOT NULL AND turma_codigo <> '' ORDER BY turma_codigo DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $turmaOptions[(string)$v] = true;
        }
    }
} catch (Throwable $e) {}
$eventoOptions = array_keys($eventoOptions);
$turmaOptions = array_keys($turmaOptions);
sort($eventoOptions);
rsort($turmaOptions);

$allRows = [];

if (($source === '' || $source === 'webhook') && logs_table_exists($pdo, 'webhook_logs')) {
    $where = [];
    $params = [];
    logs_add_like_any($where, $params, 'wl.evento', 'evento', $eventos);
    if ($turmas) {
        $ors = [];
        foreach ($turmas as $i => $value) {
            $evtKey = ':turma_evt' . $i;
            $payloadKey = ':turma_payload' . $i;
            $ors[] = "wl.evento LIKE $evtKey OR wl.payload_json LIKE $payloadKey";
            $params[$evtKey] = '%_' . $value . '%';
            $params[$payloadKey] = '%' . $value . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
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
    logs_add_like_any($where, $params, 'sl.evento', 'evento', $eventos);
    if ($turmas) {
        $ors = [];
        foreach ($turmas as $i => $value) {
            $evtKey = ':turma_evt' . $i;
            $payloadKey = ':turma_payload' . $i;
            $ors[] = "sl.evento LIKE $evtKey OR sl.request_json LIKE $payloadKey";
            $params[$evtKey] = '%_' . $value . '%';
            $params[$payloadKey] = '%' . $value . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
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

if (($source === '' || $source === 'manychat') && logs_table_exists($pdo, 'manychat_logs')) {
    $where = [];
    $params = [];
    logs_add_like_any($where, $params, 'ml.evento', 'evento', $eventos);
    if ($turmas) {
        $ors = [];
        foreach ($turmas as $i => $value) {
            $evtKey = ':mc_turma_evt' . $i;
            $payloadKey = ':mc_turma_payload' . $i;
            $ors[] = "ml.evento LIKE $evtKey OR ml.request_json LIKE $payloadKey";
            $params[$evtKey] = '%_' . $value . '%';
            $params[$payloadKey] = '%' . $value . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    if ($aluno !== '') { $where[] = '(ml.request_json LIKE :mc_aluno OR ml.subscriber_id = :mc_subscriber)'; $params[':mc_aluno'] = '%' . $aluno . '%'; $params[':mc_subscriber'] = $aluno; }
    if ($de !== '') { $where[] = 'ml.created_at >= :mc_de'; $params[':mc_de'] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = 'ml.created_at <= :mc_ate'; $params[':mc_ate'] = $ate . ' 23:59:59'; }
    if ($status === 'ok') $where[] = 'ml.ok = 1';
    elseif ($status === 'erro') $where[] = 'ml.ok = 0';
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $st = $pdo->prepare("
            SELECT ml.*, mr.nome AS rule_nome
              FROM manychat_logs ml
              LEFT JOIN manychat_rules mr ON mr.id = ml.rule_id
              $whereSql
          ORDER BY ml.created_at DESC, ml.id DESC
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
                'source' => 'manychat',
                'id' => (int)$r['id'],
                'created_at' => (string)$r['created_at'],
                'evento' => $event,
                'grupo' => logs_event_group($event, 'manychat'),
                'turma' => logs_guess_turma($event, $payload),
                'aluno_nome' => $contact['nome'],
                'aluno_email' => $contact['email'],
                'aluno_tel' => $contact['telefone'],
                'user_id' => $contact['user_id'],
                'ok' => (int)($r['ok'] ?? 0) === 1,
                'status_label' => (string)($r['http_status'] ?? '-'),
                'destino' => trim((string)($r['rule_nome'] ?? 'Manychat')) . ' / ' . trim((string)($r['action'] ?? '')),
                'summary' => trim((string)($r['error_text'] ?? '')) ?: substr(trim((string)($r['response_text'] ?? '')), 0, 160),
                'payload' => $payload,
                'response' => (string)($r['response_text'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

if (($source === '' || $source === 'disparo') && logs_table_exists($pdo, 'disparo_execucoes')) {
    $where = [];
    $params = [];
    logs_add_like_any($where, $params, "CONCAT('DISPARO_', de.disparo_id, ' ', COALESCE(d.nome,''))", 'disparo_evento', $eventos);
    if ($turmas) {
        $ors = [];
        foreach ($turmas as $i => $value) {
            $turmaUserKey = ':disparo_turma_user' . $i;
            $turmaUltKey = ':disparo_turma_ult' . $i;
            $ors[] = "u.codigo_turma LIKE $turmaUserKey OR (
                SELECT il.codigo_turma
                  FROM inscricao_logs il
                 WHERE il.user_id = de.user_id
                 ORDER BY il.created_at DESC
                 LIMIT 1
            ) LIKE $turmaUltKey";
            $params[$turmaUserKey] = '%' . $value . '%';
            $params[$turmaUltKey] = '%' . $value . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    if ($aluno !== '') {
        $where[] = '(u.nome LIKE :disparo_aluno OR u.email LIKE :disparo_aluno OR u.telefone LIKE :disparo_aluno OR de.user_id = :disparo_aluno_id OR de.resposta LIKE :disparo_aluno_resp)';
        $params[':disparo_aluno'] = '%' . $aluno . '%';
        $params[':disparo_aluno_id'] = ctype_digit($aluno) ? (int)$aluno : 0;
        $params[':disparo_aluno_resp'] = '%' . $aluno . '%';
    }
    if ($de !== '') { $where[] = 'de.executado_em >= :disparo_de'; $params[':disparo_de'] = $de . ' 00:00:00'; }
    if ($ate !== '') { $where[] = 'de.executado_em <= :disparo_ate'; $params[':disparo_ate'] = $ate . ' 23:59:59'; }
    if ($status === 'ok') $where[] = "de.status = 'ok'";
    elseif ($status === 'erro') $where[] = "de.status <> 'ok'";
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $st = $pdo->prepare("
            SELECT de.*, d.nome AS disparo_nome, d.tipo AS disparo_tipo,
                   u.nome AS user_nome, u.email AS user_email, u.telefone AS user_telefone, u.codigo_turma AS user_turma,
                   (SELECT il.codigo_turma FROM inscricao_logs il WHERE il.user_id = de.user_id ORDER BY il.created_at DESC LIMIT 1) AS ultima_turma
              FROM disparo_execucoes de
              LEFT JOIN disparos d ON d.id = de.disparo_id
              LEFT JOIN users u ON u.id = de.user_id
              $whereSql
          ORDER BY de.executado_em DESC, de.id DESC
             LIMIT :lim
        ");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $event = 'DISPARO_' . (int)($r['disparo_id'] ?? 0);
            $payload = json_encode([
                'disparo_id' => (int)($r['disparo_id'] ?? 0),
                'disparo_nome' => (string)($r['disparo_nome'] ?? ''),
                'disparo_tipo' => (string)($r['disparo_tipo'] ?? ''),
                'user_id' => (int)($r['user_id'] ?? 0),
                'aluno' => [
                    'nome' => (string)($r['user_nome'] ?? ''),
                    'email' => (string)($r['user_email'] ?? ''),
                    'telefone' => (string)($r['user_telefone'] ?? ''),
                    'codigo_turma' => (string)(($r['ultima_turma'] ?? '') ?: ($r['user_turma'] ?? '')),
                ],
                'status' => (string)($r['status'] ?? ''),
                'executado_em' => (string)($r['executado_em'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ok = (string)($r['status'] ?? '') === 'ok';
            $allRows[] = [
                'source' => 'disparo',
                'id' => (int)$r['id'],
                'created_at' => (string)$r['executado_em'],
                'evento' => $event,
                'grupo' => 'Disparos',
                'turma' => (string)(($r['ultima_turma'] ?? '') ?: ($r['user_turma'] ?? '')),
                'aluno_nome' => (string)($r['user_nome'] ?? ''),
                'aluno_email' => (string)($r['user_email'] ?? ''),
                'aluno_tel' => (string)($r['user_telefone'] ?? ''),
                'user_id' => (int)($r['user_id'] ?? 0),
                'ok' => $ok,
                'status_label' => $ok ? 'OK' : 'ERRO',
                'destino' => trim((string)($r['disparo_nome'] ?? 'Disparo')) ?: 'Disparo',
                'summary' => substr(trim((string)($r['resposta'] ?? '')), 0, 160),
                'payload' => $payload ?: '',
                'response' => (string)($r['resposta'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}
}

if (($source === '' || $source === 'cron_live') && logs_table_exists($pdo, 'live_turma_dispatch_logs')) {
    $where = [];
    $params = [];
    logs_add_like_any($where, $params, "'CRON_LIVE_TURMA'", 'evento', $eventos);
    logs_add_like_any($where, $params, 'turma_codigo', 'turma', $turmas);
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
                'manychat_ok' => (int)($r['manychat_ok'] ?? 0),
                'manychat_fail' => (int)($r['manychat_fail'] ?? 0),
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
                'summary' => 'Total: ' . (int)($r['total_alunos'] ?? 0) . ' | Elegiveis: ' . (int)($r['elegiveis'] ?? 0) . ' | SF OK: ' . (int)($r['sf_ok'] ?? 0) . ' | Manychat OK: ' . (int)($r['manychat_ok'] ?? 0) . ' | Excluidos: ' . logs_skipped_summary($r['skipped_json'] ?? null),
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
.logs-multi{position:relative;min-width:220px;}
.logs-multi summary{list-style:none;cursor:pointer;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);min-height:38px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
.logs-multi summary::-webkit-details-marker{display:none;}
.logs-multi summary:after{content:'v';font-size:10px;color:var(--muted);}
.logs-multi[open] summary{border-color:var(--primary);}
.logs-multi-menu{position:absolute;z-index:50;top:calc(100% + 6px);left:0;width:min(360px,90vw);max-height:320px;overflow:auto;border:1px solid var(--border);border-radius:12px;background:var(--bg-card);box-shadow:var(--shadow);padding:8px;}
.logs-multi-actions{display:flex;gap:8px;justify-content:space-between;position:sticky;top:-8px;background:var(--bg-card);padding:4px 0 8px;margin-bottom:4px;border-bottom:1px solid var(--border);}
.logs-multi-actions button{border:1px solid var(--border);background:rgba(15,23,42,.65);color:var(--text);border-radius:999px;padding:5px 8px;font-size:11px;cursor:pointer;}
.logs-multi-option{display:flex;align-items:center;gap:8px;padding:7px 6px;border-radius:8px;font-size:12px;}
.logs-multi-option:hover{background:rgba(148,163,184,.08);}
.logs-multi-option input{width:auto;padding:0;accent-color:var(--primary);}
.logs-multi-empty{padding:8px;color:var(--muted);font-size:12px;}
.logs-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px;}
.logs-kpi{border:1px solid var(--border);border-radius:12px;padding:12px;background:rgba(15,23,42,.35);}
.logs-kpi span{display:block;color:var(--muted);font-size:11px;font-weight:800;text-transform:uppercase;}
.logs-kpi strong{display:block;color:var(--text);font-size:24px;line-height:1.1;margin-top:3px;}
.log-source{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:900;border:1px solid var(--border);}
.log-source.webhook{color:#7dd3fc;background:rgba(56,189,248,.10);border-color:rgba(56,189,248,.25);}
.log-source.sf{color:#c4b5fd;background:rgba(167,139,250,.10);border-color:rgba(167,139,250,.25);}
.log-source.manychat{color:#f9a8d4;background:rgba(236,72,153,.10);border-color:rgba(236,72,153,.25);}
.log-source.disparo{color:#93c5fd;background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.25);}
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
            <div class="text-muted text-sm">Auditoria unificada de eventos, webhooks, SuperFuncionario, Manychat, disparos e cron de live. Logs com mais de 1 ano sao limpos automaticamente.</div>
        </div>
        <button class="logs-help-btn" type="button" onclick="document.getElementById('logsHelp').classList.toggle('open')" title="Descricao dos eventos">?</button>
    </div>
    <div class="log-help" id="logsHelp">
        <div class="log-help-grid">
            <div class="log-help-item"><b>Live turma</b>Disparos coletivos da live da turma. Ex.: LIVE_TURMA ou LIVE_TURMA_300526.</div>
            <div class="log-help-item"><b>Live reagendada</b>Eventos ligados ao reagendamento e lembretes individuais. Ex.: LIVE_REAGENDADA.</div>
            <div class="log-help-item"><b>Disparos</b>Execucoes dos disparos criados na tela Disparos, com sucesso/erro por aluno.</div>
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
                <option value="manychat" <?= $source==='manychat'?'selected':'' ?>>Manychat</option>
                <option value="disparo" <?= $source==='disparo'?'selected':'' ?>>Disparos</option>
                <option value="cron_live" <?= $source==='cron_live'?'selected':'' ?>>Cron live</option>
            </select>
        </div>
        <div class="field">
            <label>Grupo</label>
            <select name="grupo">
                <option value="" <?= $grupo===''?'selected':'' ?>>Todos</option>
                <?php foreach (['Cron live','Disparos','Live turma','Live reagendada','Live evento','Inscricao','Login','Certificado','WhatsApp','Geral'] as $g): ?>
                    <option value="<?= h($g) ?>" <?= $grupo===$g?'selected':'' ?>><?= h($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field wide">
            <label>Evento</label>
            <details class="logs-multi" data-multi-filter>
                <summary data-empty="Todos os eventos"><?= $eventos ? h(count($eventos) . ' selecionado(s)') : 'Todos os eventos' ?></summary>
                <div class="logs-multi-menu">
                    <div class="logs-multi-actions">
                        <button type="button" data-multi-select-all>Selecionar todos</button>
                        <button type="button" data-multi-clear>Limpar</button>
                    </div>
                    <?php if (!$eventoOptions): ?>
                        <div class="logs-multi-empty">Nenhum evento encontrado.</div>
                    <?php endif; ?>
                    <?php foreach ($eventoOptions as $opt): ?>
                        <label class="logs-multi-option">
                            <input type="checkbox" name="evento[]" value="<?= h($opt) ?>" <?= in_array($opt, $eventos, true) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <div class="field">
            <label>Turma</label>
            <details class="logs-multi" data-multi-filter>
                <summary data-empty="Todas as turmas"><?= $turmas ? h(count($turmas) . ' selecionada(s)') : 'Todas as turmas' ?></summary>
                <div class="logs-multi-menu">
                    <div class="logs-multi-actions">
                        <button type="button" data-multi-select-all>Selecionar todas</button>
                        <button type="button" data-multi-clear>Limpar</button>
                    </div>
                    <?php if (!$turmaOptions): ?>
                        <div class="logs-multi-empty">Nenhuma turma encontrada.</div>
                    <?php endif; ?>
                    <?php foreach ($turmaOptions as $opt): ?>
                        <label class="logs-multi-option">
                            <input type="checkbox" name="turma[]" value="<?= h($opt) ?>" <?= in_array($opt, $turmas, true) ? 'checked' : '' ?>>
                            <span><?= h($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-multi-filter]').forEach(function(box) {
        var summary = box.querySelector('summary');
        var empty = summary ? (summary.getAttribute('data-empty') || 'Todos') : 'Todos';
        function updateLabel() {
            if (!summary) return;
            var checked = Array.from(box.querySelectorAll('input[type="checkbox"]:checked')).map(function(input) {
                return input.value;
            });
            if (checked.length === 0) summary.textContent = empty;
            else if (checked.length === 1) summary.textContent = checked[0];
            else summary.textContent = checked.length + ' selecionados';
        }
        box.querySelectorAll('input[type="checkbox"]').forEach(function(input) {
            input.addEventListener('change', updateLabel);
        });
        var allBtn = box.querySelector('[data-multi-select-all]');
        if (allBtn) allBtn.addEventListener('click', function() {
            box.querySelectorAll('input[type="checkbox"]').forEach(function(input) { input.checked = true; });
            updateLabel();
        });
        var clearBtn = box.querySelector('[data-multi-clear]');
        if (clearBtn) clearBtn.addEventListener('click', function() {
            box.querySelectorAll('input[type="checkbox"]').forEach(function(input) { input.checked = false; });
            updateLabel();
        });
        updateLabel();
    });
    document.addEventListener('click', function(ev) {
        document.querySelectorAll('[data-multi-filter][open]').forEach(function(box) {
            if (!box.contains(ev.target)) box.removeAttribute('open');
        });
    });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>
