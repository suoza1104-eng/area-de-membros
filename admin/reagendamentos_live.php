<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$pdo = getPDO();
$menu = 'reagendamentos_live';
$page_title = 'Reagendamentos de Live';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rl_admin_dt(?string $v): string {
    if (!$v) return '-';
    try { return (new DateTime((string)$v))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return (string)$v; }
}
function rl_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function rl_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function rl_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
function rl_pct(int $parte, int $total): string {
    if ($total <= 0) return '0,0%';
    return number_format(($parte / $total) * 100, 1, ',', '.') . '%';
}
function rl_make_token_link(string $token): string {
    $publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
    return $publicBase . '/public/reagendar_live.php?t=' . urlencode($token);
}
function rl_parse_offset_minutes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $sign = 1;
    if ($v[0] === '-') { $sign = -1; $v = substr($v, 1); }
    elseif ($v[0] === '+') { $v = substr($v, 1); }
    if (!preg_match('/^(\d{1,3})(?::([0-5]\d))?$/', $v, $m)) return 0;
    return $sign * (((int)$m[1] * 60) + (int)($m[2] ?? 0));
}
function rl_format_offset(int $minutes): string {
    $sign = $minutes < 0 ? '-' : '';
    $abs = abs($minutes);
    return $sign . intdiv($abs, 60) . ':' . str_pad((string)($abs % 60), 2, '0', STR_PAD_LEFT);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS live_reschedule_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by_admin_id INT NULL,
        UNIQUE KEY uk_live_reschedule_token (token),
        KEY idx_live_reschedule_user (user_id),
        KEY idx_live_reschedule_expires (expires_at),
        KEY idx_live_reschedule_used (used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        KEY idx_reag_live_created (created_at),
        KEY idx_reag_live_new_live (new_turma_live_at),
        KEY idx_reag_live_disparo (sf_disparo_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        "ALTER TABLE reagendamentos_live ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'reagendado'",
        "ALTER TABLE reagendamentos_live ADD COLUMN live_url TEXT NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_disparo_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_delay_ms INT NOT NULL DEFAULT 500",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_sent_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN expired_checked_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN ip VARCHAR(64) NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN user_agent VARCHAR(250) NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN origem VARCHAR(30) NULL AFTER user_agent",
        "ALTER TABLE reagendamentos_live ADD COLUMN webhook_url TEXT NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    // A tela continua carregando para exibir o erro nas acoes dependentes.
}

$msg = '';
$msgTipo = 'ok';
$generatedLink = '';

$opcoesN = (int)get_setting('reagendar_opcoes_qtd', (string)get_setting('reagendar_next_lives_count', '3'));
if ($opcoesN < 1) $opcoesN = 3;
$ttlHours = (int)get_setting('reagendar_token_ttl_hours', '72');
if ($ttlHours < 1) $ttlHours = 72;
$windowDays = (int)get_setting('reagendar_window_days', '15');
if ($windowDays < 1) $windowDays = 15;
$liveUrl = (string)get_setting('reagendar_live_url', '');
$liveTime = (string)get_setting('reagendar_live_time', '19:30');
if (!preg_match('/^\d{2}:\d{2}$/', $liveTime)) $liveTime = '19:30';
$blackoutRaw = (string)get_setting('reagendar_blackout_dates', '');
$blackoutDates = array_values(array_filter(array_map('trim', explode(',', $blackoutRaw))));
$dispatchOffsetMin = (int)get_setting('reagendar_dispatch_offset_min', '0');
$dispatchOffsetText = rl_format_offset($dispatchOffsetMin);
$dispatchDelayMs = (int)get_setting('reagendar_dispatch_delay_ms', '500');
if ($dispatchDelayMs < 0) $dispatchDelayMs = 500;
$expireGraceMin = (int)get_setting('reagendar_expire_grace_min', '60');
if ($expireGraceMin < 0) $expireGraceMin = 60;
if ($expireGraceMin > 1440) $expireGraceMin = 1440;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'salvar_config') {
            $opcoesN = (int)($_POST['reagendar_opcoes_qtd'] ?? $opcoesN);
            if ($opcoesN < 1) $opcoesN = 1;
            if ($opcoesN > 30) $opcoesN = 30;

            $ttlHours = (int)($_POST['reagendar_token_ttl_hours'] ?? $ttlHours);
            if ($ttlHours < 1) $ttlHours = 1;
            if ($ttlHours > 720) $ttlHours = 720;

            $windowDays = (int)($_POST['reagendar_window_days'] ?? $windowDays);
            if ($windowDays < 1) $windowDays = 1;
            if ($windowDays > 365) $windowDays = 365;

            $liveUrl = trim((string)($_POST['reagendar_live_url'] ?? ''));
            $liveTime = trim((string)($_POST['reagendar_live_time'] ?? '19:30'));
            if (!preg_match('/^\d{2}:\d{2}$/', $liveTime)) $liveTime = '19:30';
            $blackoutDates = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($_POST['reagendar_blackout_dates'] ?? ''))))));
            $dispatchOffsetText = trim((string)($_POST['reagendar_dispatch_offset'] ?? '0:00'));
            $dispatchOffsetMin = rl_parse_offset_minutes($dispatchOffsetText);
            $dispatchOffsetText = rl_format_offset($dispatchOffsetMin);
            $dispatchDelayMs = (int)($_POST['reagendar_dispatch_delay_ms'] ?? 500);
            if ($dispatchDelayMs < 0) $dispatchDelayMs = 0;
            if ($dispatchDelayMs > 30000) $dispatchDelayMs = 30000;
            $expireGraceMin = (int)($_POST['reagendar_expire_grace_min'] ?? 60);
            if ($expireGraceMin < 0) $expireGraceMin = 0;
            if ($expireGraceMin > 1440) $expireGraceMin = 1440;

            set_setting('reagendar_opcoes_qtd', (string)$opcoesN);
            set_setting('reagendar_next_lives_count', (string)$opcoesN);
            set_setting('reagendar_token_ttl_hours', (string)$ttlHours);
            set_setting('reagendar_window_days', (string)$windowDays);
            set_setting('reagendar_live_url', $liveUrl);
            set_setting('reagendar_live_time', $liveTime);
            set_setting('reagendar_blackout_dates', implode(',', $blackoutDates));
            set_setting('reagendar_dispatch_offset_min', (string)$dispatchOffsetMin);
            set_setting('reagendar_dispatch_delay_ms', (string)$dispatchDelayMs);
            set_setting('reagendar_expire_grace_min', (string)$expireGraceMin);
            $msg = 'Configuracoes de reagendamento salvas.';
        } elseif ($acao === 'manual_reagendar') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $manualDt = trim((string)($_POST['manual_data_live'] ?? ''));
            if ($userId <= 0) throw new RuntimeException('Informe o ID do aluno.');
            if ($manualDt === '') throw new RuntimeException('Informe a nova data/hora da live.');
            $dLive = new DateTimeImmutable(str_replace('T', ' ', $manualDt));
            if ($dLive <= new DateTimeImmutable('now')) throw new RuntimeException('A nova data da live deve ser futura.');
            $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new RuntimeException('Aluno nao encontrado.');
            $oldCodigo = (string)($u['codigo_turma'] ?? ($u['turma_codigo'] ?? ''));
            $oldLive = (string)($u['turma_live_at'] ?? ($u['data_live'] ?? ''));
            $newLive = $dLive->format('Y-m-d H:i:s');
            $sets = [];
            $params = [':id'=>$userId];
            if (rl_col_exists($pdo, 'users', 'turma_live_at')) { $sets[] = 'turma_live_at=:tl'; $params[':tl'] = $newLive; }
            if (rl_col_exists($pdo, 'users', 'data_live')) { $sets[] = 'data_live=:dl'; $params[':dl'] = $newLive; }
            if (!$sets) throw new RuntimeException('Aluno sem campo de data da live.');
            $dispatchAt = $dLive->modify(($dispatchOffsetMin >= 0 ? '+' : '') . $dispatchOffsetMin . ' minutes')->format('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=:id LIMIT 1')->execute($params);
            $pdo->prepare("INSERT INTO reagendamentos_live (user_id, old_codigo_turma, new_codigo_turma, old_turma_live_at, new_turma_live_at, status, live_url, sf_disparo_at, sf_delay_ms, ip, user_agent, origem, webhook_url, created_at)
                VALUES (:u,:oc,:nc,:ol,:nl,'reagendado',:url,:sf,:delay,:ip,:ua,'suporte',:wh,NOW())")
                ->execute([':u'=>$userId, ':oc'=>$oldCodigo ?: null, ':nc'=>$oldCodigo ?: null, ':ol'=>$oldLive ?: null, ':nl'=>$newLive, ':url'=>$liveUrl ?: null, ':sf'=>$dispatchAt, ':delay'=>$dispatchDelayMs, ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null, ':ua'=>'admin_manual', ':wh'=>null]);
            $histId = (int)$pdo->lastInsertId();
            reagendamento_live_log($pdo, $histId, $userId, 'agendamento_criado', 'pendente', 'Reagendamento criado pela tela de reagendamentos.', [
                'new_turma_live_at' => $newLive,
                'sf_disparo_at' => $dispatchAt,
                'origem' => 'admin_reagendamentos_live',
            ]);
            $pdo->commit();
            disparar_webhooks('LIVE_REAGENDADA', $userId, [
                'reagendamento_id'=>$histId,
                'codigo_turma'=>$oldCodigo,
                'data_live'=>$dLive->format('d/m/Y H:i'),
                'data_live_iso'=>$newLive,
                'live_url'=>$liveUrl,
                'origem'=>'admin_manual',
                'reagendamento'=>['id'=>$histId,'turma_original'=>$oldCodigo,'live_antiga'=>rl_admin_dt($oldLive),'live_nova'=>$dLive->format('d/m/Y H:i'),'live_nova_iso'=>$newLive,'live_url'=>$liveUrl,'status'=>'reagendado'],
            ]);
            $msg = 'Aluno reagendado manualmente.';
        } elseif ($acao === 'gerar_link') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new RuntimeException('Informe o ID do aluno.');
            $st = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $userId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Aluno nao encontrado.');
            if (!rl_table_exists($pdo, 'live_reschedule_tokens')) throw new RuntimeException('Tabela de tokens indisponivel.');

            $token = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttlHours . ' hours')->format('Y-m-d H:i:s');
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            $pdo->prepare("INSERT INTO live_reschedule_tokens (user_id, token, expires_at, used_at, created_at, created_by_admin_id)
                VALUES (:u, :t, :e, NULL, NOW(), :a)")
                ->execute([
                    ':u' => $userId,
                    ':t' => $token,
                    ':e' => $expiresAt,
                    ':a' => $adminId > 0 ? $adminId : null,
                ]);
            $generatedLink = rl_make_token_link($token);
            $msg = 'Link de reagendamento gerado.';
        } elseif ($acao === 'revogar_token') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE live_reschedule_tokens SET used_at = COALESCE(used_at, NOW()) WHERE id = :id")
                    ->execute([':id' => $id]);
            }
            $msg = 'Link revogado.';
        }
    } catch (Throwable $e) {
        $msg = 'Erro: ' . $e->getMessage();
        $msgTipo = 'erro';
    }
}

$publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
$publicTokenExample = $publicBase . '/public/reagendar_live.php?t=SEU_TOKEN';
$publicAutoExample = $publicBase . '/public/reagendar_live.php?email=EMAIL_DO_ALUNO&telefone=TELEFONE_DO_ALUNO';
$generatorExample = BASE_URL_ADMIN . '/reagendar_link.php?user_id=ID_DO_ALUNO';

$nowSql = date('Y-m-d H:i:s');
$endSql = (new DateTimeImmutable('now'))->modify('+' . $windowDays . ' days')->format('Y-m-d H:i:s');

$kpiTokensAtivos = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NULL AND expires_at >= NOW()");
$kpiTokensUsados = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NOT NULL");
$kpiTokensExpirados = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NULL AND expires_at < NOW()");
$kpiLivesDisponiveis = rl_count($pdo, "SELECT COUNT(*) FROM turmas WHERE data_live >= :now AND data_live <= :end", [':now' => $nowSql, ':end' => $endSql]);

$fAluno = trim((string)($_GET['aluno'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));
$fTurmaNova = trim((string)($_GET['turma_nova'] ?? ''));
$fTurmaAntiga = trim((string)($_GET['turma_antiga'] ?? ''));
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo = trim((string)($_GET['to'] ?? ''));

$whereHist = [];
$paramsHist = [];
if ($fAluno !== '') {
    $whereHist[] = "(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR u.id = :aluno_id)";
    $paramsHist[':aluno'] = '%' . $fAluno . '%';
    $paramsHist[':aluno_id'] = ctype_digit($fAluno) ? (int)$fAluno : 0;
}
if ($fTurmaNova !== '') {
    $whereHist[] = "r.new_codigo_turma = :turma_nova";
    $paramsHist[':turma_nova'] = $fTurmaNova;
}
if ($fTurmaAntiga !== '') {
    $whereHist[] = "r.old_codigo_turma = :turma_antiga";
    $paramsHist[':turma_antiga'] = $fTurmaAntiga;
}
if ($fFrom !== '') {
    $whereHist[] = "r.created_at >= :from";
    $paramsHist[':from'] = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $whereHist[] = "r.created_at <= :to";
    $paramsHist[':to'] = $fTo . ' 23:59:59';
}
$whereHistSql = $whereHist ? 'WHERE ' . implode(' AND ', $whereHist) : '';

$liveEventsReady = rl_table_exists($pdo, 'live_event_recebimentos') && rl_table_exists($pdo, 'live_events');
$eventExpr = function(string $tipo): string {
    return "EXISTS (
        SELECT 1
        FROM live_event_recebimentos ler
        JOIN live_events le ON le.id = ler.event_id
        WHERE ler.user_id = r.user_id
          AND ler.status = 'processado'
          AND le.tipo = '$tipo'
          AND COALESCE(ler.processado_em, ler.recebido_em) >= r.created_at
        LIMIT 1
    )";
};
$exprAcessou = $liveEventsReady ? $eventExpr('acessou') : '0';
$exprOferta = $liveEventsReady ? $eventExpr('oferta') : '0';
$hotmartSalesReady = rl_table_exists($pdo, 'hotmart_sales')
    && rl_col_exists($pdo, 'hotmart_sales', 'matched_user_id')
    && rl_col_exists($pdo, 'hotmart_sales', 'status')
    && rl_col_exists($pdo, 'hotmart_sales', 'transaction_date');
$exprCompra = $hotmartSalesReady
    ? "EXISTS (
        SELECT 1
        FROM hotmart_sales s
        WHERE s.matched_user_id = r.user_id
          AND s.status IN ('Aprovado','Completo')
          AND s.transaction_date IS NOT NULL
          AND s.transaction_date >= r.created_at
        LIMIT 1
    )"
    : '0';

try {
    $st = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM($exprAcessou) AS acessou,
            SUM($exprOferta) AS oferta,
            SUM($exprCompra) AS compra,
            COUNT(DISTINCT r.user_id) AS alunos_unicos
        FROM reagendamentos_live r
        LEFT JOIN users u ON u.id = r.user_id
        $whereHistSql");
    $st->execute($paramsHist);
    $metricas = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $metricas = [];
}
$kpiReagFiltrados = (int)($metricas['total'] ?? 0);
$kpiEntrada = (int)($metricas['acessou'] ?? 0);
$kpiOferta = (int)($metricas['oferta'] ?? 0);
$kpiVenda = (int)($metricas['compra'] ?? 0);
$kpiAlunosUnicos = (int)($metricas['alunos_unicos'] ?? 0);
$kpiReagTotal = rl_count($pdo, "SELECT COUNT(*) FROM reagendamentos_live");
$kpiReag7 = rl_count($pdo, "SELECT COUNT(*) FROM reagendamentos_live WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

try {
    $st = $pdo->prepare("SELECT r.*, u.nome, u.email, u.telefone,
            ($exprAcessou) AS teve_acesso,
            ($exprOferta) AS teve_oferta,
            ($exprCompra) AS teve_compra,
            CASE
                WHEN r.status = 'expirou' THEN 'Expirou'
                WHEN ($exprCompra) THEN 'Comprou'
                WHEN ($exprOferta) THEN 'Ficou ate oferta'
                WHEN ($exprAcessou) THEN 'Acessou'
                ELSE 'Reagendado'
            END AS status_visual,
            (SELECT COUNT(*) FROM reagendamentos_live rr WHERE rr.user_id = r.user_id) AS frequencia_aluno
        FROM reagendamentos_live r
        LEFT JOIN users u ON u.id = r.user_id
        $whereHistSql
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 200");
    $st->execute($paramsHist);
    $historico = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $historico = [];
}

try {
    $st = $pdo->prepare("SELECT r.user_id, u.nome, u.email, u.telefone, COUNT(*) AS total, MAX(r.created_at) AS ultimo_reagendamento
        FROM reagendamentos_live r
        LEFT JOIN users u ON u.id = r.user_id
        $whereHistSql
        GROUP BY r.user_id, u.nome, u.email, u.telefone
        ORDER BY total DESC, ultimo_reagendamento DESC
        LIMIT 50");
    $st->execute($paramsHist);
    $frequencias = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $frequencias = [];
}
$kpiMaiorFreq = 0;
foreach ($frequencias as $fr) {
    $kpiMaiorFreq = max($kpiMaiorFreq, (int)($fr['total'] ?? 0));
}

try {
    $st = $pdo->prepare("SELECT DATE(r.created_at) AS dia, COUNT(*) AS total, SUM($exprCompra) AS vendas
        FROM reagendamentos_live r
        LEFT JOIN users u ON u.id = r.user_id
        $whereHistSql
        GROUP BY DATE(r.created_at)
        ORDER BY dia ASC
        LIMIT 60");
    $st->execute($paramsHist);
    $reagPorDia = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reagPorDia = [];
}
$maxReagDia = 0;
foreach ($reagPorDia as $rp) $maxReagDia = max($maxReagDia, (int)($rp['total'] ?? 0));
$chartLabels = [];
$chartReagData = [];
$chartVendaData = [];
foreach ($reagPorDia as $rp) {
    $chartLabels[] = date('d/m', strtotime((string)$rp['dia']));
    $chartReagData[] = (int)($rp['total'] ?? 0);
    $chartVendaData[] = (int)($rp['vendas'] ?? 0);
}

$whereTokens = [];
$paramsTokens = [];
if ($fAluno !== '') {
    $whereTokens[] = "(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR u.id = :aluno_id)";
    $paramsTokens[':aluno'] = '%' . $fAluno . '%';
    $paramsTokens[':aluno_id'] = ctype_digit($fAluno) ? (int)$fAluno : 0;
}
if ($fStatus === 'ativo') {
    $whereTokens[] = "t.used_at IS NULL AND t.expires_at >= NOW()";
} elseif ($fStatus === 'usado') {
    $whereTokens[] = "t.used_at IS NOT NULL";
} elseif ($fStatus === 'expirado') {
    $whereTokens[] = "t.used_at IS NULL AND t.expires_at < NOW()";
}
$whereTokensSql = $whereTokens ? 'WHERE ' . implode(' AND ', $whereTokens) : '';

try {
    $turmasFiltro = $pdo->query("SELECT DISTINCT codigo FROM turmas WHERE codigo IS NOT NULL AND codigo <> '' ORDER BY codigo ASC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $turmasFiltro = [];
}

try {
    $st = $pdo->prepare("SELECT t.*, u.nome, u.email, u.telefone
        FROM live_reschedule_tokens t
        LEFT JOIN users u ON u.id = t.user_id
        $whereTokensSql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 200");
    $st->execute($paramsTokens);
    $tokens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $tokens = [];
}

try {
    $alunosLiveWhere = [];
    if (rl_col_exists($pdo, 'users', 'codigo_turma')) $alunosLiveWhere[] = 'u.codigo_turma = turmas.codigo';
    if (rl_col_exists($pdo, 'users', 'turma_codigo')) $alunosLiveWhere[] = 'u.turma_codigo = turmas.codigo';
    $alunosSub = $alunosLiveWhere
        ? '(SELECT COUNT(*) FROM users u WHERE ' . implode(' OR ', $alunosLiveWhere) . ')'
        : '0';
    $st = $pdo->prepare("SELECT id, codigo, data_live,
        $alunosSub AS alunos
        FROM turmas
        WHERE data_live >= :now AND data_live <= :end
        ORDER BY data_live ASC, id ASC
        LIMIT 50");
    $st->execute([':now' => $nowSql, ':end' => $endSql]);
    $lives = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $lives = [];
}

require __DIR__ . '/_header.php';
?>
<style>
.rl-grid { display:grid; grid-template-columns:1fr; gap:16px; align-items:start; }
@media(max-width:1100px){ .rl-grid{grid-template-columns:1fr;} }
.rl-status { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.rl-status.ativo { background:var(--success-dim); color:#86efac; }
.rl-status.usado { background:var(--info-dim); color:#7dd3fc; }
.rl-status.expirado { background:var(--danger-dim); color:#fca5a5; }
.rl-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.rl-link-box { display:flex; gap:8px; align-items:center; min-width:0; }
.rl-link-box input { min-width:0; font-size:12px; }
.rl-table-small td { font-size:12px; }
.rl-copy { max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rl-event-pills { display:flex; gap:4px; flex-wrap:wrap; }
.rl-event-pill { display:inline-flex; align-items:center; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:700; border:1px solid var(--border); color:var(--muted); background:rgba(100,116,139,.08); }
.rl-event-pill.on.acesso { background:var(--info-dim); color:#7dd3fc; border-color:rgba(56,189,248,.25); }
.rl-event-pill.on.oferta { background:var(--warning-dim); color:#fcd34d; border-color:rgba(245,158,11,.25); }
.rl-event-pill.on.compra { background:var(--success-dim); color:#86efac; border-color:rgba(34,197,94,.25); }
.rl-chart-card { margin-bottom:16px; }
.rl-chart-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px; }
.rl-chart-wrap { height:300px; width:100%; }
.rl-chart-toggle { display:inline-flex; align-items:center; gap:8px; border:1px solid var(--border); border-radius:999px; padding:7px 11px; color:var(--muted); font-size:12px; cursor:pointer; background:rgba(15,23,42,.55); }
.rl-chart-toggle input { accent-color:var(--primary); }
.rl-calendar { border:1px solid var(--border); border-radius:14px; overflow:hidden; background:rgba(2,6,23,.35); }
.rl-calendar-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-bottom:1px solid var(--border); }
.rl-calendar-title { font-weight:800; color:var(--text); text-transform:capitalize; }
.rl-cal-nav { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:10px; background:var(--bg); color:var(--text); cursor:pointer; font-size:18px; line-height:1; }
.rl-cal-nav:hover { border-color:var(--primary); color:var(--primary); }
.rl-calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:1px; background:var(--border); }
.rl-cal-dow, .rl-cal-day { background:var(--bg); min-height:44px; }
.rl-cal-dow { display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:11px; font-weight:800; text-transform:uppercase; }
.rl-cal-day { min-height:78px; padding:8px; display:flex; flex-direction:column; justify-content:space-between; gap:6px; }
.rl-cal-day.out { opacity:.35; }
.rl-cal-day label { display:flex; align-items:center; justify-content:space-between; gap:8px; cursor:pointer; color:var(--text); font-weight:800; }
.rl-cal-day input { accent-color:var(--primary); }
.rl-cal-date { font-size:14px; }
.rl-cal-state { font-size:10px; color:var(--muted); }
.rl-cal-day.blocked { background:rgba(239,68,68,.08); }
.rl-cal-day.blocked .rl-cal-state { color:#fca5a5; }
@media(max-width:720px){ .rl-chart-wrap{height:240px;} .rl-cal-day{min-height:62px;padding:6px;} .rl-cal-dow{font-size:10px;} }
</style>

<?php if ($msg): ?>
<div class="alert <?= $msgTipo === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($generatedLink !== ''): ?>
<div class="alert alert-info">
    <div class="fw-700 mb-3">Link gerado</div>
    <div class="rl-link-box">
        <input type="text" id="generatedLink" value="<?= h($generatedLink) ?>" readonly>
        <button class="btn btn-primary btn-sm" type="button" onclick="copyText('generatedLink')">Copiar</button>
    </div>
</div>
<?php endif; ?>

<div class="topbar">
    <div>
        <div class="topbar-title">Reagendamentos de live</div>
        <div class="text-muted text-sm">Central para configurar a pagina publica, gerar links e acompanhar as trocas de turma/live feitas pelos alunos.</div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= h($publicBase . '/public/reagendar_live.php') ?>" target="_blank">Abrir pagina publica</a>
</div>

<div class="card rl-chart-card">
    <div class="rl-chart-head">
        <div class="card-header-title">Reagendamentos por dia</div>
        <label class="rl-chart-toggle">
            <input type="checkbox" id="toggleVendasReag" checked>
            Mostrar vendas dos reagendados
        </label>
    </div>
    <?php if (!$reagPorDia): ?>
        <div class="text-muted text-sm" style="padding:48px;text-align:center">Sem dados para o filtro atual.</div>
    <?php else: ?>
        <div class="rl-chart-wrap"><canvas id="reagLineChart"></canvas></div>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi kpi-g"><div class="kpi-label">Links ativos</div><div class="kpi-value"><?= number_format($kpiTokensAtivos, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-b"><div class="kpi-label">Links usados</div><div class="kpi-value"><?= number_format($kpiTokensUsados, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-r"><div class="kpi-label">Links expirados</div><div class="kpi-value"><?= number_format($kpiTokensExpirados, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-y"><div class="kpi-label">Reagendamentos</div><div class="kpi-value"><?= number_format($kpiReagFiltrados, 0, ',', '.') ?></div><div class="kpi-sub"><?= number_format($kpiReagTotal, 0, ',', '.') ?> total · <?= number_format($kpiReag7, 0, ',', '.') ?> em 7 dias</div></div>
    <div class="kpi kpi-b"><div class="kpi-label">Taxa de entrada</div><div class="kpi-value"><?= h(rl_pct($kpiEntrada, $kpiReagFiltrados)) ?></div><div class="kpi-sub"><?= number_format($kpiEntrada, 0, ',', '.') ?> acessaram a live</div></div>
    <div class="kpi kpi-o"><div class="kpi-label">Taxa ate oferta</div><div class="kpi-value"><?= h(rl_pct($kpiOferta, $kpiReagFiltrados)) ?></div><div class="kpi-sub"><?= number_format($kpiOferta, 0, ',', '.') ?> ficaram ate a oferta</div></div>
    <div class="kpi kpi-g"><div class="kpi-label">Conversao venda</div><div class="kpi-value"><?= h(rl_pct($kpiVenda, $kpiReagFiltrados)) ?></div><div class="kpi-sub"><?= number_format($kpiVenda, 0, ',', '.') ?> venda(s) Hotmart</div></div>
    <div class="kpi"><div class="kpi-label">Frequencia</div><div class="kpi-value"><?= number_format($kpiMaiorFreq, 0, ',', '.') ?>x</div><div class="kpi-sub"><?= number_format($kpiAlunosUnicos, 0, ',', '.') ?> aluno(s) no filtro</div></div>
    <div class="kpi kpi-o"><div class="kpi-label">Lives disponiveis</div><div class="kpi-value"><?= number_format($kpiLivesDisponiveis, 0, ',', '.') ?></div><div class="kpi-sub">janela de <?= (int)$windowDays ?> dia(s)</div></div>
</div>

<form method="get" class="filter-bar">
    <div class="filter-group" style="min-width:220px"><label>Aluno</label><input name="aluno" value="<?= h($fAluno) ?>" placeholder="Nome, email, telefone ou ID"></div>
    <div class="filter-group"><label>Turma nova</label><select name="turma_nova"><option value="">Todas</option><?php foreach ($turmasFiltro as $tc): ?><option value="<?= h($tc) ?>" <?= $fTurmaNova===(string)$tc?'selected':'' ?>><?= h($tc) ?></option><?php endforeach; ?></select></div>
    <div class="filter-group"><label>Turma antiga</label><select name="turma_antiga"><option value="">Todas</option><?php foreach ($turmasFiltro as $tc): ?><option value="<?= h($tc) ?>" <?= $fTurmaAntiga===(string)$tc?'selected':'' ?>><?= h($tc) ?></option><?php endforeach; ?></select></div>
    <div class="filter-group"><label>Status do link</label><select name="status"><option value="">Todos</option><option value="ativo" <?= $fStatus==='ativo'?'selected':'' ?>>Ativos</option><option value="usado" <?= $fStatus==='usado'?'selected':'' ?>>Usados</option><option value="expirado" <?= $fStatus==='expirado'?'selected':'' ?>>Expirados</option></select></div>
    <div class="filter-group"><label>De</label><input type="date" name="from" value="<?= h($fFrom) ?>"></div>
    <div class="filter-group"><label>Ate</label><input type="date" name="to" value="<?= h($fTo) ?>"></div>
    <div class="filter-actions"><button class="btn btn-primary btn-sm">Filtrar</button><a class="reset-link" href="reagendamentos_live.php">Limpar</a></div>
</form>

<div class="rl-grid">
    <div>
        <div class="card">
            <div class="card-header-title mb-3">Configuracoes</div>
            <form method="post">
                <input type="hidden" name="acao" value="salvar_config">
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Proximas lives exibidas</label>
                        <input type="number" min="1" max="30" name="reagendar_opcoes_qtd" value="<?= (int)$opcoesN ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Validade do link (horas)</label>
                        <input type="number" min="1" max="720" name="reagendar_token_ttl_hours" value="<?= (int)$ttlHours ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Janela de lives (dias)</label>
                        <input type="number" min="1" max="365" name="reagendar_window_days" value="<?= (int)$windowDays ?>">
                    </div>
                </div>
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Horario diario da live</label>
                        <input type="time" name="reagendar_live_time" value="<?= h($liveTime) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deslocamento do disparo</label>
                        <input type="text" id="dispatchOffset" name="reagendar_dispatch_offset" value="<?= h($dispatchOffsetText) ?>" placeholder="ex: -2:30" oninput="updateDispatchPreview()">
                        <div class="text-xs text-muted mt-2">Use <code>-2:30</code> para disparar 2h30 antes, <code>0:00</code> no horario da live ou <code>1:15</code> depois.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Delay entre disparos (ms)</label>
                        <input type="number" min="0" max="30000" name="reagendar_dispatch_delay_ms" value="<?= (int)$dispatchDelayMs ?>">
                    </div>
                </div>
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Prazo para considerar expirado (min)</label>
                        <input type="number" min="0" max="1440" name="reagendar_expire_grace_min" value="<?= (int)$expireGraceMin ?>">
                        <div class="text-xs text-muted mt-2">Ex.: live 19:30 e prazo 60: so dispara expirado a partir de 20:30 se o aluno nao acessou.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Previsualizacao do disparo</label>
                    <input type="text" id="dispatchPreview" value="" readonly>
                    <div class="text-xs text-muted mt-2">Baseado no proximo dia disponivel e no horario diario da live.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Link da live de repescagem</label>
                    <input type="url" name="reagendar_live_url" value="<?= h($liveUrl) ?>" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label">Dias indisponiveis</label>
                    <input type="hidden" id="blackoutDates" name="reagendar_blackout_dates" value="<?= h(implode(',', $blackoutDates)) ?>">
                    <div class="rl-calendar">
                        <div class="rl-calendar-head">
                            <button type="button" class="rl-cal-nav" onclick="changeBlackoutMonth(-1)" aria-label="Mes anterior">&lsaquo;</button>
                            <div class="rl-calendar-title" id="blackoutMonthTitle"></div>
                            <button type="button" class="rl-cal-nav" onclick="changeBlackoutMonth(1)" aria-label="Proximo mes">&rsaquo;</button>
                        </div>
                        <div class="rl-calendar-grid" id="blackoutCalendar"></div>
                    </div>
                    <div class="text-xs text-muted mt-2">Desmarque os dias em que nao havera live de repescagem.</div>
                </div>
                <button class="btn btn-primary">Salvar configuracoes</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header-title mb-3">Links da pagina publica</div>
            <div class="form-group">
                <label class="form-label">Com token</label>
                <div class="rl-link-box"><input id="linkToken" value="<?= h($publicTokenExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkToken')">Copiar</button></div>
            </div>
            <div class="form-group">
                <label class="form-label">Automatico por email e telefone</label>
                <div class="rl-link-box"><input id="linkAuto" value="<?= h($publicAutoExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkAuto')">Copiar</button></div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Gerador admin</label>
                <div class="rl-link-box"><input id="linkAdmin" value="<?= h($generatorExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkAdmin')">Copiar</button></div>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Historico de reagendamentos</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Aluno</th><th>Antes</th><th>Depois</th><th>Origem</th><th>Status</th><th>Eventos</th><th>Freq.</th><th>Quando</th></tr></thead>
                    <tbody>
                    <?php if (!$historico): ?>
                        <tr><td colspan="8" class="text-muted" style="text-align:center;padding:24px">Nenhum reagendamento encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($historico as $r): ?>
                        <tr>
                            <td>
                                <div class="fw-700"><?= h($r['nome'] ?? ('Aluno #' . (int)$r['user_id'])) ?></div>
                                <div class="text-xs text-muted">#<?= (int)$r['user_id'] ?> &middot; <?= h($r['email'] ?? '') ?></div>
                            </td>
                            <td>
                                <div class="fw-700"><?= h($r['old_codigo_turma'] ?: '-') ?></div>
                                <div class="text-xs text-muted"><?= h(rl_admin_dt($r['old_turma_live_at'] ?? null)) ?></div>
                            </td>
                            <td>
                                <div class="fw-700"><?= h($r['new_codigo_turma'] ?: '-') ?></div>
                                <div class="text-xs text-muted"><?= h(rl_admin_dt($r['new_turma_live_at'] ?? null)) ?></div>
                            </td>
                            <?php
                                $origemRaw = (string)($r['origem'] ?? '');
                                if ($origemRaw === '') {
                                    $origemRaw = ((string)($r['user_agent'] ?? '') === 'admin_manual') ? 'suporte' : 'aluno';
                                }
                                $origemLabel = $origemRaw === 'suporte' ? 'Suporte' : 'Aluno';
                            ?>
                            <td><span class="badge <?= $origemRaw === 'suporte' ? 'badge-neutral' : 'badge-info' ?>"><?= h($origemLabel) ?></span></td>
                            <td><span class="badge badge-info"><?= h($r['status_visual'] ?? 'Reagendado') ?></span></td>
                            <td>
                                <div class="rl-event-pills">
                                    <span class="rl-event-pill <?= !empty($r['teve_acesso']) ? 'on acesso' : '' ?>">Entrada</span>
                                    <span class="rl-event-pill <?= !empty($r['teve_oferta']) ? 'on oferta' : '' ?>">Oferta</span>
                                    <span class="rl-event-pill <?= !empty($r['teve_compra']) ? 'on compra' : '' ?>">Venda</span>
                                </div>
                            </td>
                            <td><span class="badge badge-neutral"><?= (int)($r['frequencia_aluno'] ?? 0) ?>x</span></td>
                            <td>
                                <div><?= h(rl_admin_dt($r['created_at'] ?? null)) ?></div>
                                <div class="text-xs text-muted"><?= h($r['ip'] ?? '') ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header-title mb-3">Gerar link para aluno</div>
            <form method="post">
                <input type="hidden" name="acao" value="gerar_link">
                <div class="form-group">
                    <label class="form-label">ID do aluno</label>
                    <input type="number" min="1" name="user_id" value="<?= h($_GET['user_id'] ?? '') ?>" placeholder="Ex: 123" required>
                </div>
                <button class="btn btn-primary">Gerar link</button>
            </form>
        </div>

        <div class="card" id="manual">
            <div class="card-header-title mb-3">Reagendar manualmente</div>
            <form method="post">
                <input type="hidden" name="acao" value="manual_reagendar">
                <div class="form-group">
                    <label class="form-label">ID do aluno</label>
                    <input type="number" min="1" name="user_id" value="<?= h($_GET['user_id'] ?? '') ?>" placeholder="Ex: 123" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nova data/hora da live</label>
                    <input type="datetime-local" name="manual_data_live" required>
                </div>
                <button class="btn btn-primary">Confirmar reagendamento</button>
            </form>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Frequencia de reagendamento por aluno</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Aluno</th><th>Qtd.</th><th>Ultimo</th></tr></thead>
                    <tbody>
                    <?php if (!$frequencias): ?>
                        <tr><td colspan="3" class="text-muted" style="text-align:center;padding:24px">Nenhuma frequencia encontrada no filtro.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($frequencias as $fr): ?>
                        <tr>
                            <td>
                                <div class="fw-700"><?= h($fr['nome'] ?? ('Aluno #' . (int)$fr['user_id'])) ?></div>
                                <div class="text-xs text-muted">#<?= (int)$fr['user_id'] ?> &middot; <?= h($fr['email'] ?? '') ?></div>
                            </td>
                            <td><span class="badge badge-primary"><?= (int)($fr['total'] ?? 0) ?>x</span></td>
                            <td><?= h(rl_admin_dt($fr['ultimo_reagendamento'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Links gerados</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Aluno</th><th>Status</th><th>Expira</th><th style="text-align:right">Acoes</th></tr></thead>
                    <tbody>
                    <?php if (!$tokens): ?>
                        <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px">Nenhum link encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tokens as $t):
                        $status = !empty($t['used_at']) ? 'usado' : ((strtotime((string)$t['expires_at']) < time()) ? 'expirado' : 'ativo');
                        $linkId = 'tok_' . (int)$t['id'];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-700"><?= h($t['nome'] ?? ('Aluno #' . (int)$t['user_id'])) ?></div>
                                <div class="text-xs text-muted">#<?= (int)$t['user_id'] ?> &middot; <?= h($t['email'] ?? '') ?></div>
                                <input id="<?= h($linkId) ?>" type="hidden" value="<?= h(rl_make_token_link((string)$t['token'])) ?>">
                            </td>
                            <td><span class="rl-status <?= h($status) ?>"><?= h($status) ?></span><?php if (!empty($t['used_at'])): ?><div class="text-xs text-muted"><?= h(rl_admin_dt($t['used_at'])) ?></div><?php endif; ?></td>
                            <td><?= h(rl_admin_dt($t['expires_at'] ?? null)) ?><div class="text-xs text-muted">Criado: <?= h(rl_admin_dt($t['created_at'] ?? null)) ?></div></td>
                            <td>
                                <div class="rl-actions">
                                    <button type="button" class="btn btn-ghost btn-xs" onclick="copyText('<?= h($linkId) ?>')">Copiar</button>
                                    <?php if ($status === 'ativo'): ?>
                                    <form method="post" onsubmit="return confirm('Revogar este link?')"><input type="hidden" name="acao" value="revogar_token"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-danger btn-xs">Revogar</button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Lives disponiveis para reagendar</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Turma</th><th>Data live</th><th>Alunos</th></tr></thead>
                    <tbody>
                    <?php if (!$lives): ?>
                        <tr><td colspan="3" class="text-muted" style="text-align:center;padding:24px">Nenhuma live futura dentro da janela configurada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lives as $l): ?>
                        <tr>
                            <td class="fw-700"><?= h($l['codigo'] ?? '') ?></td>
                            <td><?= h(rl_admin_dt($l['data_live'] ?? null)) ?></td>
                            <td><?= number_format((int)($l['alunos'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const BLACKOUT_INIT = <?= json_encode($blackoutDates, JSON_UNESCAPED_UNICODE) ?>;
const LIVE_TIME = <?= json_encode($liveTime) ?>;
const WINDOW_DAYS = <?= (int)$windowDays ?>;
const REAG_CHART_LABELS = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const REAG_CHART_DATA = <?= json_encode($chartReagData, JSON_UNESCAPED_UNICODE) ?>;
const REAG_CHART_VENDAS = <?= json_encode($chartVendaData, JSON_UNESCAPED_UNICODE) ?>;
let blackoutSelected = new Set(BLACKOUT_INIT);
let blackoutMonth = new Date();
blackoutMonth.setDate(1);
function parseOffsetMinutes(value) {
    value = String(value || '').trim();
    if (!value) return 0;
    var sign = 1;
    if (value[0] === '-') { sign = -1; value = value.slice(1); }
    if (value[0] === '+') value = value.slice(1);
    var m = value.match(/^(\d{1,3})(?::([0-5]\d))?$/);
    if (!m) return 0;
    return sign * ((parseInt(m[1], 10) * 60) + parseInt(m[2] || '0', 10));
}
function fmtBrDate(date) {
    return date.toLocaleString('pt-BR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'});
}
function isoLocalDate(d) {
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}
function syncBlackoutInput() {
    var input = document.getElementById('blackoutDates');
    if (input) input.value = Array.from(blackoutSelected).sort().join(',');
}
function buildBlackoutCalendar() {
    var box = document.getElementById('blackoutCalendar');
    var input = document.getElementById('blackoutDates');
    if (!box || !input) return;
    var title = document.getElementById('blackoutMonthTitle');
    var year = blackoutMonth.getFullYear();
    var month = blackoutMonth.getMonth();
    var first = new Date(year, month, 1);
    var start = new Date(year, month, 1 - first.getDay());
    if (title) title.textContent = first.toLocaleDateString('pt-BR', {month:'long', year:'numeric'});
    box.innerHTML = '';
    ['Dom','Seg','Ter','Qua','Qui','Sex','Sab'].forEach(function(dow) {
        var h = document.createElement('div');
        h.className = 'rl-cal-dow';
        h.textContent = dow;
        box.appendChild(h);
    });
    for (var i = 0; i < 42; i++) {
        var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        var key = isoLocalDate(d);
        var blocked = blackoutSelected.has(key);
        var cell = document.createElement('div');
        cell.className = 'rl-cal-day' + (d.getMonth() !== month ? ' out' : '') + (blocked ? ' blocked' : '');
        var label = document.createElement('label');
        var number = document.createElement('span');
        number.className = 'rl-cal-date';
        number.textContent = String(d.getDate()).padStart(2, '0');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = !blocked;
        cb.setAttribute('data-date', key);
        cb.onchange = function() {
            var date = this.getAttribute('data-date');
            if (this.checked) blackoutSelected.delete(date);
            else blackoutSelected.add(date);
            syncBlackoutInput();
            buildBlackoutCalendar();
            updateDispatchPreview();
        };
        label.appendChild(number);
        label.appendChild(cb);
        var state = document.createElement('div');
        state.className = 'rl-cal-state';
        state.textContent = blocked ? 'Indisponivel' : 'Disponivel';
        cell.appendChild(label);
        cell.appendChild(state);
        box.appendChild(cell);
    }
    syncBlackoutInput();
}
function changeBlackoutMonth(delta) {
    blackoutMonth = new Date(blackoutMonth.getFullYear(), blackoutMonth.getMonth() + delta, 1);
    buildBlackoutCalendar();
}
function updateDispatchPreview() {
    var out = document.getElementById('dispatchPreview');
    if (!out) return;
    var blackouts = blackoutSelected;
    var now = new Date();
    var live = null;
    for (var i = 0; i < WINDOW_DAYS; i++) {
        var d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i);
        var key = d.toISOString().slice(0, 10);
        if (blackouts.has(key)) continue;
        var parts = String(document.querySelector('[name="reagendar_live_time"]')?.value || LIVE_TIME || '19:30').split(':');
        d.setHours(parseInt(parts[0] || '19', 10), parseInt(parts[1] || '30', 10), 0, 0);
        if (d > now) { live = d; break; }
    }
    if (!live) { out.value = 'Nenhuma live disponivel na janela configurada.'; return; }
    live = new Date(live.getTime() + parseOffsetMinutes(document.getElementById('dispatchOffset')?.value || '0:00') * 60000);
    out.value = fmtBrDate(live);
}
function buildReagLineChart() {
    var canvas = document.getElementById('reagLineChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var vendasDataset = {
        label: 'Vendas dos reagendados',
        data: REAG_CHART_VENDAS,
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,.12)',
        tension: .35,
        pointRadius: 4,
        pointHoverRadius: 6,
        borderWidth: 2,
        fill: false
    };
    var chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: REAG_CHART_LABELS,
            datasets: [
                {
                    label: 'Reagendamentos',
                    data: REAG_CHART_DATA,
                    borderColor: '#facc15',
                    backgroundColor: 'rgba(250,204,21,.14)',
                    tension: .35,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2,
                    fill: true
                },
                vendasDataset
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#cbd5e1', boxWidth: 10, usePointStyle: true } },
                tooltip: { backgroundColor: '#0f172a', borderColor: '#334155', borderWidth: 1 }
            },
            scales: {
                x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,.12)' } },
                y: { beginAtZero: true, ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(148,163,184,.12)' } }
            }
        }
    });
    var toggle = document.getElementById('toggleVendasReag');
    if (toggle) {
        toggle.addEventListener('change', function() {
            chart.setDatasetVisibility(1, this.checked);
            chart.update();
        });
    }
}
document.addEventListener('DOMContentLoaded', function() {
    buildReagLineChart();
    buildBlackoutCalendar();
    updateDispatchPreview();
    var time = document.querySelector('[name="reagendar_live_time"]');
    if (time) time.addEventListener('input', updateDispatchPreview);
});
function copyText(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.value || el.textContent || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
        return;
    }
    if (el.select) {
        el.select();
        document.execCommand('copy');
    }
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
