<?php
// FILE: app/live_turma_filters.php
// Filtros de publico do disparo de live por turma (SF e Sino usam os mesmos)
// e calculo de andamento. Extraido de cron/processar_lives.php: os dois crons
// rodam no mesmo processo do cron_manager, por isso cada funcao e protegida.
declare(strict_types=1);

if (!function_exists('table_exists')) {
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
}

if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
            $stmt->execute([':c' => $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('first_existing_table')) {
    /**
     * Retorna o primeiro nome de tabela que existir.
     */
    function first_existing_table(PDO $pdo, array $candidates): ?string {
        foreach ($candidates as $t) {
            if (table_exists($pdo, $t)) return $t;
        }
        return null;
    }
}

if (!function_exists('csv_ids')) {
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
}

if (!function_exists('live_filter_config')) {
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
    
        $cfg['include_any'] = csv_ids($raw);
        return $cfg;
    }
}

if (!function_exists('user_has_any_tag')) {
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
}

if (!function_exists('user_has_certificate')) {
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
}

if (!function_exists('user_has_purchase')) {
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
}

if (!function_exists('user_has_active_live_reschedule')) {
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
}

if (!function_exists('total_aulas_obrigatorias')) {
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
}

if (!function_exists('calc_andamento')) {
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
}

