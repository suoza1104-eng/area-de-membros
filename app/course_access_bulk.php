<?php
declare(strict_types=1);

require_once __DIR__ . '/course_access.php';

/**
 * course_access_status() de varios alunos de uma vez (listas do admin): mesmo
 * resultado campo a campo, mas com 4 consultas no total em vez de 4-5 por aluno.
 * Devolve [user_id => status]. Qualquer mudanca de regra em course_access_status
 * precisa ser repetida aqui.
 */
function course_access_status_bulk(PDO $pdo, array $userIds): array
{
    course_access_ensure_schema($pdo);
    $default = [
        'enabled' => false,
        'allowed' => true,
        'expired' => false,
        'lifetime' => false,
        'expires_at' => null,
        'expires_at_iso' => null,
        'remaining_seconds' => null,
        'checkout_url' => '',
        'message' => '',
        'countdown_enabled' => false,
        'turma_codigo' => '',
        'offer_codes' => [],
        'access_days' => null,
        'grant_type' => null,
        'is_paid' => false,
        'grant_source' => null,
    ];
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    $out = [];
    foreach ($userIds as $uid) $out[(int)$uid] = $default;
    if (!$ids) return $out;
    $in = implode(',', $ids);

    try {
        $users = [];
        foreach ($pdo->query("SELECT * FROM users WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $users[(int)$row['id']] = $row;
        }

        // Mesma ordem de course_access_lifetime_entitlement: pago primeiro, mais recente.
        $grants = [];
        $st = $pdo->query("SELECT user_id, granted_at, transaction_code, offer_code, source, grant_type, is_paid
            FROM course_lifetime_access
            WHERE user_id IN ($in)
            ORDER BY user_id ASC, is_paid DESC, granted_at DESC, id DESC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $uid = (int)$row['user_id'];
            if (!isset($grants[$uid])) $grants[$uid] = $row;
        }

        $codes = [];
        foreach ($users as $user) {
            $code = course_access_user_turma_code($user);
            if ($code !== '') $codes[$code] = true;
        }
        $turmas = [];
        if ($codes) {
            $ph = implode(',', array_fill(0, count($codes), '?'));
            $st = $pdo->prepare("SELECT * FROM turmas WHERE codigo IN ($ph) ORDER BY id ASC");
            $st->execute(array_keys($codes));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $code = (string)$row['codigo'];
                if (!isset($turmas[$code])) $turmas[$code] = $row;
            }
        }

        // Ultima inscricao do aluno em cada turma (inicio do prazo no modo "cadastro").
        $lastEnroll = [];
        try {
            $st = $pdo->query("SELECT user_id, codigo_turma, MAX(created_at) AS created_at
                FROM inscricao_logs
                WHERE user_id IN ($in)
                GROUP BY user_id, codigo_turma");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $lastEnroll[(int)$row['user_id']][(string)$row['codigo_turma']] = (string)($row['created_at'] ?? '');
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        @error_log('course_access_status_bulk: ' . $e->getMessage());
        // Sem os dados em lote: cai no calculo aluno a aluno.
        foreach ($ids as $uid) $out[$uid] = course_access_status($pdo, $uid);
        return $out;
    }

    foreach ($ids as $uid) {
        $status = $default;
        try {
            $user = $users[$uid] ?? null;
            if (!$user) { $out[$uid] = $status; continue; }

            $turmaCodigo = course_access_user_turma_code($user);
            $status['turma_codigo'] = $turmaCodigo;

            $grant = $grants[$uid] ?? null;
            if ($grant) {
                $status['lifetime'] = true;
                $status['lifetime_granted_at'] = $grant['granted_at'] ?? null;
                $status['lifetime_transaction_code'] = $grant['transaction_code'] ?? null;
                $status['grant_type'] = (int)($grant['is_paid'] ?? 0) === 1 ? 'paid' : ($grant['grant_type'] ?? 'manual');
                $status['is_paid'] = (int)($grant['is_paid'] ?? 0) === 1;
                $status['grant_source'] = $grant['source'] ?? null;
            }

            if ($turmaCodigo === '') { $out[$uid] = $status; continue; }
            $turma = $turmas[$turmaCodigo] ?? null;
            if (!$turma) { $out[$uid] = $status; continue; }

            $status['checkout_url'] = course_access_checkout_url((string)($turma['lifetime_checkout_url'] ?? ''), $user);
            $status['message'] = trim((string)($turma['access_expired_message'] ?? ''));
            $status['offer_codes'] = course_access_offer_codes((string)($turma['lifetime_offer_codes'] ?? ''));
            $status['countdown_enabled'] = (int)($turma['access_countdown_enabled'] ?? 1) === 1;

            if ($grant) { $out[$uid] = $status; continue; }

            $enabled = (int)($turma['access_deadline_enabled'] ?? 0) === 1;
            $days = max(1, (int)($turma['access_deadline_days'] ?? 30));
            $startMode = (string)($turma['access_deadline_start'] ?? 'cadastro');
            $status['enabled'] = $enabled;
            $status['access_days'] = $days;
            if (!$enabled) { $out[$uid] = $status; continue; }

            $startAt = null;
            if ($startMode === 'live') {
                foreach (['turma_live_at', 'data_live'] as $column) {
                    if (!empty($user[$column])) {
                        $startAt = (string)$user[$column];
                        break;
                    }
                }
                if (!$startAt && !empty($turma['data_live'])) $startAt = (string)$turma['data_live'];
            } else {
                $startAt = (string)($lastEnroll[$uid][$turmaCodigo] ?? '');
                if (!$startAt) $startAt = (string)($user['created_at'] ?? '');
            }
            if (!$startAt) { $out[$uid] = $status; continue; }

            $start = new DateTimeImmutable($startAt);
            $expires = $start->modify('+' . $days . ' days');
            $now = new DateTimeImmutable('now');
            $remaining = $expires->getTimestamp() - $now->getTimestamp();
            $status['start_at'] = $start->format('Y-m-d H:i:s');
            $status['expires_at'] = $expires->format('Y-m-d H:i:s');
            $status['expires_at_iso'] = $expires->format(DateTimeInterface::ATOM);
            $status['remaining_seconds'] = max(0, $remaining);
            $status['expired'] = $remaining <= 0;
            $status['allowed'] = $remaining > 0;
            $out[$uid] = $status;
        } catch (Throwable $e) {
            @error_log('course_access_status_bulk ' . $uid . ': ' . $e->getMessage());
            $out[$uid] = $status; // como no original: o que ja foi preenchido
        }
    }
    return $out;
}
