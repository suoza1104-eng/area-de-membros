<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function disparos_engine_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS disparos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(200) NOT NULL,
        status ENUM('rascunho','aguardando','executando','pausado','concluido','erro') NOT NULL DEFAULT 'rascunho',
        tipo ENUM('instantaneo','agendado') NOT NULL DEFAULT 'instantaneo',
        agendado_em DATETIME NULL,
        intervalo_seg INT UNSIGNED NOT NULL DEFAULT 0,
        filtros_json MEDIUMTEXT NULL,
        acoes_json MEDIUMTEXT NULL,
        batch_size INT UNSIGNED NOT NULL DEFAULT 1,
        total_enviados INT UNSIGNED NOT NULL DEFAULT 0,
        total_erros INT UNSIGNED NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS disparo_execucoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        disparo_id INT NOT NULL,
        user_id INT NOT NULL,
        status ENUM('ok','erro') NOT NULL DEFAULT 'ok',
        resposta TEXT NULL,
        executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (disparo_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_tags_sistema (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        tag VARCHAR(200) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_tag (user_id, tag),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'intervalo_ms INT UNSIGNED NOT NULL DEFAULT 0',
        'batch_size INT UNSIGNED NOT NULL DEFAULT 1',
        'horario_ativo TINYINT(1) NOT NULL DEFAULT 0',
        'horario_inicio TIME NULL',
        'horario_fim TIME NULL',
        "dias_semana VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6'",
        'proximo_lote_em DATETIME NULL',
    ] as $col) {
        try { $pdo->exec("ALTER TABLE disparos ADD COLUMN $col"); } catch (Throwable $e) {}
    }

    // Um envio so pode existir uma vez por (disparo,aluno). Sem essa constraint, dois
    // processos rodando ao mesmo tempo para a mesma campanha podiam mandar a mesma
    // mensagem duas vezes para o mesmo aluno (a checagem NOT EXISTS sozinha nao
    // protege contra corrida entre processos concorrentes).
    try {
        $pdo->exec("
            DELETE t1 FROM disparo_execucoes t1
            INNER JOIN disparo_execucoes t2
                    ON t1.disparo_id = t2.disparo_id
                   AND t1.user_id = t2.user_id
                   AND t1.id > t2.id
        ");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE disparo_execucoes ADD UNIQUE KEY uk_disparo_user (disparo_id, user_id)");
    } catch (Throwable $e) {}
}

function disparos_engine_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        try { $pdo->query("SELECT 1 FROM `$table` LIMIT 0"); $cache[$table] = true; }
        catch (Throwable $e) { $cache[$table] = false; }
    }
    return $cache[$table];
}

function disparos_engine_audience_where(array $filters, PDO $pdo): array
{
    $inc = $filters['inclusao'] ?? [];
    $exc = $filters['exclusao'] ?? [];
    $logic = strtoupper((string)($filters['logica_inclusao'] ?? 'AND'));
    if (!in_array($logic, ['AND', 'OR'], true)) $logic = 'AND';
    $params = [];
    $p = 0;
    $next = static function () use (&$p): string { return ':p' . (++$p); };
    $build = static function (array $rules) use (&$params, $next, $pdo): array {
        $out = [];
        foreach ($rules as $rule) {
            $type = (string)($rule['tipo'] ?? '');
            $value = trim((string)($rule['valor'] ?? ''));
            if ($type === 'turma' && $value !== '') {
                $pk = $next(); $out[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id=u.id AND il.codigo_turma=$pk)"; $params[$pk] = $value;
            } elseif ($type === 'contato' && $value !== '') {
                $pk = $next(); $out[] = "(u.nome LIKE $pk OR u.email LIKE $pk OR u.telefone LIKE $pk)"; $params[$pk] = '%' . $value . '%';
            } elseif (($type === 'tag_sf' || $type === 'tag_sistema') && $value !== '') {
                $pk = $next();
                $out[] = "(
                    EXISTS(SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=u.id AND t.nome=$pk)
                    OR EXISTS(SELECT 1 FROM user_tags_sistema uts WHERE uts.user_id=u.id AND uts.tag=$pk)
                )";
                $params[$pk] = $value;
            } elseif ($type === 'inscricao_de' && $value !== '') {
                $pk = $next(); $out[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id=u.id AND DATE(il.created_at)>=$pk)"; $params[$pk] = $value;
            } elseif ($type === 'inscricao_ate' && $value !== '') {
                $pk = $next(); $out[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id=u.id AND DATE(il.created_at)<=$pk)"; $params[$pk] = $value;
            } elseif ($type === 'ultimo_de' && $value !== '') {
                $pk = $next(); $out[] = "(SELECT MAX(DATE(il.created_at)) FROM inscricao_logs il WHERE il.user_id=u.id)>=$pk"; $params[$pk] = $value;
            } elseif ($type === 'ultimo_ate' && $value !== '') {
                $pk = $next(); $out[] = "(SELECT MAX(DATE(il.created_at)) FROM inscricao_logs il WHERE il.user_id=u.id)<=$pk"; $params[$pk] = $value;
            } elseif (($type === 'qtd_min' || $type === 'qtd_max') && $value !== '') {
                $pk = $next(); $op = $type === 'qtd_min' ? '>=' : '<='; $out[] = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id=u.id) $op $pk"; $params[$pk] = (int)$value;
            } elseif ($type === 'tem_cert' && disparos_engine_table_exists($pdo, 'certificates')) {
                $out[] = "EXISTS(SELECT 1 FROM certificates c WHERE c.user_id=u.id)";
            } elseif ($type === 'nao_tem_cert' && disparos_engine_table_exists($pdo, 'certificates')) {
                $out[] = "NOT EXISTS(SELECT 1 FROM certificates c WHERE c.user_id=u.id)";
            } elseif ($type === 'evento_webhook' && $value !== '') {
                $pk = $next(); $out[] = "EXISTS(SELECT 1 FROM webhook_logs wl WHERE wl.user_id=u.id AND wl.evento=$pk)"; $params[$pk] = $value;
            } elseif ($type === 'ja_recebeu' && $value !== '') {
                $pk = $next(); $out[] = "EXISTS(SELECT 1 FROM disparo_execucoes de2 WHERE de2.user_id=u.id AND de2.disparo_id=$pk AND de2.status='ok')"; $params[$pk] = (int)$value;
            }
        }
        return $out;
    };

    $incClauses = $build(is_array($inc) ? $inc : []);
    $excClauses = $build(is_array($exc) ? $exc : []);
    $where = 'u.id > 0';
    if ($incClauses) $where .= ' AND (' . implode(" $logic ", $incClauses) . ')';
    if ($excClauses) $where .= ' AND NOT (' . implode(' OR ', $excClauses) . ')';
    return ['where' => $where, 'params' => $params];
}

function disparos_engine_magic_link(PDO $pdo, int $userId): string
{
    if (function_exists('gerar_magic_link')) {
        try { return gerar_magic_link($userId, 30, false); } catch (Throwable $e) {}
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS magic_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            one_shot TINYINT(1) NOT NULL DEFAULT 0,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT NOW(),
            UNIQUE KEY uk_ml_token (token),
            INDEX idx_ml_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO magic_links (user_id, token, expires_at, one_shot) VALUES (:u,:t,DATE_ADD(NOW(),INTERVAL 30 DAY),0)")
            ->execute(['u'=>$userId, 't'=>$token]);
        return rtrim((string)BASE_URL, '/') . '/login.php?am=' . $token;
    } catch (Throwable $e) {
        return '';
    }
}

function disparos_engine_user_value(PDO $pdo, array $user, string $key): string
{
    if ($key === 'magic_link') return disparos_engine_magic_link($pdo, (int)($user['id'] ?? 0));
    $map = [
        'turma' => $user['ultima_turma'] ?? ($user['codigo_turma'] ?? ''),
        'codigo_turma' => $user['ultima_turma'] ?? ($user['codigo_turma'] ?? ''),
        'data_live' => $user['turma_live_at'] ?? ($user['user_data_live'] ?? ($user['data_live'] ?? '')),
        'live' => $user['turma_live_at'] ?? ($user['user_data_live'] ?? ($user['data_live'] ?? '')),
    ];
    $value = array_key_exists($key, $map) ? $map[$key] : ($user[$key] ?? '');
    if (is_array($value) || is_object($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return (string)$value;
}

function disparos_engine_event(array $actions): string
{
    foreach ($actions as $action) {
        if (is_array($action) && ($action['tipo'] ?? '') === 'evento' && trim((string)($action['valor'] ?? '')) !== '') {
            return mb_substr(trim((string)$action['valor']), 0, 120);
        }
    }
    return 'DISPARO_MANUAL';
}

function disparos_engine_provider(array $actions): string
{
    foreach ($actions as $action) {
        if (is_array($action) && ($action['tipo'] ?? '') === 'provider') {
            $provider = strtolower(trim((string)($action['valor'] ?? '')));
            if (in_array($provider, ['sf', 'superfuncionario', 'manychat', 'webhook'], true)) return $provider === 'superfuncionario' ? 'sf' : $provider;
        }
    }
    return 'sf';
}

function disparos_engine_extra(PDO $pdo, array $user, array $actions, string $event): array
{
    return [
        'origem' => 'disparo_manual',
        'evento' => $event,
        'turma' => disparos_engine_user_value($pdo, $user, 'turma'),
        'codigo_turma' => disparos_engine_user_value($pdo, $user, 'codigo_turma'),
        'data_live' => disparos_engine_user_value($pdo, $user, 'data_live'),
        'acoes' => $actions,
    ];
}

function disparos_engine_resolve_value(PDO $pdo, array $user, string $value, array $actions = []): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (str_starts_with($value, 'literal:')) return substr($value, 8);
    if (str_starts_with($value, 'user.')) return disparos_engine_user_value($pdo, $user, substr($value, 5));
    if (str_starts_with($value, 'users.')) return disparos_engine_user_value($pdo, $user, substr($value, 6));
    if (str_starts_with($value, 'extra.')) return disparos_engine_user_value($pdo, $user, substr($value, 6));
    if ($value === 'evento') return disparos_engine_event($actions);
    if ($value === 'timestamp') return date('c');
    $value = str_replace(['{{evento}}', '{{ timestamp }}', '{{timestamp}}'], [disparos_engine_event($actions), date('c'), date('c')], $value);
    return preg_replace_callback('/\{\{\s*(user|users|extra)\.([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($pdo, $user) {
        return disparos_engine_user_value($pdo, $user, $m[2]);
    }, $value) ?? '';
}

function disparos_engine_sf_manual(PDO $pdo, array $user, array $actions): array
{
    require_once __DIR__ . '/superfuncionario_dispatcher.php';
    $cfg = $pdo->query("SELECT * FROM superfuncionario_config ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cfg || empty($cfg['is_enabled'])) return ['ok'=>false, 'msg'=>'SF desabilitado'];
    if (empty($cfg['base_url']) || empty($cfg['token'])) return ['ok'=>false, 'msg'=>'SF sem config'];
    $sfActions = [];
    foreach ($actions as $action) {
        if (!is_array($action)) continue;
        if (($action['tipo'] ?? '') === 'flow' && trim((string)($action['valor'] ?? '')) !== '') {
            foreach (array_filter(array_map('trim', explode(',', (string)$action['valor']))) as $flowId) {
                if (ctype_digit($flowId)) $sfActions[] = ['action'=>'send_flow', 'flow_id'=>(int)$flowId];
            }
        } elseif (($action['tipo'] ?? '') === 'tag_sf' && trim((string)($action['valor'] ?? '')) !== '') {
            $sfActions[] = ['action'=>'add_tag', 'tag_name'=>trim((string)$action['valor'])];
        } elseif (($action['tipo'] ?? '') === 'custom_field' && trim((string)($action['campo'] ?? '')) !== '') {
            $sfActions[] = [
                'action' => 'set_field_value',
                'field_name' => trim((string)$action['campo']),
                'value' => disparos_engine_resolve_value($pdo, $user, (string)($action['valor'] ?? ''), $actions),
            ];
        }
    }
    if (!$sfActions) return ['ok'=>false, 'msg'=>'Nenhuma acao SF valida foi montada'];
    $payloadArr = [
        'email' => $user['email'] ?? '',
        'phone' => $user['telefone'] ?? '',
        'first_name' => $user['nome'] ?? '',
        'actions' => $sfActions,
    ];
    $endpoint = trim((string)($cfg['default_endpoint'] ?? '')) ?: '/api/contacts';
    $url = rtrim((string)$cfg['base_url'], '/') . '/' . ltrim($endpoint, '/');
    $headerMode = strtolower((string)($cfg['header_mode'] ?? 'x-access-token'));
    $authHeader = $headerMode === 'bearer' ? 'Authorization: Bearer ' . $cfg['token'] : 'X-ACCESS-TOKEN: ' . $cfg['token'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => (int)($cfg['timeout_seconds'] ?? 10),
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', $authHeader],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $respText = $resp === false ? '' : (string)$resp;
    $respJson = json_decode(trim($respText), true);
    $apiSuccess = is_array($respJson) && array_key_exists('success', $respJson) ? (bool)$respJson['success'] : null;
    $ok = $code >= 200 && $code < 300 && $apiSuccess !== false;
    return ['ok'=>$ok, 'msg'=>json_encode(['http_status'=>$code, 'api_success'=>$apiSuccess, 'response'=>is_array($respJson) ? $respJson : $respText, 'curl_error'=>$curlErr ?: null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

function disparos_engine_manychat_value(string $field, string $value): string
{
    if (!preg_match('/(^|_)(data|date|inicio|fim|start|end)(_|$)/i', $field)) return $value;
    $value = trim($value);
    if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) return $value;
    $tz = new DateTimeZone('America/Sao_Paulo');
    foreach (['d/m/Y H:i:s','d/m/Y H:i','d/m/Y','Y-m-d H:i:s','Y-m-d H:i','Y-m-d','Y-m-d\TH:i:s','Y-m-d\TH:i'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $value, $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt instanceof DateTimeImmutable && (($errors['warning_count'] ?? 0) + ($errors['error_count'] ?? 0)) === 0) return $dt->format('Y-m-d\TH:i:sP');
    }
    return $value;
}

function disparos_engine_manychat_manual(PDO $pdo, array $user, array $actions): array
{
    require_once __DIR__ . '/webhook_dispatcher.php';
    require_once __DIR__ . '/manychat_dispatcher.php';
    $manualActions = array_values(array_filter($actions, static fn($a) => is_array($a) && in_array(($a['tipo'] ?? ''), ['flow','tag_sf','custom_field'], true)));
    if (!$manualActions) return ['ok'=>false, 'msg'=>'Nenhuma acao ManyChat manual foi montada'];
    $event = disparos_engine_event($actions);
    $cfg = mc_get_config($pdo);
    if ((int)$cfg['is_enabled'] !== 1 || trim((string)$cfg['token']) === '') return ['ok'=>false, 'msg'=>'ManyChat desabilitado ou sem token'];
    $userRow = mc_get_user_row($pdo, $user);
    $subscriberId = mc_get_or_create_subscriber($pdo, $cfg, $event, null, $userRow);
    if ($subscriberId === '') return ['ok'=>false, 'msg'=>'ManyChat: subscriber nao encontrado/criado'];
    $ok = false; $results = [];
    foreach ($manualActions as $action) {
        $type = (string)($action['tipo'] ?? '');
        if ($type === 'tag_sf') {
            $tag = trim((string)($action['valor'] ?? ''));
            if ($tag === '') continue;
            $res = mc_api($pdo, $cfg, $event, null, 'add_tag_manual', 'POST', '/fb/subscriber/addTagByName', ['subscriber_id'=>$subscriberId, 'tag_name'=>$tag], $subscriberId, ['origem'=>'disparo_manual']);
            $ok = $ok || (bool)$res['ok'];
            $results[] = ['acao'=>'tag', 'valor'=>$tag, 'ok'=>(bool)$res['ok'], 'http_status'=>$res['http_status'] ?? null];
        } elseif ($type === 'flow') {
            foreach (array_filter(array_map('trim', explode(',', (string)($action['valor'] ?? '')))) as $flowNs) {
                $res = mc_api($pdo, $cfg, $event, null, 'send_flow_manual', 'POST', '/fb/sending/sendFlow', ['subscriber_id'=>$subscriberId, 'flow_ns'=>$flowNs], $subscriberId, ['origem'=>'disparo_manual']);
                $ok = $ok || (bool)$res['ok'];
                $results[] = ['acao'=>'flow', 'valor'=>$flowNs, 'ok'=>(bool)$res['ok'], 'http_status'=>$res['http_status'] ?? null];
            }
        } elseif ($type === 'custom_field') {
            $fieldName = trim((string)($action['campo'] ?? ''));
            if ($fieldName === '') continue;
            $raw = disparos_engine_resolve_value($pdo, $userRow, (string)($action['valor'] ?? ''), $actions);
            $field = function_exists('mc_prepare_custom_field')
                ? mc_prepare_custom_field((string)($action['valor'] ?? ''), $fieldName, $raw)
                : ['field_name'=>$fieldName, 'field_value'=>disparos_engine_manychat_value($fieldName, $raw)];
            $body = ['subscriber_id'=>$subscriberId, 'field_value'=>$field['field_value']];
            if (!empty($field['field_id'])) {
                $body['field_id'] = (int)$field['field_id'];
                $path = '/fb/subscriber/setCustomField';
            } else {
                $body['field_name'] = (string)($field['field_name'] ?? $fieldName);
                $path = '/fb/subscriber/setCustomFieldByName';
            }
            $res = mc_api($pdo, $cfg, $event, null, 'set_custom_field_manual', 'POST', $path, $body, $subscriberId, ['origem'=>'disparo_manual']);
            $ok = $ok || (bool)$res['ok'];
            $results[] = ['acao'=>'custom_field', 'campo'=>$fieldName, 'ok'=>(bool)$res['ok'], 'http_status'=>$res['http_status'] ?? null];
        }
    }
    return ['ok'=>$ok, 'msg'=>json_encode(['provider'=>'manychat', 'subscriber_id'=>$subscriberId, 'results'=>$results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

function disparos_engine_send(PDO $pdo, array $user, array $actions): array
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid > 0 && function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $uid)) {
        return ['ok'=>false, 'msg'=>'Aluno bloqueado para disparos'];
    }
    $provider = disparos_engine_provider($actions);
    $event = disparos_engine_event($actions);
    $extra = disparos_engine_extra($pdo, $user, $actions, $event);
    try {
        $hasManualActions = (bool)array_filter($actions, static fn($a) => is_array($a) && in_array(($a['tipo'] ?? ''), ['flow','tag_sf','custom_field'], true));
        if ($provider === 'sf' && $hasManualActions) return disparos_engine_sf_manual($pdo, $user, $actions);
        if ($provider === 'manychat' && $hasManualActions) return disparos_engine_manychat_manual($pdo, $user, $actions);
        if ($provider === 'sf') {
            require_once __DIR__ . '/superfuncionario_dispatcher.php';
            $ok = sf_disparar_evento($pdo, $event, $user, $extra);
            return ['ok'=>$ok, 'msg'=>$ok ? 'SF: evento disparado' : 'SF: nenhuma regra ativa aceitou o evento'];
        }
        if ($provider === 'manychat') {
            require_once __DIR__ . '/webhook_dispatcher.php';
            require_once __DIR__ . '/manychat_dispatcher.php';
            $ok = mc_disparar_evento($pdo, $event, $user, $extra);
            return ['ok'=>$ok, 'msg'=>$ok ? 'ManyChat: evento disparado' : 'ManyChat: nenhuma regra ativa aceitou o evento'];
        }
        require_once __DIR__ . '/webhook_dispatcher.php';
        disparar_evento_webhooks($pdo, $event, $user, $extra);
        return ['ok'=>true, 'msg'=>'Webhook: evento disparado'];
    } catch (Throwable $e) {
        return ['ok'=>false, 'msg'=>$provider . ': ' . $e->getMessage()];
    }
}

function disparos_engine_apply_tags(PDO $pdo, int $userId, array $actions): void
{
    foreach ($actions as $action) {
        if (is_array($action) && ($action['tipo'] ?? '') === 'tag_sistema' && trim((string)($action['valor'] ?? '')) !== '') {
            try {
                $pdo->prepare("INSERT IGNORE INTO user_tags_sistema (user_id, tag, criado_em) VALUES (:u,:t,NOW())")
                    ->execute(['u'=>$userId, 't'=>trim((string)$action['valor'])]);
            } catch (Throwable $e) {}
        }
    }
}

function disparos_engine_within_window(array $campaign, ?DateTimeImmutable $now = null): bool
{
    if ((int)($campaign['horario_ativo'] ?? 0) !== 1) return true;
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $dow = (int)$now->format('w');
    $days = array_map('intval', array_filter(array_map('trim', explode(',', (string)($campaign['dias_semana'] ?? '0,1,2,3,4,5,6'))), 'strlen'));
    if (!in_array($dow, $days, true) && !($dow === 0 && in_array(7, $days, true))) return false;
    $toMin = static function (?string $hm, int $fallback): int {
        if (!$hm || !preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m)) return $fallback;
        return ((int)$m[1]) * 60 + (int)$m[2];
    };
    $cur = ((int)$now->format('H')) * 60 + (int)$now->format('i');
    $start = $toMin($campaign['horario_inicio'] ?? null, 0);
    $end = $toMin($campaign['horario_fim'] ?? null, 1439);
    if ($start <= $end) return $cur >= $start && $cur <= $end;
    return $cur >= $start || $cur <= $end;
}

function disparos_engine_execute_batch(PDO $pdo, int $campaignId, int $maxBatchSize = 25): array
{
    $st = $pdo->prepare('SELECT * FROM disparos WHERE id=:id LIMIT 1');
    $st->execute(['id'=>$campaignId]);
    $campaign = $st->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) return ['ok'=>false, 'msg'=>'Disparo nao encontrado'];
    if (!disparos_engine_within_window($campaign)) {
        $pdo->prepare("UPDATE disparos SET status='aguardando' WHERE id=:id AND status='executando'")->execute(['id'=>$campaignId]);
        return ['ok'=>true, 'waiting'=>true, 'processados'=>0, 'done'=>false];
    }

    $limit = max(1, min($maxBatchSize, (int)($campaign['batch_size'] ?? 1) ?: 1));
    $filters = json_decode((string)($campaign['filtros_json'] ?? '{}'), true) ?: [];
    $actions = json_decode((string)($campaign['acoes_json'] ?? '[]'), true) ?: [];
    $pdo->prepare("UPDATE disparos SET status='executando' WHERE id=:id AND status IN ('aguardando','executando')")->execute(['id'=>$campaignId]);
    $aw = disparos_engine_audience_where($filters, $pdo);
    $sql = "SELECT u.*,
                   u.codigo_turma,
                   u.data_live AS user_data_live,
                   u.turma_live_at,
                   (SELECT il2.codigo_turma FROM inscricao_logs il2 WHERE il2.user_id=u.id ORDER BY il2.created_at DESC LIMIT 1) AS ultima_turma,
                   (SELECT t.data_live FROM turmas t WHERE t.codigo=(SELECT il3.codigo_turma FROM inscricao_logs il3 WHERE il3.user_id=u.id ORDER BY il3.created_at DESC LIMIT 1) LIMIT 1) AS data_live
              FROM users u
             WHERE {$aw['where']}
               AND NOT EXISTS (SELECT 1 FROM disparo_execucoes de_done WHERE de_done.disparo_id=:campaign AND de_done.user_id=u.id)
             ORDER BY u.id ASC
             LIMIT $limit";
    $params = $aw['params'];
    $params[':campaign'] = $campaignId;
    $users = $pdo->prepare($sql);
    $users->execute($params);
    $rows = $users->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sent = 0; $errors = 0;
    foreach ($rows as $user) {
        // disparos_engine_send() ja' captura falha do provedor (API fora do ar, timeout,
        // etc.) e devolve ok=false — mas um problema inesperado aqui (ex.: soluco pontual
        // de banco na hora de gravar) nao pode derrubar o restante do lote nem travar os
        // proximos alunos. Se algo assim acontecer, nenhuma linha e' gravada para este
        // aluno e ele e' automaticamente tentado de novo no proximo tick do cron.
        try {
            $result = disparos_engine_send($pdo, $user, $actions);
            disparos_engine_apply_tags($pdo, (int)$user['id'], $actions);
            $status = !empty($result['ok']) ? 'ok' : 'erro';
            // INSERT IGNORE + a UNIQUE KEY em (disparo_id,user_id) garantem que, mesmo se
            // dois processos tentarem processar esta campanha ao mesmo tempo, o mesmo
            // aluno nunca e' contado/enviado duas vezes: quem chega depois so' descarta.
            $insert = $pdo->prepare("INSERT IGNORE INTO disparo_execucoes (disparo_id,user_id,status,resposta) VALUES (:d,:u,:s,:r)");
            $insert->execute(['d'=>$campaignId, 'u'=>(int)$user['id'], 's'=>$status, 'r'=>mb_substr((string)($result['msg'] ?? ''), 0, 5000)]);
            if ($insert->rowCount() === 0) continue;
            if ($status === 'ok') $sent++; else $errors++;
        } catch (Throwable $e) {
            error_log('disparos_engine_execute_batch: falha inesperada no aluno ' . (int)($user['id'] ?? 0) . ' da campanha ' . $campaignId . ': ' . $e->getMessage());
            continue;
        }
    }
    $pdo->prepare("UPDATE disparos SET total_enviados=total_enviados+:sent,total_erros=total_erros+:errors WHERE id=:id")
        ->execute(['sent'=>$sent, 'errors'=>$errors, 'id'=>$campaignId]);
    $done = count($rows) < $limit;
    if ($done) {
        $pdo->prepare("UPDATE disparos SET status='concluido', proximo_lote_em=NULL WHERE id=:id")->execute(['id'=>$campaignId]);
    } else {
        // Respeita o ritmo configurado na campanha (ex.: "1 por minuto") mesmo depois
        // que a tela foi fechada: o proximo tick do cron so processa este disparo de
        // novo quando proximo_lote_em tiver passado.
        $waitSeconds = (int)ceil(max(0, (int)($campaign['intervalo_ms'] ?? 0)) / 1000);
        if ($waitSeconds > 0) {
            $pdo->prepare("UPDATE disparos SET proximo_lote_em=DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE id=:id")
                ->execute(['s'=>$waitSeconds, 'id'=>$campaignId]);
        } else {
            $pdo->prepare("UPDATE disparos SET proximo_lote_em=NULL WHERE id=:id")->execute(['id'=>$campaignId]);
        }
    }
    return ['ok'=>true, 'processados'=>count($rows), 'enviados'=>$sent, 'erros'=>$errors, 'done'=>$done];
}

function disparos_engine_process_due(PDO $pdo, int $maxSeconds = 45, int $maxBatchSize = 25): array
{
    disparos_engine_ensure_schema($pdo);
    $started = time();
    $stats = ['campaigns'=>0, 'batches'=>0, 'processed'=>0, 'sent'=>0, 'errors'=>0, 'waiting'=>0, 'completed'=>0];
    $due = $pdo->query("
        SELECT *
          FROM disparos
         WHERE status IN ('aguardando','executando')
           AND (tipo <> 'agendado' OR agendado_em IS NULL OR agendado_em <= NOW())
           AND (proximo_lote_em IS NULL OR proximo_lote_em <= NOW())
         ORDER BY FIELD(status,'executando','aguardando'), COALESCE(agendado_em,criado_em), id
         LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($due as $campaign) {
        if (time() - $started >= $maxSeconds) break;
        $stats['campaigns']++;
        if (!disparos_engine_within_window($campaign)) {
            if (($campaign['status'] ?? '') === 'executando') {
                $pdo->prepare("UPDATE disparos SET status='aguardando' WHERE id=:id")->execute(['id'=>(int)$campaign['id']]);
            }
            $stats['waiting']++;
            continue;
        }
        // Uma campanha com problema (ex.: filtro salvo invalido) nao pode travar as
        // demais: se disparos_engine_execute_batch() explodir, registra o erro e segue
        // para a proxima campanha devida neste mesmo tick, em vez de abortar tudo.
        try {
            $res = disparos_engine_execute_batch($pdo, (int)$campaign['id'], $maxBatchSize);
        } catch (Throwable $e) {
            error_log('disparos_engine_process_due: falha na campanha ' . (int)$campaign['id'] . ': ' . $e->getMessage());
            $stats['errors']++;
            continue;
        }
        $stats['batches']++;
        if (!empty($res['waiting'])) $stats['waiting']++;
        $stats['processed'] += (int)($res['processados'] ?? 0);
        $stats['sent'] += (int)($res['enviados'] ?? 0);
        $stats['errors'] += (int)($res['erros'] ?? 0);
        if (!empty($res['done'])) $stats['completed']++;
        // O ritmo de cada campanha (intervalo_ms) ja e' respeitado via proximo_lote_em
        // (gravado em disparos_engine_execute_batch) e pelo proprio filtro do $due
        // acima — nao ha necessidade de pausar aqui antes de olhar a proxima campanha.
    }
    return $stats;
}
