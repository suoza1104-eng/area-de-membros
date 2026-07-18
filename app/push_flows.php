<?php
declare(strict_types=1);

require_once __DIR__ . '/push_notifications.php';
require_once __DIR__ . '/automation_catalog.php';

function push_flows_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        draft_graph_json LONGTEXT NOT NULL,
        lock_version INT UNSIGNED NOT NULL DEFAULT 1,
        current_version_id BIGINT UNSIGNED NULL,
        published_at DATETIME NULL,
        created_by VARCHAR(150) NULL,
        updated_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_push_flows_status (status),
        KEY idx_push_flows_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        graph_json LONGTEXT NOT NULL,
        name_snapshot VARCHAR(150) NOT NULL,
        description_snapshot VARCHAR(500) NULL,
        published_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_flow_version (flow_id, version_number),
        KEY idx_push_flow_versions_flow (flow_id),
        KEY idx_push_flow_versions_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function push_flow_blank_graph(): array
{
    return [
        'schemaVersion' => 1,
        'nodes' => [[
            'id' => 'trigger_' . bin2hex(random_bytes(4)),
            'type' => 'trigger',
            'x' => 120,
            'y' => 140,
            'config' => ['label' => 'Início do fluxo', 'event' => 'INSCRITO', 'filter'=>'', 'advanceDuration'=>1, 'advanceUnit'=>'hours'],
        ]],
        'edges' => [],
        'viewport' => ['x' => 80, 'y' => 60, 'zoom' => 1],
    ];
}

function push_flow_trigger_options(): array
{
    return automation_trigger_options();
}

function push_flow_decode_graph(string $json): array
{
    if (strlen($json) > 2 * 1024 * 1024) throw new InvalidArgumentException('O fluxo excede o limite de 2 MB.');
    $graph = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($graph)) throw new InvalidArgumentException('Estrutura do fluxo inválida.');
    return $graph;
}

function push_flow_validate_graph(array $graph, bool $forPublish = false): array
{
    $errors = [];
    $nodes = $graph['nodes'] ?? null;
    $edges = $graph['edges'] ?? null;
    if (!is_array($nodes) || !is_array($edges)) return ['O fluxo precisa conter listas de blocos e conexões.'];
    if (count($nodes) > 200) $errors[] = 'O fluxo pode ter no máximo 200 blocos.';
    if (count($edges) > 400) $errors[] = 'O fluxo pode ter no máximo 400 conexões.';

    $allowedTypes = ['trigger','condition','wait','action','integration','push'];
    $nodeMap = [];
    $triggerIds = [];
    foreach ($nodes as $i => $node) {
        if (!is_array($node)) { $errors[] = 'Bloco #' . ($i + 1) . ' inválido.'; continue; }
        $id = (string)($node['id'] ?? '');
        $type = (string)($node['type'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $id)) { $errors[] = 'Um bloco possui identificador inválido.'; continue; }
        if (isset($nodeMap[$id])) { $errors[] = 'Há blocos com identificadores duplicados.'; continue; }
        if (!in_array($type, $allowedTypes, true)) { $errors[] = "Tipo de bloco inválido: {$type}."; continue; }
        if (!is_numeric($node['x'] ?? null) || !is_numeric($node['y'] ?? null) || (float)$node['x'] < 0 || (float)$node['y'] < 0 || (float)$node['x'] > 100000 || (float)$node['y'] > 100000) {
            $errors[] = "O bloco {$id} possui posição inválida.";
        }
        $nodeMap[$id] = $node;
        if ($type === 'trigger') $triggerIds[] = $id;
        if (!isset($node['config']) || !is_array($node['config'])) $errors[] = "O bloco {$id} não possui configuração válida.";
    }

    $adj = array_fill_keys(array_keys($nodeMap), []);
    $incoming = array_fill_keys(array_keys($nodeMap), 0);
    $edgeIds = [];
    $edgeRoutes = [];
    $outgoing = [];
    foreach ($edges as $i => $edge) {
        if (!is_array($edge)) { $errors[] = 'Conexão #' . ($i + 1) . ' inválida.'; continue; }
        $id = (string)($edge['id'] ?? '');
        $source = (string)($edge['source'] ?? '');
        $target = (string)($edge['target'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{3,100}$/', $id) || isset($edgeIds[$id])) { $errors[] = 'Há uma conexão sem identificador válido ou duplicada.'; continue; }
        $edgeIds[$id] = true;
        if (!isset($nodeMap[$source]) || !isset($nodeMap[$target])) { $errors[] = 'Uma conexão aponta para um bloco inexistente.'; continue; }
        if ($source === $target) { $errors[] = 'Um bloco não pode conectar a si mesmo.'; continue; }
        $handle = (string)($edge['sourceHandle'] ?? 'default');
        $route = $source . '|' . $handle . '|' . $target;
        if (isset($edgeRoutes[$route])) { $errors[] = 'Há conexões duplicadas entre os mesmos blocos.'; continue; }
        $edgeRoutes[$route] = true;
        $adj[$source][] = $target;
        $incoming[$target]++;
        $outgoing[$source][$handle] = ($outgoing[$source][$handle] ?? 0) + 1;
    }

    if (!$forPublish) return array_values(array_unique($errors));
    if (count($nodes) === 0) $errors[] = 'Adicione pelo menos um bloco.';
    if (count($triggerIds) !== 1) $errors[] = 'O fluxo publicado deve possuir exatamente um gatilho inicial.';
    if (count($triggerIds) === 1 && ($incoming[$triggerIds[0]] ?? 0) > 0) $errors[] = 'O gatilho inicial não pode receber conexões.';

    foreach ($nodeMap as $id => $node) {
        $config = $node['config'];
        $type = $node['type'];
        if ($type === 'trigger' && trim((string)($config['event'] ?? '')) === '') $errors[] = 'Configure o evento do gatilho.';
        if ($type === 'trigger' && ($config['event'] ?? '') === 'LIVE_LEMBRETE_AGENDADO') {
            if (mb_strlen((string)($config['filter'] ?? '')) > 100) $errors[] = 'Selecione uma turma valida ou deixe em branco para todas as turmas.';
            if ((int)($config['advanceDuration'] ?? 0) < 1 || (int)($config['advanceDuration'] ?? 0) > 525600 || !in_array(($config['advanceUnit'] ?? ''), ['minutes','hours','days'], true)) $errors[] = 'Configure uma antecedência válida para o lembrete de live.';
        }
        if ($type === 'condition') {
            $conditionField=(string)($config['field']??'');
            if(!in_array($conditionField,['tag','turma','email','previous_push_clicked'],true))$errors[]='Selecione um campo válido em todas as condições.';
            elseif($conditionField!=='previous_push_clicked'&&(!in_array(($config['operator']??''),['has','not_has','equals','not_equals'],true)||trim((string)($config['value']??''))===''||mb_strlen((string)($config['value']??''))>255))$errors[]='Configure operador e valor válidos em todas as condições.';
        }
        if ($type === 'wait' && ((int)($config['duration'] ?? 0) < 1 || (int)($config['duration'] ?? 0) > 525600 || !in_array(($config['unit'] ?? ''), ['minutes','hours','days'], true))) $errors[] = 'Configure uma espera válida em minutos, horas ou dias.';
        if ($type === 'wait' && !empty($config['limitWindow'])) {
            $start = (string)($config['windowStart'] ?? ''); $end = (string)($config['windowEnd'] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) $errors[] = 'Configure uma faixa de horário válida no bloco de espera.';
        }
        if ($type === 'action' && (!in_array(($config['action'] ?? ''), ['add_tag','remove_tag'], true) || trim((string)($config['tag'] ?? '')) === '' || mb_strlen((string)($config['tag'] ?? '')) > 150)) $errors[] = 'Configure a ação e uma tag de até 150 caracteres.';
        if ($type === 'integration' && (!in_array(($config['provider'] ?? ''), ['webhook','superfuncionario','manychat'], true) || trim((string)($config['target'] ?? '')) === '' || mb_strlen((string)($config['target'] ?? '')) > 100 || mb_strlen((string)($config['payload'] ?? '')) > 20000)) $errors[] = 'Selecione a integração, informe um destino de até 100 caracteres e limite o payload a 20 mil caracteres.';
        if ($type === 'push' && (trim((string)($config['title'] ?? '')) === '' || mb_strlen((string)($config['title'] ?? '')) > 150 || trim((string)($config['body'] ?? '')) === '' || mb_strlen((string)($config['body'] ?? '')) > 500)) $errors[] = 'Informe título de até 150 caracteres e mensagem de até 500 caracteres em todos os blocos push.';
        if ($type === 'push') {
            $url = trim((string)($config['clickUrl'] ?? ''));
            try { push_normalize_click_url($url); }
            catch (Throwable $e) { $errors[] = $e->getMessage(); }
        }
        if ($type === 'condition') {
            if (($outgoing[$id]['yes'] ?? 0) > 1 || ($outgoing[$id]['no'] ?? 0) > 1) $errors[] = 'Cada saída SIM/NÃO de uma condição pode ter apenas uma conexão.';
        } elseif (array_sum($outgoing[$id] ?? []) > 1) {
            $errors[] = 'Cada bloco, exceto condição, pode ter apenas uma saída.';
        }
    }

    if (count($triggerIds) === 1) {
        $visited = [];
        $visiting = [];
        $hasCycle = false;
        $walk = function(string $id) use (&$walk, &$visited, &$visiting, &$hasCycle, $adj): void {
            if (isset($visiting[$id])) { $hasCycle = true; return; }
            if (isset($visited[$id])) return;
            $visiting[$id] = true;
            foreach ($adj[$id] ?? [] as $next) $walk($next);
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        $walk($triggerIds[0]);
        if ($hasCycle) $errors[] = 'Ciclos não são permitidos nesta versão do editor.';
        if (count($visited) !== count($nodeMap)) $errors[] = 'Todos os blocos precisam estar conectados ao gatilho inicial.';
    }
    return array_values(array_unique($errors));
}

function push_flow_find(PDO $pdo, int $flowId): ?array
{
    $st = $pdo->prepare("SELECT f.*,v.version_number current_version_number FROM push_flows f LEFT JOIN push_flow_versions v ON v.id=f.current_version_id WHERE f.id=:id AND f.status<>'deleted' LIMIT 1");
    $st->execute(['id'=>$flowId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function push_flow_create(PDO $pdo, string $name, string $admin): int
{
    $name = trim($name);
    if ($name === '') $name = 'Novo fluxo';
    if (mb_strlen($name) > 150) throw new InvalidArgumentException('O nome pode ter no máximo 150 caracteres.');
    $graph = json_encode(push_flow_blank_graph(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $st = $pdo->prepare("INSERT INTO push_flows (name,status,draft_graph_json,created_by,updated_by) VALUES (:name,'draft',:graph,:admin,:admin2)");
    $st->execute(['name'=>$name,'graph'=>$graph,'admin'=>$admin,'admin2'=>$admin]);
    return (int)$pdo->lastInsertId();
}

function push_flow_save(PDO $pdo, int $flowId, string $name, string $description, array $graph, int $lockVersion, string $admin): int
{
    $name = trim($name); $description = trim($description);
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Informe um nome de até 150 caracteres.');
    if (mb_strlen($description) > 500) throw new InvalidArgumentException('A descrição pode ter no máximo 500 caracteres.');
    $errors = push_flow_validate_graph($graph, false);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $st = $pdo->prepare("UPDATE push_flows SET name=:name,description=:description,draft_graph_json=:graph,updated_by=:admin,lock_version=lock_version+1 WHERE id=:id AND lock_version=:lock_version");
    $st->execute(['name'=>$name,'description'=>$description!==''?$description:null,'graph'=>$json,'admin'=>$admin,'id'=>$flowId,'lock_version'=>$lockVersion]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Este fluxo foi alterado em outra aba. Recarregue a página antes de salvar novamente.');
    return $lockVersion + 1;
}

function push_flow_publish(PDO $pdo, int $flowId, string $name, string $description, array $graph, int $lockVersion, string $admin): int
{
    $errors = push_flow_validate_graph($graph, true);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    $pdo->beginTransaction();
    try {
        $newLock = push_flow_save($pdo, $flowId, $name, $description, $graph, $lockVersion, $admin);
        $st = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM push_flow_versions WHERE flow_id=:id');
        $st->execute(['id'=>$flowId]);
        $version = (int)$st->fetchColumn();
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $st = $pdo->prepare('INSERT INTO push_flow_versions (flow_id,version_number,graph_json,name_snapshot,description_snapshot,published_by) VALUES (:flow,:version,:graph,:name,:description,:admin)');
        $st->execute(['flow'=>$flowId,'version'=>$version,'graph'=>$json,'name'=>trim($name),'description'=>trim($description)!==''?trim($description):null,'admin'=>$admin]);
        $versionId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE push_flows SET current_version_id=:version,status='active',published_at=NOW() WHERE id=:id AND lock_version=:lock")
            ->execute(['version'=>$versionId,'id'=>$flowId,'lock'=>$newLock]);
        $pdo->commit();
        return $version;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function push_flow_clone(PDO $pdo, int $flowId, string $admin): int
{
    $flow = push_flow_find($pdo, $flowId);
    if (!$flow) throw new RuntimeException('Fluxo não encontrado.');
    $name = mb_substr('Cópia de ' . $flow['name'], 0, 150);
    $st = $pdo->prepare("INSERT INTO push_flows (name,description,status,draft_graph_json,created_by,updated_by) VALUES (:name,:description,'draft',:graph,:admin,:admin2)");
    $st->execute(['name'=>$name,'description'=>$flow['description'],'graph'=>$flow['draft_graph_json'],'admin'=>$admin,'admin2'=>$admin]);
    return (int)$pdo->lastInsertId();
}

function push_flow_set_status(PDO $pdo, int $flowId, string $status, string $admin): void
{
    if (!in_array($status, ['active','paused'], true)) throw new InvalidArgumentException('Estado inválido.');
    $flow = push_flow_find($pdo, $flowId);
    if (!$flow) throw new RuntimeException('Fluxo não encontrado.');
    if ($status === 'active' && empty($flow['current_version_id'])) throw new RuntimeException('Publique o fluxo antes de ativá-lo.');
    $pdo->prepare('UPDATE push_flows SET status=:status,updated_by=:admin WHERE id=:id')->execute(['status'=>$status,'admin'=>$admin,'id'=>$flowId]);
}

function push_flow_delete(PDO $pdo, int $flowId): void
{
    if ($flowId <= 0) throw new InvalidArgumentException('Fluxo inválido.');
    try {
        $table = $pdo->query("SHOW TABLES LIKE 'push_flow_runs'");
        if ($table && $table->fetchColumn()) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM push_flow_runs WHERE flow_id=:id AND status='running'");
            $st->execute(['id'=>$flowId]);
            if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Pause o fluxo e aguarde as execuções em andamento terminarem antes de excluí-lo.');
        }
    } catch (RuntimeException $e) { throw $e; }
    catch (Throwable $ignored) {}
    $st = $pdo->prepare("UPDATE push_flows SET status='deleted',updated_at=NOW() WHERE id=:id AND status<>'deleted'");
    $st->execute(['id'=>$flowId]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Fluxo não encontrado.');
}
