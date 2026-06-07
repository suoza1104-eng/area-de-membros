<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';

session_start();
if (empty($_SESSION['admin_logado'])) {
    header('Location: login.php'); exit;
}
// Este arquivo so LE a sessao (auth acima) e nunca grava em $_SESSION.
// Liberamos o lock imediatamente: sem isso, um disparo em lote
// (executar_batch, com cURL por aluno) segura o lock por minutos e
// congela TODA a area admin para o mesmo navegador.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = getPDO();

// ── Garantir tabelas ──────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS disparos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(200) NOT NULL,
    status          ENUM('rascunho','aguardando','executando','pausado','concluido','erro') NOT NULL DEFAULT 'rascunho',
    tipo            ENUM('instantaneo','agendado') NOT NULL DEFAULT 'instantaneo',
    agendado_em     DATETIME NULL,
    intervalo_seg   INT UNSIGNED NOT NULL DEFAULT 0,
    filtros_json    MEDIUMTEXT NULL,
    acoes_json      MEDIUMTEXT NULL,
    total_enviados  INT UNSIGNED NOT NULL DEFAULT 0,
    total_erros     INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS disparo_execucoes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    disparo_id  INT NOT NULL,
    user_id     INT NOT NULL,
    status      ENUM('ok','erro') NOT NULL DEFAULT 'ok',
    resposta    TEXT NULL,
    executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (disparo_id),
    INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS user_tags_sistema (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    tag        VARCHAR(200) NOT NULL,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_tag (user_id, tag),
    INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migração de colunas — cada ALTER ignorado se coluna já existir
foreach ([
    'intervalo_ms INT UNSIGNED NOT NULL DEFAULT 0',
    'horario_ativo TINYINT(1) NOT NULL DEFAULT 0',
    'horario_inicio TIME NULL',
    'horario_fim TIME NULL',
    "dias_semana VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7'",
] as $col) {
    try { $pdo->exec("ALTER TABLE disparos ADD COLUMN $col"); } catch (Throwable $e) {}
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // Helper: verifica se tabela existe (cached por chamada)
    function dpTableExists(PDO $pdo, string $t): bool {
        static $cache = [];
        if (!isset($cache[$t])) {
            try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); $cache[$t] = true; }
            catch (Throwable $e) { $cache[$t] = false; }
        }
        return $cache[$t];
    }

    // Helper: constrói WHERE de audiência a partir de filtros_json
    function buildAudienceWhere(array $f, PDO $pdo): array {
        $inc = $f['inclusao'] ?? [];
        $exc = $f['exclusao'] ?? [];
        $logic = strtoupper($f['logica_inclusao'] ?? 'AND');
        if (!in_array($logic, ['AND','OR'], true)) $logic = 'AND';

        $incClauses = [];
        $params = [];
        $p = 0;
        $nextP = function() use (&$p): string { return ':p' . (++$p); };

        foreach ($inc as $regra) {
            $tipo = $regra['tipo'] ?? '';
            switch ($tipo) {
                case 'turma':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id = u.id AND il.codigo_turma = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'tag_sf':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = u.id AND t.nome = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'tag_sistema':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "(
                            EXISTS(SELECT 1 FROM user_tags_sistema uts WHERE uts.user_id = u.id AND uts.tag = $pk)
                            OR EXISTS(SELECT 1 FROM user_tags utS JOIN tags tS ON tS.id = utS.tag_id WHERE utS.user_id = u.id AND tS.nome = $pk)
                        )";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'inscricao_de':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id = u.id AND DATE(il.created_at) >= $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'inscricao_ate':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id = u.id AND DATE(il.created_at) <= $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'ultimo_de':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id = u.id AND DATE(il.created_at) >= $pk ORDER BY il.created_at DESC LIMIT 1)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'ultimo_ate':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "(SELECT MAX(DATE(il.created_at)) FROM inscricao_logs il WHERE il.user_id = u.id) <= $pk";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'qtd_min':
                    if (isset($regra['valor']) && $regra['valor'] !== '') {
                        $pk = $nextP();
                        $incClauses[] = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id = u.id) >= $pk";
                        $params[$pk] = (int)$regra['valor'];
                    }
                    break;
                case 'qtd_max':
                    if (isset($regra['valor']) && $regra['valor'] !== '') {
                        $pk = $nextP();
                        $incClauses[] = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id = u.id) <= $pk";
                        $params[$pk] = (int)$regra['valor'];
                    }
                    break;
                case 'tem_cert':
                    if (dpTableExists($pdo, 'certificates'))
                        $incClauses[] = "EXISTS(SELECT 1 FROM certificates c WHERE c.user_id = u.id)";
                    break;
                case 'nao_tem_cert':
                    if (dpTableExists($pdo, 'certificates'))
                        $incClauses[] = "NOT EXISTS(SELECT 1 FROM certificates c WHERE c.user_id = u.id)";
                    break;
                case 'evento_webhook':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "EXISTS(SELECT 1 FROM webhook_logs wl WHERE wl.user_id = u.id AND wl.evento = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
            }
        }

        $excClauses = [];
        foreach ($exc as $regra) {
            $tipo = $regra['tipo'] ?? '';
            switch ($tipo) {
                case 'tem_cert':
                    if (dpTableExists($pdo, 'certificates'))
                        $excClauses[] = "EXISTS(SELECT 1 FROM certificates c WHERE c.user_id = u.id)";
                    break;
                case 'tag_sf':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "EXISTS(SELECT 1 FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = u.id AND t.nome = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'tag_sistema':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "(
                            EXISTS(SELECT 1 FROM user_tags_sistema uts WHERE uts.user_id = u.id AND uts.tag = $pk)
                            OR EXISTS(SELECT 1 FROM user_tags utS JOIN tags tS ON tS.id = utS.tag_id WHERE utS.user_id = u.id AND tS.nome = $pk)
                        )";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'turma':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id = u.id AND il.codigo_turma = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'qtd_min':
                    if (isset($regra['valor']) && $regra['valor'] !== '') {
                        $pk = $nextP();
                        $excClauses[] = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id = u.id) >= $pk";
                        $params[$pk] = (int)$regra['valor'];
                    }
                    break;
                case 'evento_webhook':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "EXISTS(SELECT 1 FROM webhook_logs wl WHERE wl.user_id = u.id AND wl.evento = $pk)";
                        $params[$pk] = $regra['valor'];
                    }
                    break;
                case 'ja_recebeu':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "EXISTS(SELECT 1 FROM disparo_execucoes de2 WHERE de2.user_id = u.id AND de2.disparo_id = $pk AND de2.status = 'ok')";
                        $params[$pk] = (int)$regra['valor'];
                    }
                    break;
            }
        }

        $where = 'u.id > 0';
        if ($incClauses) {
            $where .= ' AND (' . implode(" $logic ", $incClauses) . ')';
        }
        if ($excClauses) {
            $where .= ' AND NOT (' . implode(' OR ', $excClauses) . ')';
        }
        return ['where' => $where, 'params' => $params];
    }

    function dpUsuarioValor(array $usuario, string $chave): string {
        $map = [
            'turma' => $usuario['ultima_turma'] ?? ($usuario['codigo_turma'] ?? ''),
            'codigo_turma' => $usuario['ultima_turma'] ?? ($usuario['codigo_turma'] ?? ''),
            'data_live' => $usuario['data_live'] ?? ($usuario['turma_live_at'] ?? ''),
            'live' => $usuario['data_live'] ?? ($usuario['turma_live_at'] ?? ''),
        ];
        $valor = array_key_exists($chave, $map) ? $map[$chave] : ($usuario[$chave] ?? '');
        if (is_array($valor) || is_object($valor)) return json_encode($valor, JSON_UNESCAPED_UNICODE);
        return (string)$valor;
    }

    function dpResolverValorAcao(array $usuario, string $valor): string {
        $valor = trim($valor);
        if ($valor === '') return '';
        if (strpos($valor, 'literal:') === 0) return substr($valor, 8);
        if (strpos($valor, 'user.') === 0) return dpUsuarioValor($usuario, substr($valor, 5));
        return preg_replace_callback('/\{\{\s*user\.([a-zA-Z0-9_]+)\s*\}\}/', function($m) use ($usuario) {
            return dpUsuarioValor($usuario, $m[1]);
        }, $valor);
    }

    // Helper: envia via SF
    function enviarSF(array $usuario, array $acoes, PDO $pdo): array {
        try {
            $cfg = $pdo->query("SELECT * FROM superfuncionario_config ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $cfg = null; }

        if (!$cfg || empty($cfg['is_enabled'])) return ['ok' => false, 'msg' => 'SF desabilitado'];
        if (empty($cfg['base_url']) || empty($cfg['token'])) return ['ok' => false, 'msg' => 'SF sem config'];

        $sfAcoes = [];
        foreach ($acoes as $a) {
            if ($a['tipo'] === 'flow' && !empty($a['valor'])) {
                foreach (array_filter(array_map('trim', explode(',', (string)$a['valor']))) as $fid) {
                    $sfAcoes[] = ['action' => 'send_flow', 'flow_id' => (int)$fid];
                }
            } elseif ($a['tipo'] === 'tag_sf' && !empty($a['valor'])) {
                $sfAcoes[] = ['action' => 'add_tag', 'tag_name' => $a['valor']];
            } elseif ($a['tipo'] === 'custom_field' && !empty($a['campo'])) {
                $sfAcoes[] = [
                    'action' => 'set_field_value',
                    'field_name' => trim((string)$a['campo']),
                    'value' => dpResolverValorAcao($usuario, (string)($a['valor'] ?? '')),
                ];
            }
        }
        if (empty($sfAcoes)) return ['ok' => true, 'msg' => 'sem_acao_sf'];

        $payload = json_encode([
            'email'      => $usuario['email'] ?? '',
            'phone'      => $usuario['telefone'] ?? '',
            'first_name' => $usuario['nome'] ?? '',
            'actions'    => $sfAcoes,
        ]);

        $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($cfg['default_endpoint'] ?? '', '/');
        $headerMode = strtolower((string)($cfg['header_mode'] ?? 'x-access-token'));
        $authHeader = ($headerMode === 'bearer')
            ? 'Authorization: Bearer ' . $cfg['token']
            : 'X-ACCESS-TOKEN: ' . $cfg['token'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => (int)($cfg['timeout_seconds'] ?? 10),
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', $authHeader],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $ok = $code >= 200 && $code < 300;
        $msg = $resp === false ? ('Erro cURL: ' . ($curlErr ?: 'falha desconhecida')) : (string)$resp;
        if (!$ok && trim($msg) === '') $msg = 'HTTP ' . (int)$code . ' sem resposta';
        return ['ok' => $ok, 'msg' => $msg];
    }

    // Helper: aplica tags do sistema
    function aplicarTagSistema(int $userId, array $acoes, PDO $pdo): void {
        foreach ($acoes as $a) {
            if ($a['tipo'] === 'tag_sistema' && !empty($a['valor'])) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO user_tags_sistema (user_id, tag, criado_em) VALUES (:uid, :tag, NOW())")
                        ->execute([':uid' => $userId, ':tag' => $a['valor']]);
                } catch (Throwable $e) {}
            }
        }
    }

    switch ($acao) {

        // Diagnóstico (temporário)
        case 'diag':
            $info = [];
            $info['certificates_exists'] = dpTableExists($pdo, 'certificates');
            $info['certificados_exists']  = dpTableExists($pdo, 'certificados');
            try {
                $info['certificates_count'] = (int)$pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
                $row = $pdo->query("SELECT * FROM certificates LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $info['certificates_sample'] = $row ?: null;
            } catch (Throwable $e) { $info['certificates_err'] = $e->getMessage(); }
            try {
                $info['users_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            } catch (Throwable $e) { $info['users_err'] = $e->getMessage(); }
            echo json_encode($info);
            exit;

        // Contar audiência (preview de contagem)
        case 'preview':
            $filtros = json_decode($_POST['filtros_json'] ?? '{}', true) ?: [];
            // Inclusão apenas (sem exclusões) → badge do topo
            $filtrosSemExc = $filtros; $filtrosSemExc['exclusao'] = [];
            $awInc = buildAudienceWhere($filtrosSemExc, $pdo);
            // Inclusão + exclusão → badge da prévia
            $awFull = buildAudienceWhere($filtros, $pdo);
            try {
                $stInc = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$awInc['where']}");
                $stInc->execute($awInc['params']);
                $totalInc = (int)$stInc->fetchColumn();

                $stFull = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$awFull['where']}");
                $stFull->execute($awFull['params']);
                $totalFull = (int)$stFull->fetchColumn();

                echo json_encode(['ok' => true, 'total_inclusao' => $totalInc, 'total' => $totalFull]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            exit;

        // Preview lista de contatos (até 50)
        case 'preview_contatos':
            $filtros = json_decode($_POST['filtros_json'] ?? '{}', true) ?: [];
            $aw = buildAudienceWhere($filtros, $pdo);
            try {
                $stP = $pdo->prepare(
                    "SELECT u.id, u.nome, u.email, u.telefone,
                            (SELECT MAX(il.created_at) FROM inscricao_logs il WHERE il.user_id = u.id) AS ultimo_cadastro,
                            (SELECT il2.codigo_turma FROM inscricao_logs il2 WHERE il2.user_id = u.id ORDER BY il2.created_at DESC LIMIT 1) AS ultima_turma,
                            (SELECT COUNT(*) FROM inscricao_logs il3 WHERE il3.user_id = u.id) AS qtd_inscricoes
                     FROM users u WHERE {$aw['where']} ORDER BY u.id DESC LIMIT 50"
                );
                $stP->execute($aw['params']);
                echo json_encode(['ok' => true, 'data' => $stP->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            exit;

        // Salvar (criar ou editar)
        case 'salvar':
            $id   = (int)($_POST['id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $tipo = in_array($_POST['tipo'] ?? '', ['instantaneo','agendado']) ? $_POST['tipo'] : 'instantaneo';
            $agendado_em   = !empty($_POST['agendado_em'])   ? $_POST['agendado_em']   : null;
            $intervalo_ms  = (int)($_POST['intervalo_ms'] ?? 0);
            $filtros_json  = $_POST['filtros_json']  ?? '{}';
            $acoes_json    = $_POST['acoes_json']    ?? '[]';
            $status        = ($id > 0) ? ($_POST['status'] ?? 'rascunho') : 'rascunho';
            $horario_ativo = (int)($_POST['horario_ativo'] ?? 0);
            $horario_inicio = !empty($_POST['horario_inicio']) ? $_POST['horario_inicio'] : null;
            $horario_fim   = !empty($_POST['horario_fim'])    ? $_POST['horario_fim']    : null;
            $dias_semana   = preg_replace('/[^0-9,]/', '', $_POST['dias_semana'] ?? '1,2,3,4,5,6,7');

            if ($nome === '') { echo json_encode(['ok' => false, 'msg' => 'Nome obrigatório']); exit; }

            if ($id > 0) {
                $st = $pdo->prepare("UPDATE disparos SET nome=:nome, tipo=:tipo, agendado_em=:ag, intervalo_ms=:iv, filtros_json=:fj, acoes_json=:aj, status=:st, horario_ativo=:ha, horario_inicio=:hi, horario_fim=:hf, dias_semana=:ds WHERE id=:id");
                $st->execute([':nome'=>$nome,':tipo'=>$tipo,':ag'=>$agendado_em,':iv'=>$intervalo_ms,':fj'=>$filtros_json,':aj'=>$acoes_json,':st'=>$status,':ha'=>$horario_ativo,':hi'=>$horario_inicio,':hf'=>$horario_fim,':ds'=>$dias_semana,':id'=>$id]);
            } else {
                $st = $pdo->prepare("INSERT INTO disparos (nome, tipo, agendado_em, intervalo_ms, filtros_json, acoes_json, horario_ativo, horario_inicio, horario_fim, dias_semana) VALUES (:nome,:tipo,:ag,:iv,:fj,:aj,:ha,:hi,:hf,:ds)");
                $st->execute([':nome'=>$nome,':tipo'=>$tipo,':ag'=>$agendado_em,':iv'=>$intervalo_ms,':fj'=>$filtros_json,':aj'=>$acoes_json,':ha'=>$horario_ativo,':hi'=>$horario_inicio,':hf'=>$horario_fim,':ds'=>$dias_semana]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok' => true, 'id' => $id]);
            exit;

        // Deletar
        case 'deletar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM disparo_execucoes WHERE disparo_id = :id")->execute([':id'=>$id]);
                $pdo->prepare("DELETE FROM disparos WHERE id = :id")->execute([':id'=>$id]);
            }
            echo json_encode(['ok' => true]);
            exit;

        // Clonar
        case 'clonar':
            $id = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT * FROM disparos WHERE id = :id");
            $row->execute([':id'=>$id]);
            $row = $row->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Não encontrado']); exit; }
            $st = $pdo->prepare("INSERT INTO disparos (nome, tipo, agendado_em, intervalo_seg, filtros_json, acoes_json, status) VALUES (:nome,:tipo,:ag,:iv,:fj,:aj,'rascunho')");
            $st->execute([':nome'=>'[Cópia] '.$row['nome'],':tipo'=>$row['tipo'],':ag'=>$row['agendado_em'],':iv'=>$row['intervalo_seg'],':fj'=>$row['filtros_json'],':aj'=>$row['acoes_json']]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            exit;

        // Mudar status (pausar, retomar, desativar, ativar)
        case 'set_status':
            $id     = (int)($_POST['id'] ?? 0);
            $novoSt = $_POST['status'] ?? '';
            $allowed = ['rascunho','aguardando','pausado','concluido'];
            if ($id > 0 && in_array($novoSt, $allowed, true)) {
                $pdo->prepare("UPDATE disparos SET status = :st WHERE id = :id")->execute([':st'=>$novoSt,':id'=>$id]);
            }
            echo json_encode(['ok' => true]);
            exit;

        // Buscar um disparo (para edição)
        case 'get':
            $id  = (int)($_GET['id'] ?? 0);
            $row = $pdo->prepare("SELECT * FROM disparos WHERE id = :id");
            $row->execute([':id'=>$id]);
            $row = $row->fetch(PDO::FETCH_ASSOC);
            echo $row ? json_encode(['ok'=>true,'data'=>$row]) : json_encode(['ok'=>false]);
            exit;

        // Listar
        case 'listar':
            $rows = $pdo->query("SELECT id, nome, status, tipo, agendado_em, total_enviados, total_erros, criado_em FROM disparos ORDER BY criado_em DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'data'=>$rows]);
            exit;

        // Logs e resumo de um disparo
        case 'logs':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Disparo invalido']); exit; }
            try {
                $stSum = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total,
                        SUM(status='ok') AS ok_total,
                        SUM(status='erro') AS erro_total
                    FROM disparo_execucoes
                    WHERE disparo_id = :id
                ");
                $stSum->execute([':id'=>$id]);
                $sum = $stSum->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'ok_total'=>0,'erro_total'=>0];

                $stRows = $pdo->prepare("
                    SELECT de.id, de.user_id, de.status, de.resposta, de.executado_em,
                           u.nome, u.email, u.telefone
                      FROM disparo_execucoes de
                      LEFT JOIN users u ON u.id = de.user_id
                     WHERE de.disparo_id = :id
                     ORDER BY de.id DESC
                     LIMIT 300
                ");
                $stRows->execute([':id'=>$id]);
                $rows = $stRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $total = (int)($sum['total'] ?? 0);
                $okTotal = (int)($sum['ok_total'] ?? 0);
                $erroTotal = (int)($sum['erro_total'] ?? 0);
                echo json_encode([
                    'ok' => true,
                    'summary' => [
                        'total' => $total,
                        'ok' => $okTotal,
                        'erro' => $erroTotal,
                        'taxa_ok' => $total > 0 ? round(($okTotal / $total) * 100, 1) : 0,
                        'taxa_erro' => $total > 0 ? round(($erroTotal / $total) * 100, 1) : 0,
                    ],
                    'data' => $rows,
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            exit;

        // Executar lote de um disparo (chamado progressivamente via JS)
        case 'executar_batch':
            try {
                $id     = (int)($_POST['id'] ?? 0);
                $offset = (int)($_POST['offset'] ?? 0);
                $limit  = 1;

                $row = $pdo->prepare("SELECT * FROM disparos WHERE id = :id");
                $row->execute([':id'=>$id]);
                $row = $row->fetch(PDO::FETCH_ASSOC);
                if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Disparo não encontrado']); exit; }

                $filtros = json_decode($row['filtros_json'] ?? '{}', true) ?: [];
                $acoes   = json_decode($row['acoes_json']   ?? '[]', true) ?: [];

                if ($offset === 0) {
                    $pdo->prepare("UPDATE disparos SET status='executando', total_enviados=0, total_erros=0 WHERE id=:id")->execute([':id'=>$id]);
                }

                $aw = buildAudienceWhere($filtros, $pdo);
                $totalGeral = null;
                if ($offset === 0) {
                    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$aw['where']}");
                    $stCnt->execute($aw['params']);
                    $totalGeral = (int)$stCnt->fetchColumn();
                }

                $stUsers = $pdo->prepare(
                    "SELECT u.id, u.nome, u.email, u.telefone,
                            (SELECT il2.codigo_turma FROM inscricao_logs il2 WHERE il2.user_id = u.id ORDER BY il2.created_at DESC LIMIT 1) AS ultima_turma,
                            (SELECT t.data_live
                               FROM turmas t
                              WHERE t.codigo = (SELECT il3.codigo_turma FROM inscricao_logs il3 WHERE il3.user_id = u.id ORDER BY il3.created_at DESC LIMIT 1)
                              LIMIT 1) AS data_live
                     FROM users u WHERE {$aw['where']} LIMIT $limit OFFSET $offset"
                );
                $stUsers->execute($aw['params']);
                $userList = $stUsers->fetchAll(PDO::FETCH_ASSOC);

                $enviados = 0;
                $erros    = 0;
                foreach ($userList as $usr) {
                    $r = enviarSF($usr, $acoes, $pdo);
                    aplicarTagSistema((int)$usr['id'], $acoes, $pdo);
                    $status = $r['ok'] ? 'ok' : 'erro';
                    $pdo->prepare("INSERT INTO disparo_execucoes (disparo_id, user_id, status, resposta) VALUES (:did,:uid,:st,:resp)")
                        ->execute([':did'=>$id,':uid'=>$usr['id'],':st'=>$status,':resp'=>substr($r['msg'],0,1000)]);
                    if ($r['ok']) $enviados++; else $erros++;
                }

                $pdo->prepare("UPDATE disparos SET total_enviados = total_enviados + :e, total_erros = total_erros + :er WHERE id = :id")
                    ->execute([':e'=>$enviados,':er'=>$erros,':id'=>$id]);

                $done = count($userList) < $limit;
                if ($done) {
                    $pdo->prepare("UPDATE disparos SET status='concluido' WHERE id=:id")->execute([':id'=>$id]);
                }

                echo json_encode([
                    'ok'          => true,
                    'processados' => count($userList),
                    'enviados'    => $enviados,
                    'erros'       => $erros,
                    'done'        => $done,
                    'next_offset' => $offset + $limit,
                    'total'       => $totalGeral,
                ]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida']);
            exit;
    }
}

// ── Carrega turmas para o filtro ──────────────────────────────────────────────
$turmas = [];
try {
    $turmas = $pdo->query(
        "SELECT codigo,
                CONCAT(codigo, IF(janela_inicio IS NOT NULL AND janela_inicio != '', CONCAT(' (', LEFT(janela_inicio,10), ')'), '')) AS nome
         FROM turmas ORDER BY janela_inicio DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$tagsSf = [];
try {
    $tagsSf = $pdo->query("SELECT nome FROM tags ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

$tagsSistema = [];
try {
    $tagsSistema = $pdo->query("
        SELECT tag AS nome FROM user_tags_sistema
        UNION
        SELECT nome FROM tags
        ORDER BY nome ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

$currentMenu = 'disparos';
$page_title  = 'Disparos';
require_once __DIR__ . '/_header.php';
?>
<style>
/* ── Layout ── */
.dp-wrap { display: flex; gap: 24px; align-items: flex-start; }
.dp-list-panel { flex: 1; min-width: 0; }
.dp-form-panel {
    width: 520px; flex-shrink: 0;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    position: sticky; top: 24px;
}
@media (max-width: 1100px) {
    .dp-wrap { flex-direction: column; }
    .dp-form-panel { width: 100%; position: static; }
}

/* ── Cards de disparo ── */
.dp-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 12px;
    display: block;
}
.dp-card-main {
    display: flex; align-items: center; gap: 14px;
}
.dp-card-info { flex: 1; min-width: 0; }
.dp-card-nome { font-weight: 600; font-size: 15px; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dp-card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; }
.dp-card-actions { display: flex; gap: 8px; flex-shrink: 0; }
.dp-logs-panel {
    display: none;
    border-top: 1px solid var(--border);
    margin-top: 14px;
    padding-top: 14px;
}
.dp-logs-panel.open { display: block; }
.dp-log-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.dp-log-metric {
    border: 1px solid var(--border);
    border-radius: 8px;
    background: rgba(255,255,255,.025);
    padding: 10px 12px;
}
.dp-log-metric-label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 5px;
}
.dp-log-metric-value { font-size: 20px; font-weight: 800; }
.dp-log-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
.dp-log-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12px; }
.dp-log-table th {
    background: #101827;
    color: var(--text-muted);
    text-align: left;
    padding: 8px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.dp-log-table td {
    border-top: 1px solid var(--border);
    padding: 8px 10px;
    vertical-align: top;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dp-log-table .resp {
    white-space: pre-wrap;
    word-break: break-word;
    color: #cbd5e1;
}
.dp-log-ok { color: #34d399; font-weight: 700; }
.dp-log-erro { color: #f87171; font-weight: 700; }

/* Badges de status */
.badge-rascunho   { background: #3a3a4a; color: #aaa; }
.badge-aguardando { background: #1e3a5f; color: #60a5fa; }
.badge-executando { background: #1a3a2a; color: #4ade80; }
.badge-pausado    { background: #3a2e10; color: #fbbf24; }
.badge-concluido  { background: #1a3520; color: #34d399; }
.badge-erro       { background: #3a1a1a; color: #f87171; }
.badge-st { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }

/* ── Filtro builder ── */
.filter-group {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}
.filter-group-header {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .5px;
    margin-bottom: 10px;
}
.filter-row {
    display: flex; gap: 6px; align-items: center; margin-bottom: 6px;
    flex-wrap: wrap;
}
.filter-row select, .filter-row input {
    background: var(--input-bg, #1e1e2e);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: 5px 8px;
    font-size: 13px;
}
.filter-row select { min-width: 150px; }
.filter-row input  { flex: 1; min-width: 80px; }
.filter-row .btn-rm {
    background: none; border: 1px solid #553; color: #f87171;
    border-radius: 6px; padding: 4px 8px; cursor: pointer; font-size: 13px; line-height: 1;
}
.btn-add-filter {
    background: none; border: 1px dashed var(--border); color: var(--text-muted);
    border-radius: 6px; padding: 6px 12px; cursor: pointer; font-size: 12px; width: 100%;
    margin-top: 4px;
}
.btn-add-filter:hover { border-color: var(--accent); color: var(--accent); }

/* ── Ações ── */
.acao-row {
    display: flex; gap: 6px; align-items: center; margin-bottom: 6px; flex-wrap: wrap;
}
.acao-row select { min-width: 130px; background: var(--input-bg,#1e1e2e); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 5px 8px; font-size: 13px; }
.acao-row input  { flex: 1; min-width: 80px; background: var(--input-bg,#1e1e2e); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 5px 8px; font-size: 13px; }

/* ── Progress modal ── */
.dp-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000; display: none;
}
.dp-modal-overlay.visible { display: flex; }
.dp-modal-box {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 14px; padding: 32px; width: 480px; max-width: 95vw;
    text-align: center;
}
.dp-progress-bar-wrap { background: #1a1a2a; border-radius: 8px; height: 12px; margin: 16px 0; overflow: hidden; }
.dp-progress-bar { height: 100%; background: linear-gradient(90deg, #6366f1, #8b5cf6); border-radius: 8px; transition: width .3s; }
.dp-modal-stats { display: flex; gap: 24px; justify-content: center; margin-top: 10px; }
.dp-stat { text-align: center; }
.dp-stat-val { font-size: 22px; font-weight: 700; }
.dp-stat-lbl { font-size: 11px; color: var(--text-muted); }

/* ── Misc ── */
.logic-toggle { display: flex; gap: 0; border-radius: 6px; overflow: hidden; border: 1px solid var(--border); }
.logic-toggle button {
    background: none; border: none; padding: 4px 12px; cursor: pointer;
    font-size: 12px; font-weight: 700; color: var(--text-muted);
}
.logic-toggle button.active { background: var(--accent, #6366f1); color: #fff; }
.form-row { margin-bottom: 14px; }
.form-row label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 5px; font-weight: 600; }
.form-row input, .form-row select, .form-row textarea {
    width: 100%; box-sizing: border-box;
    background: var(--input-bg,#1e1e2e); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text); padding: 8px 12px; font-size: 14px;
}
.preview-badge {
    display: inline-block; background: #1e2a3a; color: #60a5fa;
    padding: 4px 14px; border-radius: 20px; font-size: 13px; font-weight: 700;
    margin-left: auto;
}
.dp-empty { text-align: center; color: var(--text-muted); padding: 48px 0; font-size: 15px; }

/* ── Preview contatos ── */
.dp-preview-panel {
    border: 1px solid #2a2a4a; border-radius: 8px;
    margin-bottom: 12px; overflow: hidden;
}
.dp-preview-header {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; cursor: pointer;
    background: #14142a; font-size: 12px; font-weight: 600;
    color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px;
    user-select: none;
}
.dp-preview-header:hover { background: #1a1a36; }
.dp-preview-body {
    max-height: 260px; overflow-y: auto;
    display: none;
}
.dp-preview-body.open { display: block; }
.dp-preview-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.dp-preview-table th { background: #1a1a2e; color: var(--text-muted); padding: 6px 10px; text-align: left; font-weight: 600; position: sticky; top: 0; }
.dp-preview-table td { padding: 5px 10px; border-bottom: 1px solid var(--border); }
.dp-preview-table tr:hover td { background: rgba(99,102,241,.06); }

/* ── Horário de execução ── */
.horario-wrap {
    background: #14142a; border: 1px solid var(--border);
    border-radius: 8px; padding: 12px; margin-top: 8px;
}
.day-check { display: inline-flex; align-items: center; gap: 4px; margin-right: 6px; font-size: 12px; cursor: pointer; }
.toggle-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #333; border-radius: 20px; transition: .2s;
}
.toggle-slider:before {
    content: ''; position: absolute;
    width: 14px; height: 14px; left: 3px; top: 3px;
    background: #fff; border-radius: 50%; transition: .2s;
}
.toggle-switch input:checked + .toggle-slider { background: #6366f1; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(16px); }
</style>

<div class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Disparos</h1>
      <p class="page-subtitle">Gerencie campanhas instantâneas e agendadas</p>
    </div>
    <button class="btn btn-primary" onclick="dpNovoDisparo()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:6px;vertical-align:-3px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Novo Disparo
    </button>
  </div>

  <div class="dp-wrap">
    <!-- Lista -->
    <div class="dp-list-panel">
      <div id="dpListContainer">
        <div class="dp-empty">Carregando…</div>
      </div>
    </div>

    <!-- Painel de edição / criação -->
    <div class="dp-form-panel" id="dpFormPanel" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 style="margin:0;font-size:16px" id="dpFormTitle">Novo Disparo</h3>
        <button onclick="dpFecharForm()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1">&times;</button>
      </div>

      <input type="hidden" id="dpId" value="0">

      <div class="form-row">
        <label>Nome do disparo</label>
        <input type="text" id="dpNome" placeholder="Ex: Black Friday — inscritos turma A">
      </div>

      <div class="form-row" style="display:flex;gap:12px">
        <div style="flex:1">
          <label>Tipo</label>
          <select id="dpTipo" onchange="dpToggleTipo()">
            <option value="instantaneo">Instantâneo</option>
            <option value="agendado">Agendado</option>
          </select>
        </div>
        <div style="flex:1" id="dpAgendadoWrap">
          <label>Data / hora</label>
          <input type="datetime-local" id="dpAgendadoEm">
        </div>
      </div>

      <div class="form-row">
        <label>Intervalo entre lotes (ms) <span style="color:var(--text-muted)">[0 = sem pausa | ex: 2000 = 2s entre cada lote de 20]</span></label>
        <input type="number" id="dpIntervaloMs" value="0" min="0" step="100">
      </div>

      <div class="form-row">
        <div style="display:flex;align-items:center;gap:10px">
          <label class="toggle-switch">
            <input type="checkbox" id="dpHorarioAtivo" onchange="dpToggleHorario()">
            <span class="toggle-slider"></span>
          </label>
          <span style="font-size:13px;font-weight:600">Restringir horário de envio</span>
        </div>
        <div class="horario-wrap" id="dpHorarioWrap" style="display:none">
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:center">
            <div style="flex:1">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">De</label>
              <input type="time" id="dpHorarioInicio" value="08:00" style="width:100%;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 8px;font-size:13px">
            </div>
            <div style="flex:1">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Até</label>
              <input type="time" id="dpHorarioFim" value="21:00" style="width:100%;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 8px;font-size:13px">
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;font-weight:600">DIAS DA SEMANA</div>
            <div id="dpDiasSemana">
              <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $di => $dn): ?>
              <label class="day-check">
                <input type="checkbox" class="dp-dia" value="<?= $di ?>"> <?= $dn ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- FILTROS INCLUSÃO -->
      <div class="filter-group">
        <div class="filter-group-header">
          <span>Incluir quem:</span>
          <div class="logic-toggle" id="logicaToggle">
            <button id="btnAnd" class="active" onclick="dpSetLogica('AND')">E (AND)</button>
            <button id="btnOr"  onclick="dpSetLogica('OR')">OU (OR)</button>
          </div>
          <span id="dpPreviewBadge" class="preview-badge" style="margin-left:auto">? leads</span>
        </div>
        <div id="dpFiltrosInc"></div>
        <button class="btn-add-filter" onclick="dpAddFiltroInc()">+ Adicionar filtro de inclusão</button>
      </div>

      <!-- FILTROS EXCLUSÃO -->
      <div class="filter-group">
        <div class="filter-group-header" style="color:#f87171">Excluir quem: (sempre AND NOT)</div>
        <div id="dpFiltrosExc"></div>
        <button class="btn-add-filter" onclick="dpAddFiltroExc()">+ Adicionar filtro de exclusão</button>
      </div>

      <!-- PREVIEW CONTATOS -->
      <div class="dp-preview-panel">
        <div class="dp-preview-header" onclick="dpTogglePreviewContatos()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          <span>Prévia dos contatos inclusos</span>
          <span id="dpPreviewContatosCount" style="margin-left:auto;font-size:11px;color:#60a5fa">—</span>
          <span id="dpPreviewArrow" style="font-size:14px">▶</span>
        </div>
        <div class="dp-preview-body" id="dpPreviewBody">
          <div id="dpPreviewContatosContent" style="padding:12px;color:var(--text-muted);font-size:12px">Carregando…</div>
        </div>
      </div>

      <!-- AÇÕES -->
      <div class="filter-group">
        <div class="filter-group-header">Ações</div>
        <div id="dpAcoes"></div>
        <datalist id="dpCampoValorSugestoes">
          <option value="user.nome"></option>
          <option value="user.id"></option>
          <option value="user.email"></option>
          <option value="user.telefone"></option>
          <option value="user.turma"></option>
          <option value="user.codigo_turma"></option>
          <option value="user.data_live"></option>
          <option value="literal:valor fixo"></option>
          <option value="{{user.nome}} - {{user.turma}}"></option>
        </datalist>
        <button class="btn-add-filter" onclick="dpAddAcao()">+ Adicionar ação</button>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn btn-primary" style="flex:1" onclick="dpSalvar(false)">Salvar rascunho</button>
        <button class="btn btn-success" style="flex:1" onclick="dpSalvarExecutar()" id="btnSalvarExecutar">Salvar e Disparar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de progresso -->
<div class="dp-modal-overlay" id="dpProgressModal">
  <div class="dp-modal-box">
    <div style="font-size:18px;font-weight:700;margin-bottom:8px" id="dpProgressTitle">Disparando…</div>
    <div style="font-size:13px;color:var(--text-muted)" id="dpProgressSub">Aguarde…</div>
    <div class="dp-progress-bar-wrap">
      <div class="dp-progress-bar" id="dpProgressBar" style="width:0%"></div>
    </div>
    <div class="dp-modal-stats">
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatEnv">0</div><div class="dp-stat-lbl">Sucessos</div></div>
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatErr" style="color:#f87171">0</div><div class="dp-stat-lbl">Erros</div></div>
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatTot">—</div><div class="dp-stat-lbl">Total</div></div>
    </div>
    <button class="btn" style="margin-top:24px;display:none" id="dpProgressClose" onclick="dpFecharModal()">Fechar</button>
  </div>
</div>

<script>
const TURMAS = <?= json_encode($turmas) ?>;
const TAGS_SF = <?= json_encode(array_values($tagsSf), JSON_UNESCAPED_UNICODE) ?>;
const TAGS_SISTEMA = <?= json_encode(array_values($tagsSistema), JSON_UNESCAPED_UNICODE) ?>;
let dpLogica = 'AND';
let dpPreviewTimer = null;
let dpPreviewContatosOpen = false;
let dpExecutando = false; // flag global para parar loop

// ── Inicialização ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    dpCarregarLista();
    dpToggleTipo();
    // Marcar todos dias por padrão
    document.querySelectorAll('.dp-dia').forEach(cb => cb.checked = true);
});

// ── Lista ─────────────────────────────────────────────────────────────────────
async function dpCarregarLista() {
    const r = await fetch('disparos.php?acao=listar');
    const j = await r.json();
    const cont = document.getElementById('dpListContainer');
    if (!j.ok || !j.data.length) {
        cont.innerHTML = '<div class="dp-empty">Nenhum disparo criado ainda.<br>Clique em <strong>Novo Disparo</strong> para começar.</div>';
        return;
    }
    cont.innerHTML = j.data.map(d => {
        const meta = [
            `<span>${d.tipo === 'agendado' ? '📅 ' + dpFmtDate(d.agendado_em) : '⚡ Instantâneo'}</span>`,
            `<span>Enviados: ${d.total_enviados}</span>`,
            d.total_erros > 0 ? `<span style="color:#f87171">Erros: ${d.total_erros}</span>` : '',
            `<span>${dpFmtDate(d.criado_em)}</span>`,
        ].filter(Boolean).join('');

        const canRun   = ['rascunho','pausado','aguardando'].includes(d.status);
        const canPause = d.status === 'executando';
        const canResume= d.status === 'pausado';

        return `<div class="dp-card" id="dpCard${d.id}">
            <div class="dp-card-main">
            <div class="dp-card-info">
                <div class="dp-card-nome">${dpEsc(d.nome)}</div>
                <div class="dp-card-meta">
                    <span class="badge-st badge-${d.status}">${d.status}</span>
                    ${meta}
                </div>
            </div>
            <div class="dp-card-actions">
                <button class="btn btn-sm" onclick="dpToggleLogs(${d.id})" title="Logs do disparo">▾</button>
                ${canRun   ? `<button class="btn btn-sm btn-success" onclick="dpIniciarDisparo(${d.id})" title="Disparar">▶</button>` : ''}
                ${canPause ? `<button class="btn btn-sm" onclick="dpPararDisparo()" title="Pausar">⏸</button>` : ''}
                ${canResume? `<button class="btn btn-sm btn-success" onclick="dpIniciarDisparo(${d.id})" title="Retomar">▶</button>` : ''}
                <button class="btn btn-sm" onclick="dpEditarDisparo(${d.id})" title="Editar">✏️</button>
                <button class="btn btn-sm" onclick="dpClonarDisparo(${d.id})" title="Clonar">🗐</button>
                <button class="btn btn-sm btn-danger" onclick="dpDeletar(${d.id})" title="Excluir">🗑</button>
            </div>
            </div>
            <div class="dp-logs-panel" id="dpLogs${d.id}"></div>
        </div>`;
    }).join('');
}

// ── Form ──────────────────────────────────────────────────────────────────────
function dpShortResp(resp) {
    resp = String(resp || '').trim();
    if (!resp) return '-';
    try {
        const j = JSON.parse(resp);
        if (j.message) return j.message;
        if (j.error) return typeof j.error === 'string' ? j.error : JSON.stringify(j.error);
        if (j.msg) return j.msg;
        return JSON.stringify(j);
    } catch(e) {
        return resp;
    }
}

async function dpToggleLogs(id) {
    const panel = document.getElementById('dpLogs' + id);
    if (!panel) return;
    const opening = !panel.classList.contains('open');
    panel.classList.toggle('open', opening);
    if (!opening) return;

    panel.innerHTML = '<div style="color:var(--text-muted);font-size:12px">Carregando logs...</div>';
    try {
        const r = await fetch('disparos.php?acao=logs&id=' + encodeURIComponent(id));
        const j = await r.json();
        if (!j.ok) throw new Error(j.msg || 'Erro ao carregar logs');
        const s = j.summary || {};
        panel.innerHTML = `<div class="dp-log-metrics">
            <div class="dp-log-metric"><div class="dp-log-metric-label">Total processado</div><div class="dp-log-metric-value">${s.total || 0}</div></div>
            <div class="dp-log-metric"><div class="dp-log-metric-label">Sucessos</div><div class="dp-log-metric-value dp-log-ok">${s.ok || 0}</div></div>
            <div class="dp-log-metric"><div class="dp-log-metric-label">Erros</div><div class="dp-log-metric-value dp-log-erro">${s.erro || 0}</div></div>
            <div class="dp-log-metric"><div class="dp-log-metric-label">Taxa de acerto</div><div class="dp-log-metric-value">${s.taxa_ok || 0}%</div></div>
        </div>` + dpRenderLogsTable(j.data || []);
    } catch(e) {
        panel.innerHTML = `<div style="color:#f87171;font-size:12px">Erro ao carregar logs: ${dpEsc(e.message)}</div>`;
    }
}

function dpRenderLogsTable(rows) {
    if (!rows.length) return '<div style="color:var(--text-muted);font-size:12px">Nenhuma execucao registrada para este disparo.</div>';
    return `<div class="dp-log-table-wrap"><table class="dp-log-table">
        <thead><tr>
            <th style="width:72px">Status</th>
            <th style="width:170px">Contato</th>
            <th style="width:150px">Telefone</th>
            <th>Motivo / resposta</th>
            <th style="width:145px">Horario</th>
        </tr></thead>
        <tbody>${rows.map(row => `<tr>
            <td class="${row.status === 'ok' ? 'dp-log-ok' : 'dp-log-erro'}">${row.status === 'ok' ? 'OK' : 'ERRO'}</td>
            <td title="${dpEsc(row.email || '')}">${dpEsc(row.nome || row.email || ('ID ' + row.user_id))}<br><span style="color:var(--text-muted)">${dpEsc(row.email || '')}</span></td>
            <td>${dpEsc(row.telefone || '-')}</td>
            <td class="resp">${dpEsc(dpShortResp(row.resposta))}</td>
            <td>${dpFmtDate(row.executado_em)}</td>
        </tr>`).join('')}</tbody>
    </table></div>
    ${rows.length === 300 ? '<div style="color:var(--text-muted);font-size:11px;margin-top:6px">Mostrando os 300 registros mais recentes.</div>' : ''}`;
}

function dpNovoDisparo() {
    document.getElementById('dpId').value = 0;
    document.getElementById('dpNome').value = '';
    document.getElementById('dpTipo').value = 'instantaneo';
    document.getElementById('dpAgendadoEm').value = '';
    document.getElementById('dpIntervaloMs').value = 0;
    document.getElementById('dpHorarioAtivo').checked = false;
    document.getElementById('dpHorarioInicio').value = '08:00';
    document.getElementById('dpHorarioFim').value = '21:00';
    document.querySelectorAll('.dp-dia').forEach(cb => cb.checked = true);
    document.getElementById('dpFiltrosInc').innerHTML = '';
    document.getElementById('dpFiltrosExc').innerHTML = '';
    document.getElementById('dpAcoes').innerHTML = '';
    dpLogica = 'AND';
    dpRenderLogica();
    document.getElementById('dpFormTitle').textContent = 'Novo Disparo';
    document.getElementById('dpFormPanel').style.display = '';
    dpToggleTipo();
    dpToggleHorario();
    dpAtualizarPreview();
}

async function dpEditarDisparo(id) {
    const r = await fetch(`disparos.php?acao=get&id=${id}`);
    const j = await r.json();
    if (!j.ok) return alert('Erro ao carregar disparo');
    const d = j.data;
    document.getElementById('dpId').value = d.id;
    document.getElementById('dpNome').value = d.nome;
    document.getElementById('dpTipo').value = d.tipo;
    document.getElementById('dpAgendadoEm').value = d.agendado_em ? d.agendado_em.replace(' ','T').slice(0,16) : '';
    document.getElementById('dpIntervaloMs').value = d.intervalo_ms || 0;
    document.getElementById('dpHorarioAtivo').checked = parseInt(d.horario_ativo || 0) === 1;
    document.getElementById('dpHorarioInicio').value = d.horario_inicio ? d.horario_inicio.slice(0,5) : '08:00';
    document.getElementById('dpHorarioFim').value    = d.horario_fim    ? d.horario_fim.slice(0,5)    : '21:00';
    const dias = (d.dias_semana || '0,1,2,3,4,5,6').split(',').map(Number);
    document.querySelectorAll('.dp-dia').forEach(cb => { cb.checked = dias.includes(parseInt(cb.value)); });
    document.getElementById('dpFormTitle').textContent = 'Editar: ' + d.nome;
    document.getElementById('dpFormPanel').style.display = '';
    dpToggleTipo();
    dpToggleHorario();

    const filtros = JSON.parse(d.filtros_json || '{}');
    const acoes   = JSON.parse(d.acoes_json   || '[]');
    dpLogica = filtros.logica_inclusao || 'AND';
    dpRenderLogica();
    document.getElementById('dpFiltrosInc').innerHTML = '';
    document.getElementById('dpFiltrosExc').innerHTML = '';
    document.getElementById('dpAcoes').innerHTML = '';
    (filtros.inclusao || []).forEach(f => dpAddFiltroInc(f));
    (filtros.exclusao || []).forEach(f => dpAddFiltroExc(f));
    acoes.forEach(a => dpAddAcao(a));
    dpAtualizarPreview();
}

function dpFecharForm() {
    document.getElementById('dpFormPanel').style.display = 'none';
}

function dpToggleTipo() {
    const t = document.getElementById('dpTipo').value;
    document.getElementById('dpAgendadoWrap').style.display = t === 'agendado' ? '' : 'none';
    document.getElementById('btnSalvarExecutar').textContent = t === 'agendado' ? 'Salvar e Agendar' : 'Salvar e Disparar';
}

function dpToggleHorario() {
    const on = document.getElementById('dpHorarioAtivo').checked;
    document.getElementById('dpHorarioWrap').style.display = on ? '' : 'none';
}

function dpSetLogica(l) {
    dpLogica = l;
    dpRenderLogica();
    dpAtualizarPreview();
}

function dpRenderLogica() {
    document.getElementById('btnAnd').className = dpLogica === 'AND' ? 'active' : '';
    document.getElementById('btnOr').className  = dpLogica === 'OR'  ? 'active' : '';
}

// ── Filtros ───────────────────────────────────────────────────────────────────
const INC_TIPOS = [
    {v:'turma',       l:'Turma'},
    {v:'tag_sf',      l:'Tag SF'},
    {v:'tag_sistema', l:'Tag sistema'},
    {v:'inscricao_de',l:'Inscrição a partir de'},
    {v:'inscricao_ate',l:'Inscrição até'},
    {v:'ultimo_de',   l:'Último cadastro a partir de'},
    {v:'ultimo_ate',  l:'Último cadastro até'},
    {v:'qtd_min',     l:'Mín. inscrições'},
    {v:'qtd_max',     l:'Máx. inscrições'},
    {v:'tem_cert',    l:'Tem certificado'},
    {v:'nao_tem_cert',l:'Não tem certificado'},
    {v:'evento_webhook',l:'Evento webhook'},
];
const EXC_TIPOS = [
    {v:'tem_cert',    l:'Tem certificado'},
    {v:'tag_sf',      l:'Tag SF'},
    {v:'tag_sistema', l:'Tag sistema'},
    {v:'turma',       l:'Turma'},
    {v:'qtd_min',     l:'Mín. inscrições'},
    {v:'evento_webhook',l:'Evento webhook'},
    {v:'ja_recebeu',  l:'Já recebeu este disparo (ID)'},
];
const ACAO_TIPOS = [
    {v:'flow',       l:'Enviar fluxo SF (IDs, vírgula)'},
    {v:'tag_sf',     l:'Inserir tag SF'},
    {v:'tag_sistema',l:'Inserir tag sistema'},
    {v:'custom_field',l:'Atualizar campo personalizado SF'},
];

function dpBuildSelect(tipos, val) {
    return '<select onchange="dpAtualizarPreview()" style="min-width:160px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">'
        + tipos.map(t => `<option value="${t.v}"${val===t.v?' selected':''}>${t.l}</option>`).join('')
        + '</select>';
}

function dpBuildOptions(valores, selecionado) {
    const set = new Set((valores || []).filter(v => String(v || '').trim() !== '').map(String));
    const atual = String(selecionado || '');
    if (atual !== '') set.add(atual);
    return Array.from(set).sort((a, b) => a.localeCompare(b, 'pt-BR'))
        .map(v => `<option value="${dpEsc(v)}"${atual===v?' selected':''}>${dpEsc(v)}</option>`).join('');
}

function dpBuildTagSelect(tipo, valor) {
    const tags = tipo === 'tag_sistema' ? TAGS_SISTEMA : TAGS_SF;
    const label = tipo === 'tag_sistema' ? 'tag do sistema' : 'tag SF';
    const options = dpBuildOptions(tags, valor);
    const emptyText = tags.length ? `-- ${label} --` : 'Nenhuma tag cadastrada';
    return '<select onchange="dpAtualizarPreview()" style="flex:1;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">'
        + `<option value="">${emptyText}</option>`
        + options
        + '</select>';
}

function dpBuildValueInput(tipo, valor) {
    if (tipo === 'turma') {
        return '<select onchange="dpAtualizarPreview()" style="flex:1;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px"><option value="">-- turma --</option>'
            + TURMAS.map(t => `<option value="${t.codigo}"${valor===t.codigo?' selected':''}>${t.nome || t.codigo}</option>`).join('')
            + '</select>';
    }
    if (tipo === 'tag_sf' || tipo === 'tag_sistema') {
        return dpBuildTagSelect(tipo, valor);
    }
    if (['tem_cert','nao_tem_cert'].includes(tipo)) return '';
    let inputType = (tipo.includes('de') || tipo.includes('ate')) ? 'date' : 'text';
    if (tipo.includes('qtd') || tipo === 'ja_recebeu') inputType = 'number';
    let ph = inputType === 'date' ? 'YYYY-MM-DD' : 'valor';
    return `<input type="${inputType}" placeholder="${ph}" value="${dpEsc(valor||'')}" oninput="dpAtualizarPreview()" style="flex:1;min-width:80px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
}

function dpMakeFilterRow(tipos, data, cont) {
    const div  = document.createElement('div');
    div.className = 'filter-row';
    const tipo  = data ? data.tipo  : tipos[0].v;
    const valor = data ? data.valor : '';
    div.innerHTML = dpBuildSelect(tipos, tipo) + dpBuildValueInput(tipo, valor)
        + '<button class="btn-rm" onclick="this.parentNode.remove();dpAtualizarPreview()">×</button>';
    div.querySelector('select').addEventListener('change', function() {
        const oldV = div.querySelectorAll('select')[1] || div.querySelector('input');
        const tmp  = document.createElement('div');
        tmp.innerHTML = dpBuildValueInput(this.value, '');
        const ni = tmp.firstChild;
        const rm = div.querySelector('.btn-rm');
        if (oldV) div.replaceChild(ni || document.createTextNode(''), oldV);
        else if (ni) div.insertBefore(ni, rm);
        dpAtualizarPreview();
    });
    cont.appendChild(div);
    dpAtualizarPreview();
}

function dpAddFiltroInc(data) { dpMakeFilterRow(INC_TIPOS, data || null, document.getElementById('dpFiltrosInc')); }
function dpAddFiltroExc(data) { dpMakeFilterRow(EXC_TIPOS, data || null, document.getElementById('dpFiltrosExc')); }

function dpBuildAcaoInputs(tipo, data) {
    const valor = data ? (data.valor || '') : '';
    if (tipo === 'custom_field') {
        const campo = data ? (data.campo || '') : '';
        return `<input class="acao-campo" type="text" value="${dpEsc(campo)}" placeholder="campo no SF" style="flex:1;min-width:130px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`
            + `<input class="acao-valor" type="text" list="dpCampoValorSugestoes" value="${dpEsc(valor)}" placeholder="valor ou origem ex: user.nome" style="flex:1;min-width:150px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
    }
    return `<input class="acao-valor" type="text" value="${dpEsc(valor)}" placeholder="valor" style="flex:1;min-width:80px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
}

function dpAddAcao(data) {
    const cont = document.getElementById('dpAcoes');
    const div  = document.createElement('div');
    div.className = 'acao-row';
    const tipo  = data ? data.tipo  : 'flow';
    div.innerHTML = '<select style="min-width:160px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">'
        + ACAO_TIPOS.map(t => `<option value="${t.v}"${tipo===t.v?' selected':''}>${t.l}</option>`).join('')
        + `</select><span class="acao-inputs" style="display:flex;gap:6px;flex:1">${dpBuildAcaoInputs(tipo, data || {})}</span>`
        + '<button class="btn-rm" onclick="this.parentNode.remove()">×</button>';
    div.querySelector('select').addEventListener('change', function() {
        div.querySelector('.acao-inputs').innerHTML = dpBuildAcaoInputs(this.value, {});
    });
    cont.appendChild(div);
}

// ── Preview badge + contatos ──────────────────────────────────────────────────
function dpAtualizarPreview() {
    clearTimeout(dpPreviewTimer);
    dpPreviewTimer = setTimeout(async () => {
        const badge = document.getElementById('dpPreviewBadge');
        const fj = JSON.stringify(dpColetarFiltros());
        badge.textContent = '…';
        const fd = new FormData(); fd.append('acao','preview'); fd.append('filtros_json', fj);
        try {
            const j = await (await fetch('disparos.php', {method:'POST',body:fd})).json();
            badge.textContent = j.ok ? `${j.total_inclusao} leads` : '?';
            document.getElementById('dpPreviewContatosCount').textContent = j.ok ? `${j.total} contatos` : '?';
        } catch { badge.textContent = '?'; }
        if (dpPreviewContatosOpen) dpCarregarPreviewContatos();
    }, 600);
}

function dpTogglePreviewContatos() {
    const body  = document.getElementById('dpPreviewBody');
    const arrow = document.getElementById('dpPreviewArrow');
    dpPreviewContatosOpen = !dpPreviewContatosOpen;
    body.classList.toggle('open', dpPreviewContatosOpen);
    arrow.textContent = dpPreviewContatosOpen ? '▼' : '▶';
    if (dpPreviewContatosOpen) dpCarregarPreviewContatos();
}

async function dpCarregarPreviewContatos() {
    const cont = document.getElementById('dpPreviewContatosContent');
    cont.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:12px">Carregando…</div>';
    const fd = new FormData(); fd.append('acao','preview_contatos'); fd.append('filtros_json', JSON.stringify(dpColetarFiltros()));
    try {
        const j = await (await fetch('disparos.php', {method:'POST',body:fd})).json();
        if (!j.ok) { cont.innerHTML = `<div style="padding:12px;color:#f87171;font-size:12px">Erro: ${dpEsc(j.msg||'?')}</div>`; return; }
        if (!j.data.length) { cont.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:12px">Nenhum contato encontrado com estes filtros.</div>'; return; }
        cont.innerHTML = `<table class="dp-preview-table">
            <thead><tr>
                <th>Nome</th><th>E-mail</th><th>Telefone</th>
                <th>Turma</th><th>Último cadastro</th><th>Inscrições</th>
            </tr></thead>
            <tbody>${j.data.map(u => `<tr>
                <td>${dpEsc(u.nome||'—')}</td>
                <td style="color:#60a5fa">${dpEsc(u.email||'—')}</td>
                <td>${dpEsc(u.telefone||'—')}</td>
                <td>${dpEsc(u.ultima_turma||'—')}</td>
                <td style="font-size:11px">${dpEsc(u.ultimo_cadastro ? u.ultimo_cadastro.slice(0,16) : '—')}</td>
                <td style="text-align:center">${u.qtd_inscricoes||0}</td>
            </tr>`).join('')}</tbody>
        </table>
        ${j.data.length === 50 ? '<div style="padding:6px 10px;font-size:11px;color:var(--text-muted)">Mostrando primeiros 50 contatos</div>' : ''}`;
    } catch(e) { cont.innerHTML = `<div style="padding:12px;color:#f87171;font-size:12px">Erro: ${e.message}</div>`; }
}

// ── Coleta de dados do form ───────────────────────────────────────────────────
function dpColetarFiltros() {
    const inc = [];
    document.querySelectorAll('#dpFiltrosInc .filter-row').forEach(row => {
        const tipo  = row.querySelector('select')?.value || '';
        const vEl   = row.querySelectorAll('select')[1] || row.querySelector('input');
        const valor = vEl ? vEl.value : '';
        if (tipo) inc.push({tipo, valor});
    });
    const exc = [];
    document.querySelectorAll('#dpFiltrosExc .filter-row').forEach(row => {
        const tipo  = row.querySelector('select')?.value || '';
        const vEl   = row.querySelectorAll('select')[1] || row.querySelector('input');
        const valor = vEl ? vEl.value : '';
        if (tipo) exc.push({tipo, valor});
    });
    return {logica_inclusao: dpLogica, inclusao: inc, exclusao: exc};
}

function dpColetarAcoes() {
    const acoes = [];
    document.querySelectorAll('#dpAcoes .acao-row').forEach(row => {
        const tipo  = row.querySelector('select')?.value || '';
        const valor = row.querySelector('.acao-valor')?.value || '';
        if (tipo === 'custom_field') {
            const campo = row.querySelector('.acao-campo')?.value || '';
            if (campo.trim()) acoes.push({tipo, campo, valor});
        } else if (tipo) {
            acoes.push({tipo, valor});
        }
    });
    return acoes;
}

function dpColetarDias() {
    return Array.from(document.querySelectorAll('.dp-dia:checked')).map(cb => cb.value).join(',');
}

// ── Salvar ────────────────────────────────────────────────────────────────────
async function dpSalvar(retornaId) {
    const nome = document.getElementById('dpNome').value.trim();
    if (!nome) { alert('Informe um nome para o disparo'); return null; }

    const fd = new FormData();
    fd.append('acao',           'salvar');
    fd.append('id',             document.getElementById('dpId').value);
    fd.append('nome',           nome);
    fd.append('tipo',           document.getElementById('dpTipo').value);
    fd.append('agendado_em',    document.getElementById('dpAgendadoEm').value);
    fd.append('intervalo_ms',   document.getElementById('dpIntervaloMs').value);
    fd.append('filtros_json',   JSON.stringify(dpColetarFiltros()));
    fd.append('acoes_json',     JSON.stringify(dpColetarAcoes()));
    fd.append('horario_ativo',  document.getElementById('dpHorarioAtivo').checked ? 1 : 0);
    fd.append('horario_inicio', document.getElementById('dpHorarioInicio').value);
    fd.append('horario_fim',    document.getElementById('dpHorarioFim').value);
    fd.append('dias_semana',    dpColetarDias());

    const r = await fetch('disparos.php', {method:'POST', body:fd});
    const j = await r.json();
    if (!j.ok) { alert('Erro: ' + (j.msg||'desconhecido')); return null; }
    document.getElementById('dpId').value = j.id;
    dpCarregarLista();
    if (retornaId) return j.id;
    else dpFecharForm();
    return null;
}

async function dpSalvarExecutar() {
    const tipo = document.getElementById('dpTipo').value;
    const id   = await dpSalvar(true);
    if (!id) return;
    if (tipo === 'agendado') {
        // Apenas marca como aguardando, não dispara
        await dpSetStatus(id, 'aguardando');
        dpFecharForm();
    } else {
        dpIniciarDisparo(id);
    }
}

// ── Janela de horário ─────────────────────────────────────────────────────────
function dpDentroDoHorario(disparo) {
    if (!parseInt(disparo.horario_ativo || 0)) return true;
    const now  = new Date();
    const dow  = now.getDay(); // 0=dom
    const dias = (disparo.dias_semana || '0,1,2,3,4,5,6').split(',').map(Number);
    if (!dias.includes(dow)) return false;
    const hm   = now.getHours() * 60 + now.getMinutes();
    const ini  = dpHmToMin(disparo.horario_inicio || '00:00');
    const fim  = dpHmToMin(disparo.horario_fim    || '23:59');
    return hm >= ini && hm <= fim;
}

function dpHmToMin(hm) {
    const [h, m] = hm.split(':').map(Number);
    return h * 60 + (m || 0);
}

function dpProximoHorario(disparo) {
    const ini = disparo.horario_inicio || '08:00';
    const dias = (disparo.dias_semana || '0,1,2,3,4,5,6').split(',').map(Number);
    const now  = new Date();
    for (let i = 0; i < 8; i++) {
        const d = new Date(now);
        d.setDate(d.getDate() + i);
        if (dias.includes(d.getDay())) return `${['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][d.getDay()]} às ${ini}`;
    }
    return ini;
}

// ── Execução progressiva ──────────────────────────────────────────────────────
async function dpIniciarDisparo(id) {
    // Buscar dados do disparo para verificar janela de horário
    let disparo = {};
    try {
        const r = await fetch(`disparos.php?acao=get&id=${id}`);
        const j = await r.json();
        if (j.ok) disparo = j.data;
    } catch(e) {}

    dpFecharForm();
    dpExecutando = true;
    document.getElementById('dpProgressTitle').textContent = 'Disparando…';
    document.getElementById('dpProgressSub').textContent   = 'Preparando…';
    document.getElementById('dpProgressBar').style.width   = '0%';
    document.getElementById('dpStatEnv').textContent = '0';
    document.getElementById('dpStatErr').textContent = '0';
    document.getElementById('dpStatTot').textContent = '—';
    document.getElementById('dpProgressClose').style.display = 'none';
    document.getElementById('dpProgressModal').classList.add('visible');

    let offset = 0;
    let totalEnv = 0, totalErr = 0, totalGeral = null;
    const intervaloMs = Math.max(0, parseInt(disparo.intervalo_ms || 0));

    while (dpExecutando) {
        // Verificar janela de horário antes de cada lote
        if (!dpDentroDoHorario(disparo)) {
            const prox = dpProximoHorario(disparo);
            document.getElementById('dpProgressTitle').textContent = 'Aguardando horário…';
            document.getElementById('dpProgressSub').textContent   = `Fora da faixa de envio. Próximo: ${prox}`;
            // Aguardar 60s e tentar novamente
            await new Promise(res => setTimeout(res, 60000));
            if (!dpExecutando) break;
            continue;
        }

        const fd = new FormData();
        fd.append('acao',   'executar_batch');
        fd.append('id',     id);
        fd.append('offset', offset);
        let j;
        try {
            document.getElementById('dpProgressTitle').textContent = 'Executando…';
            const r = await fetch('disparos.php', {method:'POST', body:fd});
            j = await r.json();
        } catch (e) {
            document.getElementById('dpProgressSub').textContent = 'Erro de rede: ' + e.message;
            document.getElementById('dpProgressClose').style.display = '';
            break;
        }
        if (!j.ok) {
            document.getElementById('dpProgressTitle').textContent = 'Erro';
            document.getElementById('dpProgressSub').textContent = j.msg || 'Erro desconhecido';
            document.getElementById('dpProgressClose').style.display = '';
            break;
        }
        if (j.total !== null && j.total !== undefined) totalGeral = j.total;
        totalEnv += j.enviados;
        totalErr += j.erros;
        offset    = j.next_offset;

        document.getElementById('dpStatEnv').textContent = totalEnv;
        document.getElementById('dpStatErr').textContent = totalErr;
        if (totalGeral !== null) {
            document.getElementById('dpStatTot').textContent = totalGeral;
            const pct = totalGeral > 0 ? Math.min(100, Math.round(offset / totalGeral * 100)) : 100;
            document.getElementById('dpProgressBar').style.width = pct + '%';
            document.getElementById('dpProgressSub').textContent = `${offset} / ${totalGeral} processados`;
        }

        if (j.done) {
            dpExecutando = false;
            document.getElementById('dpProgressTitle').textContent = 'Concluído!';
            document.getElementById('dpProgressSub').textContent   = `${totalEnv} enviados, ${totalErr} erros`;
            document.getElementById('dpProgressBar').style.width   = '100%';
            document.getElementById('dpProgressClose').style.display = '';
            dpCarregarLista();
            break;
        }
        if (intervaloMs > 0) await new Promise(res => setTimeout(res, intervaloMs));
    }
}

function dpPararDisparo() {
    dpExecutando = false;
    document.getElementById('dpProgressTitle').textContent = 'Pausado';
    document.getElementById('dpProgressSub').textContent   = 'Disparo pausado pelo usuário';
    document.getElementById('dpProgressClose').style.display = '';
    dpCarregarLista();
}

function dpFecharModal() {
    document.getElementById('dpProgressModal').classList.remove('visible');
}

// ── Ações rápidas ─────────────────────────────────────────────────────────────
async function dpDeletar(id) {
    if (!confirm('Excluir este disparo e todo seu histórico?')) return;
    const fd = new FormData(); fd.append('acao','deletar'); fd.append('id',id);
    await fetch('disparos.php', {method:'POST',body:fd});
    dpCarregarLista();
}

async function dpClonarDisparo(id) {
    const fd = new FormData(); fd.append('acao','clonar'); fd.append('id',id);
    const r  = await fetch('disparos.php', {method:'POST',body:fd});
    const j  = await r.json();
    if (j.ok) { dpCarregarLista(); dpEditarDisparo(j.id); }
}

async function dpSetStatus(id, st) {
    const fd = new FormData(); fd.append('acao','set_status'); fd.append('id',id); fd.append('status',st);
    await fetch('disparos.php', {method:'POST',body:fd});
    dpCarregarLista();
}

// ── Utilitários ───────────────────────────────────────────────────────────────
function dpEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function dpFmtDate(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ','T'));
    return d.toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
