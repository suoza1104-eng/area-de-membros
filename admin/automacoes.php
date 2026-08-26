<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/automation_flows.php';
require_once __DIR__ . '/../app/automation_diagnostics.php';
require_once __DIR__ . '/../app/cron_manager.php';
proteger_admin();
$pdo = getPDO();
automation_flows_ensure_schema($pdo);
automation_diagnostics_ensure_schema($pdo);
email_marketing_ensure_schema($pdo);

if (empty($_SESSION['automation_admin_csrf'])) $_SESSION['automation_admin_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['automation_admin_csrf'];
$error = '';
$message = '';
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['automacoes']['escrever']);
}
function af_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function af_check_csrf(string $csrf): void { if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.'); }
function af_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function af_test_max_id(PDO $pdo, string $table): int
{
    try { return (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM {$table}")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function af_test_new_logs(PDO $pdo, string $table, int $afterId): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id>:id ORDER BY id ASC LIMIT 80");
        $st->execute(['id' => $afterId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
function af_flow_test_extra(PDO $pdo, array $trigger, array $user): array
{
    $filter = trim((string)($trigger['config']['filter'] ?? ''));
    $turma = $filter !== '' ? $filter : trim((string)($user['codigo_turma'] ?? $user['turma_codigo'] ?? ''));
    $liveAt = '';
    $codigoLive = '';
    $linkLive = 'trilha.php';
    if ($turma !== '') {
        try {
            $st = $pdo->prepare("SELECT data_live,codigo_live,webhook_live_url FROM turmas WHERE codigo=:c LIMIT 1");
            $st->execute(['c' => $turma]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $liveAt = trim((string)($row['data_live'] ?? ''));
            $codigoLive = trim((string)($row['codigo_live'] ?? ''));
            if (trim((string)($row['webhook_live_url'] ?? '')) !== '') $linkLive = trim((string)$row['webhook_live_url']);
        } catch (Throwable $e) {}
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    if ($liveAt === '') $liveAt = $now->format('Y-m-d H:i:s');
    $live = null;
    try { $live = new DateTimeImmutable($liveAt, new DateTimeZone('America/Sao_Paulo')); } catch (Throwable $e) {}
    return [
        'event_id' => 'automation-test-' . bin2hex(random_bytes(5)),
        'is_test' => true,
        'teste_painel' => true,
        'codigo_turma' => $turma,
        'codigo_live' => $codigoLive,
        'data_live' => $live ? $live->format('d/m/Y H:i:s') : $liveAt,
        'data_live_iso' => $live ? $live->format(DateTimeInterface::ATOM) : $liveAt,
        'live_at' => $liveAt,
        'hora_live' => $live ? $live->format('H:i:s') : '',
        'link_live' => $linkLive,
        'gateway' => 'teste_painel',
        'transaction_code' => 'TESTE-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
        'product_name' => 'Produto de Teste da Automacao',
        'product_code' => 'TESTE',
        'checkout_id' => 'TESTE',
        'valor_bruto' => 197.00,
        'valor_liquido' => 180.00,
        'moeda' => 'BRL',
    ];
}
function af_run_flow_test(PDO $pdo, array $flow, array $graph, int $userId): array
{
    $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
    $map = [];
    $trigger = null;
    foreach ($nodes as $node) {
        $id = (string)($node['id'] ?? '');
        if ($id === '') continue;
        $map[$id] = $node;
        if (($node['type'] ?? '') === 'trigger' && !$trigger) $trigger = $node;
    }
    if (!$trigger) throw new RuntimeException('O fluxo nao possui gatilho inicial.');
    $user = buscar_usuario_por_id($userId);
    if (!$user) throw new RuntimeException('Usuario de teste nao encontrado.');
    $event = (string)($trigger['config']['event'] ?? 'TESTE_AUTOMACAO');
    $extra = af_flow_test_extra($pdo, $trigger, $user);
    $payload = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $versionId = (int)($flow['current_version_id'] ?? 0);
    $graphJson = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $manychatAfter = af_test_max_id($pdo, 'manychat_logs');
    $webhookAfter = af_test_max_id($pdo, 'webhook_logs');
    $sfAfter = af_test_max_id($pdo, 'superfuncionario_logs');

    $pdo->prepare("INSERT INTO automation_flow_events(event_code,user_id,source_key,payload_json,matched_flows) VALUES(:e,:u,:s,:p,1)")
        ->execute([
            'e' => $event,
            'u' => $userId,
            's' => hash('sha256', 'manual-test|' . (int)$flow['id'] . '|' . $userId . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)),
            'p' => $payload,
        ]);
    $eventId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO automation_flow_runs(flow_id,version_id,event_id,user_id,status,started_at) VALUES(:f,:v,:e,:u,'running',NOW())")
        ->execute(['f' => (int)$flow['id'], 'v' => $versionId, 'e' => $eventId, 'u' => $userId]);
    $runId = (int)$pdo->lastInsertId();

    $steps = [];
    $node = $trigger;
    $input = ['_test_mode' => true];
    $ok = true;
    for ($guard = 0; $node && $guard < 200; $guard++) {
        $nodeId = (string)($node['id'] ?? '');
        $type = (string)($node['type'] ?? '');
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $label = (string)($config['label'] ?? $type);
        $started = microtime(true);
        $jobId = 0;
        try {
            $pdo->prepare("INSERT INTO automation_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'processing',NOW(),:i)")
                ->execute(['r' => $runId, 'n' => $nodeId, 'i' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $jobId = (int)$pdo->lastInsertId();
            $job = [
                'id' => $jobId,
                'run_id' => $runId,
                'flow_id' => (int)$flow['id'],
                'version_id' => $versionId,
                'event_id' => $eventId,
                'event_code' => $event,
                'user_id' => $userId,
                'node_id' => $nodeId,
                'graph_json' => $graphJson,
                'event_payload' => $payload,
                'payload_json' => $payload,
                'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'run_status' => 'running',
                'lease_token' => '',
            ];
            $handle = 'default';
            $output = [];
            if ($type === 'trigger') {
                $output = ['event' => $event, 'test_payload' => $extra];
            } elseif ($type === 'condition') {
                $result = email_flow_condition($pdo, $config, $userId, $user);
                $handle = $result ? 'yes' : 'no';
                $output = ['result' => $result, 'route' => $handle];
            } elseif ($type === 'wait') {
                $output = ['simulated_wait' => true, 'would_resume_at' => push_flow_wait_until($config), 'advanced_without_waiting' => true];
            } elseif ($type === 'email') {
                $output = automation_flow_send_email($pdo, $job, $config, $user);
            } elseif ($type === 'push') {
                $output = push_flow_send_push($pdo, $config, $userId, $job);
            } elseif ($type === 'voice') {
                $output = voice_automation_start_call($pdo, $config, $user, $job, $extra);
            } elseif ($type === 'action') {
                (($config['action'] ?? '') === 'remove_tag' ? remover_tag_usuario($userId, (string)($config['tag'] ?? '')) : adicionar_tag($userId, (string)($config['tag'] ?? ''), 'automation_test', $runId));
                $output = ['tag' => (string)($config['tag'] ?? ''), 'action' => (string)($config['action'] ?? 'add_tag')];
            } elseif ($type === 'integration') {
                $output = push_flow_dispatch_integration($pdo, $config, $user, $extra, $job);
            } elseif ($type === 'end') {
                $output = ['ended' => true];
            } else {
                throw new RuntimeException('Bloco nao suportado: ' . $type);
            }
            $nextId = $type === 'end' ? null : automation_flow_next($graph, $nodeId, $handle);
            $output['next_node'] = $nextId;
            $outJson = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $pdo->prepare("INSERT INTO automation_flow_steps(run_id,job_id,node_id,node_type,status,output_json,finished_at) VALUES(:r,:j,:n,:t,'completed',:o,NOW())")
                ->execute(['r' => $runId, 'j' => $jobId, 'n' => $nodeId, 't' => $type, 'o' => $outJson]);
            $pdo->prepare("UPDATE automation_flow_jobs SET status='completed',output_json=:o,last_error=NULL WHERE id=:id")
                ->execute(['o' => $outJson, 'id' => $jobId]);
            $steps[] = ['node_id' => $nodeId, 'type' => $type, 'label' => $label, 'status' => 'completed', 'handle' => $handle, 'duration_ms' => (int)round((microtime(true) - $started) * 1000), 'output' => $output];
            $node = $nextId && isset($map[$nextId]) ? $map[$nextId] : null;
        } catch (Throwable $e) {
            $ok = false;
            $err = mb_substr($e->getMessage(), 0, 1000);
            if ($jobId > 0) {
                $pdo->prepare("INSERT INTO automation_flow_steps(run_id,job_id,node_id,node_type,status,error_message,finished_at) VALUES(:r,:j,:n,:t,'failed',:e,NOW())")
                    ->execute(['r' => $runId, 'j' => $jobId, 'n' => $nodeId, 't' => $type, 'e' => $err]);
                $pdo->prepare("UPDATE automation_flow_jobs SET status='failed',last_error=:e WHERE id=:id")->execute(['e' => $err, 'id' => $jobId]);
            }
            $steps[] = ['node_id' => $nodeId, 'type' => $type, 'label' => $label, 'status' => 'failed', 'duration_ms' => (int)round((microtime(true) - $started) * 1000), 'error' => $err];
            $nextId = $type === 'end' ? null : automation_flow_next($graph, $nodeId, 'default');
            $node = $nextId && isset($map[$nextId]) ? $map[$nextId] : null;
            if (!$node) break;
        }
    }
    $pdo->prepare("UPDATE automation_flow_runs SET status=:s,finished_at=NOW(),last_error=:e WHERE id=:id")
        ->execute(['s' => $ok ? 'completed' : 'completed', 'e' => $ok ? null : 'Teste concluiu com falha em um ou mais blocos.', 'id' => $runId]);
    return [
        'ok' => $ok,
        'run_id' => $runId,
        'event_id' => $eventId,
        'event' => $event,
        'user' => ['id' => $userId, 'nome' => $user['nome'] ?? '', 'email' => $user['email'] ?? '', 'telefone' => $user['telefone'] ?? '', 'codigo_turma' => $user['codigo_turma'] ?? ($user['turma_codigo'] ?? '')],
        'extra' => $extra,
        'steps' => $steps,
        'provider_logs' => [
            'manychat' => af_test_new_logs($pdo, 'manychat_logs', $manychatAfter),
            'webhooks' => af_test_new_logs($pdo, 'webhook_logs', $webhookAfter),
            'superfuncionario' => af_test_new_logs($pdo, 'superfuncionario_logs', $sfAfter),
        ],
    ];
}
function af_problem_node_ids(array $graph, bool $publish = false): array
{
    $bad = [];
    $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
    $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
    $allowed = ['trigger','condition','wait','email','ab_test','push','voice','action','integration','end'];
    $ids = [];
    $triggers = [];
    foreach ($nodes as $node) {
        $id = (string)($node['id'] ?? '');
        $type = (string)($node['type'] ?? '');
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $id) || isset($ids[$id]) || !in_array($type, $allowed, true)) {
            if ($id !== '') $bad[] = $id;
            continue;
        }
        $ids[$id] = true;
        if ($type === 'trigger') $triggers[] = $id;
        $c = is_array($node['config'] ?? null) ? $node['config'] : [];
        if ($type === 'trigger' && trim((string)($c['event'] ?? '')) === '') $bad[] = $id;
        if ($type === 'wait' && ((int)($c['duration'] ?? 0) < 1 || !in_array(($c['unit'] ?? ''), ['minutes','hours','days'], true))) $bad[] = $id;
        if ($type === 'email') {
            if (!empty($c['abEnabled'])) {
                $validVariants = 0;
                foreach ((is_array($c['variants'] ?? null) ? $c['variants'] : []) as $variant) {
                    if ((int)($variant['templateVersionId'] ?? 0) > 0 && (int)($variant['weight'] ?? 0) > 0) $validVariants++;
                }
                if ($validVariants < 2) $bad[] = $id;
            } elseif ((int)($c['templateVersionId'] ?? 0) < 1) {
                $bad[] = $id;
            }
        }
        if ($type === 'push' && (trim((string)($c['title'] ?? '')) === '' || trim((string)($c['body'] ?? '')) === '')) $bad[] = $id;
        if ($type === 'voice' && (string)($c['messageMode'] ?? 'text_to_speech') === 'audio_url' && trim((string)($c['audioUrl'] ?? '')) === '' && (int)($c['audioMediaId'] ?? 0) < 1) $bad[] = $id;
        if ($type === 'voice' && (string)($c['messageMode'] ?? 'text_to_speech') !== 'audio_url' && trim((string)($c['message'] ?? '')) === '') $bad[] = $id;
        if ($type === 'action' && trim((string)($c['tag'] ?? '')) === '') $bad[] = $id;
        if ($type === 'integration' && !in_array(($c['provider'] ?? ''), ['webhook','superfuncionario','manychat'], true)) $bad[] = $id;
        if ($type === 'condition' && empty($c['rules'])) $bad[] = $id;
    }
    foreach ($edges as $edge) {
        foreach (['source','target'] as $key) {
            $nodeId = (string)($edge[$key] ?? '');
            if ($nodeId !== '' && empty($ids[$nodeId])) $bad[] = $nodeId;
        }
    }
    if ($publish && count($triggers) !== 1) $bad = array_merge($bad, $triggers);
    return array_values(array_unique(array_filter($bad)));
}

$editId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$postedGraph = null;
$postedName = null;
$postedDescription = null;
$problemNodeIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Sem permissao de escrita.');
        af_check_csrf($csrf);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $id = automation_flow_create($pdo, trim((string)($_POST['name'] ?? '')) ?: 'Novo fluxo', (string)($_SESSION['equipe_nome'] ?? 'Administrador'));
            header('Location: automacoes.php?id=' . $id);
            exit;
        }
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE automation_flows SET status=IF(status='active','paused','active') WHERE id=:id AND current_version_id IS NOT NULL AND status<>'deleted'")->execute(['id'=>$id]);
            header('Location: automacoes.php?view=flows&saved=1');
            exit;
        }
        if ($action === 'clone') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM automation_flows WHERE id=:id AND status<>'deleted' LIMIT 1");
            $st->execute(['id'=>$id]);
            $source = $st->fetch(PDO::FETCH_ASSOC);
            if (!$source) throw new RuntimeException('Fluxo original nao encontrado.');
            $name = trim((string)$source['name']);
            $pdo->prepare("INSERT INTO automation_flows(name,description,status,draft_graph_json,created_by,updated_by) VALUES(:n,:d,'draft',:g,:a,:a)")
                ->execute([
                    'n' => 'Copia de ' . $name,
                    'd' => $source['description'] ?? null,
                    'g' => (string)$source['draft_graph_json'],
                    'a' => (string)($_SESSION['equipe_nome'] ?? 'Administrador'),
                ]);
            header('Location: automacoes.php?id=' . (int)$pdo->lastInsertId() . '&cloned=1');
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE automation_flows SET status='deleted' WHERE id=:id")->execute(['id'=>$id]);
            header('Location: automacoes.php?view=flows&deleted=1');
            exit;
        }
        if ($action === 'process_now') {
            cron_manager_ensure_tables($pdo);
            $totalProcessed = 0;
            foreach (automation_flow_channel_task_keys() as $taskKey) {
                $taskResult = cron_manager_execute($pdo, $taskKey, 'manual', true);
                $decoded = json_decode((string)($taskResult['output'] ?? ''), true);
                $totalProcessed += (int)($decoded['processed'] ?? 0);
            }
            header('Location: automacoes.php?view=flows&processed=' . $totalProcessed);
            exit;
        }
        if ($action === 'simulate_flow') {
            $id = (int)($_POST['id'] ?? 0);
            $flow = automation_flow_find($pdo, $id);
            if (!$flow) throw new RuntimeException('Fluxo nao encontrado.');
            $graph = automation_flow_decode_graph((string)($_POST['graph_json'] ?? ''));
            $userId = (int)($_POST['test_user_id'] ?? 0);
            $userQuery = trim((string)($_POST['test_user_query'] ?? ''));
            if ($userId < 1 && $userQuery !== '') {
                if (ctype_digit($userQuery)) {
                    $userId = (int)$userQuery;
                } else {
                    $ust = $pdo->prepare("SELECT id FROM users WHERE email=:q ORDER BY id DESC LIMIT 1");
                    $ust->execute(['q' => $userQuery]);
                    $userId = (int)$ust->fetchColumn();
                }
            }
            if ($userId < 1) throw new RuntimeException('Selecione um usuario de teste.');
            af_json(af_run_flow_test($pdo, $flow, $graph, $userId));
        }
        if ($action === 'save_channel_settings') {
            foreach (['email','push','manychat','superfuncionario','webhook'] as $channelKey) {
                $prefix = 'ch_' . $channelKey . '_';
                $pdo->prepare("UPDATE automation_channel_settings SET enabled=:en,min_interval_ms=:mi,batch_size=:bs,max_attempts=:ma,backoff_step_seconds=:bstep,backoff_max_seconds=:bmax WHERE channel=:c")
                    ->execute([
                        'en' => isset($_POST[$prefix . 'enabled']) ? 1 : 0,
                        'mi' => max(0, min(60000, (int)($_POST[$prefix . 'min_interval_ms'] ?? 300))),
                        'bs' => max(1, min(200, (int)($_POST[$prefix . 'batch_size'] ?? 30))),
                        'ma' => max(1, min(20, (int)($_POST[$prefix . 'max_attempts'] ?? 5))),
                        'bstep' => max(1, min(3600, (int)($_POST[$prefix . 'backoff_step_seconds'] ?? 30))),
                        'bmax' => max(1, min(86400, (int)($_POST[$prefix . 'backoff_max_seconds'] ?? 1800))),
                        'c' => $channelKey,
                    ]);
            }
            header('Location: automacoes.php?view=canais&saved=1');
            exit;
        }
        if ($action === 'reprocess_flow') {
            $id = (int)($_POST['id'] ?? 0);
            $flow = automation_flow_find($pdo, $id);
            if (!$flow) throw new RuntimeException('Fluxo não encontrado.');
            $runs = $pdo->prepare("
                SELECT r.id, r.user_id, r.version_id, v.graph_json 
                FROM automation_flow_runs r 
                JOIN automation_flow_versions v ON v.id=r.version_id 
                WHERE r.flow_id=:id
            ");
            $runs->execute(['id'=>$id]);
            $runRows = $runs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($runRows as $run) {
                $graph = json_decode((string)$run['graph_json'], true) ?: [];
                $trigger = null;
                foreach (($graph['nodes'] ?? []) as $node) {
                    if (($node['type'] ?? '') === 'trigger') { $trigger = $node; break; }
                }
                if (!$trigger) continue;
                $pdo->prepare("UPDATE automation_flow_runs SET status='running', finished_at=NULL, last_error=NULL WHERE id=:id")->execute(['id'=>(int)$run['id']]);
                $pdo->prepare("DELETE FROM automation_flow_jobs WHERE run_id=:id")->execute(['id'=>(int)$run['id']]);
                $pdo->prepare("INSERT INTO automation_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'queued',NOW(),'{}')")
                    ->execute(['r'=>(int)$run['id'], 'n'=>(string)$trigger['id']]);
            }
            $res = automation_flow_process_flow_now($pdo, $id, 100);
            header('Location: automacoes.php?view=flows&reprocessed=' . count($runRows) . '&dispatched=' . (int)($res['processed'] ?? 0));
            exit;
        }
        if ($action === 'clear_flow_queue') {
            $id = (int)($_POST['id'] ?? 0);
            $flow = automation_flow_find($pdo, $id);
            if (!$flow) throw new RuntimeException('Fluxo não encontrado.');
            $stJobs = $pdo->prepare("
                UPDATE automation_flow_jobs j
                JOIN automation_flow_runs r ON r.id = j.run_id
                SET j.status = 'canceled', j.last_error = 'Fila limpa pelo admin'
                WHERE r.flow_id = :id AND j.status IN ('queued', 'scheduled', 'retry')
            ");
            $stJobs->execute(['id' => $id]);
            $canceledJobsCount = $stJobs->rowCount();
            $stRuns = $pdo->prepare("
                UPDATE automation_flow_runs 
                SET status = 'canceled', finished_at = NOW(), last_error = 'Fila limpa pelo admin'
                WHERE flow_id = :id AND status IN ('running', 'queued')
            ");
            $stRuns->execute(['id' => $id]);
            header('Location: automacoes.php?view=flows&cleared=1&jobs_canceled=' . $canceledJobsCount);
            exit;
        }
        if ($action === 'test_flow') {
            $id = (int)($_POST['id'] ?? 0);
            $flow = automation_flow_find($pdo, $id);
            if (!$flow) throw new RuntimeException('Fluxo não encontrado.');
            $vst = $pdo->prepare("SELECT graph_json FROM automation_flow_versions WHERE id=:v LIMIT 1");
            $vst->execute([':v' => (int)($flow['current_version_id'] ?? 0)]);
            $graphJson = (string)($vst->fetchColumn() ?: ($flow['draft_graph_json'] ?? '{}'));
            $graph = json_decode($graphJson, true) ?: [];
            $trigger = null;
            foreach (($graph['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') === 'trigger') { $trigger = $node; break; }
            }
            if (!$trigger) throw new RuntimeException('O fluxo não possui bloco de gatilho.');
            $event = (string)($trigger['config']['event'] ?? 'PAGAMENTO_APROVADO');
            $filterTurma = (string)($trigger['config']['filter'] ?? '');

            // Busca o último aluno com essa turma ou o último cadastrado
            $testUser = null;
            if ($filterTurma !== '') {
                $ust = $pdo->prepare("SELECT * FROM users WHERE codigo_turma=:t OR turma_codigo=:t ORDER BY id DESC LIMIT 1");
                $ust->execute([':t' => $filterTurma]);
                $testUser = $ust->fetch(PDO::FETCH_ASSOC);
            }
            if (!$testUser) {
                $testUser = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['id'=>1,'nome'=>'Aluno Teste','email'=>'teste@exemplo.com','telefone'=>'11999999999'];
            }
            $testUserId = (int)$testUser['id'];

            $testExtra = [
                'gateway' => 'teste_painel',
                'transacao_id' => 'TESTE-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'valor_bruto' => 197.00,
                'valor_liquido' => 180.00,
                'taxa' => 17.00,
                'moeda' => 'BRL',
                'metodo' => 'credit_card',
                'produto_nome' => 'Curso de Teste (Disparo Manual)',
                'codigo_turma' => $filterTurma ?: ($testUser['codigo_turma'] ?? 'GERAL'),
                'utm_source' => 'painel_admin',
                'utm_campaign' => 'teste_automacao',
                'is_test' => true,
            ];

            // Cria o evento e a execução direta para este fluxo específico
            $payload = json_encode($testExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sourceKey = 'test_manual_' . microtime(true) . '_' . bin2hex(random_bytes(6));
            $pdo->prepare("INSERT INTO automation_flow_events (event_code, user_id, source_key, payload_json, matched_flows) VALUES (:e, :u, :s, :p, 1)")
                ->execute([':e' => $event, ':u' => $testUserId, ':s' => $sourceKey, ':p' => $payload]);
            $eventId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO automation_flow_runs (flow_id, version_id, event_id, user_id, status, started_at) VALUES (:f, :v, :e, :u, 'running', NOW())")
                ->execute([':f' => $id, ':v' => (int)$flow['current_version_id'], ':e' => $eventId, ':u' => $testUserId]);
            $runId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO automation_flow_jobs (run_id, node_id, status, available_at, input_json) VALUES (:r, :n, 'queued', NOW(), '{}')")
                ->execute([':r' => $runId, ':n' => (string)$trigger['id']]);

            $res = automation_flow_process_flow_now($pdo, $id, 100);
            header('Location: automacoes.php?view=flows&tested=1&dispatched=' . (int)($res['processed'] ?? 0));
            exit;
        }
        if ($action === 'run_diagnostics') {
            $diagResult = automation_run_complete_diagnostics($pdo, (string)($_SESSION['equipe_nome'] ?? 'Administrador'));
            header('Location: automacoes.php?view=flows&diagnosed=1&issues=' . (int)($diagResult['issues_count'] ?? 0));
            exit;
        }
        if ($action === 'acknowledge_diagnostics') {
            automation_acknowledge_diagnostics($pdo, (string)($_SESSION['equipe_nome'] ?? 'Administrador'));
            header('Location: automacoes.php?view=flows&acknowledged=1');
            exit;
        }
        if (in_array($action, ['save','publish'], true)) {
            $flow = automation_flow_find($pdo, (int)$_POST['id']);
            if (!$flow) throw new RuntimeException('Fluxo nao encontrado.');
            $graph = automation_flow_decode_graph((string)($_POST['graph_json'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $publish = $action === 'publish';
            $postedGraph = $graph;
            $postedName = $name;
            $postedDescription = $description;
            $problemNodeIds = af_problem_node_ids($graph, $publish);
            $admin = (string)($_SESSION['equipe_nome'] ?? 'Administrador');
            if ($publish) automation_flow_publish($pdo, (int)$flow['id'], $name, $description, $graph, (int)$_POST['lock_version'], $admin);
            else automation_flow_save($pdo, (int)$flow['id'], $name, $description, $graph, (int)$_POST['lock_version'], $admin);
            header('Location: automacoes.php?id=' . (int)$flow['id'] . '&saved=1');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)($_POST['action'] ?? '') === 'simulate_flow') af_json(['ok' => false, 'error' => $e->getMessage()], 400);
        if ($postedGraph !== null && !$problemNodeIds) $problemNodeIds = af_problem_node_ids($postedGraph, (string)($_POST['action'] ?? '') === 'publish');
        $error = $e->getMessage();
    }
}

$view = $editId > 0 ? 'editor' : (string)($_GET['view'] ?? 'overview');
$menu = 'automacoes';
$page_title = 'Automações';

$flow = $editId > 0 ? automation_flow_find($pdo, $editId) : null;
if ($editId > 0 && !$flow) { http_response_code(404); exit('Fluxo nao encontrado.'); }

$kpis = ['flows'=>0,'active'=>0,'runs'=>0,'completed'=>0,'failed'=>0,'queued'=>0];
try {
    $kpis['flows'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flows WHERE status<>'deleted'")->fetchColumn();
    $kpis['active'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flows WHERE status='active'")->fetchColumn();
    $kpis['runs'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flow_runs")->fetchColumn();
    $kpis['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flow_runs WHERE status='completed'")->fetchColumn();
    $kpis['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flow_runs WHERE status='failed'")->fetchColumn();
    $kpis['queued'] = (int)$pdo->query("SELECT COUNT(*) FROM automation_flow_jobs WHERE status IN ('queued','retry','scheduled')")->fetchColumn();
} catch (Throwable $e) {}

$flows = $pdo->query("SELECT f.*,v.version_number,
    (SELECT COUNT(*) FROM automation_flow_runs r WHERE r.flow_id=f.id) runs,
    (SELECT COUNT(*) FROM automation_flow_runs r WHERE r.flow_id=f.id AND r.status='completed') completed,
    (SELECT COUNT(*) FROM automation_flow_runs r WHERE r.flow_id=f.id AND r.status='failed') failed,
    (SELECT COUNT(*) FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id WHERE r.flow_id=f.id AND j.status IN ('queued','retry','scheduled')) pending
    FROM automation_flows f LEFT JOIN automation_flow_versions v ON v.id=f.current_version_id
    WHERE f.status<>'deleted' ORDER BY f.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$logFlowId = max(0, (int)($_GET['log_flow'] ?? 0));
$logAluno = trim((string)($_GET['log_aluno'] ?? ''));
$logBloco = trim((string)($_GET['log_bloco'] ?? ''));
$logStatus = trim((string)($_GET['log_status'] ?? ''));
$logDe = trim((string)($_GET['log_de'] ?? ''));
$logAte = trim((string)($_GET['log_ate'] ?? ''));
$logLimit = (int)($_GET['log_limit'] ?? 300);
if (!in_array($logLimit, [100, 300, 500, 1000, 2000], true)) $logLimit = 300;

$logWhere = [];
$logParams = [];
if ($logFlowId > 0) { $logWhere[] = 'r.flow_id=:flow_id'; $logParams['flow_id'] = $logFlowId; }
if ($logAluno !== '') { $logWhere[] = '(u.nome LIKE :aluno OR u.email LIKE :aluno2)'; $logParams['aluno'] = '%' . $logAluno . '%'; $logParams['aluno2'] = '%' . $logAluno . '%'; }
if ($logBloco !== '') { $logWhere[] = 's.node_type=:bloco'; $logParams['bloco'] = $logBloco; }
if ($logStatus !== '') { $logWhere[] = 's.status=:status'; $logParams['status'] = $logStatus; }
if ($logDe !== '') { $logWhere[] = 's.started_at>=:de'; $logParams['de'] = $logDe . ' 00:00:00'; }
if ($logAte !== '') { $logWhere[] = 's.started_at<=:ate'; $logParams['ate'] = $logAte . ' 23:59:59'; }
$logWhereSql = $logWhere ? ('WHERE ' . implode(' AND ', $logWhere)) : '';
$logsStmt = $pdo->prepare("SELECT s.*,r.flow_id,r.user_id,f.name flow_name,u.nome,u.email
    FROM automation_flow_steps s
    JOIN automation_flow_runs r ON r.id=s.run_id
    JOIN automation_flows f ON f.id=r.flow_id
    LEFT JOIN users u ON u.id=r.user_id
    {$logWhereSql}
    ORDER BY s.id DESC LIMIT {$logLimit}");
$logsStmt->execute($logParams);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$logBlocoOptions = $pdo->query("SELECT DISTINCT node_type FROM automation_flow_steps WHERE node_type IS NOT NULL AND node_type<>'' ORDER BY node_type")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$logStatusOptions = $pdo->query("SELECT DISTINCT status FROM automation_flow_steps WHERE status IS NOT NULL AND status<>'' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$eventsByDay = $pdo->query("SELECT DATE(created_at) d,COUNT(*) c FROM automation_flow_events WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$statusRows = $pdo->query("SELECT status,COUNT(*) c FROM automation_flow_runs GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$latestDiag = null;
$flowDiagMap = [];
try {
    $latestDiag = automation_get_latest_diagnostics($pdo);
    $flowDiagMap = automation_get_flow_diagnostics_map($pdo);
} catch (Throwable $e) {}

$templates = $pdo->query("SELECT v.id,t.name,v.version_number,v.subject FROM email_templates t JOIN email_template_versions v ON v.id=t.current_version_id WHERE t.status='active' ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$triggers = automation_trigger_options($pdo);
$triggerGroups = automation_trigger_groups($pdo);
$voiceMedia = voice_media_options($pdo);
$turmas = $pdo->query("SELECT codigo FROM turmas WHERE codigo IS NOT NULL AND codigo<>'' ORDER BY codigo")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$paymentProductOptions = [];
try {
    $paymentProductRows = $pdo->query("
        SELECT DISTINCT TRIM(product_name) v FROM student_payment_events WHERE product_name IS NOT NULL AND TRIM(product_name)<>''
        UNION
        SELECT DISTINCT TRIM(product_name) v FROM payment_sales WHERE product_name IS NOT NULL AND TRIM(product_name)<>''
        ORDER BY v LIMIT 500
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $paymentProductSeen = [];
    foreach ($paymentProductRows as $paymentProductRow) {
        $paymentProductLabel = trim((string)$paymentProductRow);
        if ($paymentProductLabel === '') continue;
        $paymentProductKey = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $paymentProductLabel);
        if (!is_string($paymentProductKey) || $paymentProductKey === '') $paymentProductKey = $paymentProductLabel;
        $paymentProductKey = strtolower($paymentProductKey);
        $paymentProductKey = preg_replace('/[^a-z0-9]+/', '', $paymentProductKey) ?: $paymentProductKey;
        $duplicate = false;
        foreach ($paymentProductSeen as $seenKey => $_) {
            if ($paymentProductKey === $seenKey || (strlen($paymentProductKey) > 20 && abs(strlen($paymentProductKey) - strlen($seenKey)) <= 2 && levenshtein($paymentProductKey, $seenKey) <= 2)) {
                $duplicate = true;
                break;
            }
        }
        if ($duplicate) continue;
        $paymentProductSeen[$paymentProductKey] = true;
        $paymentProductOptions[] = $paymentProductLabel;
    }
} catch (Throwable $e) {}
$tags = $pdo->query("SELECT nome FROM tags WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$testUsers = [];
try {
    $testUsers = $pdo->query("
        SELECT id,nome,email,telefone,codigo_turma,turma_codigo
        FROM users
        WHERE email IS NOT NULL AND email<>''
        ORDER BY id DESC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$integrationFieldOptions = [
    'user.id' => 'ID do aluno',
    'user.nome' => 'Nome',
    'user.email' => 'Email',
    'user.telefone' => 'Telefone',
    'user.celular' => 'Celular',
    'user.whatsapp' => 'WhatsApp',
    'user.codigo_turma' => 'Turma',
    'user.magic_link' => 'Magic link',
    'extra.codigo_turma' => 'Turma do evento',
    'extra.codigo_live' => 'Codigo da live',
    'extra.data_live' => 'Data da live',
    'extra.data_live_iso' => 'Data da live ISO',
    'extra.hora_live' => 'Hora da live',
    'extra.link_live' => 'Link da live',
    'extra.andamento' => 'Andamento',
    'extra.aulas_concluidas' => 'Aulas concluidas',
    'extra.aulas_totais' => 'Aulas totais',
    'extra.pdf_url' => 'PDF certificado',
    'extra.codigo_certificado' => 'Codigo certificado',
    'extra.product_name' => 'Produto comprado',
    'extra.product_code' => 'Codigo do produto',
    'extra.checkout_id' => 'Checkout',
    'extra.transaction_code' => 'Transacao',
    'literal:valor_fixo' => 'Valor fixo',
];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') {
            $integrationFieldOptions['user.' . $name] = 'users.' . $name;
            $integrationFieldOptions['users.' . $name] = 'users.' . $name;
        }
    }
} catch (Throwable $e) {}
$abStats = [];
try {
    $st = $pdo->prepare("SELECT template_version_id,COUNT(*) sent,SUM(delivered_at IS NOT NULL) delivered,SUM(first_opened_at IS NOT NULL) opened,SUM(first_clicked_at IS NOT NULL) clicked,SUM(status='bounced') bounced FROM email_messages WHERE flow_id=:id GROUP BY template_version_id");
    $st->execute(['id'=>$editId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $abStats[(int)$row['template_version_id']] = $row;
} catch (Throwable $e) {}
$pushNotifications = [];
try {
    if (function_exists('push_ensure_schema')) push_ensure_schema($pdo);
    $pushNotifications = $pdo->query("SELECT id,title,body,created_at FROM push_notifications ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$pushPreviewIcon = function_exists('push_app_icon_url') ? push_app_icon_url() : 'pwa-icon-192.png';
if (!preg_match('~^(?:https?:)?//|^data:|^/~i', $pushPreviewIcon)) $pushPreviewIcon = '../public/' . ltrim($pushPreviewIcon, '/');
$pushInternalPages = [
    ['url' => 'trilha.php', 'label' => 'Trilha do aluno'],
    ['url' => 'aplicativo.php', 'label' => 'Aplicativo'],
    ['url' => 'certificado.php', 'label' => 'Certificado'],
    ['url' => 'reagendar_live.php', 'label' => 'Reagendar live'],
    ['url' => 'nao_consigo_acessar.php', 'label' => 'Ajuda de acesso'],
    ['url' => 'verificar_certificado.php', 'label' => 'Verificar certificado'],
    ['url' => 'formulario_lead.php', 'label' => 'Formulário de lead'],
];
try {
    $lessonRows = $pdo->query("SELECT id,titulo,ordem FROM lessons WHERE ativo=1 ORDER BY ordem ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($lessonRows as $lesson) {
        $order = (int)($lesson['ordem'] ?? 0);
        $title = trim((string)($lesson['titulo'] ?? ''));
        $pushInternalPages[] = [
            'url' => 'aula.php?id=' . (int)$lesson['id'],
            'label' => 'Aula ' . ($order > 0 ? $order : (int)$lesson['id']) . ($title !== '' ? ' - ' . $title : ''),
        ];
    }
} catch (Throwable $e) {}
$voiceCampaigns = [];
try {
    if (function_exists('voice_ensure_schema')) voice_ensure_schema($pdo);
    $voiceCampaigns = $pdo->query("SELECT id,name,status,created_at FROM voice_campaigns ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$channelLabels = ['email' => 'E-mail', 'push' => 'Push', 'manychat' => 'ManyChat', 'superfuncionario' => 'SuperFuncionário', 'webhook' => 'Webhook'];
$channelAllLabels = ['general' => 'Etapas locais (gatilho/condição/espera/ação/fim)', 'voice' => 'Voz (Torpedo de Voz)'] + $channelLabels;
$channelRows = [];
try {
    $channelStmt = $pdo->query("SELECT * FROM automation_channel_settings ORDER BY channel")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($channelStmt as $row) $channelRows[(string)$row['channel']] = $row;
    $channelPending = $pdo->query("SELECT channel,COUNT(*) c FROM automation_flow_jobs WHERE status IN ('queued','retry','scheduled') GROUP BY channel")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) { $channelPending = []; }
$queueOverview = [];
if ($view === 'canais') {
    try { $queueOverview = automation_channel_queue_overview($pdo); } catch (Throwable $e) {}
}

include __DIR__ . '/_header.php';
?>
<style>
.af{display:grid;gap:14px}.af-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.af-head h1{font-size:22px}.af-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.af-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.af-nav a.active,.af-nav a:hover{background:var(--primary-dim);color:var(--primary)}.af-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px}.af-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.af-kpi small{color:var(--muted);font-size:10px;text-transform:uppercase}.af-kpi strong{display:block;font-size:26px}.af-actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center;justify-content:flex-end}.af-msg{padding:10px 12px;border-radius:99px;background:var(--success-dim);color:#86efac}.af-error{padding:10px 12px;border-radius:9px;background:var(--danger-dim);color:#fca5a5}.af-table{overflow:auto}.af-table table{width:100%;border-collapse:collapse}.af-table th,.af-table td{padding:9px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.af-table th{font-size:10px;color:var(--muted);text-transform:uppercase}.af-pill{display:inline-flex;padding:3px 8px;border-radius:999px;background:var(--bg-hover);font-size:10px}.af-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.af-form input{min-width:260px;flex:1;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text)}.af-flow-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.af-flow-create{display:grid;grid-template-columns:minmax(240px,1fr) auto;gap:8px;min-width:min(520px,100%)}.af-flow-list{display:grid;gap:8px}.af-flow-row{display:grid;grid-template-columns:minmax(220px,1fr) repeat(4,minmax(65px,85px)) auto;gap:14px;align-items:center;padding:12px 16px;border:1px solid var(--border-light,var(--border));border-radius:10px;background:#071020;transition:all .2s ease}.af-flow-row:hover{border-color:#334155}.af-flow-row.has-diag-warning{border-color:#f59e0b;background:rgba(245,158,11,0.04)}.af-flow-row.has-diag-critical{border-color:#ef4444!important;background:rgba(239,68,68,0.06)!important;box-shadow:0 0 15px rgba(239,68,68,0.18);animation:afPulseRed 2.5s infinite}.af-flow-name strong{display:block;font-size:14px}.af-flow-name small,.af-flow-meta small{display:block;color:var(--muted);font-size:10px}.af-flow-stat strong{display:block;font-size:17px}.af-flow-stat small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase}.af-flow-empty{padding:22px;text-align:center;color:var(--muted);border:1px dashed var(--border);border-radius:10px}.af-menu-dropdown{position:relative;display:inline-block}.af-menu-dropdown summary{list-style:none;cursor:pointer;user-select:none}.af-menu-dropdown summary::-webkit-details-marker{display:none}.af-menu-dropdown[open] summary{background:var(--bg-hover);color:var(--primary)}.af-menu-content{position:absolute;right:0;top:calc(100% + 4px);z-index:999;min-width:190px;padding:6px;border:1px solid var(--border-light,#334155);border-radius:10px;background:#0d172a;box-shadow:0 12px 36px rgba(0,0,0,0.7);display:flex;flex-direction:column;gap:2px}.af-menu-content form{width:100%;margin:0}.af-menu-item{display:flex;align-items:center;gap:8px;width:100%;padding:7px 10px;border:0;border-radius:6px;background:transparent;color:#cbd5e1;font-size:11px;font-weight:600;text-align:left;cursor:pointer;transition:background .15s ease}.af-menu-item:hover{background:#1e293b;color:#fff}.af-menu-item.text-danger{color:#f87171}.af-menu-item.text-danger:hover{background:rgba(239,68,68,0.18);color:#fca5a5}.af-menu-divider{height:1px;background:var(--border,#1e293b);margin:4px 0}@keyframes afPulseRed{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,0.35)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}@media(max-width:1100px){.af-flow-head{display:grid}.af-flow-create{grid-template-columns:1fr}.af-flow-row{grid-template-columns:1fr 1fr;align-items:start}.af-flow-row .af-actions{grid-column:1/-1}}@media(max-width:640px){.af-flow-row{grid-template-columns:1fr}}
.af-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px}.af-filters .field{display:flex;flex-direction:column;gap:4px;min-width:140px}.af-filters .field.wide{min-width:220px}.af-filters label{font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase}.af-filters input,.af-filters select{padding:8px 10px;border-radius:9px;border:1px solid var(--border);background:var(--bg);color:var(--text)}@media(max-width:800px){.af-filters .field{min-width:100%}}
.af-diag-banner{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 18px;border-radius:12px;background:rgba(239,68,68,0.14);border:1px solid #ef4444;color:#fecaca;box-shadow:0 8px 24px rgba(239,68,68,0.18)}
.af-diag-modal{position:fixed;inset:0;z-index:14000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(2,6,15,.85);backdrop-filter:blur(6px)}
.af-diag-modal.open{display:flex}
.af-diag-dialog{width:min(780px,100%);max-height:90vh;overflow-y:auto;border:1px solid var(--border);border-radius:18px;padding:22px;background:#0d1526;box-shadow:0 24px 80px rgba(0,0,0,.8);color:var(--text)}
.af-diag-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:14px 0}
.af-diag-card{padding:12px;border:1px solid var(--border);border-radius:10px;background:#081020;font-size:11px}
.af-diag-card strong{color:#fff;font-size:13px;display:block;margin-bottom:6px}
.af-diag-timeline{display:grid;gap:6px;margin-top:8px}
.af-diag-step{display:flex;align-items:center;justify-content:space-between;padding:6px 8px;border-radius:6px;background:rgba(255,255,255,0.03);font-size:10px}
.afe{display:flex;flex-direction:column;gap:12px}.afe-top{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--border);border-radius:14px;background:var(--bg-card)}.afe-id{display:grid;grid-template-columns:minmax(180px,320px) minmax(220px,1fr);gap:8px;flex:1}.afe-id input{width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text)}.afe-note{padding:9px 12px;border:1px solid #38bdf844;border-radius:9px;background:var(--info-dim);color:#bae6fd;font-size:11px}.afe-editor{height:calc(100vh - 190px);min-height:600px;display:grid;grid-template-columns:190px minmax(440px,1fr) 330px;border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#070d18}.afe-palette,.afe-inspector{overflow:auto;background:var(--bg-card);padding:14px}.afe-palette{border-right:1px solid var(--border)}.afe-inspector{border-left:1px solid var(--border)}.afe-title{font-size:12px;font-weight:800;margin-bottom:4px}.afe-copy{font-size:10px;color:var(--muted);margin-bottom:12px;line-height:1.45}.afe-list{display:grid;gap:8px}.afe-item{display:flex;gap:8px;align-items:center;width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-size:11px;font-weight:700;text-align:left;cursor:grab}.afe-dot{width:9px;height:9px;border-radius:50%;background:var(--c)}.afe-canvas{position:relative;overflow:hidden;touch-action:none;cursor:grab;background-color:#090f1b;background-image:radial-gradient(circle,#94a3b82e 1px,transparent 1px);background-size:22px 22px}.afe-canvas.is-panning{cursor:grabbing}.afe-view{position:absolute;width:3200px;height:2200px;transform-origin:0 0}.afe-edges,.afe-nodes{position:absolute;inset:0;width:3200px;height:2200px}.afe-edges{z-index:1;pointer-events:auto}.afe-nodes{z-index:2;pointer-events:none}.afe-edge{fill:none;stroke:#64748b;stroke-width:2}.afe-edge-hit{fill:none;stroke:transparent;stroke-width:18;cursor:pointer;pointer-events:stroke}.afe-edge-g:hover .afe-edge,.afe-edge-g.selected .afe-edge{stroke:#facc15;stroke-width:3}.afe-edge-trash{cursor:pointer;pointer-events:all}.afe-edge-trash circle{fill:#ef4444;stroke:#fecaca;stroke-width:1}.afe-edge-trash text{fill:#fff;font-size:16px;font-weight:800;text-anchor:middle;dominant-baseline:central}.afe-node{position:absolute;width:210px;min-height:92px;border:1px solid var(--c);border-radius:12px;background:#0d1526;box-shadow:0 10px 28px #0006;user-select:none;pointer-events:auto}.afe-node.selected{box-shadow:0 0 0 3px #facc1544}.afe-node.has-error{border-color:#fb7185;box-shadow:0 0 0 3px #fb718555,0 10px 28px #0006}.afe-node.has-error .afe-node-head{border-bottom-color:#fb718566}.afe-node-head{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--border);cursor:move}.afe-node-body{padding:10px 12px;color:#94a3b8;font-size:10px}.afe-port{position:absolute;width:14px;height:14px;border:2px solid #e2e8f0;border-radius:50%;background:var(--c);cursor:crosshair;z-index:3}.afe-port.in{left:-8px;top:40px}.afe-port.out{right:-8px;bottom:12px}.afe-port.yes{bottom:34px;background:#22c55e}.afe-port.no{bottom:8px;background:#ef4444}.afe-port.pending{box-shadow:0 0 0 5px #facc1544}.afe-port-label{position:absolute;right:13px;font-size:8px;font-weight:800;color:#94a3b8}.afe-port-label.yes{bottom:35px}.afe-port-label.no{bottom:9px}.afe-tools{position:absolute;right:12px;bottom:12px;z-index:5;display:flex;gap:5px;padding:5px;border:1px solid var(--border);border-radius:10px;background:#080e1ae8}.afe-tools button{min-width:32px;height:30px;border-radius:7px;background:var(--bg-card);color:var(--text)}.afe-fields{display:grid;gap:11px}.afe-field label{display:block;margin-bottom:4px;color:var(--muted);font-size:9px;text-transform:uppercase}.afe-field input,.afe-field select,.afe-field textarea{width:100%;padding:8px 9px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:11px}.afe-event-row{display:grid;grid-template-columns:1fr auto;gap:6px}.afe-event-menu{display:none;max-height:360px;overflow:auto;margin-top:6px;padding:8px;border:1px solid var(--border-light,var(--border));border-radius:10px;background:#081020}.afe-event-menu.open{display:block}.afe-event-group{margin:9px 0 5px;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.afe-event-option{display:block;width:100%;padding:9px;border:0;border-radius:8px;background:transparent;color:var(--text);text-align:left}.afe-event-option:hover,.afe-event-option.active{background:#1f2937}.afe-event-option strong{display:flex;gap:6px;align-items:center}.afe-badge{padding:2px 6px;border-radius:999px;background:#14532d;color:#86efac;font-size:9px}.afe-event-option p{margin:4px 0 0;color:var(--muted);font-size:10px;line-height:1.35}.afe-config-box{display:grid;gap:8px;padding:10px;border:1px solid var(--border);border-radius:10px;background:#081020}.afe-pair-row{display:grid;grid-template-columns:88px minmax(0,1fr);gap:6px;padding:7px;border:1px solid var(--border);border-radius:9px;background:var(--bg)}.afe-pair-row button{grid-column:1/-1;border:1px solid var(--border);border-radius:8px;background:transparent;color:#94a3b8;min-height:28px}.afe-rule{padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--bg);display:grid;gap:6px}.afe-rule-head{display:flex;justify-content:space-between;gap:5px}.afe-rule-remove{background:transparent;color:#f87171}.afe-check{display:flex;gap:7px;font-size:10px}.afe-check input{width:auto}.afe-empty{padding:30px 5px;text-align:center;color:var(--muted);font-size:11px}@media(max-width:900px){.afe-top{flex-wrap:wrap}.afe-id{order:3;flex-basis:100%;grid-template-columns:1fr}.afe-editor{height:auto;grid-template-columns:1fr}.afe-canvas{height:620px}.afe-list{grid-template-columns:repeat(3,1fr)}}
.afe-push-preview{position:fixed;inset:0;z-index:13000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(2,6,15,.82);backdrop-filter:blur(5px)}.afe-push-preview.open{display:flex}.afe-preview-dialog{width:min(420px,100%);border:1px solid var(--border);border-radius:18px;padding:18px;background:#111827;box-shadow:0 24px 80px #000}.afe-preview-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.afe-preview-head strong{font-size:14px}.afe-preview-close{border:1px solid var(--border);border-radius:8px;padding:7px 10px;background:#0b1220;color:#fff}.afe-android{border-radius:18px;padding:18px 12px 24px;background:linear-gradient(#263238,#101820);color:#fff}.afe-android-clock{text-align:center;font-size:11px;margin-bottom:16px;color:#d8e0e4}.afe-notification{display:grid;grid-template-columns:42px minmax(0,1fr) 24px;gap:9px;width:min(300px,100%);margin:auto;padding:13px;border-radius:14px;background:#f5f5f5;color:#172027;box-shadow:0 8px 25px rgba(0,0,0,.3)}.afe-notification img{width:42px;height:42px;border-radius:10px;object-fit:cover}.afe-notification>div{min-width:0}.afe-notification-title{width:94px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:800}.afe-notification-body{display:-webkit-box;width:185px;max-width:100%;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;margin-top:4px;color:#4b5563;font-size:12px;line-height:1.35}.afe-notification.expanded{width:100%}.afe-notification.expanded .afe-notification-title{width:auto;white-space:normal}.afe-notification.expanded .afe-notification-body{display:block;width:auto}.afe-notification-expand{border:0;background:transparent;color:#374151;font-size:18px;align-self:start;cursor:pointer}.afe-preview-note{margin-top:12px;color:#94a3b8;font-size:10px;line-height:1.45}.afe-push-risk{padding:9px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);font-size:10px;line-height:1.5;color:var(--muted)}.afe-push-risk strong{color:var(--text)}.afe-push-risk .risk{color:#f87171;font-weight:800}.afe-push-risk.warning{border-color:rgba(248,113,113,.45);background:rgba(127,29,29,.14)}.afe-push-risk-ok{color:#86efac}.afe-preview-risk{margin-top:10px;padding:8px 10px;border-radius:8px;background:rgba(127,29,29,.18);color:#fca5a5;font-size:10px}.afe-preview-risk:empty{display:none}
.afe-test-modal{position:fixed;inset:0;z-index:13500;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(2,6,15,.84);backdrop-filter:blur(6px)}.afe-test-modal.open{display:flex}.afe-test-dialog{width:min(900px,100%);max-height:92vh;overflow:auto;border:1px solid var(--border);border-radius:18px;padding:18px;background:#0d1526;box-shadow:0 24px 90px #000;color:var(--text)}.afe-test-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.afe-test-head strong{display:block;font-size:16px}.afe-test-head span{display:block;margin-top:3px;color:var(--muted);font-size:11px}.afe-test-controls{display:grid;grid-template-columns:minmax(220px,1fr) minmax(190px,.75fr) auto;gap:10px;align-items:end;margin-bottom:12px}.afe-test-controls label span{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.afe-test-controls select,.afe-test-controls input{width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text)}.afe-test-status{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#081020;color:#cbd5e1;font-size:12px;margin-bottom:12px}.afe-test-status.ok{border-color:#22c55e;color:#86efac}.afe-test-status.err{border-color:#ef4444;color:#fca5a5}.afe-test-result{display:grid;gap:10px}.afe-test-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px}.afe-test-summary div,.afe-test-step,.afe-provider-log{padding:10px;border:1px solid var(--border);border-radius:10px;background:#081020}.afe-test-summary small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase}.afe-test-summary strong{display:block;margin-top:3px;font-size:13px}.afe-test-step{display:grid;gap:8px}.afe-test-step-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.afe-test-step-head strong{font-size:13px}.afe-test-step-head span{font-size:10px;color:#94a3b8}.afe-test-step.completed{border-color:rgba(34,197,94,.45)}.afe-test-step.failed{border-color:rgba(239,68,68,.65)}.afe-test-readable{display:grid;gap:6px}.afe-test-line{display:grid;grid-template-columns:118px minmax(0,1fr);gap:8px;align-items:start;padding:8px;border:1px solid rgba(148,163,184,.18);border-radius:8px;background:rgba(15,23,42,.72);font-size:12px}.afe-test-line b{color:#93c5fd;font-size:10px;text-transform:uppercase}.afe-test-line span{color:#e5e7eb}.afe-test-line.good b{color:#86efac}.afe-test-line.bad b{color:#fca5a5}.afe-test-details summary{cursor:pointer;color:#93c5fd;font-size:11px;font-weight:700}.afe-test-step pre,.afe-provider-log pre{max-height:260px;overflow:auto;margin:0;padding:9px;border-radius:8px;background:#020617;color:#cbd5e1;font-size:10px;white-space:pre-wrap;word-break:break-word}.afe-provider-group{display:grid;gap:8px}.afe-provider-group h4{margin:4px 0 0;font-size:12px}.afe-provider-log.ok{border-color:rgba(34,197,94,.45)}.afe-provider-log.fail{border-color:rgba(239,68,68,.65)}@media(max-width:720px){.afe-test-controls{grid-template-columns:1fr}.afe-test-line{grid-template-columns:1fr}}
</style>
<div class="af">
  <div class="af-head">
    <div>
      <h1>Automações</h1>
      <p class="text-muted">Central única para fluxos com e-mail, push, tags, webhooks, SuperFuncionário e Manychat.</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <form method="post" style="margin:0;">
        <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
        <input type="hidden" name="action" value="run_diagnostics">
        <button class="btn btn-ghost btn-sm" <?=$canWrite?'':'disabled'?> title="Varre todos os fluxos ativos testando 2 amostras temporais e benchmark de SLAs">🔍 Diagnóstico Completo (07h, 15h, 20h)</button>
      </form>
    </div>
  </div>
  <nav class="af-nav">
    <a class="<?=$view==='overview'?'active':''?>" href="automacoes.php">Visão geral</a>
    <a class="<?=$view==='flows'?'active':''?>" href="automacoes.php?view=flows">Fluxos</a>
    <a class="<?=$view==='diagnostics'?'active':''?>" href="automacoes.php?view=diagnostics">
      🔬 Raio-X (Diagnóstico)
      <?php if ($latestDiag && empty($latestDiag['acknowledged']) && ($latestDiag['status'] ?? 'healthy') !== 'healthy'): ?>
        <span class="af-pill" style="background:#ef4444;color:#fff;font-weight:700;margin-left:4px;padding:2px 6px;"><?=(int)($latestDiag['issues_count'] ?? 0)?></span>
      <?php endif; ?>
    </a>
    <a class="<?=$view==='logs'?'active':''?>" href="automacoes.php?view=logs">Logs detalhados</a>
    <a class="<?=$view==='canais'?'active':''?>" href="automacoes.php?view=canais">Canais de disparo</a>
    <?php if($flow): ?><a class="active" href="automacoes.php?id=<?=(int)$flow['id']?>">Editor</a><?php endif; ?>
  </nav>
  <?php if($error): ?><div class="af-error"><?=af_h($error)?></div><?php endif; ?>
  <?php if(isset($_GET['saved'])): ?><div class="af-msg">Alteração salva com sucesso.</div><?php endif; ?>
  <?php if(isset($_GET['deleted'])): ?><div class="af-msg">Fluxo removido.</div><?php endif; ?>
  <?php if(isset($_GET['cloned'])): ?><div class="af-msg">Fluxo clonado como rascunho.</div><?php endif; ?>
  <?php if(isset($_GET['processed'])): ?><div class="af-msg">Fila processada: <?=(int)$_GET['processed']?> etapa(s) executada(s).</div><?php endif; ?>
  <?php if(isset($_GET['reprocessed'])): ?><div class="af-msg">Reprocessamento concluído: <?=(int)$_GET['reprocessed']?> execução(ões) pendente(s)/com falha re-enfileirada(s) e <?=(int)($_GET['dispatched'] ?? 0)?> etapa(s) disparada(s).</div><?php endif; ?>
  <?php if(isset($_GET['tested'])): ?><div class="af-msg">Disparo de teste gerado e enviado com sucesso para a integração! Confira na aba Logs detalhados.</div><?php endif; ?>
  <?php if(isset($_GET['diagnosed'])): ?><div class="af-msg">Diagnóstico executado com sucesso! Foram analisados todos os fluxos ativos e a infraestrutura.</div><?php endif; ?>
  <?php if(isset($_GET['acknowledged'])): ?><div class="af-msg">Ciência registrada com sucesso. Alertas arquivados.</div><?php endif; ?>

  <?php if ($latestDiag && empty($latestDiag['acknowledged']) && ($latestDiag['status'] ?? 'healthy') !== 'healthy'): ?>
    <div class="af-diag-banner">
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:26px;">🚨</span>
        <div>
          <strong style="color:#fff;font-size:14px;display:block;">Sistema de Diagnóstico: Inconsistência Detectada nos Fluxos de Automação</strong>
          <span style="font-size:12px;color:#fca5a5;">
            Varredura realizada em <?=af_h(date('d/m/Y H:i', strtotime((string)$latestDiag['check_time'])))?> identificou <strong><?=(int)($latestDiag['issues_count'] ?? 0)?> inconformidade(s)</strong> nos fluxos ou crons.
          </span>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="automacoes.php?view=diagnostics" class="btn btn-sm" style="background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.25);font-weight:700;padding:8px 14px;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;">🔍 Abrir Aba Raio-X</a>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
          <input type="hidden" name="action" value="acknowledge_diagnostics">
          <button class="btn btn-sm" style="background:#ef4444;color:#fff;font-weight:700;border:0;padding:8px 14px;border-radius:8px;cursor:pointer;" title="Reconhece o alerta e fecha a notificação">✓ Dar Ciência</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

<?php if($flow):
    $graph = $postedGraph ?? (json_decode((string)$flow['draft_graph_json'], true) ?: automation_flow_blank_graph());
    $formName = $postedName ?? (string)$flow['name'];
    $formDescription = $postedDescription ?? (string)($flow['description'] ?? '');
?>
  <form method="post" id="afeForm" class="afe">
    <input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$flow['id']?>"><input type="hidden" name="lock_version" value="<?=(int)$flow['lock_version']?>"><input type="hidden" name="graph_json" id="afeGraph"><input type="hidden" name="action" id="afeAction" value="save">
    <div class="afe-top"><a class="btn btn-ghost" href="automacoes.php">← Voltar</a><div class="afe-id"><input name="name" maxlength="180" value="<?=af_h($formName)?>" required><input name="description" maxlength="500" value="<?=af_h($formDescription)?>" placeholder="Descrição opcional"></div><div class="af-actions"><button class="btn btn-ghost" type="button" id="afeOpenTest" <?=$canWrite?'':'disabled'?>>Testar fluxo</button><button class="btn btn-ghost" type="submit" data-action="save" <?=$canWrite?'':'disabled'?>>Salvar rascunho</button><button class="btn btn-primary" type="submit" data-action="publish" <?=$canWrite?'':'disabled'?>>Publicar versão</button></div></div>
    <div class="afe-note">Esta central não desmonta as automações antigas. Fluxos publicados aqui passam a receber eventos novos pela captura central.</div>
    <div class="afe-editor">
      <aside class="afe-palette"><div class="afe-title">Blocos</div><div class="afe-copy">Arraste para o canvas ou clique.</div><div class="afe-list" id="afePalette"></div></aside>
      <main class="afe-canvas" id="afeCanvas"><div class="afe-view" id="afeView"><svg class="afe-edges" id="afeEdges"></svg><div class="afe-nodes" id="afeNodes"></div></div><div class="afe-tools"><button type="button" id="afeFit">◎</button><button type="button" id="afeOut">−</button><button type="button" id="afeZoom">100%</button><button type="button" id="afeIn">+</button></div></main>
      <aside class="afe-inspector"><div class="afe-title">Configuração do bloco</div><div class="afe-copy">Selecione um bloco para editar.</div><div id="afeInspector"></div></aside>
    </div>
  </form>
  <div class="afe-test-modal" id="afeTestModal">
    <div class="afe-test-dialog">
      <div class="afe-test-head"><div><strong>Teste do fluxo</strong><span>Executa o canvas atual para um aluno escolhido e registra o passo a passo.</span></div><button type="button" class="afe-preview-close" id="afeTestClose">Fechar</button></div>
      <div class="afe-test-controls">
        <label><span>Aluno recente</span><select id="afeTestUser"><option value="">Selecione ou digite ao lado</option><?php foreach($testUsers as $tu): ?><option value="<?=(int)$tu['id']?>"><?=af_h('#' . (int)$tu['id'] . ' - ' . trim((string)($tu['nome'] ?? '')) . ' - ' . (string)($tu['email'] ?? '') . ' - turma ' . ((string)($tu['codigo_turma'] ?? '') ?: (string)($tu['turma_codigo'] ?? '')))?></option><?php endforeach; ?></select></label>
        <label><span>ID ou e-mail</span><input id="afeTestUserQuery" placeholder="ex: 58 ou souza1104@hotmail.com"></label>
        <button type="button" class="btn btn-primary" id="afeRunTest">Executar teste agora</button>
      </div>
      <div class="afe-test-status" id="afeTestStatus">Selecione um aluno e execute o teste.</div>
      <div class="afe-test-result" id="afeTestResult"></div>
    </div>
  </div>
  <div class="afe-push-preview" id="afePushPreview"><div class="afe-preview-dialog"><div class="afe-preview-head"><strong>Previa recolhida no Android</strong><button type="button" class="afe-preview-close" id="afePreviewClose">Fechar</button></div><div class="afe-android"><div class="afe-android-clock">12:45 · Notificacoes</div><div class="afe-notification" id="afeNotificationMock"><img src="<?=af_h($pushPreviewIcon)?>" alt=""><div><div class="afe-notification-title" id="afePreviewTitle"></div><div class="afe-notification-body" id="afePreviewBody"></div></div><button type="button" class="afe-notification-expand" id="afePreviewExpand" aria-label="Expandir">⌄</button></div></div><div class="afe-preview-risk" id="afePreviewRisk"></div><div class="afe-preview-note">Esta visualizacao usa um espaco conservador baseado no teste real. O Android pode variar conforme aparelho e tamanho da fonte. Clique na seta para comparar com a versao expandida.</div></div></div>
  <script>
(()=>{const canWrite=<?= $canWrite ? 'true':'false' ?>,triggers=<?=json_encode($triggers,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,triggerGroups=<?=json_encode($triggerGroups,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,templates=<?=json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,voiceMedia=<?=json_encode($voiceMedia,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,turmas=<?=json_encode($turmas,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,paymentProducts=<?=json_encode($paymentProductOptions,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,integrationFieldOptions=<?=json_encode($integrationFieldOptions,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,tags=<?=json_encode($tags,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,pushNotifications=<?=json_encode($pushNotifications,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,voiceCampaigns=<?=json_encode($voiceCampaigns,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,abStats=<?=json_encode($abStats,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,problemNodeIds=<?=json_encode($problemNodeIds,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;let graph=<?=json_encode($graph,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG)?>;if(!Array.isArray(graph.nodes)||!Array.isArray(graph.edges))graph={schemaVersion:3,nodes:[],edges:[],viewport:{x:80,y:60,zoom:1}};graph.schemaVersion=3;graph.nodes.forEach(n=>{n.config=n.config||{};if(n.type==='push'&&n.config.label==='Enviar push')n.config.label='Enviar notificação';if(n.type==='condition'&&!Array.isArray(n.config.rules))n.config.rules=[{field:n.config.field||'tag',operator:n.config.operator||'has',value:n.config.value||''}]});
const types={trigger:{label:'Gatilho inicial',color:'#38bdf8'},condition:{label:'Condição',color:'#10b981'},wait:{label:'Temporizador',color:'#fb7185'},email:{label:'Enviar e-mail',color:'#facc15'},push:{label:'Notificação push',color:'#facc15'},voice:{label:'Chamada de voz',color:'#14b8a6'},action:{label:'Ação de tag',color:'#f59e0b'},integration:{label:'Integração',color:'#a78bfa'},end:{label:'Encerrar',color:'#64748b'}};
const canvas=afeCanvas,viewEl=afeView,nodesEl=afeNodes,edgesEl=afeEdges,inspector=afeInspector;let view=Object.assign({x:80,y:60,zoom:1},graph.viewport||{}),selected=(problemNodeIds[0]||null),selectedEdge=null,pending=null,drag=null,pan=null;const uid=p=>p+'_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,7),esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));const opts=(obj,val='')=>Object.entries(obj).map(([k,v])=>`<option value="${esc(k)}" ${String(k)===String(val)?'selected':''}>${esc(v)}</option>`).join('');
function defs(t){return {trigger:{label:'Início do fluxo',event:'INSCRITO',filter:'',paymentProduct:'',advanceDuration:1,advanceUnit:'hours'},condition:{label:'Nova condição',logic:'and',rules:[{field:'tag',operator:'has',value:''}]},wait:{label:'Aguardar',duration:1,unit:'hours',limitWindow:false,windowStart:'08:00',windowEnd:'20:00'},email:{label:'Enviar e-mail',templateVersionId:0,templateLabel:'',abEnabled:false,variants:[{id:uid('var'),label:'A',weight:50,templateVersionId:0,templateLabel:''},{id:uid('var'),label:'B',weight:50,templateVersionId:0,templateLabel:''}]},push:{label:'Enviar notificação',title:'',body:'',clickUrl:'trilha.php'},voice:{label:'Chamada de voz',phoneField:'',messageMode:'text_to_speech',message:'Ola, {{primeiro_nome|aluno}}.',audioMediaId:0,audioUrl:'',fromNumber:'',answeringMachineDetection:true,recordCalls:false,transcribeCalls:false,timeLimitSecs:120,timeoutSecs:30,maxQueueEnabled:false,maxQueueDuration:0,maxQueueUnit:'hours',preferredTags:[]},action:{label:'Alterar tag',action:'add_tag',tag:''},integration:{label:'Enviar integração',provider:'webhook',target:'',tagsText:'',flowsText:'',fields:[],payload:''},end:{label:'Encerrar fluxo'}}[t]||{}}
function selectedPaymentProducts(c){const values=Array.isArray(c.paymentProducts)?c.paymentProducts.slice():[];if(c.paymentProduct)values.unshift(c.paymentProduct);return [...new Set(values.map(x=>String(x||'').trim()).filter(Boolean))]}
function setPaymentProducts(c,values){const clean=[...new Set((values||[]).map(x=>String(x||'').trim()).filter(Boolean))];c.paymentProducts=clean;c.paymentProduct=clean[0]||''}
function sum(n){const c=n.config||{};if(n.type==='trigger'){const base=triggers[c.event]||c.event||'Evento',products=selectedPaymentProducts(c);return products.length?base+' - '+(products.length===1?products[0]:products.length+' cursos/produtos'):base}if(n.type==='condition')return `${(c.rules||[]).length} regra(s) · ${(c.logic||'and').toUpperCase()}`;if(n.type==='wait')return `${c.duration||1} ${{minutes:'minuto(s)',hours:'hora(s)',days:'dia(s)'}[c.unit]||''}`;if(n.type==='email')return c.abEnabled?`Teste A/B/n · ${(c.variants||[]).length||0} variantes · peso ${(c.variants||[]).reduce((s,v)=>s+(+v.weight||0),0)}%`:(c.templateLabel||'Selecione o modelo');if(n.type==='push')return c.title||'Configure a notificação';if(n.type==='voice'){const pref=Array.isArray(c.preferredTags)&&c.preferredTags.length?` · ${c.preferredTags.length} tag(s) preferenciais`:'';const limit=(c.maxQueueEnabled||+c.maxQueueDuration>0)?` · max ${c.maxQueueDuration||0} ${{minutes:'min',hours:'h',days:'d'}[c.maxQueueUnit]||'h'} na fila`:'';return (c.messageMode==='audio_url'?'Audio URL':'TTS')+' para '+(c.phoneField||'telefone do aluno')+limit+pref}if(n.type==='action')return `${c.action==='remove_tag'?'Remover':'Adicionar'} ${c.tag||'tag'}`;if(n.type==='integration')return `${c.provider||'integração'} ${c.target||''}`;return c.label||types[n.type].label}
function apply(){view.zoom=Math.max(.35,Math.min(1.8,+view.zoom||1));viewEl.style.transform=`translate(${view.x}px,${view.y}px) scale(${view.zoom})`;afeZoom.textContent=Math.round(view.zoom*100)+'%';graph.viewport={...view}}
function render(){nodesEl.innerHTML='';graph.nodes.forEach(n=>{const m=types[n.type];if(!m)return;const e=document.createElement('div');e.className='afe-node'+(selected===n.id?' selected':'')+(problemNodeIds.includes(n.id)?' has-error':'');e.style.cssText=`left:${+n.x||0}px;top:${+n.y||0}px;--c:${m.color}`;e.innerHTML=`${n.type!=='trigger'?'<span class="afe-port in" data-port="in"></span>':''}<div class="afe-node-head"><span class="afe-dot"></span><strong>${esc(n.config?.label||m.label)}</strong></div><div class="afe-node-body">${esc(sum(n))}</div>${n.type==='condition'?'<span class="afe-port-label yes">SIM</span><span class="afe-port out yes" data-port="out" data-handle="yes"></span><span class="afe-port-label no">NÃO</span><span class="afe-port out no" data-port="out" data-handle="no"></span>':n.type!=='end'?'<span class="afe-port out" data-port="out" data-handle="default"></span>':''}`;e.onclick=x=>{if(x.target.closest('.afe-port'))return;selected=n.id;selectedEdge=null;render();inspect()};e.querySelector('.afe-node-head').onpointerdown=x=>{if(!canWrite)return;x.stopPropagation();drag={n,sx:x.clientX,sy:x.clientY,ox:+n.x||0,oy:+n.y||0};canvas.setPointerCapture(x.pointerId)};e.querySelectorAll('.afe-port').forEach(p=>p.onclick=x=>port(x,n,p));nodesEl.appendChild(e)});requestAnimationFrame(edges);apply()}
function port(e,n,p){e.stopPropagation();if(!canWrite)return;selectedEdge=null;if(p.dataset.port==='out'){pending={source:n.id,handle:p.dataset.handle||'default'};document.querySelectorAll('.afe-port.pending').forEach(x=>x.classList.remove('pending'));p.classList.add('pending');return}if(pending&&pending.source!==n.id){graph.edges=graph.edges.filter(x=>!(x.source===pending.source&&x.sourceHandle===pending.handle));graph.edges.push({id:uid('edge'),source:pending.source,target:n.id,sourceHandle:pending.handle});pending=null;render()}}
function edgePath(x1,y1,x2,y2){const gap=x2-x1;if(gap>=0){const b=Math.max(48,Math.min(180,gap*.45));return `M ${x1} ${y1} C ${x1+b} ${y1},${x2-b} ${y2},${x2} ${y2}`}const offset=Math.max(70,Math.min(180,Math.abs(gap)*.45)),midY=(y1+y2)/2;return `M ${x1} ${y1} C ${x1+offset} ${y1},${x1+offset} ${midY},${x1+offset/2} ${midY} L ${x2-offset/2} ${midY} C ${x2-offset} ${midY},${x2-offset} ${y2},${x2} ${y2}`}
function edges(){edgesEl.innerHTML='';graph.edges.forEach(x=>{const s=graph.nodes.find(n=>n.id===x.source),t=graph.nodes.find(n=>n.id===x.target);if(!s||!t)return;const x1=(+s.x||0)+210,y1=(+s.y||0)+(s.type==='condition'?(x.sourceHandle==='yes'?58:84):80),x2=+t.x||0,y2=(+t.y||0)+40,d=edgePath(x1,y1,x2,y2),mx=(x1+x2)/2,my=(y1+y2)/2;const g=document.createElementNS('http://www.w3.org/2000/svg','g');g.setAttribute('class','afe-edge-g'+(selectedEdge===x.id?' selected':''));g.innerHTML=`<path class="afe-edge" d="${d}"/><path class="afe-edge-hit" d="${d}"/>${selectedEdge===x.id?`<g class="afe-edge-trash" transform="translate(${mx} ${my})"><circle r="13"></circle><text y="-1">×</text></g>`:''}`;g.querySelector('.afe-edge-hit').onclick=e=>{e.stopPropagation();if(canWrite){selectedEdge=x.id;selected=null;edges();inspect()}};const del=g.querySelector('.afe-edge-trash');if(del)del.onclick=e=>{e.stopPropagation();graph.edges=graph.edges.filter(edge=>edge.id!==x.id);selectedEdge=null;render()};edgesEl.appendChild(g)})}
function add(t,x,y){if(!canWrite)return;if(t==='trigger'&&graph.nodes.some(n=>n.type==='trigger'))return alert('O fluxo aceita apenas um gatilho.');const n={id:uid(t),type:t,x,y,config:defs(t)};graph.nodes.push(n);selected=n.id;selectedEdge=null;render();inspect()}
function field(label,html){return `<div class="afe-field"><label>${label}</label>${html}</div>`}function bind(id,fn,event='input'){const e=document.getElementById(id);if(e)e.addEventListener(event,fn)}
const PUSH_COMPACT_TITLE=10,PUSH_COMPACT_BODY=38;
function graphemes(value){const text=String(value||'');if(typeof Intl!=='undefined'&&Intl.Segmenter)return Array.from(new Intl.Segmenter('pt-BR',{granularity:'grapheme'}).segment(text),x=>x.segment);return Array.from(text)}
function splitRisk(value,limit){const chars=graphemes(value);return {safe:chars.slice(0,limit).join(''),risk:chars.slice(limit).join(''),count:chars.length}}
function previewText(value){return String(value||'').replaceAll('{{nome}}','Emerson').replaceAll('{{email}}','aluno@email.com').replaceAll('{{telefone}}','(31) 99999-9999').replaceAll('{{turma}}','010726').replaceAll('{{data_live}}','10/07/2026').replaceAll('{{hora_live}}','19:00').replaceAll('{{codigo_live}}','LIVE01')}
function pushRiskHtml(title,body){const t=splitRisk(title,PUSH_COMPACT_TITLE),b=splitRisk(body,PUSH_COMPACT_BODY),warning=!!(t.risk||b.risk);return `<div class="afe-push-risk${warning?' warning':''}">${warning?'<strong>Risco de corte na notificação recolhida.</strong><br>':'<span class="afe-push-risk-ok">Sem risco evidente no formato compacto.</span><br>'}<strong>Título (${t.count}):</strong> ${esc(t.safe)}${t.risk?`<span class="risk">${esc(t.risk)}</span>`:''}<br><strong>Mensagem (${b.count}):</strong> ${esc(b.safe)}${b.risk?`<span class="risk">${esc(b.risk)}</span>`:''}${warning?'<br>Você pode salvar e publicar assim mesmo. O trecho vermelho tende a aparecer somente ao expandir.':''}</div>`}
function renderCompactPreview(expanded){const mock=document.getElementById('afeNotificationMock'),title=mock.dataset.title||'Título da notificação',body=mock.dataset.body||'Mensagem da notificação';mock.classList.toggle('expanded',expanded);const t=splitRisk(title,PUSH_COMPACT_TITLE),b=splitRisk(body,PUSH_COMPACT_BODY);document.getElementById('afePreviewTitle').textContent=expanded?title:t.safe+(t.risk?'…':'');document.getElementById('afePreviewBody').textContent=expanded?body:b.safe+(b.risk?'…':'');document.getElementById('afePreviewExpand').textContent=expanded?'⌃':'⌄'}
function openPushPreview(config){const mock=document.getElementById('afeNotificationMock'),title=previewText(config.title),body=previewText(config.body),t=splitRisk(title,PUSH_COMPACT_TITLE),b=splitRisk(body,PUSH_COMPACT_BODY);mock.dataset.title=title;mock.dataset.body=body;document.getElementById('afePreviewRisk').textContent=t.risk||b.risk?'Aviso: há conteúdo com risco de corte no estado recolhido. A seta mostra o texto completo.':'';renderCompactPreview(false);document.getElementById('afePushPreview').classList.add('open')}
const conditionGroups=[
{label:'Aluno',items:[{code:'tag',label:'Possui tag',tag:'Aluno',desc:'Aluno tem uma ou mais tags selecionadas.',value:'tags'},{code:'turma',label:'Está na turma',tag:'Aluno',desc:'Código de turma atual do aluno.',value:'text'},{code:'email',label:'E-mail contém',tag:'Aluno',desc:'Busca texto no endereço de e-mail do aluno.',value:'text'},{code:'marketing_eligible',label:'Elegível para marketing',tag:'Aluno',desc:'Aluno não está suprimido ou descadastrado.',value:'none'}]},
{label:'Curso',items:[{code:'course_progress_pct',label:'Progresso no curso (%)',tag:'Curso',desc:'Compara o percentual de aulas obrigatórias concluídas.',value:'number'},{code:'lessons_completed_count',label:'Aulas concluídas',tag:'Curso',desc:'Compara a quantidade de aulas obrigatórias concluídas.',value:'number'},{code:'completed_trail',label:'Concluiu a trilha',tag:'Curso',desc:'Todas as aulas obrigatórias foram concluídas.',value:'none'}]},
{label:'E-mail',items:[{code:'email_opened',label:'Abriu e-mail',tag:'Email',desc:'Configure qualquer e-mail, um e-mail específico ou todos.',value:'template_event'},{code:'email_clicked',label:'Clicou e-mail',tag:'Email',desc:'Configure qualquer e-mail, um e-mail específico ou todos.',value:'template_event'},{code:'any_email_opened',label:'Abriu qualquer e-mail',tag:'Email',desc:'Qualquer e-mail enviado ao aluno foi aberto.',value:'none'},{code:'any_email_clicked',label:'Clicou qualquer e-mail',tag:'Email',desc:'Qualquer e-mail enviado ao aluno recebeu clique.',value:'none'},{code:'engagement_count',label:'Engajamentos de e-mail',tag:'Email',desc:'Compara total de aberturas ou cliques.',value:'number'}]},
{label:'Push',items:[{code:'push_clicked',label:'Clicou em qualquer push',tag:'Push',desc:'Qualquer notificação do aplicativo recebeu clique.',value:'none'},{code:'push_notification_clicked',label:'Clicou na notificação push X',tag:'Push',desc:'Escolha uma notificação específica.',value:'push'},{code:'push_received',label:'Recebeu qualquer push',tag:'Push',desc:'Firebase aceitou pelo menos uma entrega para o aluno.',value:'none'},{code:'push_notification_received',label:'Recebeu a notificação push X',tag:'Push',desc:'Entrega aceita de uma notificação específica.',value:'push'}]},
{label:'Live',items:[{code:'live_accessed',label:'Acessou a live',tag:'Live',desc:'Evento LIVE_ACESSOU registrado para o aluno.',value:'none'},{code:'live_offer',label:'Chegou na oferta da live',tag:'Live',desc:'Evento LIVE_OFERTA registrado para o aluno.',value:'none'},{code:'live_purchase',label:'Clicou/comprou na live',tag:'Live',desc:'Evento LIVE_COMPRA registrado para o aluno.',value:'none'},{code:'live_event',label:'Teve evento de live',tag:'Live',desc:'Qualquer evento LIVE_* registrado para o aluno.',value:'none'}]},
{label:'Certificado',items:[{code:'certificate_issued',label:'Certificado emitido',tag:'Cert',desc:'Aluno possui certificado emitido.',value:'none'},{code:'certificate_password_error',label:'Errou senha do certificado',tag:'Cert',desc:'Evento CERT_SENHA_ERRADA registrado.',value:'none'}]},
{label:'Reagendamento',items:[{code:'live_rescheduled',label:'Live reagendada',tag:'Live',desc:'Aluno possui reagendamento de live registrado.',value:'none'},{code:'live_reschedule_expired',label:'Reagendamento expirado',tag:'Live',desc:'Evento ou marcação de expiração do reagendamento.',value:'none'}]},
{label:'Voz',items:[{code:'voice_answered',label:'Ligação atendida',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'},{code:'voice_human',label:'Ligação atendida por humano',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'},{code:'voice_machine',label:'Ligação caiu em caixa postal',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'},{code:'voice_not_answered',label:'Ligação não atendida',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'},{code:'voice_audio_completed',label:'Áudio de ligação concluído',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'},{code:'voice_dtmf',label:'Tecla recebida na ligação',tag:'Voz',desc:'Configure qualquer chamada, uma campanha específica ou todas.',value:'voice'}]}
];
const conditionIndex={},ruleFields={};conditionGroups.forEach(g=>(g.items||[]).forEach(i=>{conditionIndex[i.code]=i;ruleFields[i.code]=i.label}));
function templateOptions(value){return `<option value="">Selecione</option>${templates.map(t=>`<option value="${t.id}" ${String(value)===String(t.id)?'selected':''}>${esc(t.name)} · v${t.version_number} · ${esc(t.subject)}</option>`).join('')}`}
function pct(a,b){return b>0?(100*a/b).toFixed(1).replace('.',',')+'%':'0,0%'}
function abVariantHtml(v,i){const s=abStats[String(v.templateVersionId)]||{},sent=+s.sent||0,delivered=+s.delivered||0,opened=+s.opened||0,clicked=+s.clicked||0,bounced=+s.bounced||0;return `<div class="afe-rule"><div class="afe-rule-head"><strong>Variante ${esc(v.label||String.fromCharCode(65+i))}</strong><button type="button" class="afe-rule-remove" data-ab-remove="${i}">Excluir</button></div><input data-ab="${i}" data-k="label" value="${esc(v.label||'')}" placeholder="Nome da variante"><input type="number" min="1" max="100" data-ab="${i}" data-k="weight" value="${esc(v.weight||0)}" placeholder="Peso %"><select data-ab="${i}" data-k="templateVersionId">${templateOptions(v.templateVersionId)}</select><div class="afe-copy">Envios: ${sent} · Entrega ${pct(delivered,sent)} · Abertura ${pct(opened,sent)} · Clique ${pct(clicked,sent)} · Bounce ${pct(bounced,sent)}</div></div>`}
function emailConfigHtml(c){c.variants=Array.isArray(c.variants)&&c.variants.length?c.variants:[{id:uid('var'),label:'A',weight:50,templateVersionId:c.templateVersionId||0,templateLabel:c.templateLabel||''},{id:uid('var'),label:'B',weight:50,templateVersionId:0,templateLabel:''}];return field('Modelo publicado',`<select id="iTemplate" ${c.abEnabled?'disabled':''}>${templateOptions(c.templateVersionId)}</select>`)+`<label class="afe-check"><input id="iAbEnabled" type="checkbox" ${c.abEnabled?'checked':''}> Ativar teste A/B/n neste e-mail</label>`+(c.abEnabled?`<div class="afe-config-box"><div class="afe-field"><label>Variantes A/B/n</label><div class="afe-copy">O peso define a proporção dos disparos. As métricas abaixo usam os envios deste fluxo para cada modelo.</div><div class="afe-fields">${c.variants.map(abVariantHtml).join('')}</div><button type="button" class="btn btn-ghost btn-xs" id="addVariant">+ Inserir variante</button></div></div>`:'')}
function voiceMediaOptions(value){return `<option value="">Selecione um audio salvo</option>${voiceMedia.map(m=>`<option value="${m.id}" ${String(value)===String(m.id)?'selected':''}>${esc(m.name)}</option>`).join('')}`}
function pushNotificationOptions(value){return `<option value="">Selecione a notificação</option>${pushNotifications.map(n=>`<option value="${n.id}" ${String(value)===String(n.id)?'selected':''}>#${n.id} · ${esc(n.title||'Sem título')}</option>`).join('')}`}
function voiceCampaignOptions(value){return `<option value="">Selecione a campanha de voz</option>${voiceCampaigns.map(c=>`<option value="${c.id}" ${String(value)===String(c.id)?'selected':''}>#${c.id} · ${esc(c.name||'Sem nome')}</option>`).join('')}`}
function conditionPickerHtml(r,i){const value=r.field||'tag',current=conditionIndex[value]||{code:value,label:ruleFields[value]||value||'Selecione a condição',desc:'Clique em Ver condições para escolher.'};return `<div class="afe-event-picker"><div class="afe-event-row"><input value="${esc(current.label||value)}" readonly><button type="button" class="btn btn-ghost btn-sm" data-condition-toggle="${i}">▼ Ver condições</button></div><div class="afe-copy">${esc(current.desc||current.code||'')}</div><div class="afe-event-menu" data-condition-menu="${i}">${conditionGroups.map(g=>`<div class="afe-event-group">${esc(g.label)}</div>${(g.items||[]).map(item=>`<button type="button" class="afe-event-option ${String(item.code)===String(value)?'active':''}" data-rule-index="${i}" data-condition-field="${esc(item.code)}"><strong>${esc(item.label)} <span class="afe-badge">${esc(item.tag||g.label)}</span></strong><p>${esc(item.desc||item.code)}</p></button>`).join('')}`).join('')}</div></div>`}
function defaultOperator(field){return ['course_progress_pct','lessons_completed_count','engagement_count'].includes(field)?'gte':'has'}
function operatorOptions(field,value){const numeric=['course_progress_pct','lessons_completed_count','engagement_count'].includes(field),base=numeric?{gte:'maior ou igual',lte:'menor ou igual',equals:'é igual',not_equals:'é diferente'}:{has:'ocorreu / possui',not_has:'não ocorreu / não possui',equals:'é igual',not_equals:'é diferente',gte:'maior ou igual',lte:'menor ou igual'};return opts(base,value||defaultOperator(field))}
function scopedSelectorHtml(r,i,optionsHtml,emptyMsg){const scope=r.scope||'specific',select=`<select data-r="${i}" data-k="scope">${opts({any:'Qualquer uma',specific:'Escolher qual',all:'Todas'},scope)}</select>`;if(scope!=='specific')return select+`<div class="afe-copy">${scope==='all'?'Todas as ocorrências registradas para o aluno precisam cumprir esta condição.':'Basta uma ocorrência registrada para o aluno.'}</div>`;return select+(optionsHtml?`<select data-r="${i}" data-k="value">${optionsHtml}</select>`:`<div class="afe-copy">${emptyMsg}</div>`)}
function voicePreferredTagOptions(selected){selected=Array.isArray(selected)?selected:[];return tags.map(x=>`<option value="${esc(x)}" ${selected.includes(x)?'selected':''}>${esc(x)}</option>`).join('')}
function voiceDeadlineControls(c){const enabled=!!c.maxQueueEnabled||+c.maxQueueDuration>0;return `<label class="afe-check"><input id="iVoiceMaxQueueEnabled" type="checkbox" ${enabled?'checked':''}> Cancelar se ficar tempo demais na fila</label>`+field('Tempo maximo na fila',`<input id="iVoiceMaxQueueDuration" type="number" min="1" step="1" value="${esc(c.maxQueueDuration||10)}"><select id="iVoiceMaxQueueUnit">${opts({minutes:'minutos',hours:'horas',days:'dias'},c.maxQueueUnit||'hours')}</select><div class="afe-copy">Ex.: 10 horas significa que, se a chamada nao sair em ate 10 horas apos entrar neste bloco, os jobs restantes da mesma fila serao cancelados e logados.</div>`)}
function voicePreferredTagsField(c){return field('Tags preferenciais',`<select id="iVoicePreferredTags" multiple size="7">${voicePreferredTagOptions(c.preferredTags)}</select><div class="afe-copy">Alunos com qualquer uma dessas tags sao puxados primeiro na fila deste bloco de voz.</div>`)}
function voiceScopedSelectorHtml(r,i){const scope=r.scope||'latest',select=`<select data-r="${i}" data-k="scope">${opts({latest:'Última chamada',any:'Qualquer chamada',specific:'Campanha específica',all:'Todas as chamadas'},scope)}</select>`;if(scope==='latest')return select+'<div class="afe-copy">Avalia somente a chamada de voz mais recente registrada para este aluno.</div>';if(scope==='any')return select+'<div class="afe-copy">Basta uma chamada registrada para este aluno cumprir esta condição.</div>';if(scope==='all')return select+'<div class="afe-copy">Todas as chamadas registradas para este aluno precisam cumprir esta condição.</div>';return select+(voiceCampaigns.length?`<select data-r="${i}" data-k="value">${voiceCampaignOptions(r.value)}</select>`:'<div class="afe-copy">Nenhuma campanha de voz encontrada. Use Última chamada ou Qualquer chamada para chamadas feitas por automação/teste.</div>')}
function conditionValueHtml(r,i){const meta=conditionIndex[r.field]||{},kind=meta.value||'text';if(kind==='none')return '';if(kind==='tags'){const selected=Array.isArray(r.value)?r.value:(r.value?[r.value]:[]);return `<select data-r="${i}" data-k="value" multiple size="7">${tags.map(x=>`<option value="${esc(x)}" ${selected.includes(x)?'selected':''}>${esc(x)}</option>`).join('')}</select><div class="afe-copy">Use Ctrl/Cmd ou Shift para selecionar várias tags.</div>`}if(kind==='template')return `<select data-r="${i}" data-k="value">${templateOptions(r.value)}</select>`;if(kind==='template_event')return scopedSelectorHtml(r,i,templateOptions(r.value),'Nenhum e-mail publicado encontrado.');if(kind==='voice')return voiceScopedSelectorHtml(r,i);if(kind==='push')return `<select data-r="${i}" data-k="value">${pushNotificationOptions(r.value)}</select>`;if(kind==='number')return `<input data-r="${i}" data-k="value" type="number" min="0" step="1" value="${esc(r.value||0)}">`;return `<input data-r="${i}" data-k="value" value="${esc(r.value||'')}">`}
function ruleHtml(r,i){r.field=r.field||'tag';return `<div class="afe-rule"><div class="afe-rule-head"><strong>Regra ${i+1}</strong><button type="button" class="afe-rule-remove" data-remove="${i}">Excluir</button></div>${conditionPickerHtml(r,i)}<select data-r="${i}" data-k="operator">${operatorOptions(r.field,r.operator)}</select>${conditionValueHtml(r,i)}</div>`}
const triggerIndex={};triggerGroups.forEach(g=>(g.items||[]).forEach(i=>triggerIndex[i.code]=i));
function eventPickerHtml(value){const current=triggerIndex[value]||{code:value||'',label:triggers[value]||value||'Selecione o evento',desc:'Clique em Ver eventos para escolher o gatilho.'};return `<div class="afe-event-picker"><div class="afe-event-row"><input id="iEvent" value="${esc(value||'')}" placeholder="Selecione um evento"><button type="button" class="btn btn-ghost btn-sm" id="eventToggle">▼ Ver eventos</button></div><div class="afe-copy">${esc(current.desc||current.label||'')}</div><div class="afe-event-menu" id="eventMenu">${triggerGroups.map(g=>`<div class="afe-event-group">${esc(g.label)}</div>${(g.items||[]).map(i=>`<button type="button" class="afe-event-option ${String(i.code)===String(value)?'active':''}" data-event="${esc(i.code)}"><strong>${esc(i.label)} <span class="afe-badge">${esc(i.tag||'EVENTO')}</span></strong><p>${esc(i.desc||i.code)}</p></button>`).join('')}`).join('')}</div></div>`}
function paymentProductOptionsHtml(c){const selected=selectedPaymentProducts(c),custom=selected.filter(x=>!paymentProducts.includes(x)),list=paymentProducts.concat(custom);return `<select id="iPaymentProducts" multiple size="7">${list.map(x=>`<option value="${esc(x)}" ${selected.includes(x)?'selected':''}>${esc(x)}</option>`).join('')}</select><div class="afe-event-row"><input id="iPaymentProductCustom" list="paymentProductOptions" placeholder="Digitar curso/produto"><button type="button" class="btn btn-ghost btn-sm" id="addPaymentProduct">Adicionar</button></div><datalist id="paymentProductOptions">${list.map(x=>`<option value="${esc(x)}"></option>`).join('')}</datalist><div class="afe-copy">${selected.length?selected.length+' curso(s)/produto(s) selecionado(s)':'Todos os cursos/produtos'}</div>`}
function integrationSourceOptionsHtml(){return `<datalist id="integrationFieldOptions">${Object.entries(integrationFieldOptions).map(([value,label])=>`<option value="${esc(value)}">${esc(label)}</option>`).join('')}</datalist>`}
function integrationFieldHtml(r,i){const mode=r.sourceMode||'variable',type=r.valueType||'auto',sourceHtml=mode==='fixed'?`<input data-f="${i}" data-k="fixedValue" value="${esc(r.fixedValue||r.source||'')}" placeholder="Valor fixo">`:`<input list="integrationFieldOptions" data-f="${i}" data-k="source" value="${esc(r.source||'')}" placeholder="Escolha campo do aluno/evento">`;return `<div class="afe-pair-row"><select data-f="${i}" data-k="sourceMode"><option value="variable" ${mode==='variable'?'selected':''}>Variável</option><option value="fixed" ${mode==='fixed'?'selected':''}>Fixo</option></select>${sourceHtml}<select data-f="${i}" data-k="valueType"><option value="auto" ${type==='auto'?'selected':''}>Auto</option><option value="text" ${type==='text'?'selected':''}>Texto</option><option value="number" ${type==='number'?'selected':''}>Número</option><option value="date" ${type==='date'?'selected':''}>Data</option><option value="datetime" ${type==='datetime'?'selected':''}>Data e hora</option></select><input data-f="${i}" data-k="dest" value="${esc(r.dest||'')}" placeholder="Campo destino, field_id:123"><button type="button" data-field-remove="${i}">×</button></div>`}
function inspect(){const n=graph.nodes.find(x=>x.id===selected);if(!n){inspector.innerHTML='<div class="afe-empty">Selecione um bloco no canvas.</div>';return}const c=n.config||(n.config={});let html=field('Rótulo',`<input id="iLabel" value="${esc(c.label||'')}">`);if(n.type==='trigger'){html+=field('Evento',eventPickerHtml(c.event));if(String(c.event||'').startsWith('PAGAMENTO_'))html+=field('Curso/produto do pagamento',paymentProductOptionsHtml(c));html+=field('Filtro de turma',`<select id="iFilter"><option value="">Todas</option>${turmas.map(x=>`<option ${x===c.filter?'selected':''}>${esc(x)}</option>`).join('')}</select>`);if(c.event==='LIVE_LEMBRETE_AGENDADO')html+=field('Antecedência',`<input id="iAdvance" type="number" min="1" value="${c.advanceDuration||1}"><select id="iAdvanceUnit">${opts({minutes:'minutos',hours:'horas',days:'dias'},c.advanceUnit)}</select>`)}if(n.type==='condition'){c.rules=Array.isArray(c.rules)&&c.rules.length?c.rules:[{field:'tag',operator:'has',value:''}];html+=field('Combinação',`<select id="iLogic">${opts({and:'Todas as regras (E)',or:'Qualquer regra (OU)'},c.logic)}</select>`)+`<div id="rules">${c.rules.map(ruleHtml).join('')}</div><button type="button" class="btn btn-ghost btn-xs" id="addRule">+ Adicionar regra</button>`}if(n.type==='wait')html+=field('Duração',`<input id="iDuration" type="number" min="1" value="${c.duration||1}">`)+field('Unidade',`<select id="iUnit">${opts({minutes:'minutos',hours:'horas',days:'dias'},c.unit)}</select>`)+`<label class="afe-check"><input id="iWindow" type="checkbox" ${c.limitWindow?'checked':''}> Retomar só em faixa de horário</label>`+field('Faixa',`<input id="iStart" type="time" value="${c.windowStart||'08:00'}"><input id="iEnd" type="time" value="${c.windowEnd||'20:00'}">`);if(n.type==='email')html+=emailConfigHtml(c);if(n.type==='push')html+=field('Título',`<input id="iPushTitle" maxlength="150" value="${esc(c.title||'')}">`)+field('Mensagem',`<textarea id="iPushBody">${esc(c.body||'')}</textarea>`)+field('Link ao clicar',`<input id="iPushUrl" value="${esc(c.clickUrl||'trilha.php')}">`);if(n.type==='voice')html+=field('Destino',`<select id="iVoicePhone"><option value="">Telefone detectado do aluno</option><option value="telefone" ${c.phoneField==='telefone'?'selected':''}>user.telefone</option><option value="celular" ${c.phoneField==='celular'?'selected':''}>user.celular</option><option value="whatsapp" ${c.phoneField==='whatsapp'?'selected':''}>user.whatsapp</option></select>`)+field('Modo da mensagem',`<select id="iVoiceMode">${opts({text_to_speech:'Texto para voz',audio_url:'Audio por URL'},c.messageMode)}</select>`)+field('Mensagem TTS',`<textarea id="iVoiceMessage" placeholder="Ola, {{primeiro_nome|aluno}}">${esc(c.message||'')}</textarea>`)+field('?udio da biblioteca',`<select id="iVoiceMedia">${voiceMediaOptions(c.audioMediaId)}</select><div class="afe-copy">Selecione um ?udio enviado na aba Torpedo de Voz > ?udios e vozes.</div>`)+field('URL externa opcional',`<input id="iVoiceAudio" value="${esc(c.audioUrl||'')}" placeholder="https://.../audio.mp3"><div class="afe-copy">Use somente se o ?udio estiver fora da biblioteca.</div>`)+field('Origem opcional',`<input id="iVoiceFrom" value="${esc(c.fromNumber||'')}" placeholder="Usa numero padrao da Telnyx">`)+field('Limites',`<input id="iVoiceLimit" type="number" min="10" value="${c.timeLimitSecs||120}" placeholder="Duracao maxima em segundos"><input id="iVoiceTimeout" type="number" min="5" value="${c.timeoutSecs||30}" placeholder="Timeout atendimento em segundos">`)+`<label class="afe-check"><input id="iVoiceAmd" type="checkbox" ${c.answeringMachineDetection?'checked':''}> Detectar humano/caixa postal</label><label class="afe-check"><input id="iVoiceRecord" type="checkbox" ${c.recordCalls?'checked':''}> Gravar chamada quando implementado</label><label class="afe-check"><input id="iVoiceTranscribe" type="checkbox" ${c.transcribeCalls?'checked':''}> Transcrever quando implementado</label>`;if(n.type==='action')html+=field('Ação',`<select id="iTagAction">${opts({add_tag:'Adicionar tag',remove_tag:'Remover tag'},c.action)}</select>`)+field('Tag',`<select id="iTag"><option value="">Selecione</option>${tags.map(x=>`<option ${x===c.tag?'selected':''}>${esc(x)}</option>`).join('')}</select>`);if(n.type==='integration'){c.fields=Array.isArray(c.fields)?c.fields:[];html+=field('Canal',`<select id="iProvider">${opts({webhook:'Webhook',superfuncionario:'SuperFuncionário',manychat:'ManyChat'},c.provider)}</select>`)+`<div class="afe-config-box">`+field('Evento ou ID',`<input id="iTarget" value="${esc(c.target||'')}" placeholder="Evento, endpoint ou identificador do canal">`)+field('Flow ID(s) - separados por vírgula',`<input id="iFlowsText" value="${esc(c.flowsText||'')}" placeholder="1783294947639, 1783294947640">`)+field('Tag(s) - separadas por vírgula',`<textarea id="iTagsText" placeholder="TAG_1, TAG_2">${esc(c.tagsText||'')}</textarea>`)+`<div class="afe-field"><label>Campos personalizados</label><div class="afe-copy">Mapeie a origem do lead/evento para o destino. Escolha uma variável da lista ou marque Fixo para digitar. Tipo Auto reconhece datas; também é possível forçar Texto, Número, Data ou Data e hora.</div>${integrationSourceOptionsHtml()}<div class="afe-fields">${c.fields.map(integrationFieldHtml).join('')}</div><button type="button" class="btn btn-ghost btn-xs" id="addCustomField">+ Adicionar campo</button></div>`+field('Payload JSON opcional',`<textarea id="iPayload">${esc(c.payload||'')}</textarea>`)+`</div>`}if(n.type!=='trigger')html+='<button type="button" class="btn btn-danger btn-sm" id="removeNode">Excluir bloco</button>';inspector.innerHTML='<div class="afe-fields">'+html+'</div>';
bind('iLabel',e=>{c.label=e.target.value;render()});bind('iEvent',e=>{c.event=e.target.value;if(!String(c.event||'').startsWith('PAGAMENTO_')){c.paymentProduct='';c.paymentProducts=[];}render();inspect()});const eventBtn=document.getElementById('eventToggle'),eventBox=document.getElementById('eventMenu'),eventInput=document.getElementById('iEvent');if(eventBtn&&eventBox)eventBtn.onclick=()=>eventBox.classList.toggle('open');document.querySelectorAll('[data-event]').forEach(e=>e.onclick=()=>{c.event=e.dataset.event;if(!String(c.event||'').startsWith('PAGAMENTO_')){c.paymentProduct='';c.paymentProducts=[];}if(eventInput)eventInput.value=c.event;render();inspect()});bind('iFilter',e=>c.filter=e.target.value,'change');bind('iPaymentProducts',e=>{setPaymentProducts(c,[...e.target.selectedOptions].map(o=>o.value));render();inspect()},'change');const addPaymentProductBtn=document.getElementById('addPaymentProduct'),customPaymentProduct=document.getElementById('iPaymentProductCustom');if(addPaymentProductBtn&&customPaymentProduct)addPaymentProductBtn.onclick=()=>{const value=customPaymentProduct.value.trim();if(!value)return;setPaymentProducts(c,selectedPaymentProducts(c).concat(value));render();inspect()};bind('iAdvance',e=>c.advanceDuration=+e.target.value);bind('iAdvanceUnit',e=>c.advanceUnit=e.target.value,'change');bind('iLogic',e=>c.logic=e.target.value,'change');bind('iDuration',e=>{c.duration=+e.target.value;render()});bind('iUnit',e=>{c.unit=e.target.value;render()},'change');bind('iWindow',e=>c.limitWindow=e.target.checked,'change');bind('iStart',e=>c.windowStart=e.target.value);bind('iEnd',e=>c.windowEnd=e.target.value);bind('iTemplate',e=>{c.templateVersionId=+e.target.value;c.templateLabel=e.target.options[e.target.selectedIndex].text;render()},'change');bind('iAbEnabled',e=>{c.abEnabled=e.target.checked;if(c.abEnabled){c.variants=Array.isArray(c.variants)&&c.variants.length?c.variants:[{id:uid('var'),label:'A',weight:50,templateVersionId:c.templateVersionId||0,templateLabel:c.templateLabel||''},{id:uid('var'),label:'B',weight:50,templateVersionId:0,templateLabel:''}]}render();inspect()},'change');document.querySelectorAll('[data-ab]').forEach(e=>e.onchange=e.oninput=x=>{const i=+x.target.dataset.ab,k=x.target.dataset.k;c.variants=Array.isArray(c.variants)?c.variants:[];if(!c.variants[i])return;c.variants[i][k]=k==='weight'?Math.max(1,+x.target.value||1):x.target.value;if(k==='templateVersionId'){c.variants[i].templateVersionId=+x.target.value;c.variants[i].templateLabel=x.target.options[x.target.selectedIndex].text}render()});document.querySelectorAll('[data-ab-remove]').forEach(e=>e.onclick=()=>{c.variants=Array.isArray(c.variants)?c.variants:[];if(c.variants.length<=2)return alert('Mantenha pelo menos duas variantes.');c.variants.splice(+e.dataset.abRemove,1);inspect();render()});if(window.addVariant)addVariant.onclick=()=>{c.variants=Array.isArray(c.variants)?c.variants:[];const label=String.fromCharCode(65+c.variants.length);c.variants.push({id:uid('var'),label,weight:1,templateVersionId:0,templateLabel:''});inspect();render()};bind('iPushTitle',e=>{c.title=e.target.value;render()});bind('iPushBody',e=>c.body=e.target.value);bind('iPushUrl',e=>c.clickUrl=e.target.value);bind('iVoicePhone',e=>{c.phoneField=e.target.value;render()},'change');bind('iVoiceMode',e=>{c.messageMode=e.target.value;render();inspect()},'change');bind('iVoiceMessage',e=>{c.message=e.target.value;render()});bind('iVoiceMedia',e=>{c.audioMediaId=+e.target.value;render()},'change');bind('iVoiceAudio',e=>{c.audioUrl=e.target.value;render()});bind('iVoiceFrom',e=>c.fromNumber=e.target.value);bind('iVoiceLimit',e=>c.timeLimitSecs=+e.target.value);bind('iVoiceTimeout',e=>c.timeoutSecs=+e.target.value);bind('iVoiceAmd',e=>c.answeringMachineDetection=e.target.checked,'change');bind('iVoiceRecord',e=>c.recordCalls=e.target.checked,'change');bind('iVoiceTranscribe',e=>c.transcribeCalls=e.target.checked,'change');bind('iTagAction',e=>{c.action=e.target.value;render()},'change');bind('iTag',e=>{c.tag=e.target.value;render()},'change');bind('iProvider',e=>{c.provider=e.target.value;render();inspect()},'change');bind('iTarget',e=>{c.target=e.target.value;render()});bind('iFlowsText',e=>c.flowsText=e.target.value);bind('iTagsText',e=>c.tagsText=e.target.value);bind('iPayload',e=>c.payload=e.target.value);document.querySelectorAll('[data-f]').forEach(e=>e.onchange=e.oninput=x=>{const i=+x.target.dataset.f,k=x.target.dataset.k;c.fields=Array.isArray(c.fields)?c.fields:[];c.fields[i]=c.fields[i]||{sourceMode:'variable',source:'',fixedValue:'',valueType:'auto',dest:''};c.fields[i][k]=x.target.value;if(k==='sourceMode')inspect()});document.querySelectorAll('[data-field-remove]').forEach(e=>e.onclick=()=>{c.fields.splice(+e.dataset.fieldRemove,1);inspect()});const addFieldBtn=document.getElementById('addCustomField');if(addFieldBtn)addFieldBtn.onclick=()=>{c.fields=Array.isArray(c.fields)?c.fields:[];c.fields.push({sourceMode:'variable',source:'',fixedValue:'',valueType:'auto',dest:''});inspect()};document.querySelectorAll('[data-condition-toggle]').forEach(e=>e.onclick=()=>{const box=document.querySelector(`[data-condition-menu="${e.dataset.conditionToggle}"]`);if(box)box.classList.toggle('open')});document.querySelectorAll('[data-condition-field]').forEach(e=>e.onclick=()=>{const i=+e.dataset.ruleIndex,field=e.dataset.conditionField,kind=conditionIndex[field]?.value||'text';c.rules[i]=c.rules[i]||{};c.rules[i].field=field;c.rules[i].operator=defaultOperator(field);c.rules[i].scope=['template_event','voice'].includes(kind)?'specific':'any';c.rules[i].value=kind==='tags'?[]:'';inspect();render()});document.querySelectorAll('[data-r]').forEach(e=>e.onchange=e.oninput=x=>{const i=+x.target.dataset.r,k=x.target.dataset.k;c.rules[i][k]=x.target.multiple?[...x.target.selectedOptions].map(o=>o.value).filter(Boolean):x.target.value;if(k==='field'||k==='scope')inspect();render()});document.querySelectorAll('[data-remove]').forEach(e=>e.onclick=()=>{c.rules.splice(+e.dataset.remove,1);if(!c.rules.length)c.rules.push({field:'tag',operator:'has',value:''});inspect();render()});if(window.addRule)addRule.onclick=()=>{c.rules.push({field:'tag',operator:'has',value:''});inspect();render()};if(window.removeNode)removeNode.onclick=()=>{graph.nodes=graph.nodes.filter(x=>x.id!==n.id);graph.edges=graph.edges.filter(x=>x.source!==n.id&&x.target!==n.id);selected=null;render();inspect()}}
document.addEventListener('click',e=>{const btn=e.target.closest('[data-condition-field]');if(!btn)return;const field=btn.dataset.conditionField,meta=conditionIndex[field]||{};if(meta.value!=='voice')return;setTimeout(()=>{const n=graph.nodes.find(x=>x.id===selected),i=+btn.dataset.ruleIndex;if(!n||!n.config||!Array.isArray(n.config.rules)||!n.config.rules[i])return;n.config.rules[i].scope='latest';n.config.rules[i].value='';inspect();render()},0)});
const internalPushPages=<?=json_encode(array_map(static fn($p)=>[(string)$p['url'],(string)$p['label']],$pushInternalPages),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG)?>;
function isExternalPushUrl(value){return /^https:\/\//i.test(String(value||''))||String(value||'').includes('{{link_live}}')}
function pushLinkControlsHtml(current){const mode=isExternalPushUrl(current)?'external':'internal',internal=mode==='internal'?(current||'trilha.php'):'trilha.php',known=internalPushPages.some(p=>p[0]===internal),pages=internalPushPages.concat(!known&&internal?[[internal,'Destino atual: '+internal]]:[]);return `<div class="afe-field"><label>Tipo de destino</label><select id="iPushLinkMode"><option value="internal" ${mode==='internal'?'selected':''}>Página interna</option><option value="external" ${mode==='external'?'selected':''}>URL externa HTTPS</option></select></div><div class="afe-field" id="iPushInternalWrap"><label>Página interna</label><select id="iPushInternalPage">${pages.map(p=>`<option value="${esc(p[0])}" ${p[0]===internal?'selected':''}>${esc(p[1])}</option>`).join('')}</select></div><div class="afe-field" id="iPushExternalWrap"><label>URL externa</label><input id="iPushExternalUrl" value="${esc(mode==='external'?current:'')}" placeholder="https://dominio-autorizado.com/pagina"></div>`}
function mountPushLinkControls(c){const old=document.getElementById('iPushUrl');if(!old)return;const oldField=old.closest('.afe-field');if(!oldField||document.getElementById('iPushLinkMode'))return;const wrap=document.createElement('div');wrap.className='afe-fields';wrap.innerHTML=pushLinkControlsHtml(c.clickUrl||'trilha.php');oldField.replaceWith(wrap);const mode=document.getElementById('iPushLinkMode'),internal=document.getElementById('iPushInternalPage'),external=document.getElementById('iPushExternalUrl'),internalWrap=document.getElementById('iPushInternalWrap'),externalWrap=document.getElementById('iPushExternalWrap');const sync=()=>{const isExt=mode.value==='external';internalWrap.style.display=isExt?'none':'';externalWrap.style.display=isExt?'':'none';c.clickUrl=isExt?external.value.trim():(internal.value||'trilha.php');render()};mode.addEventListener('change',sync);internal.addEventListener('change',sync);external.addEventListener('input',sync);sync()}
function mountVoiceMediaControls(c){const select=document.getElementById('iVoiceMedia');if(!select||select.dataset.enhanced)return;select.dataset.enhanced='1';const field=select.closest('.afe-field');if(field){const label=field.querySelector('label'),copy=field.querySelector('.afe-copy');if(label)label.textContent='Audio da biblioteca';if(copy)copy.textContent='Selecione um audio salvo em Torpedo de Voz > Audios e vozes.'}const audioUrl=document.getElementById('iVoiceAudio'),audioUrlCopy=audioUrl?.closest('.afe-field')?.querySelector('.afe-copy');if(audioUrlCopy)audioUrlCopy.textContent='Use somente se o audio estiver fora da biblioteca.';if(!voiceMedia.length){select.innerHTML='<option value="">Nenhum audio salvo em Torpedo de Voz > Audios e vozes</option>';select.disabled=true;return}select.innerHTML=voiceMediaOptions(c.audioMediaId);select.addEventListener('change',()=>{c.audioMediaId=+select.value;if(c.audioMediaId>0){c.messageMode='audio_url';c.audioUrl='';const mode=document.getElementById('iVoiceMode'),audio=document.getElementById('iVoiceAudio');if(mode)mode.value='audio_url';if(audio)audio.value=''}render()})}
function mountVoiceAdvancedControls(c){const fields=inspector.querySelector('.afe-fields'),removeBtn=document.getElementById('removeNode');if(!fields||document.getElementById('iVoiceMaxQueueEnabled'))return;c.preferredTags=Array.isArray(c.preferredTags)?c.preferredTags:[];const box=document.createElement('div');box.className='afe-fields';box.innerHTML=voiceDeadlineControls(c)+voicePreferredTagsField(c);if(removeBtn)removeBtn.before(box);else fields.appendChild(box);const syncQueueVisibility=()=>{const on=document.getElementById('iVoiceMaxQueueEnabled')?.checked,field=document.getElementById('iVoiceMaxQueueDuration')?.closest('.afe-field');if(field)field.style.display=on?'':'none'};bind('iVoiceMaxQueueEnabled',e=>{c.maxQueueEnabled=e.target.checked;if(c.maxQueueEnabled&&(+c.maxQueueDuration||0)<1)c.maxQueueDuration=10;syncQueueVisibility();render()},'change');bind('iVoiceMaxQueueDuration',e=>{c.maxQueueDuration=Math.max(1,parseInt(e.target.value||'1',10)||1);render()});bind('iVoiceMaxQueueUnit',e=>{c.maxQueueUnit=e.target.value;render()},'change');bind('iVoicePreferredTags',e=>{c.preferredTags=[...e.target.selectedOptions].map(o=>o.value).filter(Boolean);render()},'change');syncQueueVisibility()}
const baseInspect=inspect;inspect=function(){baseInspect();const n=graph.nodes.find(x=>x.id===selected);if(!n)return;const c=n.config||(n.config={});if(n.type==='voice'){mountVoiceMediaControls(c);mountVoiceAdvancedControls(c)}if(n.type!=='push')return;const fields=inspector.querySelector('.afe-fields'),removeBtn=document.getElementById('removeNode');mountPushLinkControls(c);if(!fields||document.getElementById('afeOpenPushPreview'))return;const extra=document.createElement('div');extra.className='afe-fields';extra.innerHTML='<button class="btn btn-ghost btn-sm" type="button" id="afeOpenPushPreview">Pré-visualizar no Android</button><div id="afePushRisk">'+pushRiskHtml(previewText(c.title||''),previewText(c.body||''))+'</div><div class="afe-copy">Aceita página interna ou URL HTTPS de um domínio autorizado nas configurações. Variáveis gerais: {{nome}}, {{email}}, {{telefone}}, {{turma}}, {{data_live}}, {{hora_live}}, {{codigo_live}} e {{link_live}}. Para abrir a sala da live, use <code>{{link_live}}</code> no campo URL externa.</div><div class="afe-note" style="margin-top:10px"><strong>Magic link:</strong> em URLs externas, use <code>{{nome_url}}</code>, <code>{{email_url}}</code> e <code>{{telefone_url}}</code>. Exemplo:<br><code style="overflow-wrap:anywhere">https://professoremersonleite.applive.com.br/rep_mcqdc/evento?nome={{nome_url}}&amp;email={{email_url}}&amp;telefone={{telefone_url}}</code></div>';if(removeBtn)removeBtn.before(extra);else fields.appendChild(extra);const refreshRisk=()=>{const risk=document.getElementById('afePushRisk');if(risk)risk.innerHTML=pushRiskHtml(previewText(c.title||''),previewText(c.body||''))};document.getElementById('iPushTitle')?.addEventListener('input',refreshRisk);document.getElementById('iPushBody')?.addEventListener('input',refreshRisk);document.getElementById('afeOpenPushPreview')?.addEventListener('click',()=>openPushPreview(c))};
document.getElementById('afePreviewClose')?.addEventListener('click',()=>document.getElementById('afePushPreview').classList.remove('open'));document.getElementById('afePushPreview')?.addEventListener('click',e=>{if(e.target.id==='afePushPreview')e.currentTarget.classList.remove('open')});document.getElementById('afePreviewExpand')?.addEventListener('click',()=>renderCompactPreview(!document.getElementById('afeNotificationMock').classList.contains('expanded')));
function prettyJson(value){try{return JSON.stringify(value,null,2)}catch(e){return String(value??'')}}
function safeParse(value){try{return JSON.parse(value)}catch(e){return value}}
function cleanLabel(value){return String(value||'').replace(/_/g,' ').toLowerCase().replace(/\b\w/g,c=>c.toUpperCase())}
function shortValue(value){if(value===null||value===undefined||value==='')return '-';if(typeof value==='boolean')return value?'Sim':'Nao';if(typeof value==='object')return prettyJson(value);return String(value)}
function stepLines(s){const out=s.output||{},type=String(s.type||''),lines=[];if(s.error){lines.push(['Erro',s.error,'bad']);return lines}if(type==='trigger'){const p=out.test_payload||{};lines.push(['O que aconteceu','O sistema recebeu o evento '+shortValue(out.event)+'.','good']);lines.push(['Aluno/turma','Turma '+shortValue(p.codigo_turma||p.turma_codigo)+'; live '+shortValue(p.data_live||p.live_at)+'.','']);lines.push(['Proximo passo',out.next_node?'Seguiu para o proximo bloco.':'Nao havia proximo bloco.',''])}else if(type==='condition'){lines.push(['O que aconteceu','O sistema avaliou as regras da condicao.','good']);lines.push(['Resultado',out.result?'A condicao deu SIM.':'A condicao deu NAO.',out.result?'good':'bad']);lines.push(['Caminho seguido',String(out.route||s.handle||'').toUpperCase()||'-',''])}else if(type==='wait'){lines.push(['O que aconteceu','Este bloco aguardaria ate o horario calculado.','good']);lines.push(['No teste','A espera foi pulada para voce ver o fluxo completo agora.','']);lines.push(['Horario real',shortValue(out.would_resume_at||out.resume_at),''])}else if(type==='integration'){lines.push(['O que aconteceu','O sistema chamou a integracao '+cleanLabel(out.provider||'configurada')+'.','good']);lines.push(['Evento enviado',shortValue(out.event),'']);lines.push(['Destino',shortValue(out.target||'regra configurada'),''])}else if(type==='email'){lines.push(['O que aconteceu','O sistema tentou enviar o e-mail deste bloco.','good']);lines.push(['Resultado',shortValue(out.message||out.status||out.provider||'Concluido'),'']);}else if(type==='push'){lines.push(['O que aconteceu','O sistema tentou enviar a notificacao push.','good']);lines.push(['Resultado',shortValue(out.message||out.status||'Concluido'),'']);}else if(type==='voice'){lines.push(['O que aconteceu','O sistema tentou iniciar a chamada de voz.','good']);lines.push(['Resultado',shortValue(out.message||out.status||out.call_id||'Concluido'),'']);}else if(type==='action'){lines.push(['O que aconteceu',(out.action==='remove_tag'?'Removeu a tag ':'Adicionou a tag ')+shortValue(out.tag)+'.','good'])}else if(type==='end'){lines.push(['O que aconteceu','O fluxo chegou ao bloco final e encerrou.','good'])}else{lines.push(['O que aconteceu','Bloco executado pelo simulador.','good'])}if(out.next_node&&type!=='trigger')lines.push(['Proximo passo','Seguiu para o proximo bloco.','']);else if(type!=='end'&&!out.next_node)lines.push(['Proximo passo','Nao encontrou outro bloco conectado depois deste.','']);return lines}
function stepReadableHtml(s){return `<div class="afe-test-readable">${stepLines(s).map(([k,v,cls])=>`<div class="afe-test-line ${esc(cls||'')}"><b>${esc(k)}</b><span>${esc(v)}</span></div>`).join('')}</div>`}
function technicalDetailsHtml(title,value,open=false){return `<details class="afe-test-details" ${open?'open':''}><summary>${esc(title)}</summary><pre>${esc(typeof value==='string'?value:prettyJson(value||{}))}</pre></details>`}
function providerLogHtml(name,rows){if(!rows||!rows.length)return '';return `<div class="afe-provider-group"><h4>${esc(name)}</h4>${rows.map(r=>{const ok=String(r.ok)==='1'||r.ok===true,err=r.error_text||r.error_message||'',req=r.request_json?safeParse(r.request_json):'',resp=r.response_text||'';return `<div class="afe-provider-log ${ok?'ok':'fail'}"><div class="afe-test-step-head"><strong>${esc(r.action||r.evento||('log #'+r.id))}</strong><span>HTTP ${esc(r.http_status||'-')} · ${esc(r.created_at||'')}</span></div><div class="afe-test-readable"><div class="afe-test-line ${ok?'good':'bad'}"><b>Resultado</b><span>${ok?'Integracao aceitou a chamada.':'A integracao retornou falha.'}</span></div>${err?`<div class="afe-test-line bad"><b>Erro</b><span>${esc(err)}</span></div>`:''}</div>${technicalDetailsHtml('Ver request/resposta tecnica',{request:req,response:resp,error:err})}</div>`}).join('')}</div>`}
function renderTestResult(data){const result=document.getElementById('afeTestResult');if(!result)return;const steps=Array.isArray(data.steps)?data.steps:[],logs=data.provider_logs||{};result.innerHTML=`<div class="afe-test-summary"><div><small>Execucao</small><strong>#${esc(data.run_id||'-')}</strong></div><div><small>Evento</small><strong>${esc(data.event||'-')}</strong></div><div><small>Aluno</small><strong>${esc((data.user?.nome||'')+' #'+(data.user?.id||''))}</strong></div><div><small>Status</small><strong>${data.ok?'Concluido':'Concluido com falhas'}</strong></div></div>${steps.map((s,i)=>`<div class="afe-test-step ${esc(s.status||'')}"><div class="afe-test-step-head"><strong>${i+1}. ${esc(s.label||s.type||s.node_id)}</strong><span>${esc(cleanLabel(s.type||''))} · ${esc(s.status==='completed'?'ok':(s.status||''))} · ${esc(s.duration_ms||0)} ms</span></div>${s.handle?`<div class="afe-copy">Rota tecnica: ${esc(String(s.handle).toUpperCase())}</div>`:''}${stepReadableHtml(s)}${technicalDetailsHtml(s.error?'Ver erro tecnico':'Ver dados tecnicos',s.error||s.output||{})}</div>`).join('')}${providerLogHtml('ManyChat',logs.manychat)}${providerLogHtml('Webhooks',logs.webhooks)}${providerLogHtml('SuperFuncionario',logs.superfuncionario)}`;}
async function runFlowTest(){const modal=document.getElementById('afeTestModal'),status=document.getElementById('afeTestStatus'),result=document.getElementById('afeTestResult'),user=document.getElementById('afeTestUser'),query=document.getElementById('afeTestUserQuery');if(!user?.value&&!query?.value.trim()){status.className='afe-test-status err';status.textContent='Selecione um aluno ou digite ID/e-mail.';return}status.className='afe-test-status';status.textContent='Executando teste... os blocos reais podem chamar e-mail, push, voz ou integrações configuradas.';result.innerHTML='<div class="afe-test-step"><div class="afe-test-step-head"><strong>Preparando execução</strong><span>aguarde</span></div><pre>Enviando o canvas atual para o simulador...</pre></div>';graph.viewport={...view};const fd=new FormData(afeForm);fd.set('action','simulate_flow');fd.set('graph_json',JSON.stringify(graph));fd.set('test_user_id',user.value);fd.set('test_user_query',query.value.trim());try{const res=await fetch('automacoes.php?id=<?=($flow?(int)$flow['id']:0)?>',{method:'POST',body:fd,credentials:'same-origin'}),text=await res.text();let data;try{data=JSON.parse(text)}catch(e){throw new Error(text.slice(0,500)||'Resposta invalida do servidor.')}if(!res.ok||!data.ok){status.className='afe-test-status err';status.textContent=data.error||'Teste concluiu com falhas.'}else{status.className='afe-test-status ok';status.textContent='Teste concluído. Veja abaixo o caminho percorrido, respostas e logs das integrações.'}renderTestResult(data)}catch(e){status.className='afe-test-status err';status.textContent='Erro ao executar teste: '+e.message;result.innerHTML=''}}
document.getElementById('afeOpenTest')?.addEventListener('click',()=>{document.getElementById('afeTestModal')?.classList.add('open');document.getElementById('afeTestStatus').className='afe-test-status';document.getElementById('afeTestStatus').textContent='Selecione um aluno e execute o teste.'});
document.getElementById('afeTestClose')?.addEventListener('click',()=>document.getElementById('afeTestModal')?.classList.remove('open'));
document.getElementById('afeTestModal')?.addEventListener('click',e=>{if(e.target.id==='afeTestModal')e.currentTarget.classList.remove('open')});
document.getElementById('afeRunTest')?.addEventListener('click',runFlowTest);
Object.entries(types).forEach(([t,m])=>{const b=document.createElement('button');b.type='button';b.className='afe-item';b.draggable=canWrite;b.style.setProperty('--c',m.color);b.innerHTML='<span class="afe-dot"></span>'+esc(m.label);b.onclick=()=>add(t,220+graph.nodes.length*22,120+graph.nodes.length*18);b.ondragstart=e=>e.dataTransfer.setData('application/x-af-node',t);afePalette.appendChild(b)});canvas.ondragover=e=>e.preventDefault();canvas.ondrop=e=>{e.preventDefault();const t=e.dataTransfer.getData('application/x-af-node'),r=canvas.getBoundingClientRect();if(t)add(t,(e.clientX-r.left-view.x)/view.zoom,(e.clientY-r.top-view.y)/view.zoom)};canvas.onpointermove=e=>{if(drag){drag.n.x=Math.max(0,drag.ox+(e.clientX-drag.sx)/view.zoom);drag.n.y=Math.max(0,drag.oy+(e.clientY-drag.sy)/view.zoom);render()}else if(pan){view.x=pan.ox+e.clientX-pan.x;view.y=pan.oy+e.clientY-pan.y;apply()}};canvas.onpointerup=()=>{drag=pan=null;canvas.classList.remove('is-panning')};canvas.onpointerdown=e=>{if(e.button!==0)return;const hit=e.target.closest?e.target.closest('.afe-node,.afe-tools,.afe-port,.afe-edge-trash,.afe-edge-hit'):null;if(hit)return;selectedEdge=null;selected=null;inspect();edges();pan={x:e.clientX,y:e.clientY,ox:view.x,oy:view.y};canvas.classList.add('is-panning');canvas.setPointerCapture(e.pointerId)};canvas.onwheel=e=>{e.preventDefault();view.zoom*=e.deltaY<0?1.1:.9;apply()};afeIn.onclick=()=>{view.zoom*=1.15;apply()};afeOut.onclick=()=>{view.zoom*=.85;apply()};afeZoom.onclick=()=>{view.zoom=1;apply()};afeFit.onclick=()=>{view={x:60,y:50,zoom:1};apply()};document.querySelectorAll('[data-action]').forEach(b=>b.onclick=()=>afeAction.value=b.dataset.action);afeForm.onsubmit=()=>{graph.viewport={...view};afeGraph.value=JSON.stringify(graph)};render();inspect()})();
  </script>
<?php elseif($view === 'logs'): ?>
  <section class="af-card">
    <form method="get" class="af-filters">
      <input type="hidden" name="view" value="logs">
      <div class="field wide">
        <label>Fluxo</label>
        <select name="log_flow">
          <option value="0">Todos os fluxos</option>
          <?php foreach($flows as $f): ?>
            <option value="<?=(int)$f['id']?>" <?=$logFlowId===(int)$f['id']?'selected':''?>><?=af_h($f['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field wide">
        <label>Aluno</label>
        <input type="text" name="log_aluno" value="<?=af_h($logAluno)?>" placeholder="Nome ou e-mail">
      </div>
      <div class="field">
        <label>Bloco</label>
        <select name="log_bloco">
          <option value="">Todos</option>
          <?php foreach($logBlocoOptions as $opt): ?>
            <option value="<?=af_h($opt)?>" <?=$logBloco===$opt?'selected':''?>><?=af_h($opt)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Status</label>
        <select name="log_status">
          <option value="">Todos</option>
          <?php foreach($logStatusOptions as $opt): ?>
            <option value="<?=af_h($opt)?>" <?=$logStatus===$opt?'selected':''?>><?=af_h($opt)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>De</label>
        <input type="date" name="log_de" value="<?=af_h($logDe)?>">
      </div>
      <div class="field">
        <label>Até</label>
        <input type="date" name="log_ate" value="<?=af_h($logAte)?>">
      </div>
      <div class="field">
        <label>Qtd.</label>
        <select name="log_limit">
          <?php foreach([100,300,500,1000,2000] as $n): ?>
            <option value="<?=$n?>" <?=$logLimit===$n?'selected':''?>><?=$n?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
      <a class="reset-link" href="automacoes.php?view=logs">Limpar</a>
    </form>
    <div class="af-table"><table><thead><tr><th>Data</th><th>Fluxo</th><th>Aluno</th><th>Bloco</th><th>Status</th><th>Erro/Saída</th></tr></thead><tbody><?php foreach($logs as $l): ?><tr><td><?=af_h(date('d/m/Y H:i', strtotime((string)$l['started_at'])))?></td><td><a href="automacoes.php?id=<?=(int)$l['flow_id']?>"><?=af_h($l['flow_name'])?></a></td><td><?=af_h(($l['nome'] ?: '-') . ' ' . ($l['email'] ?: ''))?></td><td><?=af_h($l['node_type'])?><div class="text-muted"><?=af_h($l['node_id'])?></div></td><td><span class="af-pill"><?=af_h($l['status'])?></span></td><td class="text-muted"><?=af_h($l['error_message'] ?: mb_substr((string)$l['output_json'],0,220))?></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="6">Nenhum log encontrado para os filtros selecionados.</td></tr><?php endif; ?></tbody></table></div>
  </section>
<?php elseif($view === 'flows'): ?>
  <section class="af-card">
    <div class="af-flow-head">
      <div><div class="card-header-title">Fluxos de automação</div><p class="text-muted text-xs">Crie, edite, clone, pause ou exclua fluxos centrais. Fluxos publicados recebem novos eventos pelo cron.</p></div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="process_now"><button class="btn btn-ghost btn-sm" <?=$canWrite?'':'disabled'?> title="Executa a fila de pendências imediatamente">⚡ Processar fila agora</button></form>
        <form method="post" class="af-form af-flow-create" style="margin:0;"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="create"><input name="name" placeholder="Nome do novo fluxo" required <?=$canWrite?'':'disabled'?>><button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>+ Criar novo fluxo</button></form>
      </div>
    </div>
    <div class="af-flow-list">
      <?php foreach($flows as $f): 
        $fid = (int)$f['id'];
        $diag = $flowDiagMap[$fid] ?? null;
        $hasCritical = $diag && (($diag['status'] ?? '') === 'critical');
        $hasWarning = $diag && (($diag['status'] ?? '') === 'warning');
        $rowClass = $hasCritical ? 'has-diag-critical' : ($hasWarning ? 'has-diag-warning' : '');
      ?>
        <div class="af-flow-row <?=$rowClass?>">
          <div class="af-flow-name">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <strong><?=af_h($f['name'])?></strong>
              <span class="af-pill" style="font-weight:700;"><?=af_h($f['status'])?></span>
              <small style="color:var(--muted);font-size:10px;"><?=$f['version_number']?'v'.(int)$f['version_number']:''?></small>
            </div>
            <small><?=af_h($f['description'] ?: 'Sem descrição')?></small>
            <?php if($hasCritical || $hasWarning): ?>
              <div style="margin-top:4px;">
                <span class="af-pill" style="background:<?=$hasCritical?'#ef4444':'#f59e0b'?>;color:#fff;cursor:pointer;font-weight:700;font-size:9px;" onclick="openDiagModal(<?=$fid?>)" title="Clique para ver o raio-x do diagnóstico">
                  ⚠️ <?=af_h($diag['issue_title'] ?? 'Inconsistência Detectada')?>
                </span>
              </div>
            <?php endif; ?>
          </div>
          <div class="af-flow-stat"><strong><?=(int)$f['runs']?></strong><small>Inícios</small></div>
          <div class="af-flow-stat"><strong><?=(int)$f['completed']?></strong><small>Finalizações</small></div>
          <div class="af-flow-stat"><strong><?=(int)$f['failed']?></strong><small>Erros</small></div>
          <div class="af-flow-stat"><strong><?=(int)$f['pending']?></strong><small>Pendentes</small></div>
          <div class="af-actions">
            <?php if($diag): ?>
              <button type="button" class="btn btn-ghost btn-xs" style="color:<?=$hasCritical?'#f87171':'#fbbf24'?>;border-color:<?=$hasCritical?'#ef4444':'#f59e0b'?>;" onclick="openDiagModal(<?=$fid?>)" title="Ver raio-x completo do diagnóstico deste fluxo">🔍 Raio-X</button>
            <?php endif; ?>
            <a class="btn btn-ghost btn-xs" href="automacoes.php?id=<?=(int)$f['id']?>">Editar</a>
            <?php if($f['current_version_id']): ?>
              <form method="post" style="display:inline-block;margin:0;">
                <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?=(int)$f['id']?>">
                <button class="btn btn-ghost btn-xs"><?=$f['status']==='active'?'Pausar':'Ativar'?></button>
              </form>
            <?php endif; ?>
            <details class="af-menu-dropdown">
              <summary class="btn btn-ghost btn-xs">••• Mais</summary>
              <div class="af-menu-content">
                <form method="post"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="test_flow"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="af-menu-item" <?=$canWrite?'':'disabled'?>>🧪 Testar fluxo</button></form>
                <form method="post" onsubmit="return confirm('Deseja re-enfileirar e disparar as execuções pendentes/com falha deste fluxo?')"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="reprocess_flow"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="af-menu-item" <?=$canWrite?'':'disabled'?>>🔄 Reprocessar Pendentes</button></form>
                <form method="post" onsubmit="return confirm('Deseja CANCELAR e LIMPAR todas as etapas pendentes deste fluxo SEM disparar nada para os alunos?')"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="clear_flow_queue"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="af-menu-item text-danger" <?=$canWrite?'':'disabled'?>>🧹 Limpar Fila (Cancelar)</button></form>
                <div class="af-menu-divider"></div>
                <form method="post"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="clone"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="af-menu-item" <?=$canWrite?'':'disabled'?>>📋 Clonar Fluxo</button></form>
                <form method="post" onsubmit="return confirm('Excluir este fluxo central? O histórico permanece nos logs, mas ele sai da gestão.')"><input type="hidden" name="csrf" value="<?=af_h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="af-menu-item text-danger" <?=$canWrite?'':'disabled'?>>🗑️ Excluir Fluxo</button></form>
              </div>
            </details>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if(!$flows): ?><div class="af-flow-empty">Nenhum fluxo central criado.</div><?php endif; ?>
    </div>
  </section>
<?php elseif($view === 'diagnostics'): ?>
  <section class="af-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div>
        <h2 style="font-size:18px;margin:0 0 4px 0;color:#fff;">🔬 Central de Raio-X & Diagnóstico de Fluxos</h2>
        <p class="text-muted text-xs" style="margin:0;">
          Auditoria contínua automática (07:00, 15:00 e 20:00) avaliando dupla amostragem temporal, desvio de SLAs, torpedos de voz e saúde de infraestrutura.
        </p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
          <input type="hidden" name="action" value="run_diagnostics">
          <button class="btn btn-primary btn-sm" <?=$canWrite?'':'disabled'?> title="Executa a auditoria completa de todos os fluxos e crons agora">🔍 Executar Varredura Agora</button>
        </form>
        <?php if($latestDiag && empty($latestDiag['acknowledged']) && ($latestDiag['status'] ?? 'healthy') !== 'healthy'): ?>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
            <input type="hidden" name="action" value="acknowledge_diagnostics">
            <button class="btn btn-sm" style="background:#ef4444;color:#fff;font-weight:700;border:0;padding:8px 14px;border-radius:8px;cursor:pointer;">✓ Dar Ciência nos Alertas</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- KPIs do Diagnóstico -->
    <div class="af-grid" style="margin-bottom:18px;">
      <div class="af-card af-kpi" style="background:#081020;">
        <small>Status Geral</small>
        <strong style="color:<?=($latestDiag['status'] ?? 'healthy') === 'healthy' ? '#86efac' : '#ef4444'?>;">
          <?=($latestDiag['status'] ?? 'healthy') === 'healthy' ? '🟢 100% Saudável' : '🚨 ' . (int)($latestDiag['issues_count'] ?? 0) . ' Inconsistência(s)'?>
        </strong>
        <span class="text-muted text-xs">integridade geral</span>
      </div>
      <div class="af-card af-kpi" style="background:#081020;">
        <small>Última Varredura</small>
        <strong style="font-size:18px;color:#fff;">
          <?=$latestDiag ? af_h(date('H:i - d/m', strtotime((string)$latestDiag['check_time']))) : '-'?>
        </strong>
        <span class="text-muted text-xs"><?=$latestDiag['triggered_by'] ?? 'cron'?></span>
      </div>
      <div class="af-card af-kpi" style="background:#081020;">
        <small>Fluxos Monitorados</small>
        <strong style="color:#38bdf8;"><?=count($flows)?></strong>
        <span class="text-muted text-xs">ativos no sistema</span>
      </div>
      <div class="af-card af-kpi" style="background:#081020;">
        <small>Ciência do Alerta</small>
        <strong style="font-size:16px;color:<?=$latestDiag && !empty($latestDiag['acknowledged']) ? '#86efac' : '#f59e0b'?>;">
          <?=$latestDiag && !empty($latestDiag['acknowledged']) ? '✓ Reconhecido' : '⚠️ Pendente'?>
        </strong>
        <span class="text-muted text-xs"><?=$latestDiag['acknowledged_by'] ?? '-'?></span>
      </div>
    </div>

    <?php if (!empty($latestDiag['infra_issues'])): ?>
      <div style="margin-bottom:18px;padding:12px 14px;border-radius:10px;background:rgba(239,68,68,0.12);border:1px solid #ef4444;color:#fecaca;">
        <strong style="color:#fff;font-size:13px;display:block;margin-bottom:6px;">⚙️ Alertas de Infraestrutura & Crons:</strong>
        <?php foreach ($latestDiag['infra_issues'] as $iss): ?>
          <div style="font-size:11px;margin-top:3px;">• <b>[<?=af_h($iss['type'])?>]</b> <?=af_h($iss['message'])?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- LISTA DE TODOS OS FLUXOS COM RAIO-X COMPLETO -->
  <div style="display:grid;gap:14px;margin-top:14px;">
    <?php foreach ($flows as $f): 
      $fid = (int)$f['id'];
      $diag = $flowDiagMap[$fid] ?? null;
      $hasCrit = $diag && (($diag['status'] ?? '') === 'critical');
      $hasWarn = $diag && (($diag['status'] ?? '') === 'warning');
      $diagIssues = $diag['issues'] ?? [];
      $sampleA = $diag['sample_early'] ?? null;
      $sampleB = $diag['sample_late'] ?? null;
      $benchmark = $diag['benchmark'] ?? null;
    ?>
      <section class="af-card <?=$hasCrit ? 'has-diag-critical' : ($hasWarn ? 'has-diag-warning' : '')?>" style="border:1px solid <?=$hasCrit?'#ef4444':($hasWarn?'#f59e0b':'var(--border)')?>;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:12px;flex-wrap:wrap;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;">
              <strong style="font-size:16px;color:#fff;"><?=af_h($f['name'])?></strong>
              <span class="af-pill" style="background:<?=$hasCrit?'#ef4444':($hasWarn?'#f59e0b':'#15803d')?>;color:#fff;font-weight:700;">
                <?=$hasCrit?'🚨 Inconsistência Detectada':($hasWarn?'⚠️ Atenção':'🟢 Saudável')?>
              </span>
            </div>
            <small class="text-muted" style="display:block;margin-top:2px;">
              <?=af_h($f['description'] ?: 'Sem descrição')?> · Versão: v<?=(int)$f['version_number']?> · Duração teórica projetada: ~<?=(int)($diag['theoretical_duration_minutes'] ?? 0)?> min
            </small>
          </div>
          <div style="display:flex;gap:6px;align-items:center;">
            <a class="btn btn-ghost btn-xs" href="automacoes.php?id=<?=$fid?>">Editar Fluxo</a>
            <button type="button" class="btn btn-ghost btn-xs" onclick="openDiagModal(<?=$fid?>)">🔍 Abrir em Modal</button>
          </div>
        </div>

        <?php if($diagIssues): ?>
          <div style="margin-bottom:14px;padding:10px 12px;border-radius:8px;background:rgba(239,68,68,0.12);border:1px solid #ef4444;color:#fecaca;font-size:11px;">
            <strong style="color:#fff;display:block;margin-bottom:4px;">Inconsistências Identificadas neste Fluxo:</strong>
            <?php foreach($diagIssues as $iss): ?>
              <div>• <?=af_h($iss['message'] ?? $iss['type'] ?? '')?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- TESTE 1: DUPLA AMOSTRAGEM -->
        <div style="margin-bottom:14px;">
          <strong style="font-size:12px;color:#cbd5e1;text-transform:uppercase;display:block;margin-bottom:8px;">
            🧪 Teste 1: Dupla Amostragem Temporal (Leads Reais)
          </strong>
          <div class="af-diag-grid">
            <!-- AMOSTRA A -->
            <div class="af-diag-card">
              <strong style="display:flex;justify-content:space-between;align-items:center;">
                Amostra A (Entrou Antes / Maduro)
              </strong>
              <?php if($sampleA): ?>
                <div style="color:var(--muted);font-size:11px;margin:8px 0;padding:8px 10px;background:rgba(0,0,0,0.3);border-radius:8px;">
                  <div style="display:flex;justify-content:space-between;">
                    <span>Aluno: <b style="color:#fff;"><?=af_h($sampleA['user'] ?? 'ID #'.$sampleA['run_id'])?></b></span>
                    <span class="af-pill"><?=af_h($sampleA['status'])?></span>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:10px;margin-top:4px;">
                    <div>Início: <b style="color:#cbd5e1;"><?=af_h(!empty($sampleA['started_at']) ? substr((string)$sampleA['started_at'], 5, 11) : '-')?></b></div>
                    <div>Término: <b style="color:#fff;"><?=af_h(!empty($sampleA['finished_at']) ? substr((string)$sampleA['finished_at'], 5, 11) : 'Em andamento')?></b></div>
                  </div>
                </div>
                <?php if(!empty($sampleA['analysis']['steps'])): ?>
                  <div class="af-diag-timeline">
                    <?php foreach($sampleA['analysis']['steps'] as $st): 
                      $diffCol = ($st['delay_severity'] ?? '') === 'critical' ? '#f87171' : (($st['delay_severity'] ?? '') === 'warning' ? '#facc15' : '#86efac');
                      $stCol = $st['status'] === 'completed' ? '#86efac' : ($st['status'] === 'failed' ? '#f87171' : '#facc15');
                    ?>
                      <div style="padding:7px 9px;border-radius:7px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);margin-bottom:5px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                          <span style="font-weight:700;color:#e2e8f0;font-size:11px;">[<?=af_h($st['node_type'])?>] <?=af_h($st['node_id'])?></span>
                          <span class="af-pill" style="font-size:9px;color:<?=$stCol?>;"><?=af_h($st['status'])?></span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;font-size:10px;color:var(--muted);">
                          <div>Planejado: <b style="color:#cbd5e1;"><?=af_h(!empty($st['planned_at']) ? substr((string)$st['planned_at'], 11, 5) : '-')?></b></div>
                          <div>Real: <b style="color:#fff;"><?=af_h(!empty($st['finished_at']) ? substr((string)$st['finished_at'], 11, 5) : (!empty($st['started_at']) ? substr((string)$st['started_at'], 11, 5) : '-'))?></b></div>
                          <div>Diferença: <b style="color:<?=$diffCol?>;"><?=af_h($st['delay_formatted'] ?? '-')?></b></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?><div class="text-muted text-xs">Sem etapas executadas.</div><?php endif; ?>
              <?php else: ?><div class="text-muted text-xs">Nenhum aluno encontrado nesta janela.</div><?php endif; ?>
            </div>

            <!-- AMOSTRA B -->
            <div class="af-diag-card">
              <strong style="display:flex;justify-content:space-between;align-items:center;">
                Amostra B (Entrou Depois / Recente)
              </strong>
              <?php if($sampleB): ?>
                <div style="color:var(--muted);font-size:11px;margin:8px 0;padding:8px 10px;background:rgba(0,0,0,0.3);border-radius:8px;">
                  <div style="display:flex;justify-content:space-between;">
                    <span>Aluno: <b style="color:#fff;"><?=af_h($sampleB['user'] ?? 'ID #'.$sampleB['run_id'])?></b></span>
                    <span class="af-pill"><?=af_h($sampleB['status'])?></span>
                  </div>
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:10px;margin-top:4px;">
                    <div>Início: <b style="color:#cbd5e1;"><?=af_h(!empty($sampleB['started_at']) ? substr((string)$sampleB['started_at'], 5, 11) : '-')?></b></div>
                    <div>Término: <b style="color:#fff;"><?=af_h(!empty($sampleB['finished_at']) ? substr((string)$sampleB['finished_at'], 5, 11) : 'Em andamento')?></b></div>
                  </div>
                </div>
                <?php if(!empty($sampleB['analysis']['steps'])): ?>
                  <div class="af-diag-timeline">
                    <?php foreach($sampleB['analysis']['steps'] as $st): 
                      $diffCol = ($st['delay_severity'] ?? '') === 'critical' ? '#f87171' : (($st['delay_severity'] ?? '') === 'warning' ? '#facc15' : '#86efac');
                      $stCol = $st['status'] === 'completed' ? '#86efac' : ($st['status'] === 'failed' ? '#f87171' : '#facc15');
                    ?>
                      <div style="padding:7px 9px;border-radius:7px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);margin-bottom:5px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                          <span style="font-weight:700;color:#e2e8f0;font-size:11px;">[<?=af_h($st['node_type'])?>] <?=af_h($st['node_id'])?></span>
                          <span class="af-pill" style="font-size:9px;color:<?=$stCol?>;"><?=af_h($st['status'])?></span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;font-size:10px;color:var(--muted);">
                          <div>Planejado: <b style="color:#cbd5e1;"><?=af_h(!empty($st['planned_at']) ? substr((string)$st['planned_at'], 11, 5) : '-')?></b></div>
                          <div>Real: <b style="color:#fff;"><?=af_h(!empty($st['finished_at']) ? substr((string)$st['finished_at'], 11, 5) : (!empty($st['started_at']) ? substr((string)$st['started_at'], 11, 5) : '-'))?></b></div>
                          <div>Diferença: <b style="color:<?=$diffCol?>;"><?=af_h($st['delay_formatted'] ?? '-')?></b></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?><div class="text-muted text-xs">Sem etapas executadas.</div><?php endif; ?>
              <?php else: ?><div class="text-muted text-xs">Nenhum aluno adicional encontrado.</div><?php endif; ?>
            </div>
          </div>
        </div>

        <!-- TESTE 2: BENCHMARK DE SLA -->
        <?php if(!empty($benchmark['samples'])): ?>
          <div style="padding:12px;border:1px solid var(--border);border-radius:10px;background:#081020;font-size:11px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
              <strong style="color:#cbd5e1;text-transform:uppercase;font-size:11px;">📊 Teste 2: Benchmark de SLA (Últimos <?=count($benchmark['samples'])?> Concluídos)</strong>
              <div>
                <span>Duração Teórica: <b style="color:#38bdf8;">~<?=(int)$benchmark['theoretical_minutes']?>m</b></span> · 
                <span>Média Real: <b style="color:<?=((float)$benchmark['avg_duration_minutes'] > (int)$benchmark['theoretical_minutes'] * 2 ? '#f87171' : '#86efac')?>;">~<?=(float)$benchmark['avg_duration_minutes']?>m</b></span>
              </div>
            </div>
            <div class="af-table">
              <table>
                <thead><tr><th>Aluno</th><th>Início</th><th>Término Real</th><th>Duração Gasta</th><th>Status do SLA</th></tr></thead>
                <tbody>
                  <?php foreach($benchmark['samples'] as $s): 
                    $dur = (int)($s['duration_minutes'] ?? 0);
                    $theo = (int)($benchmark['theoretical_minutes'] ?? 0);
                    $diffM = $theo > 0 ? ($dur - $theo) : 0;
                    $slaBadge = $diffM > 60 ? '<span class="af-pill" style="background:#ef4444;color:#fff;font-weight:700;">+'.$diffM.'m atraso</span>' : ($diffM > 10 ? '<span class="af-pill" style="background:#f59e0b;color:#fff;">+'.$diffM.'m</span>' : '<span class="af-pill" style="background:#15803d;color:#fff;">No prazo</span>');
                  ?>
                    <tr>
                      <td><strong><?=af_h($s['nome'] ?? 'Lead #'.$s['run_id'])?></strong></td>
                      <td><?=af_h(!empty($s['started_at']) ? substr((string)$s['started_at'], 5, 11) : '-')?></td>
                      <td><?=af_h(!empty($s['finished_at']) ? substr((string)$s['finished_at'], 5, 11) : '-')?></td>
                      <td><strong><?=$dur?> min</strong></td>
                      <td><?=$slaBadge?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
<?php elseif($view === 'canais'): ?>
  <section class="af-card">
    <div class="card-header-title">Diagnóstico da fila, por canal</div>
    <p class="text-muted text-xs">Fotografia calculada agora mesmo. Recarregue a página para atualizar os números.</p>
    <div class="af-table" style="margin-top:14px;">
      <table>
        <thead><tr><th>Canal</th><th>Status</th><th>Pendentes</th><th>Na fila</th><th>Reagendadas (retry)</th><th>Processando</th><th>Mais antiga espera</th><th>Concluídas (1h)</th><th>Falhas (1h)</th><th>Tempo estimado p/ zerar</th></tr></thead>
        <tbody>
          <?php foreach ($channelAllLabels as $channelKey => $channelLabel): $q = $queueOverview[$channelKey] ?? null; if (!$q) continue; ?>
            <tr>
              <td><strong><?=af_h($channelLabel)?></strong></td>
              <td><span class="af-pill" style="<?=$q['enabled']?'':'background:#7c2d12;color:#fed7aa;'?>"><?=$q['enabled']?'ativo':'desativado'?></span></td>
              <td><strong><?=(int)$q['pending']?></strong></td>
              <td><?=(int)$q['queued']?></td>
              <td><?=(int)$q['retry']?></td>
              <td><?=(int)$q['processing']?></td>
              <td><?=$q['oldest_pending_minutes'] === null ? '-' : (int)$q['oldest_pending_minutes'] . ' min'?></td>
              <td style="color:#86efac"><?=(int)$q['completed_last_hour']?></td>
              <td style="<?=$q['failed_last_hour']>0?'color:#fca5a5;font-weight:700;':''?>"><?=(int)$q['failed_last_hour']?></td>
              <td><?php if ($q['pending'] === 0): ?>—<?php elseif ($q['eta_minutes'] === null): ?><span style="color:#fca5a5;">sem processamento na última hora</span><?php else: ?><?=(int)$q['eta_minutes']?> min<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted text-xs" style="margin-top:10px;">"Tempo estimado p/ zerar" é calculado dividindo os pendentes pela taxa de conclusão da última hora — é uma projeção, não uma garantia (a taxa pode mudar se novos disparos entrarem na fila).</p>
  </section>

  <section class="af-card" style="margin-top:14px;">
    <div class="card-header-title">Configuração dos canais</div>
    <p class="text-muted text-xs">Cada canal tem sua própria fila e roda em paralelo com os outros pelo cron (a cada 1 minuto). O intervalo mínimo evita sobrecarregar a API receptora; falhas tentam de novo com espera crescente até o número máximo de tentativas.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=af_h($csrf)?>">
      <input type="hidden" name="action" value="save_channel_settings">
      <div class="af-table" style="margin-top:14px;">
        <table>
          <thead><tr><th>Canal</th><th>Ativo</th><th>Intervalo mín. (ms)</th><th>Lote/execução</th><th>Máx. tentativas</th><th>Backoff inicial (s)</th><th>Backoff máximo (s)</th><th>Fila atual</th></tr></thead>
          <tbody>
            <?php foreach ($channelLabels as $channelKey => $channelLabel): $row = $channelRows[$channelKey] ?? ['enabled'=>1,'min_interval_ms'=>300,'batch_size'=>30,'max_attempts'=>5,'backoff_step_seconds'=>30,'backoff_max_seconds'=>1800]; $prefix = 'ch_' . $channelKey . '_'; ?>
              <tr>
                <td><strong><?=af_h($channelLabel)?></strong></td>
                <td><input type="checkbox" name="<?=$prefix?>enabled" <?=((int)$row['enabled']===1)?'checked':''?> <?=$canWrite?'':'disabled'?>></td>
                <td><input type="number" min="0" max="60000" step="50" name="<?=$prefix?>min_interval_ms" value="<?=(int)$row['min_interval_ms']?>" style="width:90px" <?=$canWrite?'':'disabled'?>></td>
                <td><input type="number" min="1" max="200" name="<?=$prefix?>batch_size" value="<?=(int)$row['batch_size']?>" style="width:70px" <?=$canWrite?'':'disabled'?>></td>
                <td><input type="number" min="1" max="20" name="<?=$prefix?>max_attempts" value="<?=(int)$row['max_attempts']?>" style="width:70px" <?=$canWrite?'':'disabled'?>></td>
                <td><input type="number" min="1" max="3600" name="<?=$prefix?>backoff_step_seconds" value="<?=(int)$row['backoff_step_seconds']?>" style="width:80px" <?=$canWrite?'':'disabled'?>></td>
                <td><input type="number" min="1" max="86400" name="<?=$prefix?>backoff_max_seconds" value="<?=(int)$row['backoff_max_seconds']?>" style="width:90px" <?=$canWrite?'':'disabled'?>></td>
                <td><span class="af-pill"><?=(int)($channelPending[$channelKey] ?? 0)?> pendente(s)</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:14px;"><button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>Salvar configuração dos canais</button></div>
    </form>
    <p class="text-muted text-xs" style="margin-top:14px;">O canal de voz usa a configuração própria em Torpedo de Voz. O canal "Automações - etapas locais" (gatilho/condição/espera/ação/fim) não chama API externa e não precisa de espaçamento.</p>
  </section>
<?php else: ?>
  <section class="af-grid">
    <div class="af-card af-kpi"><small>Fluxos</small><strong><?=$kpis['flows']?></strong><span class="text-muted text-xs">total criado</span></div>
    <div class="af-card af-kpi"><small>Ativos</small><strong><?=$kpis['active']?></strong><span class="text-muted text-xs">recebendo eventos</span></div>
    <div class="af-card af-kpi"><small>Execuções</small><strong><?=$kpis['runs']?></strong><span class="text-muted text-xs">inícios</span></div>
    <div class="af-card af-kpi"><small>Finalizadas</small><strong><?=$kpis['completed']?></strong><span class="text-muted text-xs">com sucesso</span></div>
    <div class="af-card af-kpi"><small>Erros</small><strong><?=$kpis['failed']?></strong><span class="text-muted text-xs">execuções falhas</span></div>
    <div class="af-card af-kpi"><small>Pendentes</small><strong><?=$kpis['queued']?></strong><span class="text-muted text-xs">fila/temporizador</span></div>
  </section>
  <section class="grid-2">
    <div class="af-card"><div class="panel-title">Eventos capturados</div><canvas id="afEventsChart"></canvas></div>
    <div class="af-card"><div class="panel-title">Status das execuções</div><canvas id="afStatusChart"></canvas></div>
  </section>
  <script>
  const evLabels=<?=json_encode(array_column($eventsByDay,'d'),JSON_UNESCAPED_UNICODE)?>,evData=<?=json_encode(array_map('intval',array_column($eventsByDay,'c')))?>,stRows=<?=json_encode($statusRows,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;
  if(window.Chart){new Chart(afEventsChart,{type:'line',data:{labels:evLabels,datasets:[{label:'Eventos',data:evData,borderColor:'#38bdf8',backgroundColor:'rgba(56,189,248,.15)',fill:true,tension:.35}]},options:{plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}},y:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}}}}});new Chart(afStatusChart,{type:'doughnut',data:{labels:stRows.map(x=>x.status),datasets:[{data:stRows.map(x=>+x.c),backgroundColor:['#22c55e','#ef4444','#facc15','#38bdf8','#a78bfa']}]},options:{plugins:{legend:{labels:{color:'#cbd5e1'}}}}})}
  </script>
<?php endif; ?>
</div>

<div class="af-diag-modal" id="diagModal">
  <div class="af-diag-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:14px;">
      <div>
        <strong id="diagModalTitle" style="font-size:16px;color:#fff;display:block;">Raio-X do Diagnóstico</strong>
        <small id="diagModalSubtitle" style="color:var(--muted);font-size:11px;"></small>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeDiagModal()">✕ Fechar</button>
    </div>
    <div id="diagModalContent"></div>
  </div>
</div>

<script>
const diagMap = <?=json_encode($flowDiagMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
function openDiagModal(fid) {
  const d = diagMap[fid];
  if (!d) return alert('Nenhum diagnóstico registrado para este fluxo ainda.');
  document.getElementById('diagModalTitle').textContent = 'Raio-X: ' + (d.flow_name || 'Fluxo #' + fid);
  document.getElementById('diagModalSubtitle').textContent = 'Última varredura: ' + (d.checked_at || '-') + ' · Duração teórica projetada: ~' + (d.theoretical_duration_minutes || 0) + ' min';
  
  let html = '';
  
  if (d.issues && d.issues.length) {
    html += '<div style="margin-bottom:14px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#fecaca;">';
    html += '<strong style="color:#fff;display:block;margin-bottom:4px;">🚨 Inconsistências Identificadas:</strong>';
    d.issues.forEach(iss => {
      html += '<div style="font-size:11px;margin-top:3px;">• ' + (iss.message || iss.type) + '</div>';
    });
    html += '</div>';
  } else {
    html += '<div style="margin-bottom:14px;padding:10px 14px;border-radius:10px;background:rgba(34,197,94,0.15);border:1px solid #22c55e;color:#86efac;">';
    html += '<strong>✅ Fluxo 100% Saudável:</strong> Todos os testes temporais e integrações responderam dentro dos padrões esperados.';
    html += '</div>';
  }

  html += '<div style="margin-top:14px;"><strong style="font-size:13px;color:#fff;">🧪 Teste 1: Dupla Amostragem Temporal (Leads Reais no Fluxo)</strong></div>';
  html += '<div class="af-diag-grid">';
  
  function formatDelayJs(diffSecs) {
    if (diffSecs === null || diffSecs === undefined || isNaN(diffSecs)) return '-';
    if (Math.abs(diffSecs) < 60) return 'No horário (0m)';
    let sign = diffSecs > 0 ? '+' : '-';
    let mins = Math.abs(Math.round(diffSecs / 60));
    let hours = Math.floor(mins / 60);
    let remMins = mins % 60;
    if (hours > 0) return sign + hours + 'h ' + remMins + 'm';
    return sign + remMins + 'm';
  }

  function renderSampleBox(title, sample) {
    let sHtml = '<div class="af-diag-card">';
    sHtml += '<strong style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' + title + '</strong>';
    if (sample) {
      sHtml += '<div style="color:var(--muted);font-size:11px;margin-bottom:10px;padding:8px 10px;background:rgba(0,0,0,0.3);border-radius:8px;border:1px solid rgba(255,255,255,0.05);">';
      sHtml += '<div style="display:flex;justify-content:space-between;margin-bottom:4px;">';
      sHtml += '<span>Aluno: <b style="color:#fff;">' + (sample.user || 'ID #' + sample.run_id) + '</b></span>';
      sHtml += '<span><span class="af-pill">' + sample.status + '</span></span>';
      sHtml += '</div>';
      sHtml += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:10px;margin-top:4px;">';
      sHtml += '<div>Início: <b style="color:#cbd5e1;">' + (sample.started_at ? sample.started_at.slice(5,16) : '-') + '</b></div>';
      sHtml += '<div>Término: <b style="color:#fff;">' + (sample.finished_at ? sample.finished_at.slice(5,16) : 'Em andamento') + '</b></div>';
      sHtml += '</div>';
      sHtml += '</div>';

      if (sample.analysis && sample.analysis.steps && sample.analysis.steps.length) {
        sHtml += '<div class="af-diag-timeline">';
        let currentPlanDate = sample.started_at ? new Date(sample.started_at.replace(/-/g, '/')) : null;

        sample.analysis.steps.forEach(st => {
          let plannedStr = '';
          let delayTxt = st.delay_formatted || '';
          let severity = st.delay_severity || 'ok';

          if (st.planned_at && st.planned_at.length >= 16) {
            plannedStr = st.planned_at.slice(11, 16);
            currentPlanDate = new Date(st.planned_at.replace(/-/g, '/'));
          } else if (currentPlanDate) {
            let hh = String(currentPlanDate.getHours()).padStart(2, '0');
            let mm = String(currentPlanDate.getMinutes()).padStart(2, '0');
            plannedStr = hh + ':' + mm;
          }

          let actualStr = (st.finished_at && st.finished_at.length >= 16 ? st.finished_at.slice(11, 16) : (st.started_at && st.started_at.length >= 16 ? st.started_at.slice(11, 16) : '-'));

          if (!delayTxt || delayTxt === '-') {
            let actDateStr = st.finished_at || st.started_at;
            if (currentPlanDate && actDateStr && actDateStr.length >= 16) {
              let actDate = new Date(actDateStr.replace(/-/g, '/'));
              let diffSecs = (actDate.getTime() - currentPlanDate.getTime()) / 1000;
              delayTxt = formatDelayJs(diffSecs);
              severity = Math.abs(diffSecs) > 1800 ? 'critical' : (Math.abs(diffSecs) > 300 ? 'warning' : 'ok');
            }
          }

          let diffColor = '#86efac';
          if (severity === 'critical') diffColor = '#f87171';
          else if (severity === 'warning') diffColor = '#facc15';

          let statusColor = st.status === 'completed' ? '#86efac' : (st.status === 'failed' ? '#f87171' : '#facc15');

          sHtml += '<div style="padding:8px 10px;border-radius:8px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);margin-bottom:6px;">';
          sHtml += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
          sHtml += '<span style="font-weight:700;color:#e2e8f0;font-size:11px;">[' + st.node_type + '] ' + st.node_id + '</span>';
          sHtml += '<span class="af-pill" style="font-size:9px;color:' + statusColor + ';">' + st.status + '</span>';
          sHtml += '</div>';
          sHtml += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;font-size:10px;color:var(--muted);">';
          sHtml += '<div>Planejado: <b style="color:#cbd5e1;">' + (plannedStr || '-') + '</b></div>';
          sHtml += '<div>Real: <b style="color:#fff;">' + actualStr + '</b></div>';
          sHtml += '<div>Diferença: <b style="color:' + diffColor + ';">' + (delayTxt || '-') + '</b></div>';
          sHtml += '</div>';
          if (st.error) {
            sHtml += '<div style="color:#f87171;font-size:10px;margin-top:4px;">⚠️ ' + st.error + '</div>';
          }
          sHtml += '</div>';
        });
        sHtml += '</div>';
      } else {
        sHtml += '<div style="color:var(--muted);font-size:10px;padding:8px;">Sem etapas executadas ainda.</div>';
      }
    } else {
      sHtml += '<div style="color:var(--muted);font-size:10px;padding:8px;">Nenhuma execução encontrada nesta janela.</div>';
    }
    sHtml += '</div>';
    return sHtml;
  }

  html += renderSampleBox('Amostra A (Entrou Antes / Maduro)', d.sample_early);
  html += renderSampleBox('Amostra B (Entrou Depois / Recente)', d.sample_late);
  html += '</div>';

  // Benchmark de SLA
  if (d.benchmark && d.benchmark.samples && d.benchmark.samples.length) {
    html += '<div style="margin-top:14px;"><strong style="font-size:13px;color:#fff;">📊 Teste 2: Benchmark de SLA (Últimos ' + d.benchmark.samples.length + ' Concluídos)</strong></div>';
    html += '<div style="padding:12px;border:1px solid var(--border);border-radius:10px;background:#081020;margin-top:6px;font-size:11px;">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;">';
    html += '<span>Duração Teórica Projetada: <strong style="color:#38bdf8;">~' + d.benchmark.theoretical_minutes + ' min</strong></span>';
    html += '<span>Média Real dos Concluídos: <strong style="color:' + (d.benchmark.avg_duration_minutes > d.benchmark.theoretical_minutes * 2 ? '#f87171' : '#86efac') + ';">~' + d.benchmark.avg_duration_minutes + ' min</strong></span>';
    html += '</div>';
    html += '<div class="af-table"><table><thead><tr><th>Aluno</th><th>Início</th><th>Término Real</th><th>Duração Gasta</th><th>Status do SLA</th></tr></thead><tbody>';
    d.benchmark.samples.forEach(s => {
      let dur = +s.duration_minutes || 0;
      let theo = +d.benchmark.theoretical_minutes || 0;
      let diffMins = theo > 0 ? (dur - theo) : 0;
      let slaTag = diffMins > 60 ? '<span class="af-pill" style="background:#ef4444;color:#fff;font-weight:700;">+' + diffMins + 'm atraso</span>' : (diffMins > 10 ? '<span class="af-pill" style="background:#f59e0b;color:#fff;">+' + diffMins + 'm</span>' : '<span class="af-pill" style="background:#15803d;color:#fff;">No prazo</span>');
      html += '<tr><td><strong>' + (s.nome || s.email || 'Lead #' + s.run_id) + '</strong></td><td>' + (s.started_at ? s.started_at.slice(5,16) : '-') + '</td><td>' + (s.finished_at ? s.finished_at.slice(5,16) : '-') + '</td><td><strong>' + dur + ' min</strong></td><td>' + slaTag + '</td></tr>';
    });
    html += '</tbody></table></div>';
    html += '</div>';
  }

  document.getElementById('diagModalContent').innerHTML = html;
  document.getElementById('diagModal').classList.add('open');
}

const globalDiag = <?=json_encode($latestDiag, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
function openGlobalDiagModal() {
  if (!globalDiag) return alert('Nenhum diagnóstico registrado ainda.');
  document.getElementById('diagModalTitle').textContent = 'Relatório Geral de Inconsistências';
  document.getElementById('diagModalSubtitle').textContent = 'Varredura de ' + (globalDiag.check_time || '-') + ' · Total de inconformidades: ' + (globalDiag.issues_count || 0);

  let html = '';

  if (globalDiag.infra_issues && globalDiag.infra_issues.length) {
    html += '<div style="margin-bottom:16px;">';
    html += '<strong style="color:#f87171;font-size:13px;display:block;margin-bottom:8px;">⚙️ Infraestrutura e Crons</strong>';
    globalDiag.infra_issues.forEach(iss => {
      html += '<div style="padding:10px 12px;border-radius:8px;background:rgba(239,68,68,0.12);border:1px solid #ef4444;color:#fecaca;margin-bottom:6px;font-size:11px;">';
      html += '<strong>[' + (iss.type || 'CRON') + ']</strong> ' + (iss.message || iss.label);
      html += '</div>';
    });
    html += '</div>';
  }

  html += '<div style="margin-bottom:16px;">';
  html += '<strong style="color:#fff;font-size:13px;display:block;margin-bottom:8px;">⚡ Inconsistências por Fluxo</strong>';
  
  let hasFlowIssues = false;
  if (globalDiag.flows && globalDiag.flows.length) {
    globalDiag.flows.forEach(fl => {
      if (fl.issues && fl.issues.length) {
        hasFlowIssues = true;
        html += '<div style="padding:12px;border-radius:10px;background:#081020;border:1px solid #ef4444;margin-bottom:10px;">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
        html += '<strong style="color:#fff;font-size:13px;">' + fl.flow_name + '</strong>';
        html += '<button type="button" class="btn btn-ghost btn-xs" style="color:#f87171;border-color:#ef4444;" onclick="openDiagModal(' + fl.flow_id + ')">🔍 Abrir Raio-X</button>';
        html += '</div>';
        
        fl.issues.forEach(iss => {
          html += '<div style="font-size:11px;color:#fca5a5;margin-top:4px;display:flex;gap:6px;">';
          html += '<span>•</span><span>' + (iss.message || iss.type) + '</span>';
          html += '</div>';
        });
        html += '</div>';
      }
    });
  }

  if (!hasFlowIssues && (!globalDiag.infra_issues || !globalDiag.infra_issues.length)) {
    html += '<div style="padding:14px;border-radius:10px;background:rgba(34,197,94,0.15);border:1px solid #22c55e;color:#86efac;font-size:12px;">';
    html += '✅ Nenhuma inconsistência encontrada nesta varredura. Todos os fluxos estão saudáveis.';
    html += '</div>';
  }

  html += '</div>';

  document.getElementById('diagModalContent').innerHTML = html;
  document.getElementById('diagModal').classList.add('open');
}

function closeDiagModal() {
  document.getElementById('diagModal').classList.remove('open');
}
document.getElementById('diagModal')?.addEventListener('click', function(e) {
  if (e.target.id === 'diagModal') closeDiagModal();
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
