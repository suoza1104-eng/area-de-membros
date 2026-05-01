<?php
// /cron/processar_lives.php
// Dispara webhooks de live por turma (fila de alunos) quando chegar a data/hora configurada.
// Também dispara para o SuperFuncionário se sf_enabled=1 na turma.
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/superfuncionario_dispatcher.php';

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

// ----------------------------------------------------------------------------------
// 2) Pega turmas aptas: não disparadas, com data de disparo <= agora, e
//    que tenham PELO MENOS webhook habilitado OU SF habilitado.
// ----------------------------------------------------------------------------------
$sfOrClause = $hasSfCol ? "OR (sf_enabled = 1)" : "";

$stmt = $pdo->prepare("
    SELECT *
      FROM turmas
     WHERE live_disparada = 0
       AND live_disparo_data IS NOT NULL
       AND live_disparo_data <= :agora
       AND (
           (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
           $sfOrClause
       )
  ORDER BY live_disparo_data ASC, id ASC
");
$stmt->execute([':agora' => $agora]);
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];


// Total de aulas obrigatórias para cálculo de andamento
$totalObrigatoriasGlobal = total_aulas_obrigatorias($pdo);
if (!$turmas) {
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

    $url       = trim((string)($turma['webhook_live_url'] ?? ''));
    $whEnabled = (int)($turma['live_webhook_enabled'] ?? 1) === 1 && $url !== '';
    $sfEnabled = $hasSfCol && (int)($turma['sf_enabled'] ?? 0) === 1;

    $delay = (int)($turma['delay_ms'] ?? 500);
    if ($delay < 0) $delay = 0;
    if ($delay > 30000) $delay = 30000; // 30s de safety

    $tagIds = csv_ids($turma['live_filter_tag_ids'] ?? null);

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

    // ----------------------------------------------------------------------------------
    // 4) Dispara para cada aluno — webhook e/ou SuperFuncionário
    // ----------------------------------------------------------------------------------
    foreach ($alunos as $aluno) {
        $uid = (int)($aluno['id'] ?? 0);
        $prog = calc_andamento($pdo, $uid, (int)$totalObrigatoriasGlobal);
        $aluno['andamento']        = $prog['andamento'];
        $aluno['aulas_concluidas'] = $prog['concluidas'];
        $aluno['aulas_totais']     = $prog['total'];

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

        // --- SuperFuncionário ---
        if ($sfEnabled) {
            try {
                sf_disparar_live_turma($pdo, $turma, $aluno, $extra);
            } catch (Throwable $e) {
                // falha de SF não para o loop
            }
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    // ----------------------------------------------------------------------------------
    // 5) Marca turma como disparada (mesmo sem alunos elegíveis)
    // ----------------------------------------------------------------------------------
    $upd = $pdo->prepare("UPDATE turmas SET live_disparada = 1 WHERE id = :id");
    $upd->execute([':id' => $turma['id']]);
}
