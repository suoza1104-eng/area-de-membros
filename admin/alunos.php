<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/certificado_pdf.php';
require_once __DIR__ . '/../app/retorno_agendamentos.php';
proteger_admin();
$pdo = getPDO();
retorno_ensure_tables($pdo);
$menu       = 'alunos';
$page_title = 'Alunos';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function table_ok(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
function col_ok(PDO $pdo, string $t, string $c): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c");
        $st->execute([':c' => $c]); return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}
// fora do foreach para não "Cannot redeclare"
function fmtDt(?string $d): string {
    if (!$d || trim($d) === '') return '-';
    try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return $d; }
}
function fmtDtHora(?string $d): string {
    if (!$d || trim($d) === '') return '-';
    try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return $d; }
}
function al_gerar_codigo_certificado(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < 36; $i++) {
        if ($i > 0 && $i % 9 === 0) $result .= '-';
        else $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}
function al_gerar_certificado_manual(PDO $pdo, int $userId): array {
    $stU = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stU->execute([':id' => $userId]);
    $aluno = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$aluno) throw new RuntimeException('Aluno não encontrado.');

    $appCfg = [];
    $certCfg = [];
    try {
        $stApp = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
        $appCfg = $stApp ? ($stApp->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {}
    try {
        $stCertCfg = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
        $certCfg = $stCertCfg ? ($stCertCfg->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {}
    $courseTitle = trim((string)($appCfg['course_title'] ?? 'Trilha de Aulas'));
    if ($courseTitle === '') $courseTitle = 'Trilha de Aulas';

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND course = :course ORDER BY id DESC LIMIT 1");
        $st->execute([':uid' => $userId, ':course' => $courseTitle]);
        $cert = $st->fetch(PDO::FETCH_ASSOC);

        if (!$cert || (string)($cert['status'] ?? '') !== 'emitido') {
            $codigo = al_gerar_codigo_certificado();
            $emitidoEm = date('Y-m-d H:i:s');
            $ins = $pdo->prepare("
                INSERT INTO certificates (user_id, course, codigo_uid, emitido_em, status)
                VALUES (:uid, :course, :codigo, :emitido, 'emitido')
            ");
            $ins->execute([
                ':uid' => $userId,
                ':course' => $courseTitle,
                ':codigo' => $codigo,
                ':emitido' => $emitidoEm,
            ]);
            $cert = [
                'id' => (int)$pdo->lastInsertId(),
                'user_id' => $userId,
                'course' => $courseTitle,
                'codigo_uid' => $codigo,
                'emitido_em' => $emitidoEm,
                'status' => 'emitido',
                'pdf_url' => null,
            ];
        }

        $pdfUrl = gerar_pdf_certificado($aluno, $cert, $certCfg);
        $upd = $pdo->prepare("UPDATE certificates SET pdf_url = :pdf_url WHERE id = :id");
        $upd->execute([':pdf_url' => $pdfUrl, ':id' => (int)$cert['id']]);
        $cert['pdf_url'] = $pdfUrl;

        try { adicionar_tag($userId, 'CERT_EMITIDO', 'admin_manual'); } catch (Throwable $e) {}
        $pdo->commit();
        return $cert;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function al_certificado_atual(PDO $pdo, int $userId): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND status = 'emitido' ORDER BY id DESC LIMIT 1");
        $st->execute([':uid' => $userId]);
        $cert = $st->fetch(PDO::FETCH_ASSOC);
        return $cert ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
function al_disparar_reenvio_certificado(int $userId, array $cert, string $origem): void {
    disparar_webhooks('REENVIO_CERTIFICADO', $userId, [
        'codigo_certificado' => $cert['codigo_uid'] ?? '',
        'curso' => $cert['course'] ?? '',
        'emitido_em' => $cert['emitido_em'] ?? '',
        'pdf_url' => $cert['pdf_url'] ?? '',
        'certificado_id' => $cert['id'] ?? null,
        'origem' => $origem,
    ]);
}
function al_get_setting(string $key, string $default = ''): string {
    try {
        $v = get_setting($key, $default);
        return ($v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}
function al_available_reagendar_slots(PDO $pdo): array {
    $now = new DateTimeImmutable('now');
    $qty = (int)al_get_setting('reagendar_opcoes_qtd', al_get_setting('reagendar_next_lives_count', '3'));
    if ($qty < 1) $qty = 1;
    if ($qty > 30) $qty = 30;

    $days = (int)al_get_setting('reagendar_window_days', '30');
    if ($days < 1) $days = 1;
    if ($days > 365) $days = 365;

    $time = trim(al_get_setting('reagendar_live_time', '19:30'));
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '19:30';

    $blackouts = array_flip(array_filter(array_map('trim', explode(',', al_get_setting('reagendar_blackout_dates', '')))));
    $slots = [];
    for ($i = 0; $i <= $days && count($slots) < $qty; $i++) {
        $day = $now->modify('+' . $i . ' days');
        $key = $day->format('Y-m-d');
        if (isset($blackouts[$key])) continue;

        $slot = new DateTimeImmutable($key . ' ' . $time . ':00');
        if ($slot <= $now) continue;

        $value = $slot->format('Y-m-d H:i:s');
        $slots[$value] = [
            'value' => $value,
            'label' => $slot->format('d/m/Y H:i'),
        ];
    }
    return $slots;
}
function al_ensure_reagendamentos_live(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reagendamentos_live (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        old_codigo_turma VARCHAR(80) NULL,
        new_codigo_turma VARCHAR(80) NULL,
        old_turma_live_at DATETIME NULL,
        new_turma_live_at DATETIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'reagendado',
        live_url TEXT NULL,
        sf_disparo_at DATETIME NULL,
        sf_delay_ms INT NOT NULL DEFAULT 500,
        sf_sent_at DATETIME NULL,
        expired_checked_at DATETIME NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(250) NULL,
        origem VARCHAR(30) NULL,
        webhook_url TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_reag_live_user (user_id),
        KEY idx_reag_live_status (status),
        KEY idx_reag_live_new_live (new_turma_live_at),
        KEY idx_reag_live_disparo (sf_disparo_at),
        KEY idx_reag_live_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->exec("ALTER TABLE reagendamentos_live ADD COLUMN origem VARCHAR(30) NULL AFTER user_agent"); } catch (Throwable $e) {}
}
function al_reagendar_live_manual(PDO $pdo, int $userId, string $dataLiveRaw): int {
    if ($userId <= 0) throw new RuntimeException('Aluno invalido.');
    $dataLiveRaw = trim($dataLiveRaw);
    if ($dataLiveRaw === '') throw new RuntimeException('Informe a nova data/hora da live.');

    $dLive = new DateTimeImmutable(str_replace('T', ' ', $dataLiveRaw));
    if ($dLive <= new DateTimeImmutable('now')) throw new RuntimeException('A nova data da live deve ser futura.');
    $newLive = $dLive->format('Y-m-d H:i:s');
    $slots = al_available_reagendar_slots($pdo);
    if (empty($slots[$newLive])) throw new RuntimeException('Esta data nao esta disponivel para reagendamento.');

    $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new RuntimeException('Aluno nao encontrado.');

    $oldCodigo = (string)($u['codigo_turma'] ?? ($u['turma_codigo'] ?? ''));
    $oldLive = (string)($u['turma_live_at'] ?? ($u['data_live'] ?? ''));
    $liveUrl = al_get_setting('reagendar_live_url', '');
    $offsetMin = (int)al_get_setting('reagendar_dispatch_offset_min', '0');
    $delayMs = (int)al_get_setting('reagendar_dispatch_delay_ms', '500');
    if ($delayMs < 0) $delayMs = 0;
    if ($delayMs > 30000) $delayMs = 30000;
    $dispatchAt = $dLive->modify(($offsetMin >= 0 ? '+' : '') . $offsetMin . ' minutes')->format('Y-m-d H:i:s');

    $sets = [];
    $params = [':id' => $userId];
    if (col_ok($pdo, 'users', 'turma_live_at')) { $sets[] = 'turma_live_at = :tl'; $params[':tl'] = $newLive; }
    if (col_ok($pdo, 'users', 'data_live')) { $sets[] = 'data_live = :dl'; $params[':dl'] = $newLive; }
    if (!$sets) throw new RuntimeException('Aluno sem campo de data da live.');

    al_ensure_reagendamentos_live($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1')->execute($params);
        $pdo->prepare("INSERT INTO reagendamentos_live
            (user_id, old_codigo_turma, new_codigo_turma, old_turma_live_at, new_turma_live_at, status, live_url, sf_disparo_at, sf_delay_ms, ip, user_agent, origem, webhook_url, created_at)
            VALUES (:u, :oc, :nc, :ol, :nl, 'reagendado', :url, :sf, :delay, :ip, :ua, 'suporte', NULL, NOW())")
            ->execute([
                ':u' => $userId,
                ':oc' => $oldCodigo ?: null,
                ':nc' => $oldCodigo ?: null,
                ':ol' => $oldLive ?: null,
                ':nl' => $newLive,
                ':url' => $liveUrl ?: null,
                ':sf' => $dispatchAt,
                ':delay' => $delayMs,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => 'admin_alunos',
            ]);
        $histId = (int)$pdo->lastInsertId();
        reagendamento_live_log($pdo, $histId, $userId, 'agendamento_criado', 'pendente', 'Reagendamento criado pelo admin de alunos.', [
            'new_turma_live_at' => $newLive,
            'sf_disparo_at' => $dispatchAt,
            'origem' => 'admin_alunos',
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    disparar_webhooks('LIVE_REAGENDADA', $userId, [
        'reagendamento_id' => $histId,
        'codigo_turma' => $oldCodigo,
        'data_live' => $dLive->format('d/m/Y H:i'),
        'data_live_iso' => $newLive,
        'live_url' => $liveUrl,
        'origem' => 'admin_alunos',
        'reagendamento' => [
            'id' => $histId,
            'turma_original' => $oldCodigo,
            'live_antiga' => fmtDtHora($oldLive),
            'live_nova' => $dLive->format('d/m/Y H:i'),
            'live_nova_iso' => $newLive,
            'live_url' => $liveUrl,
            'status' => 'reagendado',
        ],
    ]);
    return $histId;
}

// ── Detecta colunas e tabelas ─────────────────────────────────────────────
$colTurma   = col_ok($pdo,'users','codigo_turma') ? 'codigo_turma' : (col_ok($pdo,'users','turma') ? 'turma' : '');
$colCreated = col_ok($pdo,'users','created_at')   ? 'created_at'   : (col_ok($pdo,'users','criado_em') ? 'criado_em' : '');
$hasWHL     = table_ok($pdo, 'webhook_logs');
$hasIL      = table_ok($pdo, 'inscricao_logs');
$hasCerts   = table_ok($pdo, 'certificates');
$hasSenha   = col_ok($pdo,'users','senha');
$hasPassword = col_ok($pdo,'users','password');
$senhaCol   = $hasSenha ? 'senha' : ($hasPassword ? 'password' : '');
$hasUtm     = col_ok($pdo,'users','utm_source');

// ── Detecta turmas disponíveis ────────────────────────────────────────────
$turmas = [];
if (table_ok($pdo,'turmas') && $colTurma !== '') {
    $turmas = $pdo->query("SELECT codigo FROM turmas ORDER BY codigo ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// ── POST: ações inline ────────────────────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'magic_link') {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)($_GET['uid'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Aluno invalido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $uid]);
        if (!$st->fetchColumn()) {
            echo json_encode(['ok' => false, 'message' => 'Aluno nao encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $link = function_exists('gerar_magic_link') ? gerar_magic_link($uid, 30, false) : '';
        echo json_encode(['ok' => $link !== '', 'link' => $link, 'message' => $link !== '' ? '' : 'Nao foi possivel gerar o link.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Erro ao gerar link.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$msgPost = ''; $msgPostTipo = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'gerar_cert_manual') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid <= 0) {
            $msgPost = 'Aluno inválido.'; $msgPostTipo = 'erro';
        } else {
            try {
                al_gerar_certificado_manual($pdo, $uid);
                $msgPost = 'Certificado gerado manualmente. Nenhum disparo foi enviado.';
            } catch (Throwable $e) {
                $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    } elseif ($acao === 'trocar_senha' && $senhaCol !== '') {
        $uid = (int)($_POST['uid'] ?? 0);
        $ns  = trim((string)($_POST['nova_senha'] ?? ''));
        $ns2 = trim((string)($_POST['conf_senha']  ?? ''));
        if ($uid <= 0) {
            $msgPost = 'Aluno inválido.'; $msgPostTipo = 'erro';
        } elseif (strlen($ns) < 6) {
            $msgPost = 'Senha deve ter mínimo 6 caracteres.'; $msgPostTipo = 'erro';
        } elseif ($ns !== $ns2) {
            $msgPost = 'As senhas não conferem.'; $msgPostTipo = 'erro';
        } else {
            $hash = password_hash($ns, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET `$senhaCol` = :h WHERE id = :id")->execute([':h' => $hash, ':id' => $uid]);
            $msgPost = 'Senha alterada com sucesso.';
        }
    } elseif ($acao === 'trocar_login') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $nemail = trim((string)($_POST['novo_email'] ?? ''));
        if ($uid <= 0 || !filter_var($nemail, FILTER_VALIDATE_EMAIL)) {
            $msgPost = 'E-mail inválido.'; $msgPostTipo = 'erro';
        } else {
            try {
                $pdo->prepare("UPDATE users SET email = :e WHERE id = :id")->execute([':e' => $nemail, ':id' => $uid]);
                $msgPost = 'E-mail/login atualizado.';
            } catch (Throwable $e) {
                $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    } elseif ($acao === 'reenviar_cert') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            try {
                $cert = al_certificado_atual($pdo, $uid);
                if (!$cert) {
                    throw new RuntimeException('Aluno sem certificado emitido.');
                }
                al_disparar_reenvio_certificado($uid, $cert, 'admin_alunos');
                $msgPost = 'Gatilho REENVIO_CERTIFICADO disparado.';
            } catch (Throwable $e) {
                $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    } elseif ($acao === 'agendar_retorno') {
        $uid = (int)($_POST['uid'] ?? 0);
        try {
            $agId = retorno_criar_agendamento(
                $pdo,
                $uid,
                (string)($_POST['retorno_tipo'] ?? 'vendas'),
                (string)($_POST['retorno_scheduled_at'] ?? ''),
                (string)($_POST['retorno_mensagem'] ?? ''),
                'admin_alunos',
                [],
                (string)($_POST['retorno_assunto'] ?? '')
            );
            $modeloNome = trim((string)($_POST['retorno_modelo_nome'] ?? ''));
            if ($modeloNome !== '') {
                retorno_salvar_modelo($pdo, $modeloNome, (string)($_POST['retorno_tipo'] ?? 'vendas'), (string)($_POST['retorno_mensagem'] ?? ''), 0, (string)($_POST['retorno_assunto'] ?? ''));
            }
            $msgPost = 'Retorno agendado com sucesso (#' . $agId . ').';
        } catch (Throwable $e) {
            $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
        }
    } elseif ($acao === 'reagendar_live_manual') {
        $uid = (int)($_POST['uid'] ?? 0);
        try {
            $histId = al_reagendar_live_manual($pdo, $uid, (string)($_POST['nova_data_live'] ?? ''));
            $msgPost = 'Live reagendada com sucesso (#' . $histId . ').';
        } catch (Throwable $e) {
            $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
        }
    }
}

// ── Filtros ────────────────────────────────────────────────────────────────
$q         = trim((string)($_GET['q']           ?? ''));
$fTurma    = trim((string)($_GET['turma']       ?? ''));
$fTag      = trim((string)($_GET['tag']         ?? ''));
$fUtmSrc   = trim((string)($_GET['utm_source']  ?? ''));
$fUtmMed   = trim((string)($_GET['utm_medium']  ?? ''));
$fUtmCamp  = trim((string)($_GET['utm_campaign']?? ''));
$fDateFrom = trim((string)($_GET['date_from']   ?? ''));
$fDateTo   = trim((string)($_GET['date_to']     ?? ''));
$limit     = (int)($_GET['limit'] ?? 100);
$limitsOk  = [10, 100, 200, 500, 1000, 5000, 10000];
if (!in_array($limit, $limitsOk, true)) $limit = 100;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]      = "(u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($fTurma !== '' && $colTurma !== '') {
    $where[]          = "u.`$colTurma` = :turma";
    $params[':turma'] = $fTurma;
}
if ($fTag !== '') {
    $where[]       = "EXISTS (SELECT 1 FROM user_tags ut2 JOIN tags t2 ON t2.id = ut2.tag_id WHERE ut2.user_id = u.id AND t2.nome LIKE :tag)";
    $params[':tag']= '%' . $fTag . '%';
}

// Filtros estruturados: tag_is[] e tag_not[]
$tagsIs  = array_values(array_filter(array_map('trim', (array)($_GET['tag_is']  ?? []))));
$tagsNot = array_values(array_filter(array_map('trim', (array)($_GET['tag_not'] ?? []))));
foreach ($tagsIs as $i => $tg) {
    $k = ':tg_is_' . $i;
    $where[]   = "EXISTS (SELECT 1 FROM user_tags utI$i JOIN tags tI$i ON tI$i.id = utI$i.tag_id WHERE utI$i.user_id = u.id AND tI$i.nome = $k)";
    $params[$k] = $tg;
}
foreach ($tagsNot as $i => $tg) {
    $k = ':tg_not_' . $i;
    $where[]   = "NOT EXISTS (SELECT 1 FROM user_tags utN$i JOIN tags tN$i ON tN$i.id = utN$i.tag_id WHERE utN$i.user_id = u.id AND tN$i.nome = $k)";
    $params[$k] = $tg;
}
$temFiltroTagStruct = !empty($tagsIs) || !empty($tagsNot);

// Filtro de progresso: todos | so_inscrito | em_progresso | concluiram
$fProgresso = trim((string)($_GET['progresso'] ?? ''));
if (in_array($fProgresso, ['so_inscrito','em_progresso','concluiram'], true)) {
    $progSubReq = "(SELECT COUNT(*) FROM lessons WHERE conta_para_conclusao = 1 AND ativo = 1)";
    $progSubDone = "(SELECT COUNT(DISTINCT lp.lesson_id)
                     FROM lesson_progress lp
                     JOIN lessons l ON l.id = lp.lesson_id
                     WHERE lp.user_id = u.id AND lp.status = 'completed'
                       AND l.conta_para_conclusao = 1 AND l.ativo = 1)";
    $progSubAny = "(SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = u.id AND lp.status = 'completed')";
    if ($fProgresso === 'so_inscrito') {
        $where[] = "$progSubAny = 0";
    } elseif ($fProgresso === 'em_progresso') {
        $where[] = "$progSubAny > 0 AND $progSubDone < $progSubReq";
    } elseif ($fProgresso === 'concluiram') {
        $where[] = "$progSubDone >= $progSubReq AND $progSubReq > 0";
    }
}

// Lista todas as tags disponíveis (para o dropdown)
$todasTags = [];
try {
    $todasTags = $pdo->query("SELECT nome FROM tags ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
if ($fDateFrom !== '' && $colCreated !== '') {
    $where[]           = "u.`$colCreated` >= :dfrom";
    $params[':dfrom']  = $fDateFrom . ' 00:00:00';
}
if ($fDateTo !== '' && $colCreated !== '') {
    $where[]          = "u.`$colCreated` <= :dto";
    $params[':dto']   = $fDateTo . ' 23:59:59';
}
if ($fUtmSrc !== '' && $hasUtm) {
    $where[]       = "u.utm_source LIKE :us";
    $params[':us'] = '%' . $fUtmSrc . '%';
}
if ($fUtmMed !== '' && $hasUtm) {
    $where[]       = "u.utm_medium LIKE :um";
    $params[':um'] = '%' . $fUtmMed . '%';
}
if ($fUtmCamp !== '' && $hasUtm) {
    $where[]        = "u.utm_campaign LIKE :uc";
    $params[':uc']  = '%' . $fUtmCamp . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$selTurma   = $colTurma   !== '' ? "u.`$colTurma` AS turma_codigo," : "NULL AS turma_codigo,";
$selCreated = $colCreated !== '' ? "u.`$colCreated` AS primeiro_cadastro," : "NULL AS primeiro_cadastro,";
$selUtm     = $hasUtm
    ? "u.utm_source, u.utm_medium, u.utm_campaign, u.utm_content,"
    : "NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign, NULL AS utm_content,";

if ($hasIL) {
    $selWhl = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id = u.id) AS qtd_cadastros,
               (SELECT MAX(il2.created_at) FROM inscricao_logs il2 WHERE il2.user_id = u.id) AS ultimo_cadastro,";
} elseif ($hasWHL) {
    $selWhl = "(SELECT COUNT(DISTINCT DATE(wl.created_at)) FROM webhook_logs wl WHERE wl.user_id = u.id AND wl.evento = 'INSCRITO') AS qtd_cadastros,
               (SELECT MAX(wl2.created_at) FROM webhook_logs wl2 WHERE wl2.user_id = u.id AND wl2.evento = 'INSCRITO') AS ultimo_cadastro,";
} else {
    $selWhl = "1 AS qtd_cadastros, NULL AS ultimo_cadastro,";
}

$selCert = $hasCerts
    ? "(SELECT codigo_uid FROM certificates WHERE user_id = u.id AND status = 'emitido' ORDER BY id DESC LIMIT 1) AS cert_codigo,
       (SELECT emitido_em FROM certificates WHERE user_id = u.id AND status = 'emitido' ORDER BY id DESC LIMIT 1) AS cert_emitido_em,
       (SELECT course FROM certificates WHERE user_id = u.id AND status = 'emitido' ORDER BY id DESC LIMIT 1) AS cert_course,
       (SELECT pdf_url FROM certificates WHERE user_id = u.id AND status = 'emitido' ORDER BY id DESC LIMIT 1) AS cert_pdf_url,"
    : "NULL AS cert_codigo, NULL AS cert_emitido_em, NULL AS cert_course, NULL AS cert_pdf_url,";

// Count total (sem limit)
$sqlCount = "SELECT COUNT(*) FROM users u $whereSql";
$stCount  = $pdo->prepare($sqlCount);
$stCount->execute($params);
$totalGeral = (int)$stCount->fetchColumn();

$sql = "
SELECT
  u.id, u.nome, u.email, u.telefone,
  $selTurma $selCreated $selUtm $selWhl $selCert
  (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR '|')
   FROM user_tags ut JOIN tags t ON t.id = ut.tag_id
   WHERE ut.user_id = u.id) AS tags_lista
FROM users u
$whereSql
ORDER BY u.id DESC
LIMIT $limit
";

$st     = $pdo->prepare($sql);
$st->execute($params);
$alunos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$retornoModelos = retorno_listar_modelos($pdo);
$retornoTipos = retorno_tipos();
$retornosPorUser = [];
if ($alunos) {
    $ids = array_values(array_unique(array_map(static fn($a) => (int)$a['id'], $alunos)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stRet = $pdo->prepare("SELECT * FROM retorno_agendamentos WHERE user_id IN ($ph) ORDER BY scheduled_at DESC, id DESC");
        $stRet->execute($ids);
        foreach ($stRet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ret) {
            $uidRet = (int)$ret['user_id'];
            if (!isset($retornosPorUser[$uidRet])) $retornosPorUser[$uidRet] = [];
            if (count($retornosPorUser[$uidRet]) < 4) $retornosPorUser[$uidRet][] = $ret;
        }
    } catch (Throwable $e) {}
}

$temFiltroUtm  = ($fUtmSrc !== '' || $fUtmMed !== '' || $fUtmCamp !== '');
$temFiltroData = ($fDateFrom !== '' || $fDateTo !== '');
$reagendarLiveSlots = al_available_reagendar_slots($pdo);

require __DIR__ . '/_header.php';
?>
<style>
/* ── Filtros ──────────────────────────────────────────────── */
.adv-panel { display:none; padding-top:10px; }
.adv-panel.open { display:flex; flex-wrap:wrap; gap:10px; }
.filter-toggle-btn {
    font-size:11px; color:var(--muted); background:none; border:1px solid var(--border);
    border-radius:var(--r-full); padding:4px 10px; cursor:pointer; font-family:var(--font);
    display:inline-flex; align-items:center; gap:4px; transition:all var(--t);
}
.filter-toggle-btn:hover { border-color:var(--border-light); color:var(--text); background:var(--bg-hover); }
.filter-toggle-btn.ativo { border-color:rgba(250,204,21,.4); color:var(--primary); background:var(--primary-dim); }

/* ── Tag picker ── */
.tag-picker {
    border: 1px solid var(--border); border-radius: var(--r-md);
    background: var(--bg-input, #0f172a); padding: 4px;
    display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
    min-height: 32px;
}
.tag-chips { display: contents; }
.tag-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 4px 2px 8px; border-radius: 999px;
    background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.35);
    color: #4ade80; font-size: 11px; font-weight: 600; font-family: var(--font);
}
.tag-chip.is-not {
    background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.35);
    color: #f87171;
}
.tag-chip .tc-op {
    background: rgba(255,255,255,.08); border: none; color: inherit;
    font-size: 9px; font-weight: 700; cursor: pointer; padding: 2px 6px;
    border-radius: 999px; font-family: var(--font); text-transform: uppercase;
    letter-spacing: .04em;
}
.tag-chip .tc-op:hover { background: rgba(255,255,255,.15); }
.tag-chip .tc-rm {
    background: none; border: none; color: inherit; font-size: 13px; cursor: pointer;
    line-height: 1; padding: 0 4px; opacity: .7;
}
.tag-chip .tc-rm:hover { opacity: 1; }
.tag-add-btn {
    background: none; border: 1px dashed var(--border); color: var(--muted);
    border-radius: 999px; padding: 4px 10px; cursor: pointer; font-size: 11px;
    font-family: var(--font);
}
.tag-add-btn:hover { border-color: var(--primary); color: var(--primary); }
.tag-dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--r-md); box-shadow: 0 8px 24px rgba(0,0,0,.4);
    margin-top: 4px; padding: 6px; z-index: 50;
    max-height: 280px; overflow: hidden; display: flex; flex-direction: column;
}
.tag-dropdown input {
    background: var(--bg-input,#0f172a); border: 1px solid var(--border);
    border-radius: var(--r-sm); color: var(--text); padding: 5px 8px;
    font-size: 12px; margin-bottom: 6px; font-family: var(--font);
}
.tag-list { overflow-y: auto; max-height: 220px; }
.tag-list-item {
    padding: 5px 8px; border-radius: var(--r-sm); cursor: pointer;
    font-size: 12px; color: var(--text);
}
.tag-list-item:hover, .tag-list-item.focused { background: var(--bg-hover); color: var(--primary); }
.tag-list-empty { padding: 8px; color: var(--muted); font-size: 11px; text-align: center; }

/* ── Tabela ───────────────────────────────────────────────── */
.al-table { width:100%; border-collapse:collapse; font-size:13px; }
.al-table thead th {
    padding:9px 12px; text-align:left; font-size:10.5px; font-weight:700;
    text-transform:uppercase; letter-spacing:.07em; color:var(--muted);
    border-bottom:1px solid var(--border); white-space:nowrap;
    background:rgba(255,255,255,.025);
}
.al-table tbody td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.al-table tbody tr.main-row { cursor:pointer; }
.al-table tbody tr.main-row:hover td { background:var(--bg-hover); }
.expand-icon { transition:transform .2s ease; display:inline-block; color:var(--muted); font-size:11px; }
.main-row.open .expand-icon { transform:rotate(90deg); color:var(--primary); }

/* ── Expanded detail ──────────────────────────────────────── */
.expand-detail {
    display:none; padding:16px 18px 18px;
    background:rgba(255,255,255,.02);
    border-top:1px solid var(--border);
    border-bottom:1px solid var(--border);
}
.expand-detail.open { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
@media(max-width:900px) { .expand-detail.open { grid-template-columns:1fr 1fr; } }
@media(max-width:600px) { .expand-detail.open { grid-template-columns:1fr; } }
.det-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:8px; }
.det-row { display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; gap:8px; }
.det-key { color:var(--muted); flex-shrink:0; }
.det-val { color:var(--text); font-weight:500; text-align:right; word-break:break-all; }
.tag-chip {
    display:inline-block; padding:2px 8px; border-radius:var(--r-full); font-size:11px;
    margin:2px 2px 0 0; border:1px solid var(--border-light); color:var(--text);
    background:rgba(255,255,255,.05);
}
.tag-chip.cert { background:rgba(34,197,94,.1); border-color:rgba(34,197,94,.3); color:#86efac; }
.tag-chip.inscrito { background:var(--info-dim); border-color:rgba(56,189,248,.3); color:var(--info); }
.tag-chip.live { background:rgba(249,115,22,.1); border-color:rgba(249,115,22,.3); color:#fdba74; }
.det-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; grid-column:1/-1; }
.cert-link { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--success); }
.cert-link:hover { text-decoration:underline; }
.cert-mini-link { display:flex; gap:6px; align-items:center; margin-top:8px; }
.cert-mini-link input { min-width:0; flex:1; font-size:11px; color:var(--muted); }
.retorno-block { grid-column:1/-1; border-top:1px solid var(--border); padding-top:14px; }
.retorno-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.retorno-list { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px; }
.retorno-item { border:1px solid var(--border); border-radius:var(--r); padding:9px 10px; background:rgba(0,0,0,.12); }
.retorno-item.aguardando { border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); }
.retorno-item.enviado { border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.08); }
.retorno-item.erro { border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08); }
.retorno-item.cancelado { opacity:.68; }
.retorno-meta { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:11px; color:var(--muted); margin-bottom:5px; }
.retorno-msg { font-size:12px; color:var(--text); white-space:pre-wrap; max-height:48px; overflow:hidden; }
.retorno-empty { font-size:12px; color:var(--dim); }
.modal-box.modal-wide { max-width:620px; }

/* ── KPI bar ──────────────────────────────────────────────── */
.al-kpi-bar { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.al-kpi { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); padding:10px 16px; min-width:120px; }
.al-kpi-v { font-size:22px; font-weight:700; line-height:1.1; }
.al-kpi-l { font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-top:2px; }

/* ── Paginação / limit ────────────────────────────────────── */
.pg-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:10px 14px; border-top:1px solid var(--border); font-size:12px; color:var(--muted); }
.limit-select { display:inline-flex; align-items:center; gap:6px; }
.limit-select select { padding:4px 8px; font-size:12px; border-radius:var(--r); background:var(--bg); border:1px solid var(--border-light); color:var(--text); cursor:pointer; }

/* ── Modal ────────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:900; backdrop-filter:blur(4px); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border-light); border-radius:var(--r-xl); padding:24px; width:100%; max-width:400px; box-shadow:var(--shadow-lg); }
.modal-title { font-size:14px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.modal-footer { display:flex; gap:8px; margin-top:16px; }
</style>

<?php if ($msgPost): ?>
<div class="alert <?= $msgPostTipo==='ok'?'alert-ok':'alert-error' ?>" style="margin-bottom:14px"><?= h($msgPost) ?></div>
<?php endif; ?>

<!-- ─── Filtros ──────────────────────────────────────────────────────── -->
<div class="filter-bar" style="margin-bottom:14px">
    <form method="get" id="fform" style="width:100%">
        <!-- Linha 1: filtros principais -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <div class="filter-group" style="flex:2;min-width:200px">
                <label>Nome / E-mail / Telefone</label>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Busca livre…">
            </div>
            <div class="filter-group" style="min-width:150px">
                <label>Turma</label>
                <select name="turma">
                    <option value="">Todas</option>
                    <?php foreach ($turmas as $t): ?>
                    <option value="<?= h((string)$t) ?>" <?= $fTurma===(string)$t?'selected':'' ?>><?= h((string)$t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="min-width:170px">
                <label>Progresso</label>
                <select name="progresso">
                    <option value="" <?= $fProgresso===''?'selected':'' ?>>Todos</option>
                    <option value="so_inscrito"  <?= $fProgresso==='so_inscrito' ?'selected':'' ?>>Só inscritos (não viram aula)</option>
                    <option value="em_progresso" <?= $fProgresso==='em_progresso'?'selected':'' ?>>Em progresso</option>
                    <option value="concluiram"   <?= $fProgresso==='concluiram'  ?'selected':'' ?>>Concluíram a trilha</option>
                </select>
            </div>
            <div class="filter-group" style="min-width:240px;position:relative">
                <label>Tags</label>
                <div class="tag-picker" id="tagPicker">
                    <div class="tag-chips" id="tagChips"></div>
                    <button type="button" class="tag-add-btn" onclick="tpToggleDropdown(event)">
                        <span id="tpAddLabel">+ Adicionar tag</span>
                    </button>
                    <div class="tag-dropdown" id="tagDropdown" style="display:none">
                        <input type="text" id="tpSearch" placeholder="Buscar tag..." oninput="tpRenderList()" onkeydown="tpKey(event)">
                        <div class="tag-list" id="tpList"></div>
                    </div>
                </div>
                <div id="tagHidden"></div>
                <?php if ($fTag !== ''): ?>
                <input type="hidden" name="tag" value="<?= h($fTag) ?>">
                <?php endif; ?>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <?php if ($q||$fTurma||$fTag||$temFiltroTagStruct||$temFiltroUtm||$temFiltroData||$fProgresso): ?>
                <a href="alunos.php" class="reset-link">Limpar</a>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;padding-top:14px;flex-wrap:wrap">
                <button type="button" class="filter-toggle-btn <?= $temFiltroData?'ativo':'' ?>" onclick="togglePanel('panel-data',this)">
                    📅 Datas<?= $temFiltroData?' (ativo)':'' ?>
                </button>
                <?php if ($hasUtm): ?>
                <button type="button" class="filter-toggle-btn <?= $temFiltroUtm?'ativo':'' ?>" onclick="togglePanel('panel-utm',this)">
                    ⚙ UTMs<?= $temFiltroUtm?' (ativo)':'' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Painel datas -->
        <div class="adv-panel <?= $temFiltroData?'open':'' ?>" id="panel-data">
            <div class="filter-group" style="min-width:150px">
                <label>Inscrição de</label>
                <input type="date" name="date_from" value="<?= h($fDateFrom) ?>">
            </div>
            <div class="filter-group" style="min-width:150px">
                <label>Inscrição até</label>
                <input type="date" name="date_to" value="<?= h($fDateTo) ?>">
            </div>
        </div>

        <!-- Painel UTMs -->
        <?php if ($hasUtm): ?>
        <div class="adv-panel <?= $temFiltroUtm?'open':'' ?>" id="panel-utm">
            <div class="filter-group" style="min-width:140px">
                <label>UTM Source</label>
                <input type="text" name="utm_source" value="<?= h($fUtmSrc) ?>" placeholder="google, facebook…">
            </div>
            <div class="filter-group" style="min-width:140px">
                <label>UTM Medium</label>
                <input type="text" name="utm_medium" value="<?= h($fUtmMed) ?>" placeholder="cpc, email…">
            </div>
            <div class="filter-group" style="min-width:140px">
                <label>UTM Campaign</label>
                <input type="text" name="utm_campaign" value="<?= h($fUtmCamp) ?>" placeholder="nome_campanha">
            </div>
        </div>
        <?php endif; ?>

        <!-- Limit selector (preservado no submit) -->
        <input type="hidden" name="limit" id="limit-hidden" value="<?= $limit ?>">
    </form>
</div>

<!-- ─── KPI Bar ──────────────────────────────────────────────────────── -->
<div class="al-kpi-bar">
    <div class="al-kpi">
        <div class="al-kpi-v"><?= number_format($totalGeral) ?></div>
        <div class="al-kpi-l">Total encontrado</div>
    </div>
    <div class="al-kpi">
        <div class="al-kpi-v"><?= count($alunos) ?></div>
        <div class="al-kpi-l">Exibindo</div>
    </div>
    <?php
    $comCert  = count(array_filter($alunos, function($a){ return strpos((string)($a['tags_lista']??''),'CERT_EMITIDO')!==false; }));
    $comTurma = count(array_filter($alunos, function($a){ return trim((string)($a['turma_codigo']??''))!==''; }));
    ?>
    <div class="al-kpi">
        <div class="al-kpi-v" style="color:var(--success)"><?= $comCert ?></div>
        <div class="al-kpi-l">Com certificado</div>
    </div>
    <div class="al-kpi">
        <div class="al-kpi-v" style="color:var(--info)"><?= $comTurma ?></div>
        <div class="al-kpi-l">Com turma</div>
    </div>
</div>

<!-- ─── Tabela ──────────────────────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table class="al-table" style="min-width:900px">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th>Nome / E-mail</th>
                    <th>Telefone</th>
                    <th>Turma</th>
                    <th>Tags</th>
                    <th style="text-align:center">Cadastros</th>
                    <th>1° Cadastro</th>
                    <th>Último</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$alunos): ?>
                <tr><td colspan="9" style="padding:28px;text-align:center;color:var(--muted)">Nenhum aluno encontrado para os filtros aplicados.</td></tr>
            <?php else: ?>
            <?php foreach ($alunos as $i => $a):
                $tags    = array_filter(array_map('trim', explode('|', (string)($a['tags_lista']??''))));
                $turma   = trim((string)($a['turma_codigo']??''));
                $primCad = (string)($a['primeiro_cadastro']??'');
                $ultCad  = (string)($a['ultimo_cadastro']??'');
                $qtd     = (int)($a['qtd_cadastros']??1);
                $certCod = (string)($a['cert_codigo']??'');
                $certUrl = $certCod !== '' ? BASE_URL . '/verificar_certificado.php?c=' . urlencode($certCod) : '';
                $certPdf = trim((string)($a['cert_pdf_url'] ?? ''));
                $certCourse = trim((string)($a['cert_course'] ?? ''));
                $certEmitido = trim((string)($a['cert_emitido_em'] ?? ''));
                $temCert = in_array('CERT_EMITIDO', array_map('strtoupper', $tags));
                $retornosAluno = $retornosPorUser[(int)$a['id']] ?? [];
            ?>
            <tr class="main-row" id="row-<?= $i ?>" onclick="toggleExpand(<?= $i ?>)">
                <td><span class="expand-icon">▶</span></td>
                <td>
                    <div style="font-weight:600"><?= h((string)($a['nome']??'-')) ?></div>
                    <div style="font-size:11px;color:var(--muted)"><?= h((string)($a['email']??'-')) ?></div>
                </td>
                <td style="color:var(--muted);font-size:12px"><?= h(trim((string)($a['telefone']??''))) ?: '-' ?></td>
                <td>
                    <?php if ($turma !== ''): ?>
                    <span class="badge badge-info" style="font-size:11px"><?= h($turma) ?></span>
                    <?php else: ?><span style="color:var(--dim);font-size:12px">—</span><?php endif; ?>
                </td>
                <td style="max-width:180px">
                    <?php $shown=0; foreach ($tags as $tag):
                        if ($shown>=3) break;
                        $tu=strtoupper($tag);
                        $cls = $tu==='CERT_EMITIDO' ? 'cert' : ($tu==='INSCRITO' ? 'inscrito' : (strpos($tu,'LIVE')!==false?'live':''));
                    ?>
                    <span class="tag-chip <?= $cls ?>"><?= h(mb_strtolower($tag)) ?></span>
                    <?php $shown++; endforeach; ?>
                    <?php if (count($tags)>3): ?><span style="font-size:11px;color:var(--muted)">+<?= count($tags)-3 ?></span><?php endif; ?>
                    <?php if (!$tags): ?><span style="font-size:11px;color:var(--dim)">—</span><?php endif; ?>
                </td>
                <td style="text-align:center">
                    <span style="font-weight:600;<?= $qtd>1?'color:var(--warning)':'' ?>"><?= $qtd ?></span>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($primCad) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($ultCad) ?></td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                    <a href="aluno_editar.php?id=<?= (int)$a['id'] ?>" class="btn btn-ghost btn-xs">Editar</a>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="abrirReagendarLive(<?= (int)$a['id'] ?>, '<?= h((string)($a['nome'] ?? 'Aluno')) ?>')" title="Reagendar live manualmente">Reagendar Live</button>
                </td>
            </tr>
            <tr id="exp-<?= $i ?>">
                <td colspan="9" style="padding:0;border-bottom:none">
                    <div class="expand-detail" id="det-<?= $i ?>">
                        <!-- UTMs + cadastros -->
                        <div>
                            <div class="det-title">UTM / Origem</div>
                            <?php
                            $utmF=['utm_source'=>'Source','utm_medium'=>'Medium','utm_campaign'=>'Campaign','utm_content'=>'Content'];
                            $anyUtm=false;
                            foreach($utmF as $uk=>$ul):
                                $uv=trim((string)($a[$uk]??''));
                                if($uv==='') continue; $anyUtm=true;
                            ?><div class="det-row"><span class="det-key"><?=h($ul)?></span><span class="det-val"><?=h($uv)?></span></div>
                            <?php endforeach; ?>
                            <?php if(!$anyUtm): ?><div style="font-size:12px;color:var(--dim)">Sem dados UTM</div><?php endif; ?>
                            <div style="margin-top:10px">
                                <div class="det-title">Inscrições</div>
                                <div class="det-row"><span class="det-key">Qtd.</span><span class="det-val"><?=$qtd?></span></div>
                                <div class="det-row"><span class="det-key">Primeiro</span><span class="det-val"><?=fmtDt($primCad)?></span></div>
                                <?php if($ultCad): ?><div class="det-row"><span class="det-key">Último</span><span class="det-val"><?=fmtDt($ultCad)?></span></div><?php endif; ?>
                            </div>
                        </div>
                        <!-- Tags -->
                        <div>
                            <div class="det-title">Tags / Etapas do funil</div>
                            <?php if($tags): foreach($tags as $tag):
                                $tu=strtoupper($tag);
                                $cls=$tu==='CERT_EMITIDO'?'cert':($tu==='INSCRITO'?'inscrito':(strpos($tu,'LIVE')!==false?'live':''));
                            ?><span class="tag-chip <?=$cls?>"><?=h(mb_strtolower($tag))?></span><?php endforeach;
                            else: ?><div style="font-size:12px;color:var(--dim)">Sem tags</div><?php endif; ?>
                        </div>
                        <!-- Certificado + ações -->
                        <div>
                            <div class="det-title">Certificado</div>
                            <?php if($certUrl): ?>
                            <div class="det-row"><span class="det-key">Emitido em</span><span class="det-val"><?=h(fmtDtHora($certEmitido))?></span></div>
                            <div class="det-row"><span class="det-key">Curso</span><span class="det-val"><?=h($certCourse ?: '-')?></span></div>
                            <a href="<?=h($certUrl)?>" target="_blank" class="cert-link">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                                Ver certificado
                            </a>
                            <?php if($certPdf !== ''): ?>
                            <div class="cert-mini-link">
                                <input type="text" id="cert-copy-<?=$i?>" readonly value="<?=h($certPdf)?>">
                                <button type="button" class="btn btn-ghost btn-sm" onclick="event.stopPropagation();copyCertFromList('cert-copy-<?=$i?>')">Copiar</button>
                            </div>
                            <?php endif; ?>
                            <?php elseif($temCert): ?>
                            <span style="font-size:12px;color:var(--success)">✓ Emitido</span>
                            <?php else: ?>
                            <span style="font-size:12px;color:var(--dim)">Não emitido</span>
                            <?php endif; ?>

                            <div class="det-actions">
                                <a href="aluno_editar.php?id=<?=(int)$a['id']?>" class="btn btn-ghost btn-sm">✏ Editar dados</a>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="copiarLinkAcesso(<?=(int)$a['id']?>, this)">Copiar link de acesso</button>
                                <?php if($senhaCol!==''): ?>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirSenha(<?=(int)$a['id']?>,'<?=h((string)($a['nome']??''))?>')">🔑 Trocar senha</button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirLogin(<?=(int)$a['id']?>,'<?=h((string)($a['email']??''))?>')">📧 Trocar e-mail</button>
                                <?php endif; ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Gerar certificado manualmente para este aluno sem enviar disparos?')">
                                    <input type="hidden" name="acao" value="gerar_cert_manual">
                                    <input type="hidden" name="uid"  value="<?=(int)$a['id']?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--warning)">Gerar certificado manualmente</button>
                                </form>
                                <?php if($temCert): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Disparar REENVIO_CERTIFICADO para este aluno?')">
                                    <input type="hidden" name="acao" value="reenviar_cert">
                                    <input type="hidden" name="uid"  value="<?=(int)$a['id']?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--success)">↻ Reenviar certificado</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="retorno-block">
                            <div class="retorno-head">
                                <div>
                                    <div class="det-title" style="margin-bottom:2px">Agendamentos de retorno</div>
                                    <div class="text-xs text-muted">Status por cor: amarelo aguardando, verde enviado, vermelho erro.</div>
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick='abrirRetorno(<?=(int)$a['id']?>, <?=json_encode((string)($a['nome']??''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>)'>Agendar retorno</button>
                                    <a class="btn btn-ghost btn-sm" href="retorno_agendamentos.php?user_id=<?=(int)$a['id']?>">Ver controle</a>
                                </div>
                            </div>
                            <?php if ($retornosAluno): ?>
                            <div class="retorno-list">
                                <?php foreach ($retornosAluno as $ret): ?>
                                <div class="retorno-item <?=h((string)$ret['status'])?>">
                                    <div class="retorno-meta">
                                        <strong><?=h(retorno_status_label((string)$ret['status']))?></strong>
                                        <span><?=h(fmtDtHora((string)$ret['scheduled_at']))?></span>
                                    </div>
                                    <div class="text-xs text-muted" style="margin-bottom:4px"><?=h($retornoTipos[(string)$ret['tipo']] ?? (string)$ret['tipo'])?> · <?=h((string)($ret['origem'] ?? ''))?></div>
                                    <?php if (!empty($ret['assunto'])): ?><div class="text-xs" style="font-weight:700;margin-bottom:4px"><?=h((string)$ret['assunto'])?></div><?php endif; ?>
                                    <div class="retorno-msg"><?=h((string)($ret['mensagem'] ?? ''))?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="retorno-empty">Nenhum retorno agendado para este aluno.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Barra de paginação / limit -->
    <div class="pg-bar">
        <span>Exibindo <strong><?= count($alunos) ?></strong> de <strong><?= number_format($totalGeral) ?></strong> alunos</span>
        <div class="limit-select">
            <span>Mostrar:</span>
            <select id="limit-sel" onchange="changeLimit(this.value)">
                <?php foreach ([10,100,200,500,1000,5000,10000] as $lo): ?>
                <option value="<?= $lo ?>" <?= $limit===$lo?'selected':'' ?>><?= number_format($lo) ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página</span>
        </div>
    </div>
</div>

<!-- ─── Modal Senha ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-senha">
    <div class="modal-box">
        <div class="modal-title">🔑 Trocar senha de acesso</div>
        <div id="m-senha-nome" style="font-size:12px;color:var(--muted);margin-bottom:14px"></div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_senha">
            <input type="hidden" name="uid"  id="m-senha-uid">
            <div class="form-group">
                <label class="form-label">Nova senha (mín. 6 caracteres)</label>
                <input type="password" name="nova_senha" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label">Confirmar senha</label>
                <input type="password" name="conf_senha" required minlength="6">
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-senha')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal Login ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-login">
    <div class="modal-box">
        <div class="modal-title">📧 Trocar e-mail / login</div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_login">
            <input type="hidden" name="uid"  id="m-login-uid">
            <div class="form-group">
                <label class="form-label">Novo e-mail de login</label>
                <input type="email" name="novo_email" id="m-login-email" required>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-login')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-retorno">
    <div class="modal-box modal-wide">
        <div class="modal-title">Agendar retorno de contato</div>
        <div id="m-retorno-nome" style="font-size:12px;color:var(--muted);margin-bottom:14px"></div>
        <form method="post">
            <input type="hidden" name="acao" value="agendar_retorno">
            <input type="hidden" name="uid" id="m-retorno-uid">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label class="form-label">Data e hora do envio</label>
                    <input type="datetime-local" name="retorno_scheduled_at" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="retorno_tipo" id="m-retorno-tipo">
                        <?php foreach ($retornoTipos as $k => $label): ?>
                        <option value="<?=h($k)?>"><?=h($label)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Carregar mensagem salva</label>
                <select id="m-retorno-modelo" onchange="carregarModeloRetorno(this.value)">
                    <option value="">Selecionar modelo...</option>
                    <?php foreach ($retornoModelos as $m): ?>
                    <option value="<?=(int)$m['id']?>"><?=h((string)$m['nome'])?> (<?=h($retornoTipos[(string)$m['tipo']] ?? (string)$m['tipo'])?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Assunto</label>
                <input type="text" name="retorno_assunto" id="m-retorno-assunto" placeholder="Ex: Retorno sobre sua vaga">
            </div>
            <div class="form-group">
                <label class="form-label">Mensagem</label>
                <textarea name="retorno_mensagem" id="m-retorno-mensagem" rows="6" placeholder="Oi {primeiro_nome}, passando para dar continuidade..."></textarea>
                <div style="font-size:11px;color:var(--muted);margin-top:6px">Variaveis: <code>{primeiro_nome}</code>, <code>{nome}</code>, <code>{email}</code>, <code>{telefone}</code>, <code>{assunto}</code>, <code>{tipo}</code>, <code>{data_agendamento}</code>.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Salvar esta mensagem como modelo (opcional)</label>
                <input type="text" name="retorno_modelo_nome" placeholder="Nome do modelo">
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar agendamento</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-retorno')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-reagendar-live">
    <div class="modal-box">
        <div class="modal-title">Reagendar Live</div>
        <div id="m-reagendar-live-nome" style="font-size:12px;color:var(--muted);margin-bottom:14px"></div>
        <form method="post">
            <input type="hidden" name="acao" value="reagendar_live_manual">
            <input type="hidden" name="uid" id="m-reagendar-live-uid">
            <div class="form-group">
                <label class="form-label">Escolha a live de repescagem</label>
                <select name="nova_data_live" id="m-reagendar-live-data" required>
                    <option value="">Selecione uma data disponivel...</option>
                    <?php foreach ($reagendarLiveSlots as $slot): ?>
                    <option value="<?= h((string)$slot['value']) ?>"><?= h((string)$slot['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:6px">
                    As opcoes seguem a configuracao da tela Reagendamento Live: quantidade de lives, horario diario e dias indisponiveis.
                    O aluno permanece na turma atual e o sistema dispara o gatilho LIVE_REAGENDADA.
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm" <?= empty($reagendarLiveSlots) ? 'disabled' : '' ?>>Confirmar reagendamento</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-reagendar-live')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
const RETORNO_MODELOS = <?= json_encode($retornoModelos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function copyCertFromList(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(el.value);
    } else {
        document.execCommand('copy');
    }
}
function toggleExpand(i) {
    var row = document.getElementById('row-' + i);
    var det = document.getElementById('det-' + i);
    if (!det) return;
    var open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    row.classList.toggle('open', !open);
}
function togglePanel(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open');
    btn.classList.toggle('ativo', el.classList.contains('open'));
}

// ── Tag Picker ───────────────────────────────────────────────────────────────
const ALL_TAGS = <?= json_encode($todasTags, JSON_UNESCAPED_UNICODE) ?>;
const TAG_INIT_IS  = <?= json_encode($tagsIs,  JSON_UNESCAPED_UNICODE) ?>;
const TAG_INIT_NOT = <?= json_encode($tagsNot, JSON_UNESCAPED_UNICODE) ?>;
let tpSelected = []; // [{nome, op: 'is'|'not'}, ...]

function tpInit() {
    TAG_INIT_IS.forEach(n => tpSelected.push({nome:n, op:'is'}));
    TAG_INIT_NOT.forEach(n => tpSelected.push({nome:n, op:'not'}));
    tpRenderChips();
}
function tpRenderChips() {
    const cont = document.getElementById('tagChips');
    cont.innerHTML = tpSelected.map((t, i) => {
        const label = t.op === 'is' ? 'É' : 'NÃO É';
        const cls   = t.op === 'is' ? '' : 'is-not';
        return `<span class="tag-chip ${cls}">
            <button type="button" class="tc-op" onclick="tpToggleOp(${i})" title="Clique para inverter">${label}</button>
            <span>${tpEsc(t.nome)}</span>
            <button type="button" class="tc-rm" onclick="tpRemove(${i})" title="Remover">×</button>
        </span>`;
    }).join('');
    tpRenderHidden();
}
function tpRenderHidden() {
    const cont = document.getElementById('tagHidden');
    cont.innerHTML = tpSelected.map(t => {
        const name = t.op === 'is' ? 'tag_is[]' : 'tag_not[]';
        return `<input type="hidden" name="${name}" value="${tpEsc(t.nome)}">`;
    }).join('');
}
function tpToggleOp(i) {
    if (!tpSelected[i]) return;
    tpSelected[i].op = tpSelected[i].op === 'is' ? 'not' : 'is';
    tpRenderChips();
}
function tpRemove(i) {
    tpSelected.splice(i, 1);
    tpRenderChips();
}
function tpToggleDropdown(ev) {
    ev && ev.stopPropagation();
    const d = document.getElementById('tagDropdown');
    const open = d.style.display !== 'none';
    d.style.display = open ? 'none' : 'flex';
    if (!open) {
        document.getElementById('tpSearch').value = '';
        tpRenderList();
        setTimeout(() => document.getElementById('tpSearch').focus(), 30);
    }
}
function tpRenderList() {
    const q = (document.getElementById('tpSearch').value || '').trim().toLowerCase();
    const selectedNames = tpSelected.map(t => t.nome.toLowerCase());
    const items = ALL_TAGS.filter(n =>
        (!q || n.toLowerCase().includes(q)) &&
        !selectedNames.includes(n.toLowerCase())
    );
    const list = document.getElementById('tpList');
    if (!items.length) {
        list.innerHTML = '<div class="tag-list-empty">' + (q ? 'Nenhuma tag encontrada' : 'Todas as tags já selecionadas') + '</div>';
        return;
    }
    list.innerHTML = items.map(n => `<div class="tag-list-item" onclick="tpAdd('${tpEsc(n).replace(/'/g,"\\'")}')">${tpEsc(n)}</div>`).join('');
}
function tpAdd(nome) {
    tpSelected.push({nome, op:'is'});
    tpRenderChips();
    document.getElementById('tpSearch').value = '';
    tpRenderList();
    setTimeout(() => document.getElementById('tpSearch').focus(), 10);
}
function tpKey(ev) {
    if (ev.key === 'Escape') { tpToggleDropdown(); }
}
function tpEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
// Fecha dropdown ao clicar fora
document.addEventListener('click', function(ev) {
    const p = document.getElementById('tagPicker');
    if (p && !p.contains(ev.target)) {
        document.getElementById('tagDropdown').style.display = 'none';
    }
});
document.addEventListener('DOMContentLoaded', tpInit);
function changeLimit(val) {
    document.getElementById('limit-hidden').value = val;
    document.getElementById('fform').submit();
}
function abrirSenha(uid, nome) {
    document.getElementById('m-senha-uid').value = uid;
    document.getElementById('m-senha-nome').textContent = 'Aluno: ' + nome;
    document.getElementById('modal-senha').classList.add('open');
}
function abrirLogin(uid, email) {
    document.getElementById('m-login-uid').value = uid;
    document.getElementById('m-login-email').value = email;
    document.getElementById('modal-login').classList.add('open');
}
async function copiarLinkAcesso(uid, btn) {
    const original = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Gerando...';
    }
    try {
        const res = await fetch('alunos.php?ajax=magic_link&uid=' + encodeURIComponent(uid), { credentials: 'same-origin' });
        const json = await res.json();
        if (!json || !json.ok || !json.link) throw new Error((json && json.message) || 'Nao foi possivel gerar o link.');
        try {
            await navigator.clipboard.writeText(json.link);
        } catch (copyErr) {
            window.prompt('Copie o link de acesso direto:', json.link);
        }
        if (btn) btn.textContent = 'Link copiado';
        setTimeout(function(){ if (btn) btn.textContent = original; }, 1800);
    } catch (e) {
        const msg = e && e.message ? e.message : 'Erro ao copiar link.';
        alert(msg);
        if (btn) btn.textContent = original;
    } finally {
        if (btn) btn.disabled = false;
    }
}
function abrirRetorno(uid, nome) {
    document.getElementById('m-retorno-uid').value = uid;
    document.getElementById('m-retorno-nome').textContent = 'Aluno: ' + nome;
    document.getElementById('m-retorno-modelo').value = '';
    document.getElementById('m-retorno-assunto').value = '';
    document.getElementById('m-retorno-mensagem').value = '';
    document.getElementById('modal-retorno').classList.add('open');
}
function abrirReagendarLive(uid, nome) {
    document.getElementById('m-reagendar-live-uid').value = uid;
    document.getElementById('m-reagendar-live-nome').textContent = 'Aluno: ' + nome;
    document.getElementById('m-reagendar-live-data').value = '';
    document.getElementById('modal-reagendar-live').classList.add('open');
}
function carregarModeloRetorno(id) {
    var modelo = RETORNO_MODELOS.find(function(m) { return String(m.id) === String(id); });
    if (!modelo) return;
    document.getElementById('m-retorno-tipo').value = modelo.tipo || 'vendas';
    document.getElementById('m-retorno-assunto').value = modelo.assunto || '';
    document.getElementById('m-retorno-mensagem').value = modelo.mensagem || '';
}
function fecharModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
