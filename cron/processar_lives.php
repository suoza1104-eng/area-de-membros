<?php
// /cron/processar_lives.php
// Dispara webhooks de live por turma (fila de alunos) quando chegar a data/hora configurada.
// Também dispara para o SuperFuncionário se sf_enabled=1 na turma.
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/superfuncionario_dispatcher.php';
require_once __DIR__ . '/../app/manychat_dispatcher.php';
require_once __DIR__ . '/../app/webhook_dispatcher.php';

$pdo = getPDO();
$agora = date('Y-m-d H:i:s');

/**
 * Verifica se uma tabela existe.
 */
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Retorna o primeiro nome de tabela que existir.
 */
function first_existing_table(PDO $pdo, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) return $t;
    }
    return null;
}

/**
 * Sanitiza CSV de IDs (ex.: "1,2,3") => [1,2,3]
 */
function csv_ids(?string $csv): array {
    if (!$csv) return [];
    $parts = array_filter(array_map('trim', explode(',', $csv)));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $n = (int)$p;
        if ($n > 0) $out[] = $n;
    }
    return array_values(array_unique($out));
}

function live_filter_config($raw): array {
    $cfg = [
        'include_any'      => [],
        'exclude_any'      => [],
        'exclude_cert'     => 0,
        'exclude_zero'     => 0,
        'exclude_purchase' => 0,
        'exclude_rescheduled' => 1,
    ];

    $raw = trim((string)$raw);
    if ($raw === '') return $cfg;

    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach (['include_any', 'exclude_any'] as $k) {
            $cfg[$k] = array_values(array_unique(array_filter(array_map('intval', (array)($json[$k] ?? [])), fn($v) => $v > 0)));
        }
        foreach (['exclude_cert', 'exclude_zero', 'exclude_purchase'] as $k) {
            $cfg[$k] = (int)(!!($json[$k] ?? 0));
        }
        $cfg['exclude_rescheduled'] = array_key_exists('exclude_rescheduled', $json) ? (int)(!!$json['exclude_rescheduled']) : 1;
        return $cfg;
    }

    // Compatibilidade com formato antigo CSV: tratava como tags obrigatórias.
    $cfg['include_any'] = csv_ids($raw);
    return $cfg;
}

function user_has_any_tag(PDO $pdo, ?string $tagRelTable, int $userId, array $tagIds): bool {
    if (!$tagRelTable || $userId <= 0 || !$tagIds) return false;
    try {
        $in = implode(',', array_fill(0, count($tagIds), '?'));
        $st = $pdo->prepare("SELECT 1 FROM `$tagRelTable` WHERE user_id = ? AND tag_id IN ($in) LIMIT 1");
        $st->execute(array_merge([$userId], $tagIds));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_has_certificate(PDO $pdo, int $userId): bool {
    if ($userId <= 0 || !table_exists($pdo, 'certificates')) return false;
    try {
        $statusSql = column_exists($pdo, 'certificates', 'status') ? " AND status = 'emitido'" : "";
        $st = $pdo->prepare("SELECT 1 FROM certificates WHERE user_id = :u$statusSql LIMIT 1");
        $st->execute([':u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_has_purchase(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        if (table_exists($pdo, 'hotmart_sales')) {
            $st = $pdo->prepare("SELECT 1 FROM hotmart_sales WHERE matched_user_id = :u AND LOWER(COALESCE(status,'')) IN ('aprovado','completo','approved','complete','paid') LIMIT 1");
            $st->execute([':u' => $userId]);
            if ($st->fetchColumn()) return true;
        }
        if (table_exists($pdo, 'live_event_recebimentos') && table_exists($pdo, 'live_events')) {
            $st = $pdo->prepare("SELECT 1 FROM live_event_recebimentos ler JOIN live_events le ON le.id = ler.event_id WHERE ler.user_id = :u AND le.tipo = 'compra' LIMIT 1");
            $st->execute([':u' => $userId]);
            if ($st->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function user_has_active_live_reschedule(PDO $pdo, int $userId, ?string $turmaLiveAt): bool {
    if ($userId <= 0 || !table_exists($pdo, 'reagendamentos_live')) return false;
    try {
        $st = $pdo->prepare("
            SELECT new_turma_live_at
              FROM reagendamentos_live
             WHERE user_id = :u
               AND status IN ('reagendado', 'enviado')
               AND new_turma_live_at IS NOT NULL
               AND new_turma_live_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          ORDER BY created_at DESC, id DESC
             LIMIT 1
        ");
        $st->execute([':u' => $userId]);
        $newLiveAt = (string)($st->fetchColumn() ?: '');
        if ($newLiveAt === '') return false;

        $newTs = strtotime($newLiveAt);
        $turmaTs = $turmaLiveAt ? strtotime($turmaLiveAt) : false;
        if (!$newTs || !$turmaTs) return true;
        return abs($newTs - $turmaTs) > 60;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_live_dispatch_log_table(PDO $pdo): void {
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
            if (!column_exists($pdo, 'live_turma_dispatch_logs', $col)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }
}

function start_live_dispatch_log(PDO $pdo, array $turma): int {
    ensure_live_dispatch_log_table($pdo);
    try {
        $mensagemInicio = !empty($GLOBALS['manual_live_turma_id'])
            ? 'Administrador iniciou o processamento manual da turma.'
            : 'Cron iniciou processamento da turma.';
        $st = $pdo->prepare("
            INSERT INTO live_turma_dispatch_logs
                (turma_id, turma_codigo, planned_at, started_at, status, message)
            VALUES
                (:tid, :codigo, :planned, NOW(), 'iniciado', :message)
        ");
        $st->execute([
            ':tid' => (int)($turma['id'] ?? 0) ?: null,
            ':codigo' => (string)($turma['codigo'] ?? ''),
            ':planned' => $turma['live_disparo_data'] ?? null,
            ':message' => $mensagemInicio,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function finish_live_dispatch_log(PDO $pdo, int $logId, array $stats, string $status, string $message): void {
    if ($logId <= 0) return;
    try {
        $st = $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET finished_at = NOW(),
                   total_alunos = :total,
                   elegiveis = :elegiveis,
                   sf_ok = :sf_ok,
                   sf_fail = :sf_fail,
                   manychat_ok = :manychat_ok,
                   manychat_fail = :manychat_fail,
                   webhook_ok = :webhook_ok,
                   webhook_fail = :webhook_fail,
                   skipped_json = :skipped,
                   status = :status,
                   message = :message
             WHERE id = :id
             LIMIT 1
        ");
        $st->execute([
            ':total' => (int)($stats['total_alunos'] ?? 0),
            ':elegiveis' => (int)($stats['elegiveis'] ?? 0),
            ':sf_ok' => (int)($stats['sf_ok'] ?? 0),
            ':sf_fail' => (int)($stats['sf_fail'] ?? 0),
            ':manychat_ok' => (int)($stats['manychat_ok'] ?? 0),
            ':manychat_fail' => (int)($stats['manychat_fail'] ?? 0),
            ':webhook_ok' => (int)($stats['webhook_ok'] ?? 0),
            ':webhook_fail' => (int)($stats['webhook_fail'] ?? 0),
            ':skipped' => json_encode((array)($stats['skipped'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $status,
            ':message' => $message,
            ':id' => $logId,
        ]);
    } catch (Throwable $e) {}
}

/**
 * Formata uma data/hora para BR: dd/mm/aaaa HH:MM:SS
 * Aceita strings 'Y-m-d H:i:s' ou ISO. Se falhar, retorna o valor original.
 */
function dt_br(?string $dt): ?string {
    if (!$dt) return null;
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return $dt;
    }
}

/**
 * Conta quantas aulas obrigatórias existem (lessons.ativo=1 e conta_para_conclusao=1).
 * Fallback seguro caso coluna/tabela não exista.
 */
function total_aulas_obrigatorias(PDO $pdo): int {
    if (!table_exists($pdo, 'lessons')) return 0;

    // tenta com conta_para_conclusao
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1");
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // fallback: considera todas ativas como obrigatórias
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1");
            return (int)$st->fetchColumn();
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/**
 * Calcula andamento do aluno (0..100), baseado em lessons + lesson_progress.
 * Retorna [andamento, concluidas, total].
 */
function calc_andamento(PDO $pdo, int $userId, int $totalObrigatorias): array {
    if ($userId <= 0 || $totalObrigatorias <= 0) {
        return ['andamento' => 0, 'concluidas' => 0, 'total' => max(0, $totalObrigatorias)];
    }

    // caminho principal: join com lessons para respeitar "obrigatórias"
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) 
              FROM lesson_progress lp
              JOIN lessons l ON l.id = lp.lesson_id
             WHERE lp.user_id = :u
               AND lp.status = 'completed'
               AND l.ativo = 1
               AND l.conta_para_conclusao = 1
        ");
        $st->execute([':u' => $userId]);
        $concluidas = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // fallback: conta só progressos completed (sem join)
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id = :u AND status = 'completed'");
            $st->execute([':u' => $userId]);
            $concluidas = (int)$st->fetchColumn();
        } catch (Throwable $e2) {
            $concluidas = 0;
        }
    }

    $andamento = $totalObrigatorias > 0 ? (int)round(($concluidas / $totalObrigatorias) * 100) : 0;
    if ($andamento < 0) $andamento = 0;
    if ($andamento > 100) $andamento = 100;

    return ['andamento' => $andamento, 'concluidas' => $concluidas, 'total' => $totalObrigatorias];
}


/**
 * Monta payload padrão da live.
 */
function build_live_payload(array $turma, array $aluno): array {
    $jan_ini_iso  = $turma['janela_inicio'] ?? null;
    $jan_fim_iso  = $turma['janela_fim'] ?? null;
    $data_live_iso = $turma['data_live'] ?? null;
    $disparo_iso   = $turma['live_disparo_data'] ?? null;

    $aluno_live_iso = $aluno['turma_live_at'] ?? null;

    return [
        'evento'    => 'LIVE_TURMA_' . ($turma['codigo'] ?? ''),
        'turma'     => [
            'id'                => $turma['id'] ?? null,
            'codigo'            => $turma['codigo'] ?? null,

            // Datas em BR (mantém *_iso para compatibilidade)
            'janela_inicio'     => dt_br($jan_ini_iso),
            'janela_inicio_iso' => $jan_ini_iso,

            'janela_fim'        => dt_br($jan_fim_iso),
            'janela_fim_iso'    => $jan_fim_iso,

            'data_live'         => dt_br($data_live_iso),
            'data_live_iso'     => $data_live_iso,

            'live_disparo_data'     => dt_br($disparo_iso),
            'live_disparo_data_iso' => $disparo_iso,

            'webhook_live_url'  => $turma['webhook_live_url'] ?? null,
            'delay_ms'          => $turma['delay_ms'] ?? null,
            'live_filter_tag_ids' => $turma['live_filter_tag_ids'] ?? null,
        ],
        'aluno'     => [
            'id'       => $aluno['id'] ?? null,
            'nome'     => $aluno['nome'] ?? null,
            'email'    => $aluno['email'] ?? null,
            'telefone' => $aluno['telefone'] ?? null,

            'codigo_turma' => $aluno['codigo_turma'] ?? null,

            'turma_live_at'     => dt_br($aluno_live_iso),
            'turma_live_at_iso' => $aluno_live_iso,

            // Andamento (0..100)
            'andamento'        => $aluno['andamento'] ?? null,
            'aulas_concluidas' => $aluno['aulas_concluidas'] ?? null,
            'aulas_totais'     => $aluno['aulas_totais'] ?? null,
        ],

        // Timestamp em BR (mantém ISO para compatibilidade)
        'timestamp'     => date('d/m/Y H:i:s'),
        'timestamp_iso' => date('c'),
    ];
}

/**
 * Envia POST JSON.
 */
function post_json(string $url, string $json, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);
    $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$st, $resp, $err];
}

// ----------------------------------------------------------------------------------
// 1) Detecta se coluna sf_enabled existe (migration gradual)
// ----------------------------------------------------------------------------------
$hasSfCol = false;
try {
    $pdo->query("SELECT sf_enabled FROM turmas LIMIT 0");
    $hasSfCol = true;
} catch (Throwable $e) {}

$hasManychatLiveRules = false;
try {
    $mcCfg = mc_get_config($pdo);
    if ((int)$mcCfg['is_enabled'] === 1 && trim((string)$mcCfg['token']) !== '') {
        $stMc = $pdo->query("SELECT 1 FROM manychat_rules WHERE is_active = 1 AND (evento = 'LIVE_TURMA' OR evento LIKE 'LIVE_TURMA\\_%') LIMIT 1");
        $hasManychatLiveRules = (bool)($stMc ? $stMc->fetchColumn() : false);
    }
} catch (Throwable $e) {}

// ----------------------------------------------------------------------------------
// 2) Pega turmas aptas: não disparadas, com data de disparo <= agora, e
//    que tenham PELO MENOS webhook habilitado OU SF habilitado.
// ----------------------------------------------------------------------------------
$sfOrClause = $hasSfCol ? "OR (sf_enabled = 1)" : "";
$manychatOrClause = $hasManychatLiveRules ? "OR 1=1" : "";

$manualTurmaId = (int)($GLOBALS['manual_live_turma_id'] ?? 0);
if ($manualTurmaId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE id = :id
           AND live_disparada = 0
           AND data_live IS NOT NULL
           AND data_live > :agora
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
         LIMIT 1
    ");
    $stmt->execute([':id' => $manualTurmaId, ':agora' => $agora]);
} else {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE live_disparada = 0
           AND live_disparo_data IS NOT NULL
           AND live_disparo_data <= :agora
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
      ORDER BY live_disparo_data ASC, id ASC
    ");
    $stmt->execute([':agora' => $agora]);
}
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];


// Total de aulas obrigatórias para cálculo de andamento
$totalObrigatoriasGlobal = total_aulas_obrigatorias($pdo);
if (!$turmas) {
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = ['ok' => false, 'message' => 'Turma indisponivel: ja disparada, live vencida ou sem integracao ativa.'];
        return;
    }
    exit; // sem nada para disparar
}

// ----------------------------------------------------------------------------------
// 2) Descobre tabela de relação user->tags (se existir), para filtro opcional
// ----------------------------------------------------------------------------------
$tagRelTable = first_existing_table($pdo, [
    'user_tags',
    'usuarios_tags',
    'aluno_tags',
    'users_tags',
    'tags_users',
    'user_tag_rel',
    'user_tag_relations',
]);

foreach ($turmas as $turma) {
    $codigo = (string)($turma['codigo'] ?? '');
    if ($codigo === '') continue;

    $claim = $pdo->prepare("UPDATE turmas SET live_disparada=1 WHERE id=:id AND live_disparada=0 LIMIT 1");
    $claim->execute([':id' => (int)$turma['id']]);
    if ($claim->rowCount() !== 1) continue;

    $dispatchLogId = start_live_dispatch_log($pdo, $turma);
    $dispatchStats = [
        'total_alunos' => 0,
        'elegiveis' => 0,
        'sf_ok' => 0,
        'sf_fail' => 0,
        'manychat_ok' => 0,
        'manychat_fail' => 0,
        'webhook_ok' => 0,
        'webhook_fail' => 0,
        'skipped' => [
            'include_tag_table_missing' => 0,
            'andamento_zero' => 0,
            'tag_excluida' => 0,
            'certificado' => 0,
            'compra' => 0,
            'live_reagendada' => 0,
        ],
    ];

    $url       = trim((string)($turma['webhook_live_url'] ?? ''));
    $whEnabled = (int)($turma['live_webhook_enabled'] ?? 1) === 1 && $url !== '';
    $sfEnabled = $hasSfCol && (int)($turma['sf_enabled'] ?? 0) === 1;

    $delay = (int)($turma['delay_ms'] ?? 500);
    if ($delay < 0) $delay = 0;
    if ($delay > 30000) $delay = 30000; // 30s de safety

    $filterCfg = live_filter_config($turma['live_filter_tag_ids'] ?? null);
    $includeTagIds = $filterCfg['include_any'];
    $excludeTagIds = $filterCfg['exclude_any'];
    $excludeCert = (int)$filterCfg['exclude_cert'] === 1;
    $excludeZero = (int)$filterCfg['exclude_zero'] === 1;
    $excludePurchase = (int)$filterCfg['exclude_purchase'] === 1;
    $excludeRescheduled = (int)$filterCfg['exclude_rescheduled'] === 1;
    $tagIds = $includeTagIds;

    // ----------------------------------------------------------------------------------
    // 3) Busca alunos da turma
    // ----------------------------------------------------------------------------------
    if ($tagIds && $tagRelTable) {
        // tenta colunas padrão: user_id + tag_id
        // (se seu rel tiver nomes diferentes, eu ajusto quando você mandar o SQL do rel)
        $in = implode(',', array_fill(0, count($tagIds), '?'));

        $sql = "
            SELECT u.*
              FROM users u
              JOIN `$tagRelTable` ut ON ut.user_id = u.id
             WHERE u.codigo_turma = ?
               AND ut.tag_id IN ($in)
          GROUP BY u.id
          ORDER BY u.id ASC
        ";

        $params = array_merge([$codigo], $tagIds);

        $stU = $pdo->prepare($sql);
        $stU->execute($params);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stU = $pdo->prepare("SELECT * FROM users WHERE codigo_turma = :c ORDER BY id ASC");
        $stU->execute([':c' => $codigo]);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $dispatchStats['total_alunos'] = count($alunos);

    // ----------------------------------------------------------------------------------
    // 4) Dispara para cada aluno — webhook e/ou SuperFuncionário
    // ----------------------------------------------------------------------------------
    foreach ($alunos as $aluno) {
        $uid = (int)($aluno['id'] ?? 0);
        if (function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $uid)) {
            $dispatchStats['skipped']['bloqueado'] = (int)($dispatchStats['skipped']['bloqueado'] ?? 0) + 1;
            continue;
        }

        $prog = calc_andamento($pdo, $uid, (int)$totalObrigatoriasGlobal);
        $aluno['andamento']        = $prog['andamento'];
        $aluno['aulas_concluidas'] = $prog['concluidas'];
        $aluno['aulas_totais']     = $prog['total'];
        if ($includeTagIds && !$tagRelTable) { $dispatchStats['skipped']['include_tag_table_missing']++; continue; }
        if ($excludeZero && (int)$aluno['andamento'] <= 0) { $dispatchStats['skipped']['andamento_zero']++; continue; }
        if ($excludeTagIds && user_has_any_tag($pdo, $tagRelTable, $uid, $excludeTagIds)) { $dispatchStats['skipped']['tag_excluida']++; continue; }
        if ($excludeCert && user_has_certificate($pdo, $uid)) { $dispatchStats['skipped']['certificado']++; continue; }
        if ($excludePurchase && user_has_purchase($pdo, $uid)) { $dispatchStats['skipped']['compra']++; continue; }
        if ($excludeRescheduled && user_has_active_live_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) { $dispatchStats['skipped']['live_reagendada']++; continue; }
        $dispatchStats['elegiveis']++;

        // Extra disponível para resolução de campos SF e payload do webhook
        $extra = [
            'codigo_turma'     => $turma['codigo'],
            'codigo_live'      => $turma['codigo_live'] ?? $turma['codigo'],
            'data_live'        => $turma['data_live'],
            'data_live_br'     => (function(?string $d): ?string {
                if (!$d) return null;
                try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return $d; }
            })($turma['data_live']),
            'andamento'        => $aluno['andamento'],
            'aulas_concluidas' => $aluno['aulas_concluidas'],
            'aulas_totais'     => $aluno['aulas_totais'],
        ];

        // --- Webhook ---
        if ($whEnabled) {
            $payload = build_live_payload($turma, $aluno);
            $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);

            [$status, $resp, $err] = post_json($url, $json ?: '{}', 15);
            if ($status >= 200 && $status < 300 && !$err) $dispatchStats['webhook_ok']++;
            else $dispatchStats['webhook_fail']++;

            try {
                if (table_exists($pdo, 'webhook_logs')) {
                    $pdo->prepare("
                        INSERT INTO webhook_logs
                            (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
                        VALUES (NULL, :u, :e, :p, :s, :b, :er, NOW())
                    ")->execute([
                        ':u'  => $aluno['id'] ?? null,
                        ':e'  => 'LIVE_TURMA_' . $codigo,
                        ':p'  => $json,
                        ':s'  => $status ?: null,
                        ':b'  => $resp,
                        ':er' => $err ?: null,
                    ]);
                }
            } catch (Throwable $e) {}
        }

        // --- Regras globais: webhook e SF com evento LIVE_TURMA ---
        $userStd = [
            'id'       => $aluno['id']       ?? null,
            'nome'     => $aluno['nome']      ?? null,
            'email'    => $aluno['email']     ?? null,
            'telefone' => $aluno['telefone']  ?? null,
        ];
        try {
            disparar_evento_webhooks($pdo, 'LIVE_TURMA', $userStd, $extra);
        } catch (Throwable $e) {}
        if ($sfEnabled) {
            try {
                $sfOk = sf_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
                if ($sfOk) $dispatchStats['sf_ok']++;
                else $dispatchStats['sf_fail']++;
            } catch (Throwable $e) {
                $dispatchStats['sf_fail']++;
            }
        }

        if (function_exists('whatsapp_event_notifications_dispatch')) {
            try {
                whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA', $userStd, $extra);
                whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
            } catch (Throwable $e) {}
        }

        if ($hasManychatLiveRules) {
            try {
                $mcOkGlobal = mc_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
                $mcOkTurma = mc_disparar_evento($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
                if ($mcOkGlobal || $mcOkTurma) $dispatchStats['manychat_ok']++;
                else $dispatchStats['manychat_fail']++;
            } catch (Throwable $e) {
                $dispatchStats['manychat_fail']++;
            }
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    // ----------------------------------------------------------------------------------
    // 5) Marca turma como disparada (mesmo sem alunos elegíveis)
    // ----------------------------------------------------------------------------------
    $finalStatus = ($dispatchStats['sf_fail'] > 0 || $dispatchStats['manychat_fail'] > 0 || $dispatchStats['webhook_fail'] > 0) ? 'concluido_com_falhas' : 'concluido';
    $message = 'Turma marcada como disparada.';
    if ($dispatchStats['elegiveis'] <= 0) $message = 'Turma marcada como disparada, mas nenhum aluno ficou elegivel apos os filtros.';
    finish_live_dispatch_log($pdo, $dispatchLogId, $dispatchStats, $finalStatus, $message);
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = [
            'ok' => true,
            'message' => $message,
            'stats' => $dispatchStats,
        ];
    }
}
