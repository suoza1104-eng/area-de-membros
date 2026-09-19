<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/disparos_engine.php';

session_start();
if (empty($_SESSION['admin_logado'])) {
    header('Location: login.php'); exit;
}
// Este arquivo so LE a sessao (auth acima) e nunca grava em $_SESSION.
// Liberamos o lock imediatamente para nao travar o resto do admin no mesmo
// navegador enquanto esta tela fica aberta monitorando o progresso.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = getPDO();

// O schema e o envio de fato sao compartilhados com o motor usado pelo cron
// (app/disparos_engine.php). Esta tela apenas cria/arma campanhas e monitora
// o progresso — quem processa os lotes e envia de verdade e' sempre o cron
// `disparos_manuais`, mesmo que esta pagina seja fechada logo em seguida.
disparos_engine_ensure_schema($pdo);

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
                case 'contato':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $incClauses[] = "(u.nome LIKE $pk OR u.email LIKE $pk OR u.telefone LIKE $pk)";
                        $params[$pk] = '%' . trim((string)$regra['valor']) . '%';
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
                case 'contato':
                    if (!empty($regra['valor'])) {
                        $pk = $nextP();
                        $excClauses[] = "(u.nome LIKE $pk OR u.email LIKE $pk OR u.telefone LIKE $pk)";
                        $params[$pk] = '%' . trim((string)$regra['valor']) . '%';
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

    // Envio de fato (audiencia -> provider -> tags) e feito por app/disparos_engine.php,
    // usado tanto pelo cron quanto pelas acoes abaixo. Nao ha mais um sender proprio aqui.

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
            $batch_size    = max(1, min(500, (int)($_POST['batch_size'] ?? 1)));
            $filtros_json  = $_POST['filtros_json']  ?? '{}';
            $acoes_json    = $_POST['acoes_json']    ?? '[]';
            $status        = ($id > 0) ? ($_POST['status'] ?? null) : 'rascunho';
            $horario_ativo = (int)($_POST['horario_ativo'] ?? 0);
            $horario_inicio = !empty($_POST['horario_inicio']) ? $_POST['horario_inicio'] : null;
            $horario_fim   = !empty($_POST['horario_fim'])    ? $_POST['horario_fim']    : null;
            $dias_semana   = preg_replace('/[^0-9,]/', '', $_POST['dias_semana'] ?? '0,1,2,3,4,5,6');
            if ($horario_ativo === 1) {
                if (!$horario_inicio || !$horario_fim) { echo json_encode(['ok'=>false,'msg'=>'Informe a janela de horario do disparo.']); exit; }
                if ($dias_semana === '') { echo json_encode(['ok'=>false,'msg'=>'Selecione ao menos um dia da semana para a janela de disparo.']); exit; }
            }

            if ($nome === '') { echo json_encode(['ok' => false, 'msg' => 'Nome obrigatório']); exit; }

            if ($id > 0) {
                if ($status !== null) {
                    $st = $pdo->prepare("UPDATE disparos SET nome=:nome, tipo=:tipo, agendado_em=:ag, intervalo_ms=:iv, batch_size=:bs, filtros_json=:fj, acoes_json=:aj, status=:st, horario_ativo=:ha, horario_inicio=:hi, horario_fim=:hf, dias_semana=:ds WHERE id=:id");
                    $st->execute([':nome'=>$nome,':tipo'=>$tipo,':ag'=>$agendado_em,':iv'=>$intervalo_ms,':bs'=>$batch_size,':fj'=>$filtros_json,':aj'=>$acoes_json,':st'=>$status,':ha'=>$horario_ativo,':hi'=>$horario_inicio,':hf'=>$horario_fim,':ds'=>$dias_semana,':id'=>$id]);
                } else {
                    $st = $pdo->prepare("UPDATE disparos SET nome=:nome, tipo=:tipo, agendado_em=:ag, intervalo_ms=:iv, batch_size=:bs, filtros_json=:fj, acoes_json=:aj, horario_ativo=:ha, horario_inicio=:hi, horario_fim=:hf, dias_semana=:ds WHERE id=:id");
                    $st->execute([':nome'=>$nome,':tipo'=>$tipo,':ag'=>$agendado_em,':iv'=>$intervalo_ms,':bs'=>$batch_size,':fj'=>$filtros_json,':aj'=>$acoes_json,':ha'=>$horario_ativo,':hi'=>$horario_inicio,':hf'=>$horario_fim,':ds'=>$dias_semana,':id'=>$id]);
                }
            } else {
                $st = $pdo->prepare("INSERT INTO disparos (nome, tipo, agendado_em, intervalo_ms, batch_size, filtros_json, acoes_json, horario_ativo, horario_inicio, horario_fim, dias_semana) VALUES (:nome,:tipo,:ag,:iv,:bs,:fj,:aj,:ha,:hi,:hf,:ds)");
                $st->execute([':nome'=>$nome,':tipo'=>$tipo,':ag'=>$agendado_em,':iv'=>$intervalo_ms,':bs'=>$batch_size,':fj'=>$filtros_json,':aj'=>$acoes_json,':ha'=>$horario_ativo,':hi'=>$horario_inicio,':hf'=>$horario_fim,':ds'=>$dias_semana]);
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
            $st = $pdo->prepare("INSERT INTO disparos (nome, tipo, agendado_em, intervalo_ms, batch_size, filtros_json, acoes_json, status, horario_ativo, horario_inicio, horario_fim, dias_semana) VALUES (:nome,:tipo,:ag,:iv,:bs,:fj,:aj,'rascunho',:ha,:hi,:hf,:ds)");
            $st->execute([':nome'=>'[Cópia] '.$row['nome'],':tipo'=>$row['tipo'],':ag'=>$row['agendado_em'],':iv'=>(int)($row['intervalo_ms'] ?? $row['intervalo_seg'] ?? 0),':bs'=>(int)($row['batch_size'] ?? 1),':fj'=>$row['filtros_json'],':aj'=>$row['acoes_json'],':ha'=>(int)($row['horario_ativo'] ?? 0),':hi'=>$row['horario_inicio'] ?? null,':hf'=>$row['horario_fim'] ?? null,':ds'=>$row['dias_semana'] ?? '0,1,2,3,4,5,6']);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            exit;

        // Mudar status (pausar, retomar, desativar, ativar)
        case 'set_status':
            $id     = (int)($_POST['id'] ?? 0);
            $novoSt = $_POST['status'] ?? '';
            $allowed = ['rascunho','aguardando','executando','pausado','concluido','erro'];
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
            try {
                $rows = $pdo->query("SELECT id, nome, status, tipo, agendado_em, total_enviados, total_erros, criado_em, horario_ativo, horario_inicio, horario_fim, dias_semana FROM disparos ORDER BY criado_em DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['ok'=>true,'data'=>$rows]);
            } catch (Throwable $e) {
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            exit;

        // Logs e resumo de um disparo
        case 'logs':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Disparo invalido']); exit; }
            try {
                $stDisp = $pdo->prepare("SELECT filtros_json FROM disparos WHERE id = :id LIMIT 1");
                $stDisp->execute([':id'=>$id]);
                $disparoRow = $stDisp->fetch(PDO::FETCH_ASSOC) ?: [];
                $filtrosResumo = json_decode((string)($disparoRow['filtros_json'] ?? '{}'), true) ?: [];
                $audienciaTotal = null;
                $audienciaRestante = null;
                if ($disparoRow) {
                    $awResumo = buildAudienceWhere($filtrosResumo, $pdo);
                    $stAud = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$awResumo['where']}");
                    $stAud->execute($awResumo['params']);
                    $audienciaTotal = (int)$stAud->fetchColumn();

                    $paramsRest = $awResumo['params'];
                    $paramsRest[':campaign'] = $id;
                    $stRest = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$awResumo['where']} AND NOT EXISTS (SELECT 1 FROM disparo_execucoes de_done WHERE de_done.disparo_id=:campaign AND de_done.user_id=u.id)");
                    $stRest->execute($paramsRest);
                    $audienciaRestante = (int)$stRest->fetchColumn();
                }

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
                        'audiencia_total' => $audienciaTotal,
                        'restante' => $audienciaRestante,
                        'taxa_ok' => $total > 0 ? round(($okTotal / $total) * 100, 1) : 0,
                        'taxa_erro' => $total > 0 ? round(($erroTotal / $total) * 100, 1) : 0,
                    ],
                    'data' => $rows,
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            exit;

        // Arma a campanha para o cron `disparos_manuais` processar (ver app/disparos_engine.php).
        // Nao envia nada diretamente aqui: quem manda as mensagens de fato e' sempre o cron,
        // por isso o disparo continua rodando ate' o fim mesmo que esta pagina seja fechada
        // logo em seguida.
        case 'iniciar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Disparo invalido']); exit; }
            $row = $pdo->prepare("SELECT status, total_enviados, total_erros FROM disparos WHERE id = :id");
            $row->execute([':id'=>$id]);
            $row = $row->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Disparo não encontrado']); exit; }

            // "status=aguardando" tambem acontece com uma campanha em andamento que so'
            // esta fora da janela de horario (o cron marca assim e retoma sozinho depois)
            // — nunca decidir "inicio novo" so' pelo status, ou clicar em "Disparar" de novo
            // apagaria o progresso ja enviado e reenviaria a mensagem pra quem ja recebeu.
            $jaTemProgresso = ((int)($row['total_enviados'] ?? 0) + (int)($row['total_erros'] ?? 0)) > 0;
            $resume = !empty($_POST['resume']) || $row['status'] === 'pausado' || $jaTemProgresso;
            if ($resume) {
                $pdo->prepare("UPDATE disparos SET status='aguardando', proximo_lote_em=NULL WHERE id=:id")->execute([':id'=>$id]);
            } else {
                $pdo->prepare("DELETE FROM disparo_execucoes WHERE disparo_id=:id")->execute([':id'=>$id]);
                $pdo->prepare("UPDATE disparos SET status='aguardando', total_enviados=0, total_erros=0, proximo_lote_em=NULL WHERE id=:id")->execute([':id'=>$id]);
            }
            echo json_encode(['ok'=>true]);
            exit;

        // Mantidas apenas por compatibilidade com paginas antigas que possam estar
        // em cache no navegador: nao enviam nada diretamente (isso evitaria disparo
        // duplicado se o cron ja estiver processando a mesma campanha ao mesmo tempo).
        // Elas so' garantem que a campanha esteja armada e devolvem o estado atual.
        case 'executar_background':
        case 'executar_batch':
            $id     = (int)($_POST['id'] ?? 0);
            $offset = (int)($_POST['offset'] ?? 0);
            $row = $pdo->prepare("SELECT * FROM disparos WHERE id = :id");
            $row->execute([':id'=>$id]);
            $row = $row->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Disparo não encontrado']); exit; }
            if (in_array($row['status'], ['rascunho','pausado'], true)) {
                $pdo->prepare("UPDATE disparos SET status='aguardando', proximo_lote_em=NULL WHERE id=:id")->execute([':id'=>$id]);
            }
            echo json_encode([
                'ok'          => true,
                'processados' => 0,
                'enviados'    => (int)($row['total_enviados'] ?? 0),
                'erros'       => (int)($row['total_erros'] ?? 0),
                'done'        => $row['status'] === 'concluido',
                'next_offset' => $offset,
                'msg'         => 'Este disparo agora e processado pelo servidor (cron), nao mais pelo navegador. Atualize a pagina para ver o progresso.',
            ]);
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

$eventosDisparo = [];
$eventosPorCanal = ['sf' => [], 'manychat' => [], 'webhook' => []];
foreach ([
    'sf' => "SELECT DISTINCT evento FROM superfuncionario_rules WHERE is_active = 1 AND evento IS NOT NULL AND evento <> ''",
    'manychat' => "SELECT DISTINCT evento FROM manychat_rules WHERE is_active = 1 AND evento IS NOT NULL AND evento <> ''",
    'webhook' => "SELECT DISTINCT evento FROM webhooks WHERE ativo = 1 AND evento IS NOT NULL AND evento <> ''",
] as $canal => $sqlEventos) {
    try {
        foreach ($pdo->query($sqlEventos)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $evRaw) {
            foreach (array_filter(array_map('trim', explode(',', (string)$evRaw))) as $ev) {
                if ($ev !== '') {
                    $eventosDisparo[$ev] = $ev;
                    $eventosPorCanal[$canal][$ev] = $ev;
                }
            }
        }
    } catch (Throwable $e) {}
}
$eventosDisparo = array_values($eventosDisparo);
sort($eventosDisparo, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($eventosPorCanal as $canal => $eventos) {
    $eventosPorCanal[$canal] = array_values($eventos);
    sort($eventosPorCanal[$canal], SORT_NATURAL | SORT_FLAG_CASE);
}

$campoValorGrupos = [
    'Payload fixo' => [
        'evento' => 'Evento do disparo',
        'timestamp' => 'Timestamp ISO',
        'literal:valor fixo' => 'Texto fixo',
        '{{user.nome}} - {{extra.codigo_turma}}' => 'Template',
    ],
    'Aluno' => [
        'user.id' => 'ID',
        'user.nome' => 'Nome',
        'user.email' => 'Email',
        'user.telefone' => 'Telefone',
        'user.magic_link' => 'Link direto de login',
    ],
    'Turma / Live' => [
        'extra.codigo_turma' => 'Codigo da turma',
        'extra.codigo_live' => 'Codigo da live',
        'extra.data_live' => 'Data da live',
        'extra.data_live_iso' => 'Data da live ISO',
        'extra.live_url' => 'URL da live',
        'extra.reagendamento_id' => 'ID do reagendamento',
    ],
    'Inscricao / Reinscricao' => [
        'extra.qtd_inscricoes' => 'Total de inscricoes',
        'extra.primeira_inscricao' => 'Primeira inscricao',
        'extra.data_inscricao_anterior' => 'Inscricao anterior',
        'extra.turma_anterior' => 'Turma anterior',
        'extra.eh_reinscrito' => '0 novo / 1 reinscrito',
    ],
    'Progresso' => [
        'extra.andamento' => 'Percentual de conclusao',
        'extra.aulas_concluidas' => 'Aulas concluidas',
        'extra.aulas_totais' => 'Total de aulas',
    ],
    'Certificado' => [
        'extra.pdf_url' => 'Link do PDF',
        'extra.codigo_certificado' => 'Codigo do certificado',
        'extra.curso' => 'Curso',
        'extra.emitido_em' => 'Data de emissao',
        'extra.certificado_id' => 'ID do certificado',
    ],
    'Retorno agendado' => [
        'extra.agendamento_id' => 'ID do agendamento',
        'extra.tipo' => 'Tipo',
        'extra.scheduled_at' => 'Data agendada',
        'extra.assunto' => 'Assunto',
        'extra.mensagem' => 'Mensagem original',
        'extra.mensagem_renderizada' => 'Mensagem renderizada',
        'extra.origem' => 'Origem',
    ],
    'Erro / suporte' => [
        'extra.motivo' => 'Motivo',
    ],
    'Users tabela' => [],
];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $name = trim((string)($col['Field'] ?? ''));
        if (preg_match('/(senha|password|token|secret|hash|key)/i', $name)) continue;
        if ($name !== '') $campoValorGrupos['Users tabela']['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {}

$campoValorDatalist = [];
foreach ($campoValorGrupos as $grupo => $campos) {
    foreach ($campos as $valor => $label) {
        $campoValorDatalist[$valor] = $grupo . ' - ' . $label;
    }
}

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
.dp-field-ref {
    border: 1px solid var(--border);
    border-radius: 8px;
    background: rgba(255,255,255,.025);
    margin-bottom: 10px;
}
.dp-field-ref summary {
    cursor: pointer;
    padding: 9px 10px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.dp-field-ref-body {
    border-top: 1px solid var(--border);
    padding: 10px;
    max-height: 260px;
    overflow: auto;
}
.dp-field-group { margin-bottom: 12px; }
.dp-field-group-title {
    font-size: 10px;
    font-weight: 800;
    color: #93c5fd;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 6px;
}
.dp-field-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
    gap: 6px;
}
.dp-field-token {
    border: 1px solid rgba(148,163,184,.18);
    border-radius: 6px;
    background: rgba(15,23,42,.65);
    padding: 6px 7px;
    font-size: 11px;
    color: #e2e8f0;
    cursor: pointer;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dp-field-token:hover { border-color: rgba(250,204,21,.45); background: rgba(250,204,21,.08); }
.dp-field-token small { display: block; color: var(--text-muted); font-size: 10px; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dp-field-help { color: var(--text-muted); font-size: 11px; line-height: 1.6; margin-bottom: 10px; }
.dp-field-help code { background: rgba(255,255,255,.06); border-radius: 4px; padding: 1px 5px; font-size: 11px; }
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
    position: relative;
}
.dp-modal-x {
    position: absolute; right: 12px; top: 10px;
    background: transparent; border: none; color: var(--text-muted);
    font-size: 22px; line-height: 1; cursor: pointer;
}
.dp-modal-x:hover { color: var(--text); }
.dp-progress-bar-wrap { background: #1a1a2a; border-radius: 8px; height: 12px; margin: 16px 0; overflow: hidden; }
.dp-progress-bar { height: 100%; background: linear-gradient(90deg, #6366f1, #8b5cf6); border-radius: 8px; transition: width .3s; }
.dp-modal-stats { display: flex; gap: 24px; justify-content: center; margin-top: 10px; }
.dp-stat { text-align: center; }
.dp-stat-val { font-size: 22px; font-weight: 700; }
.dp-stat-lbl { font-size: 11px; color: var(--text-muted); }
.dp-progress-actions { display:flex; gap:10px; justify-content:center; margin-top:24px; flex-wrap:wrap; }

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
        <label>Canal do disparo</label>
        <select id="dpProvider" onchange="dpAtualizarEventoPadrao()">
          <option value="sf">SF</option>
          <option value="manychat">ManyChat</option>
          <option value="webhook">Webhook</option>
        </select>
      </div>

      <div class="form-row">
        <label>Evento do disparo</label>
        <input type="text" id="dpEvento" list="dpEventoSugestoes" value="DISPARO_MANUAL" placeholder="DISPARO_MANUAL">
        <datalist id="dpEventoSugestoes">
          <?php foreach ($eventosDisparo as $ev): ?>
          <option value="<?= htmlspecialchars((string)$ev, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Intervalo entre lotes (ms) <span style="color:var(--text-muted)">[ex: 2000 = 2s]</span></label>
          <input type="number" id="dpIntervaloMs" value="0" min="0" step="100">
        </div>
        <div>
          <label>Disparos por lote <span style="color:var(--text-muted)">[1 = um a um]</span></label>
          <input type="number" id="dpBatchSize" value="1" min="1" max="500" step="1">
        </div>
      </div>

      <div class="form-row">
        <div style="display:flex;align-items:center;gap:10px">
          <label class="toggle-switch">
            <input type="checkbox" id="dpHorarioAtivo" onchange="dpToggleHorario()">
            <span class="toggle-slider"></span>
          </label>
          <span style="font-size:13px;font-weight:600">Usar janela de horário</span>
        </div>
        <div class="horario-wrap" id="dpHorarioWrap" style="display:none">
          <div style="font-size:11px;color:var(--text-muted);line-height:1.5;margin-bottom:10px">
            Quando ativo, o disparo roda apenas dentro da janela. Fora dela, fica aguardando e o cron retoma automaticamente no próximo horário permitido.
          </div>
          <div style="display:flex;gap:10px;margin-bottom:10px;align-items:center">
            <div style="flex:1">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">De</label>
              <input type="time" id="dpHorarioInicio" value="08:00" style="width:100%;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 8px;font-size:13px">
            </div>
            <div style="flex:1">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">Até</label>
              <input type="time" id="dpHorarioFim" value="18:00" style="width:100%;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 8px;font-size:13px">
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
        <details class="dp-field-ref">
          <summary>Campos disponiveis para valor/origem</summary>
          <div class="dp-field-ref-body">
            <div class="dp-field-help">
              Use direto como <code>user.magic_link</code>, fixo como <code>literal:texto</code>, ou template como <code>{{user.nome}} - {{extra.codigo_turma}}</code>.
              Clique em um campo para preencher o valor da ultima acao de campo personalizado.
            </div>
            <?php foreach ($campoValorGrupos as $grupo => $campos): if (!$campos) continue; ?>
              <div class="dp-field-group">
                <div class="dp-field-group-title"><?= htmlspecialchars((string)$grupo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="dp-field-list">
                  <?php foreach ($campos as $valorCampo => $labelCampo): ?>
                    <button type="button" class="dp-field-token" onclick="dpUsarCampoValor('<?= htmlspecialchars((string)$valorCampo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>')">
                      <?= htmlspecialchars((string)$valorCampo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <small><?= htmlspecialchars((string)$labelCampo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
        <div id="dpAcoes"></div>
        <datalist id="dpCampoValorSugestoes">
          <?php foreach ($campoValorDatalist as $valorCampo => $labelCampo): ?>
          <option value="<?= htmlspecialchars((string)$valorCampo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" label="<?= htmlspecialchars((string)$labelCampo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
          <?php endforeach; ?>
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
    <button type="button" class="dp-modal-x" onclick="dpFecharModal()" title="Fechar">×</button>
    <div style="font-size:18px;font-weight:700;margin-bottom:8px" id="dpProgressTitle">Disparando…</div>
    <div style="font-size:13px;color:var(--text-muted)" id="dpProgressSub">Aguarde…</div>
    <div class="dp-progress-bar-wrap">
      <div class="dp-progress-bar" id="dpProgressBar" style="width:0%"></div>
    </div>
    <div class="dp-modal-stats">
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatEnv">0</div><div class="dp-stat-lbl">Sucessos</div></div>
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatErr" style="color:#f87171">0</div><div class="dp-stat-lbl">Erros</div></div>
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatTot">—</div><div class="dp-stat-lbl">Total</div></div>
      <div class="dp-stat"><div class="dp-stat-val" id="dpStatRest">—</div><div class="dp-stat-lbl">Faltam</div></div>
    </div>
    <div class="dp-progress-actions">
      <button class="btn" id="dpProgressPause" onclick="dpPausarDisparo()">Pausar</button>
      <button class="btn btn-danger" id="dpProgressAbort" onclick="dpAbortarDisparo()">Abortar</button>
      <button class="btn" style="display:none" id="dpProgressClose" onclick="dpFecharModal()">Fechar</button>
    </div>
  </div>
</div>

<script>
const TURMAS = <?= json_encode($turmas) ?>;
const TAGS_SF = <?= json_encode(array_values($tagsSf), JSON_UNESCAPED_UNICODE) ?>;
const TAGS_SISTEMA = <?= json_encode(array_values($tagsSistema), JSON_UNESCAPED_UNICODE) ?>;
const EVENTOS_POR_CANAL = <?= json_encode($eventosPorCanal, JSON_UNESCAPED_UNICODE) ?>;
let dpLogica = 'AND';
let dpPreviewTimer = null;
let dpPreviewContatosOpen = false;
let dpExecutando = false; // flag global para parar loop
let dpExecState = null;
let dpMonitorTimer = null;

// ── Inicialização ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    dpCarregarLista();
    dpToggleTipo();
    // Marcar todos dias por padrão
    document.querySelectorAll('.dp-dia').forEach(cb => cb.checked = true);
});

// ── Lista ─────────────────────────────────────────────────────────────────────
async function dpCarregarLista() {
    const cont = document.getElementById('dpListContainer');
    let j;
    const ctrl = new AbortController();
    const timeoutId = setTimeout(() => ctrl.abort(), 8000);
    try {
        const r = await fetch('disparos.php?acao=listar&_=' + Date.now(), {
            cache: 'no-store',
            credentials: 'same-origin',
            signal: ctrl.signal
        });
        const txt = await r.text();
        try { j = JSON.parse(txt); }
        catch (e) { throw new Error('Resposta invalida do servidor ao listar disparos'); }
        if (!r.ok || !j.ok) throw new Error((j && j.msg) ? j.msg : 'Falha ao carregar disparos');
    } catch (e) {
        const msg = e && e.name === 'AbortError' ? 'Tempo esgotado ao carregar disparos' : (e.message || e);
        cont.innerHTML = `<div class="dp-empty" style="color:#f87171">Erro ao carregar disparos.<br>${dpEsc(msg)}</div>`;
        return;
    } finally {
        clearTimeout(timeoutId);
    }
    if (!j.ok || !j.data.length) {
        cont.innerHTML = '<div class="dp-empty">Nenhum disparo criado ainda.<br>Clique em <strong>Novo Disparo</strong> para começar.</div>';
        return;
    }
    cont.innerHTML = j.data.map(d => {
        const meta = [
            `<span>${d.tipo === 'agendado' ? '📅 ' + dpFmtDate(d.agendado_em) : '⚡ Instantâneo'}</span>`,
            parseInt(d.horario_ativo || 0) ? `<span>Janela ${dpFmtHorario(d)}</span>` : '',
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
                <button class="btn btn-sm" onclick="dpMostrarStatus(${d.id})" title="Status do disparo">▾</button>
                <button class="btn btn-sm" onclick="dpMostrarStatus(${d.id})" title="Status do disparo">↗</button>
                ${canRun   ? `<button class="btn btn-sm btn-success" onclick="dpIniciarDisparo(${d.id})" title="Disparar">▶</button>` : ''}
                ${canPause ? `<button class="btn btn-sm" onclick="dpPausarDisparo(${d.id})" title="Pausar">⏸</button>` : ''}
                ${canResume? `<button class="btn btn-sm btn-success" onclick="dpIniciarDisparo(${d.id}, {resume:true})" title="Retomar">▶</button>` : ''}
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
    document.getElementById('dpProvider').value = 'sf';
    document.getElementById('dpEvento').value = 'DISPARO_MANUAL';
    document.getElementById('dpAgendadoEm').value = '';
    document.getElementById('dpIntervaloMs').value = 0;
    document.getElementById('dpBatchSize').value = 1;
    document.getElementById('dpHorarioAtivo').checked = false;
    document.getElementById('dpHorarioInicio').value = '08:00';
    document.getElementById('dpHorarioFim').value = '18:00';
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
    document.getElementById('dpBatchSize').value = d.batch_size || 1;
    document.getElementById('dpHorarioAtivo').checked = parseInt(d.horario_ativo || 0) === 1;
    document.getElementById('dpHorarioInicio').value = d.horario_inicio ? d.horario_inicio.slice(0,5) : '08:00';
    document.getElementById('dpHorarioFim').value    = d.horario_fim    ? d.horario_fim.slice(0,5)    : '18:00';
    const dias = (d.dias_semana || '0,1,2,3,4,5,6').split(',').map(Number);
    document.querySelectorAll('.dp-dia').forEach(cb => { cb.checked = dias.includes(parseInt(cb.value)); });
    document.getElementById('dpFormTitle').textContent = 'Editar: ' + d.nome;
    document.getElementById('dpFormPanel').style.display = '';
    dpToggleTipo();
    dpToggleHorario();

    const filtros = JSON.parse(d.filtros_json || '{}');
    const acoes   = JSON.parse(d.acoes_json   || '[]');
    const providerAction = acoes.find(a => a && a.tipo === 'provider');
    const eventoAction = acoes.find(a => a && a.tipo === 'evento');
    document.getElementById('dpProvider').value = providerAction ? (providerAction.valor || 'sf') : 'sf';
    document.getElementById('dpEvento').value = eventoAction ? (eventoAction.valor || 'DISPARO_MANUAL') : 'DISPARO_MANUAL';
    if (!eventoAction) dpAtualizarEventoPadrao(true);
    dpLogica = filtros.logica_inclusao || 'AND';
    dpRenderLogica();
    document.getElementById('dpFiltrosInc').innerHTML = '';
    document.getElementById('dpFiltrosExc').innerHTML = '';
    document.getElementById('dpAcoes').innerHTML = '';
    (filtros.inclusao || []).forEach(f => dpAddFiltroInc(f));
    (filtros.exclusao || []).forEach(f => dpAddFiltroExc(f));
    acoes.filter(a => !a || !['provider', 'evento'].includes(a.tipo)).forEach(a => dpAddAcao(a));
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

function dpAtualizarEventoPadrao(force = false) {
    const provider = document.getElementById('dpProvider').value || 'sf';
    const evento = document.getElementById('dpEvento');
    const sugestoes = EVENTOS_POR_CANAL[provider] || [];
    if (sugestoes.length && (force || !evento.value.trim() || evento.value.trim() === 'DISPARO_MANUAL')) {
        evento.value = sugestoes[0];
    } else if (!evento.value.trim()) {
        evento.value = 'DISPARO_MANUAL';
    }
    dpAtualizarAcoesPorCanal();
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
    {v:'contato',     l:'Nome/e-mail/telefone'},
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
    {v:'contato',     l:'Nome/e-mail/telefone'},
    {v:'tem_cert',    l:'Tem certificado'},
    {v:'tag_sf',      l:'Tag SF'},
    {v:'tag_sistema', l:'Tag sistema'},
    {v:'turma',       l:'Turma'},
    {v:'qtd_min',     l:'Mín. inscrições'},
    {v:'evento_webhook',l:'Evento webhook'},
    {v:'ja_recebeu',  l:'Já recebeu este disparo (ID)'},
];
const ACAO_TIPOS_POR_CANAL = {
    sf: [
        {v:'flow',       l:'Enviar fluxo SF (IDs, vírgula)'},
        {v:'tag_sf',     l:'Inserir tag SF'},
        {v:'tag_sistema',l:'Inserir tag sistema'},
        {v:'custom_field',l:'Atualizar campo personalizado SF'},
    ],
    manychat: [
        {v:'flow',       l:'Enviar fluxo ManyChat (Flow NS, vírgula)'},
        {v:'tag_sf',     l:'Inserir tag ManyChat'},
        {v:'tag_sistema',l:'Inserir tag sistema'},
        {v:'custom_field',l:'Atualizar campo personalizado ManyChat'},
    ],
    webhook: [
        {v:'tag_sistema',l:'Inserir tag sistema'},
    ],
};

function dpAcaoTipos() {
    const provider = document.getElementById('dpProvider')?.value || 'sf';
    return ACAO_TIPOS_POR_CANAL[provider] || ACAO_TIPOS_POR_CANAL.sf;
}

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
    if (tipo === 'contato') {
        return `<input type="text" placeholder="nome, e-mail ou telefone" value="${dpEsc(valor||'')}" oninput="dpAtualizarPreview()" style="flex:1;min-width:120px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
    }
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

function dpUsarCampoValor(valor) {
    const rows = Array.from(document.querySelectorAll('#dpAcoes .acao-row'));
    let input = null;
    for (let i = rows.length - 1; i >= 0; i--) {
        const row = rows[i];
        if (row.querySelector('select')?.value === 'custom_field') {
            input = row.querySelector('.acao-valor');
            break;
        }
    }
    if (!input) {
        dpAddAcao({tipo:'custom_field', campo:'', valor:''});
        const last = Array.from(document.querySelectorAll('#dpAcoes .acao-row')).pop();
        input = last ? last.querySelector('.acao-valor') : null;
    }
    if (input) {
        input.value = valor;
        input.focus();
        dpAtualizarPreview();
    }
}

function dpBuildAcaoInputs(tipo, data) {
    const valor = data ? (data.valor || '') : '';
    if (tipo === 'custom_field') {
        const campo = data ? (data.campo || '') : '';
        const provider = document.getElementById('dpProvider')?.value || 'sf';
        const campoPh = provider === 'manychat' ? 'campo no ManyChat' : 'campo no SF';
        return `<input class="acao-campo" type="text" value="${dpEsc(campo)}" placeholder="${campoPh}" style="flex:1;min-width:130px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`
            + `<input class="acao-valor" type="text" list="dpCampoValorSugestoes" value="${dpEsc(valor)}" placeholder="valor ou origem ex: user.nome" style="flex:1;min-width:150px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
    }
    return `<input class="acao-valor" type="text" value="${dpEsc(valor)}" placeholder="valor" style="flex:1;min-width:80px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">`;
}

function dpAddAcao(data) {
    const cont = document.getElementById('dpAcoes');
    const div  = document.createElement('div');
    div.className = 'acao-row';
    const tipos = dpAcaoTipos();
    const tipo  = data && tipos.some(t => t.v === data.tipo) ? data.tipo : tipos[0].v;
    div.innerHTML = '<select style="min-width:160px;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:5px 8px;font-size:13px">'
        + tipos.map(t => `<option value="${t.v}"${tipo===t.v?' selected':''}>${t.l}</option>`).join('')
        + `</select><span class="acao-inputs" style="display:flex;gap:6px;flex:1">${dpBuildAcaoInputs(tipo, data || {})}</span>`
        + '<button class="btn-rm" onclick="this.parentNode.remove()">×</button>';
    div.querySelector('select').addEventListener('change', function() {
        div.querySelector('.acao-inputs').innerHTML = dpBuildAcaoInputs(this.value, {});
    });
    cont.appendChild(div);
}

function dpAtualizarAcoesPorCanal() {
    const tipos = dpAcaoTipos();
    document.querySelectorAll('#dpAcoes .acao-row').forEach(row => {
        const select = row.querySelector('select');
        if (!select) return;
        const previous = select.value;
        const next = tipos.some(t => t.v === previous) ? previous : tipos[0].v;
        select.innerHTML = tipos.map(t => `<option value="${t.v}"${next===t.v?' selected':''}>${t.l}</option>`).join('');
        select.value = next;
        row.querySelector('.acao-inputs').innerHTML = dpBuildAcaoInputs(next, {});
    });
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
    acoes.push({tipo: 'provider', valor: document.getElementById('dpProvider').value || 'sf'});
    acoes.push({tipo: 'evento', valor: document.getElementById('dpEvento').value.trim() || 'DISPARO_MANUAL'});
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
    fd.append('batch_size',     document.getElementById('dpBatchSize').value);
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

// A janela de horario (dias/horario_inicio/horario_fim) e' checada sempre no
// servidor (disparos_engine_within_window, compartilhada com o cron) — nao ha
// necessidade de duplicar essa regra aqui no navegador.

// ── Execução progressiva ──────────────────────────────────────────────────────
// Inicia (ou retoma) uma campanha e sai. Quem de fato manda as mensagens, lote a
// lote, e' sempre o cron `disparos_manuais` no servidor (app/disparos_engine.php) —
// esta funcao so arma a campanha e fica monitorando o progresso. Por isso o disparo
// continua ate' o fim mesmo que esta aba seja fechada logo em seguida.
async function dpIniciarDisparo(id, opts) {
    opts = opts || {};
    let disparo = {};
    try {
        const r = await fetch(`disparos.php?acao=get&id=${id}&_=${Date.now()}`, {cache:'no-store'});
        const j = await r.json();
        if (j.ok) disparo = j.data;
    } catch(e) {}

    const jaTemProgresso = (parseInt(disparo.total_enviados || 0) + parseInt(disparo.total_erros || 0)) > 0;
    const resume = !!(opts.resume || disparo.status === 'pausado' || jaTemProgresso);
    const ok = resume ? parseInt(disparo.total_enviados || 0) : 0;
    const er = resume ? parseInt(disparo.total_erros || 0) : 0;

    dpFecharForm();
    dpExecutando = true;
    dpExecState = {id, offset:ok + er, totalEnv:ok, totalErr:er, totalGeral:null, status:'executando'};
    document.getElementById('dpProgressTitle').textContent = 'Iniciando…';
    document.getElementById('dpProgressSub').textContent   = 'Armando disparo no servidor...';
    document.getElementById('dpProgressBar').style.width   = '0%';
    document.getElementById('dpStatEnv').textContent = ok;
    document.getElementById('dpStatErr').textContent = er;
    document.getElementById('dpStatTot').textContent = '-';
    document.getElementById('dpStatRest').textContent = '-';
    document.getElementById('dpProgressPause').style.display = '';
    document.getElementById('dpProgressAbort').style.display = '';
    document.getElementById('dpProgressClose').style.display = 'none';
    document.getElementById('dpProgressModal').classList.add('visible');

    if (dpMonitorTimer) clearInterval(dpMonitorTimer);

    const fd = new FormData();
    fd.append('acao', 'iniciar');
    fd.append('id', id);
    fd.append('resume', resume ? '1' : '0');

    try {
        const r = await fetch('disparos.php', {method:'POST', body:fd});
        const j = await r.json();
        if (!j.ok) throw new Error(j.msg || 'Erro ao iniciar disparo');
    } catch(e) {
        dpExecutando = false;
        document.getElementById('dpProgressTitle').textContent = 'Erro';
        document.getElementById('dpProgressSub').textContent = e.message;
        document.getElementById('dpProgressPause').style.display = 'none';
        document.getElementById('dpProgressAbort').style.display = 'none';
        document.getElementById('dpProgressClose').style.display = '';
        return;
    }

    document.getElementById('dpProgressTitle').textContent = 'Executando em segundo plano';
    document.getElementById('dpProgressSub').textContent   = 'O servidor processa este disparo mesmo se voce fechar a tela.';
    await dpAtualizarProgressoDisparo(id);
    dpMonitorTimer = setInterval(() => dpAtualizarProgressoDisparo(id), 2500);
}

async function dpBuscarResumoDisparo(id) {
    try {
        const r = await fetch(`disparos.php?acao=logs&id=${id}&_=${Date.now()}`, {cache:'no-store'});
        const j = await r.json();
        return j.ok ? (j.summary || {}) : {};
    } catch(e) {
        return {};
    }
}

async function dpAtualizarProgressoDisparo(id) {
    if (!id) return;
    let d = null;
    try {
        const r = await fetch(`disparos.php?acao=get&id=${id}&_=${Date.now()}`, {cache:'no-store'});
        const j = await r.json();
        if (j.ok) d = j.data;
    } catch(e) {}
    if (!d) return;

    const ok = parseInt(d.total_enviados || 0);
    const er = parseInt(d.total_erros || 0);
    const processed = ok + er;
    const resumo = await dpBuscarResumoDisparo(id);
    const totalAud = resumo.audiencia_total !== null && resumo.audiencia_total !== undefined ? parseInt(resumo.audiencia_total || 0) : null;
    const restante = resumo.restante !== null && resumo.restante !== undefined ? parseInt(resumo.restante || 0) : null;
    const doneAud = totalAud !== null && restante !== null ? Math.max(0, totalAud - restante) : processed;
    const pct = totalAud && totalAud > 0 ? Math.min(100, Math.round((doneAud / totalAud) * 100)) : null;
    const st = d.status || 'executando';
    dpExecState = {id, offset:processed, totalEnv:ok, totalErr:er, totalGeral:totalAud, restante, status:st};

    document.getElementById('dpStatEnv').textContent = ok;
    document.getElementById('dpStatErr').textContent = er;
    document.getElementById('dpStatTot').textContent = totalAud !== null ? totalAud : (processed || '-');
    document.getElementById('dpStatRest').textContent = restante !== null ? restante : '-';
    document.getElementById('dpProgressSub').textContent = totalAud !== null ? `${processed} processados de ${totalAud}` : `${processed} processados`;
    document.getElementById('dpProgressBar').style.width = st === 'concluido' ? '100%' : ((pct !== null ? pct : 35) + '%');
    document.getElementById('dpProgressPause').style.display = st === 'executando' ? '' : 'none';
    document.getElementById('dpProgressAbort').style.display = st === 'executando' ? '' : 'none';
    document.getElementById('dpProgressClose').style.display = st === 'executando' ? 'none' : '';

    if (st === 'executando') {
        document.getElementById('dpProgressTitle').textContent = 'Executando em segundo plano';
    } else if (st === 'concluido') {
        dpExecutando = false;
        document.getElementById('dpProgressTitle').textContent = 'Concluido!';
        document.getElementById('dpProgressSub').textContent = `${ok} enviados, ${er} erros`;
    } else if (st === 'aguardando') {
        document.getElementById('dpProgressTitle').textContent = 'Aguardando horario';
        document.getElementById('dpProgressSub').textContent = `${processed} processados. O disparo continuara quando for retomado.`;
    } else if (st === 'pausado') {
        document.getElementById('dpProgressTitle').textContent = 'Pausado';
    } else if (st === 'erro') {
        document.getElementById('dpProgressTitle').textContent = 'Interrompido';
    }

    if (st !== 'executando') {
        if (dpMonitorTimer) clearInterval(dpMonitorTimer);
        dpMonitorTimer = null;
        dpCarregarLista();
    }
}

async function dpPausarDisparo(id) {
    dpExecutando = false;
    if (dpMonitorTimer) clearInterval(dpMonitorTimer);
    dpMonitorTimer = null;
    id = id || (dpExecState ? dpExecState.id : 0);
    if (id) await dpSetStatus(id, 'pausado');
    document.getElementById('dpProgressTitle').textContent = 'Pausado';
    document.getElementById('dpProgressSub').textContent = 'Disparo pausado pelo usuario';
    document.getElementById('dpProgressPause').style.display = 'none';
    document.getElementById('dpProgressAbort').style.display = 'none';
    document.getElementById('dpProgressClose').style.display = '';
    dpCarregarLista();
}

async function dpAbortarDisparo(id) {
    if (!confirm('Abortar este disparo agora?')) return;
    dpExecutando = false;
    if (dpMonitorTimer) clearInterval(dpMonitorTimer);
    dpMonitorTimer = null;
    id = id || (dpExecState ? dpExecState.id : 0);
    if (id) await dpSetStatus(id, 'erro');
    document.getElementById('dpProgressTitle').textContent = 'Abortado';
    document.getElementById('dpProgressSub').textContent = 'Disparo abortado pelo usuario';
    document.getElementById('dpProgressPause').style.display = 'none';
    document.getElementById('dpProgressAbort').style.display = 'none';
    document.getElementById('dpProgressClose').style.display = '';
    dpCarregarLista();
}

function dpPararDisparo() {
    return dpPausarDisparo();
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
async function dpMostrarStatus(id) {
    let d = null;
    try {
        const r = await fetch(`disparos.php?acao=get&id=${id}&_=${Date.now()}`, {cache:'no-store'});
        const j = await r.json();
        if (j.ok) d = j.data;
    } catch(e) {}
    if (!d) return alert('Nao foi possivel carregar o status do disparo');

    const ok = parseInt(d.total_enviados || 0);
    const er = parseInt(d.total_erros || 0);
    const processed = ok + er;
    const resumo = await dpBuscarResumoDisparo(id);
    const totalAud = resumo.audiencia_total !== null && resumo.audiencia_total !== undefined ? parseInt(resumo.audiencia_total || 0) : null;
    const restante = resumo.restante !== null && resumo.restante !== undefined ? parseInt(resumo.restante || 0) : null;
    const doneAud = totalAud !== null && restante !== null ? Math.max(0, totalAud - restante) : processed;
    const pct = totalAud && totalAud > 0 ? Math.min(100, Math.round((doneAud / totalAud) * 100)) : null;
    document.getElementById('dpProgressTitle').textContent = 'Status do disparo';
    document.getElementById('dpProgressSub').textContent = totalAud !== null ? `${d.status} - ${processed} processados de ${totalAud}` : `${d.status} - ${processed} processados`;
    document.getElementById('dpStatEnv').textContent = ok;
    document.getElementById('dpStatErr').textContent = er;
    document.getElementById('dpStatTot').textContent = totalAud !== null ? totalAud : (processed || '—');
    document.getElementById('dpStatRest').textContent = restante !== null ? restante : '—';
    document.getElementById('dpProgressBar').style.width = d.status === 'concluido' ? '100%' : ((pct !== null ? pct : 0) + '%');
    document.getElementById('dpProgressPause').style.display = d.status === 'executando' ? '' : 'none';
    document.getElementById('dpProgressAbort').style.display = d.status === 'executando' ? '' : 'none';
    document.getElementById('dpProgressClose').style.display = d.status === 'executando' ? 'none' : '';
    dpExecState = {id, offset:processed, totalEnv:ok, totalErr:er, totalGeral:totalAud, restante, status:d.status};
    document.getElementById('dpProgressModal').classList.add('visible');
    if (d.status === 'executando') {
        if (dpMonitorTimer) clearInterval(dpMonitorTimer);
        dpMonitorTimer = setInterval(() => dpAtualizarProgressoDisparo(id), 2500);
    }
}

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

function dpFmtHorario(d) {
    const ini = (d.horario_inicio || '08:00').slice(0,5);
    const fim = (d.horario_fim || '18:00').slice(0,5);
    const nomes = ['Dom','Seg','Ter','Qua','Qui','Sex','Sab'];
    const dias = String(d.dias_semana || '0,1,2,3,4,5,6').split(',').map(v => parseInt(v, 10)).filter(v => !Number.isNaN(v));
    const labelDias = dias.length >= 7 ? 'todos os dias' : dias.map(v => nomes[v] || (v === 7 ? 'Dom' : '')).filter(Boolean).join('/');
    return `${ini}-${fim}${labelDias ? ' ' + labelDias : ''}`;
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
