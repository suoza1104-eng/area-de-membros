<?php
declare(strict_types=1);

require_once __DIR__ . '/email_marketing.php';

function email_flow_error_text(Throwable $e): string
{
    $message = $e->getMessage();
    return function_exists('mb_substr') ? mb_substr($message, 0, 1000) : substr($message, 0, 1000);
}

function email_flow_render_user(array $user, array $job): array
{
    $extra = json_decode((string)($job['payload_json'] ?? ''), true);
    if (!is_array($extra)) $extra = [];
    foreach ($extra as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $user[$key] = $value;
        }
    }
    $user['id'] = (int)($job['user_id'] ?? ($user['id'] ?? 0));
    return $user;
}

function email_flow_engine_ensure_schema(PDO $pdo): void
{
    email_marketing_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_flow_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_code VARCHAR(100) NOT NULL,user_id INT NOT NULL,source_key VARCHAR(190) NOT NULL,payload_json LONGTEXT NULL,matched_flows INT UNSIGNED NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_email_flow_event(source_key),KEY idx_email_flow_event_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_flow_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,flow_id BIGINT UNSIGNED NOT NULL,version_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,user_id INT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'running',last_error VARCHAR(1000) NULL,started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,finished_at DATETIME NULL,UNIQUE KEY uk_email_flow_run(flow_id,version_id,event_id),KEY idx_email_flow_run_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_flow_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_id BIGINT UNSIGNED NOT NULL,node_id VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',available_at DATETIME NOT NULL,attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,lease_token VARCHAR(64) NULL,lease_until DATETIME NULL,input_json LONGTEXT NULL,output_json LONGTEXT NULL,last_error VARCHAR(1000) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_email_flow_job(run_id,node_id),KEY idx_email_flow_job_due(status,available_at),KEY idx_email_flow_job_lease(lease_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function email_flow_trigger_matches(array $node, array $user, array $extra, string $event, int $flowId = 0, int $versionId = 0): bool
{
    $c = $node['config'] ?? [];
    if (strtoupper((string)($c['event'] ?? '')) !== strtoupper($event)) return false;
    if ($event === 'LIVE_LEMBRETE_AGENDADO' && isset($extra['_scheduled_flow_id'])) {
        $kind = (string)($extra['_scheduled_flow_kind'] ?? 'email');
        if ($kind !== 'email' || (int)$extra['_scheduled_flow_id'] !== $flowId || (int)($extra['_scheduled_version_id'] ?? 0) !== $versionId || (string)($extra['_scheduled_node_id'] ?? '') !== (string)($node['id'] ?? '')) return false;
    }
    $filter = trim((string)($c['filter'] ?? ''));
    if ($filter === '') return true;
    foreach ([$user['codigo_turma'] ?? '', $extra['codigo_turma'] ?? '', $extra['turma']['codigo'] ?? ''] as $v) {
        if (strcasecmp(trim((string)$v), $filter) === 0) return true;
    }
    return false;
}

function email_flow_capture_event(PDO $pdo, string $event, int $userId, array $extra = []): int
{
    $settings = email_settings($pdo);
    if (($settings['engine_enabled'] ?? '0') !== '1' || $userId < 1 || trim($event) === '') return 0;
    email_flow_engine_ensure_schema($pdo);
    $flows = $pdo->query("SELECT f.id flow_id,f.current_version_id,v.graph_json FROM email_flows f JOIN email_flow_versions v ON v.id=f.current_version_id WHERE f.status='active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$flows) return 0;
    $user = buscar_usuario_por_id($userId) ?: ['id' => $userId];
    $payload = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $source = hash('sha256', strtoupper($event) . '|' . $userId . '|' . ($extra['event_id'] ?? $extra['transaction_code'] ?? $extra['lesson_id'] ?? $payload));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT IGNORE INTO email_flow_events(event_code,user_id,source_key,payload_json) VALUES(:e,:u,:s,:p)');
        $st->execute(['e' => $event, 'u' => $userId, 's' => $source, 'p' => $payload]);
        if (!$st->rowCount()) {
            $pdo->rollBack();
            return 0;
        }
        $eventId = (int)$pdo->lastInsertId();
        $matched = 0;
        foreach ($flows as $f) {
            $g = json_decode((string)$f['graph_json'], true);
            $trigger = null;
            foreach ($g['nodes'] ?? [] as $n) {
                if (($n['type'] ?? '') === 'trigger') {
                    $trigger = $n;
                    break;
                }
            }
            if (!$trigger || !email_flow_trigger_matches($trigger, $user, $extra, $event, (int)$f['flow_id'], (int)$f['current_version_id'])) continue;
            $st = $pdo->prepare("INSERT IGNORE INTO email_flow_runs(flow_id,version_id,event_id,user_id,status) VALUES(:f,:v,:e,:u,'running')");
            $st->execute(['f' => $f['flow_id'], 'v' => $f['current_version_id'], 'e' => $eventId, 'u' => $userId]);
            if (!$st->rowCount()) continue;
            $run = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO email_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'queued',NOW(),:i)")
                ->execute(['r' => $run, 'n' => $trigger['id'], 'i' => json_encode(['event' => $event])]);
            $matched++;
        }
        $pdo->prepare('UPDATE email_flow_events SET matched_flows=:m WHERE id=:id')->execute(['m' => $matched, 'id' => $eventId]);
        $pdo->commit();
        return $matched;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function email_flow_claim(PDO $pdo): ?array
{
    $token = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $id = (int)$pdo->query("SELECT id FROM email_flow_jobs WHERE ((status IN ('queued','retry','scheduled') AND available_at<=NOW()) OR (status='processing' AND lease_until<NOW())) ORDER BY available_at,id LIMIT 1 FOR UPDATE")->fetchColumn();
        if (!$id) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare("UPDATE email_flow_jobs SET status='processing',attempts=attempts+1,lease_token=:t,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND) WHERE id=:id")
            ->execute(['t' => $token, 'id' => $id]);
        $pdo->commit();
        $st = $pdo->prepare("SELECT j.*,r.flow_id,r.version_id,r.event_id,r.user_id,r.status run_status,v.graph_json,e.event_code,e.payload_json FROM email_flow_jobs j JOIN email_flow_runs r ON r.id=j.run_id JOIN email_flow_versions v ON v.id=r.version_id JOIN email_flow_events e ON e.id=r.event_id WHERE j.id=:id AND j.lease_token=:t");
        $st->execute(['id' => $id, 't' => $token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function email_flow_has_tag(PDO $pdo, int $userId, string $tag): bool
{
    $st = $pdo->prepare('SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=:u AND t.nome=:t LIMIT 1');
    $st->execute(['u' => $userId, 't' => $tag]);
    return (bool)$st->fetchColumn();
}

function email_flow_rule(PDO $pdo, array $r, int $userId, array $user): bool
{
    $field = (string)($r['field'] ?? '');
    $op = (string)($r['operator'] ?? 'has');
    $rawValue = $r['value'] ?? '';
    $value = is_array($rawValue) ? '' : (string)$rawValue;
    $negative = in_array($op, ['not_has', 'not_equals'], true);
    $match = false;
    if ($field === 'tag') {
        $tags = is_array($rawValue) ? $rawValue : [$value];
        $tags = array_values(array_filter(array_map(static fn($tag) => trim((string)$tag), $tags), static fn($tag) => $tag !== ''));
        foreach ($tags as $tag) {
            if (email_flow_has_tag($pdo, $userId, $tag)) {
                $match = true;
                break;
            }
        }
    } elseif ($field === 'turma') {
        $match = strcasecmp((string)($user['codigo_turma'] ?? ''), $value) === 0;
    } elseif ($field === 'email') {
        $match = $op === 'equals' || $op === 'not_equals'
            ? strcasecmp((string)($user['email'] ?? ''), $value) === 0
            : str_contains(mb_strtolower((string)($user['email'] ?? '')), mb_strtolower($value));
    } elseif ($field === 'marketing_eligible') {
        $match = !email_is_suppressed($pdo, (string)($user['email'] ?? ''));
    } elseif (str_starts_with($field, 'email_') || str_starts_with($field, 'any_email_')) {
        $specific = str_starts_with($field, 'email_');
        $event = str_replace(['email_', 'any_email_'], ['', ''], $field);
        $statusMap = ['delivered' => 'delivered_at', 'opened' => 'first_opened_at', 'clicked' => 'first_clicked_at'];
        if (isset($statusMap[$event])) {
            $sql = 'SELECT 1 FROM email_messages WHERE user_id=:u AND ' . $statusMap[$event] . ' IS NOT NULL';
        } else {
            $status = ['bounced' => 'bounced', 'complaint' => 'complaint', 'unsubscribed' => 'unsubscribed'][$event] ?? '';
            $sql = "SELECT 1 FROM email_messages WHERE user_id=:u AND status=" . $pdo->quote($status);
        }
        $params = ['u' => $userId];
        if ($specific) {
            $sql .= ' AND template_version_id=:v';
            $params['v'] = (int)$value;
        }
        if ($field === 'email_clicked_link') {
            $sql = 'SELECT 1 FROM email_messages m JOIN email_link_events l ON l.message_id=m.id WHERE m.user_id=:u AND m.template_version_id=:v AND l.url LIKE :link';
            $params = ['u' => $userId, 'v' => (int)$value, 'link' => '%' . (string)($r['link'] ?? '') . '%'];
        }
        $st = $pdo->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        $match = (bool)$st->fetchColumn();
    } elseif ($field === 'engagement_count') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM email_messages WHERE user_id=:u AND (first_opened_at IS NOT NULL OR first_clicked_at IS NOT NULL)');
        $st->execute(['u' => $userId]);
        $actual = (int)$st->fetchColumn();
        $wanted = (int)$value;
        $match = $op === 'gte' ? $actual >= $wanted : ($op === 'lte' ? $actual <= $wanted : $actual === $wanted);
    }
    return $negative ? !$match : $match;
}

function email_flow_condition(PDO $pdo, array $config, int $userId, array $user): bool
{
    $results = [];
    foreach ($config['rules'] ?? [] as $r) $results[] = email_flow_rule($pdo, $r, $userId, $user);
    return ($config['logic'] ?? 'and') === 'or' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

function email_flow_next(array $g, string $node, string $handle = 'default'): ?string
{
    foreach ($g['edges'] ?? [] as $e) {
        if (($e['source'] ?? '') === $node && ($e['sourceHandle'] ?? 'default') === $handle) return (string)$e['target'];
    }
    return null;
}

function email_flow_send(PDO $pdo, array $settings, array $job, array $config, array $user): array
{
    $version = (int)($config['templateVersionId'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM email_template_versions WHERE id=:id');
    $st->execute(['id' => $version]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('Modelo do bloco de e-mail nao encontrado.');
    if (email_is_suppressed($pdo, (string)$user['email'])) return ['skipped' => 'suppressed'];
    $user = email_flow_render_user($user, $job);
    $key = hash('sha256', 'flow|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id']);
    $messageId = email_send_rendered_message($pdo, $settings, ['flow_id' => (int)$job['flow_id'], 'flow_run_id' => (int)$job['run_id']], $user, $v, $key, ['flow_id' => (string)$job['flow_id'], 'flow_run_id' => (string)$job['run_id']]);
    return ['message_id' => $messageId];
}

function email_flow_pick_ab_variant(array $variants, array $job): array
{
    $valid = [];
    $total = 0;
    foreach ($variants as $i => $v) {
        $weight = max(0, (int)($v['weight'] ?? 0));
        $version = (int)($v['templateVersionId'] ?? 0);
        if ($weight < 1 || $version < 1) continue;
        $v['weight'] = $weight;
        $v['_index'] = $i;
        $total += $weight;
        $valid[] = $v;
    }
    if (!$valid) throw new RuntimeException('Teste A/B/n sem variantes validas.');
    $seed = hexdec(substr(hash('sha256', 'ab_test|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id']), 0, 8));
    $slot = ($seed % $total) + 1;
    $acc = 0;
    foreach ($valid as $v) {
        $acc += (int)$v['weight'];
        if ($slot <= $acc) return $v;
    }
    return $valid[array_key_last($valid)];
}

function email_flow_send_ab_test(PDO $pdo, array $settings, array $job, array $config, array $user): array
{
    $variant = email_flow_pick_ab_variant(is_array($config['variants'] ?? null) ? $config['variants'] : [], $job);
    $version = (int)($variant['templateVersionId'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM email_template_versions WHERE id=:id');
    $st->execute(['id' => $version]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('Modelo da variante A/B/n nao encontrado.');
    $variantId = (string)($variant['id'] ?? ('v' . (($variant['_index'] ?? 0) + 1)));
    if (email_is_suppressed($pdo, (string)$user['email'])) return ['skipped' => 'suppressed', 'variant_id' => $variantId, 'template_version_id' => $version];
    $user = email_flow_render_user($user, $job);
    $key = hash('sha256', 'flow_ab|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id'] . '|' . $variantId);
    $messageId = email_send_rendered_message(
        $pdo,
        $settings,
        ['flow_id' => (int)$job['flow_id'], 'flow_run_id' => (int)$job['run_id']],
        $user,
        $v,
        $key,
        ['flow_id' => (string)$job['flow_id'], 'flow_run_id' => (string)$job['run_id'], 'ab_node' => (string)$job['node_id'], 'ab_variant' => $variantId]
    );
    return ['message_id' => $messageId, 'variant_id' => $variantId, 'template_version_id' => $version];
}

function email_flow_process_job(PDO $pdo, array $job): string
{
    $g = json_decode((string)$job['graph_json'], true) ?: [];
    $node = null;
    foreach ($g['nodes'] ?? [] as $n) {
        if (($n['id'] ?? '') === $job['node_id']) {
            $node = $n;
            break;
        }
    }
    if (!$node) throw new RuntimeException('Bloco nao encontrado.');
    $c = $node['config'] ?? [];
    $user = buscar_usuario_por_id((int)$job['user_id']) ?: ['id' => $job['user_id']];
    $type = $node['type'];
    $handle = 'default';
    $output = [];
    try {
        if ($type === 'condition') {
            $result = email_flow_condition($pdo, $c, (int)$job['user_id'], $user);
            $handle = $result ? 'yes' : 'no';
            $output = ['result' => $result];
        } elseif ($type === 'wait') {
            $input = json_decode((string)$job['input_json'], true) ?: [];
            if (empty($input['_waited'])) {
                $due = (new DateTimeImmutable())->modify('+' . max(1, (int)$c['duration']) . ' ' . ($c['unit'] ?? 'hours'))->format('Y-m-d H:i:s');
                $input['_waited'] = true;
                $pdo->prepare("UPDATE email_flow_jobs SET status='scheduled',available_at=:d,input_json=:i,attempts=0,lease_token=NULL,lease_until=NULL WHERE id=:id")
                    ->execute(['d' => $due, 'i' => json_encode($input), 'id' => $job['id']]);
                return 'scheduled';
            }
        } elseif ($type === 'email') {
            $output = email_flow_send($pdo, email_settings($pdo), $job, $c, $user);
        } elseif ($type === 'ab_test') {
            $output = email_flow_send_ab_test($pdo, email_settings($pdo), $job, $c, $user);
        } elseif ($type === 'action') {
            (($c['action'] ?? '') === 'remove_tag'
                ? remover_tag_usuario((int)$job['user_id'], (string)$c['tag'])
                : adicionar_tag((int)$job['user_id'], (string)$c['tag'], 'email_flow', (int)$job['run_id']));
        } elseif ($type === 'integration') {
            require_once __DIR__ . '/push_flow_engine.php';
            $output = push_flow_dispatch_integration($pdo, $c, $user, json_decode((string)$job['payload_json'], true) ?: [], $job);
        } elseif (!in_array($type, ['trigger', 'end'], true)) {
            throw new RuntimeException('Bloco nao suportado.');
        }

        $next = $type === 'end' ? null : email_flow_next($g, (string)$job['node_id'], $handle);
        $pdo->beginTransaction();
        if ($next) {
            $pdo->prepare("INSERT IGNORE INTO email_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'queued',NOW(),'{}')")
                ->execute(['r' => $job['run_id'], 'n' => $next]);
        }
        $pdo->prepare("UPDATE email_flow_jobs SET status='completed',output_json=:o,lease_token=NULL,lease_until=NULL WHERE id=:id")
            ->execute(['o' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $job['id']]);
        if (!$next) $pdo->prepare("UPDATE email_flow_runs SET status='completed',finished_at=NOW() WHERE id=:id")->execute(['id' => $job['run_id']]);
        $pdo->commit();
        return 'completed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $retry = (int)$job['attempts'] < (int)$job['max_attempts'];
        $error = email_flow_error_text($e);
        $pdo->prepare("UPDATE email_flow_jobs SET status=:s,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id")
            ->execute(['s' => $retry ? 'retry' : 'failed', 'e' => $error, 'id' => $job['id']]);
        if (!$retry) {
            $pdo->prepare("UPDATE email_flow_runs SET status='failed',last_error=:e,finished_at=NOW() WHERE id=:id")
                ->execute(['e' => $error, 'id' => $job['run_id']]);
        }
        return $retry ? 'retry' : 'failed';
    }
}

function email_flow_process_queue(PDO $pdo, int $limit = 25): array
{
    email_flow_engine_ensure_schema($pdo);
    if ((email_settings($pdo)['engine_enabled'] ?? '0') !== '1') return ['processed' => 0, 'completed' => 0, 'scheduled' => 0, 'failed' => 0, 'reason' => 'engine_paused'];
    $done = ['processed' => 0, 'completed' => 0, 'scheduled' => 0, 'failed' => 0];
    for ($i = 0; $i < $limit; $i++) {
        if (!$job = email_flow_claim($pdo)) break;
        $status = email_flow_process_job($pdo, $job);
        $done['processed']++;
        if (isset($done[$status])) $done[$status]++;
    }
    return $done;
}
