<?php
declare(strict_types=1);

function enrollment_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $st->execute([':column' => $column]);
        return $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function enrollment_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS inscricao_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        codigo_turma VARCHAR(100) NULL,
        utm_source VARCHAR(255) NULL,
        utm_medium VARCHAR(255) NULL,
        utm_campaign VARCHAR(255) NULL,
        utm_term VARCHAR(255) NULL,
        utm_content VARCHAR(255) NULL,
        is_novo TINYINT(1) NOT NULL DEFAULT 0,
        access_type VARCHAR(30) NOT NULL DEFAULT 'free',
        source VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_il_user (user_id), INDEX idx_il_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!enrollment_column_exists($pdo, 'inscricao_logs', 'access_type')) {
        try { $pdo->exec("ALTER TABLE inscricao_logs ADD COLUMN access_type VARCHAR(30) NOT NULL DEFAULT 'free' AFTER is_novo"); } catch (Throwable $e) {}
    }
    if (!enrollment_column_exists($pdo, 'inscricao_logs', 'source')) {
        try { $pdo->exec("ALTER TABLE inscricao_logs ADD COLUMN source VARCHAR(80) NULL AFTER access_type"); } catch (Throwable $e) {}
    }

    // Repara uma unica vez cadastros antigos que gravavam somente o codigo da turma.
    $runBackfill = !function_exists('get_setting') || (string)get_setting('enrollment_turma_backfill_v1', '') !== '1';
    try {
        if ($runBackfill) {
        $turmaExpr = enrollment_column_exists($pdo, 'users', 'turma_codigo')
            ? "COALESCE(NULLIF(u.codigo_turma,''), NULLIF(u.turma_codigo,''))"
            : "NULLIF(u.codigo_turma,'')";
        $sets = [];
        $missing = [];
        if (enrollment_column_exists($pdo, 'users', 'data_live')) {
            $fallback = enrollment_column_exists($pdo, 'users', 'turma_live_at') ? 'u.turma_live_at, ' : '';
            $sets[] = "u.data_live = COALESCE(u.data_live, {$fallback}t.data_live)";
            $missing[] = 'u.data_live IS NULL';
        }
        if (enrollment_column_exists($pdo, 'users', 'turma_live_at')) {
            $fallback = enrollment_column_exists($pdo, 'users', 'data_live') ? 'u.data_live, ' : '';
            $sets[] = "u.turma_live_at = COALESCE(u.turma_live_at, {$fallback}t.data_live)";
            $missing[] = 'u.turma_live_at IS NULL';
        }
        if (enrollment_column_exists($pdo, 'users', 'codigo_live')) {
            $sets[] = "u.codigo_live = COALESCE(NULLIF(u.codigo_live,''), NULLIF(t.codigo_live,''))";
            $missing[] = "u.codigo_live IS NULL OR u.codigo_live = ''";
        }
        if ($sets) {
            $pdo->exec("UPDATE users u JOIN turmas t ON t.codigo = {$turmaExpr} SET " . implode(', ', $sets) .
                ' WHERE (' . implode(' OR ', $missing) . ')');
        }
            if (function_exists('set_setting')) set_setting('enrollment_turma_backfill_v1', '1');
        }
    } catch (Throwable $e) {
        @error_log('enrollment_backfill: ' . $e->getMessage());
    }
    $done = true;
}

function enrollment_find_turma(PDO $pdo, string $codigo = ''): array
{
    if ($codigo !== '') {
        $st = $pdo->prepare("SELECT * FROM turmas WHERE codigo = :codigo LIMIT 1");
        $st->execute([':codigo' => $codigo]);
    } else {
        $st = $pdo->prepare("SELECT * FROM turmas WHERE janela_inicio <= NOW() AND janela_fim >= NOW() ORDER BY janela_inicio DESC LIMIT 1");
        $st->execute();
    }
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function enrollment_find_user(PDO $pdo, string $email, string $telefone): ?array
{
    if ($email !== '') {
        $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = :email LIMIT 1");
        $st->execute([':email' => mb_strtolower($email)]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    $digits = preg_replace('/\D+/', '', $telefone) ?? '';
    if ($digits !== '') {
        $local = (strlen($digits) >= 12 && str_starts_with($digits, '55')) ? substr($digits, 2) : $digits;
        $st = $pdo->prepare("SELECT * FROM users WHERE telefone IN (:raw, :digits, :local, :country) LIMIT 1");
        $st->execute([':raw'=>$telefone, ':digits'=>$digits, ':local'=>$local, ':country'=>'55'.$local]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    return null;
}

/** Registra uma inscricao com as mesmas atribuicoes, independentemente do canal de entrada. */
function enrollment_register(PDO $pdo, array $input): array
{
    enrollment_ensure_schema($pdo);
    course_access_ensure_schema($pdo);

    $nome = trim((string)($input['nome'] ?? ''));
    $email = mb_strtolower(trim((string)($input['email'] ?? '')));
    $telefone = trim((string)($input['telefone'] ?? ''));
    if ($email === '' && $telefone === '') throw new InvalidArgumentException('Informe email ou telefone para inscrever o aluno.');

    $accessType = strtolower(trim((string)($input['access_type'] ?? 'free')));
    if (!in_array($accessType, ['free', 'lifetime'], true)) $accessType = 'free';
    $source = trim((string)($input['source'] ?? 'inscricao')) ?: 'inscricao';
    $turma = enrollment_find_turma($pdo, trim((string)($input['codigo_turma'] ?? '')));
    if (!$turma) throw new RuntimeException('Nenhuma turma valida foi encontrada para a inscricao.');

    $codigoTurma = trim((string)($turma['codigo'] ?? ''));
    $dataLive = trim((string)($turma['data_live'] ?? ''));
    $codigoLive = trim((string)($turma['codigo_live'] ?? ''));
    $utm = [];
    foreach (['utm_source','utm_medium','utm_campaign','utm_term','utm_content'] as $key) {
        $utm[$key] = trim((string)($input[$key] ?? ''));
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $existing = enrollment_find_user($pdo, $email, $telefone);
        $isNew = !$existing;
        $oldTurmaCodigo = $existing ? course_access_user_turma_code($existing) : '';
        $priorLifetimeGrant = $existing ? course_access_lifetime_entitlement($pdo, (int)$existing['id']) : null;
        $hadLifetime = $priorLifetimeGrant !== null;
        $sameTurma = !$isNew && $oldTurmaCodigo !== '' && $oldTurmaCodigo === $codigoTurma;
        if ($existing) {
            $sets = [];
            $params = [':id' => (int)$existing['id']];
            $values = [
                'nome'=>$nome, 'email'=>$email, 'telefone'=>$telefone,
                'codigo_turma'=>$codigoTurma, 'turma_codigo'=>$codigoTurma,
                'data_live'=>$dataLive, 'turma_live_at'=>$dataLive,
                'codigo_live'=>$codigoLive,
            ] + $utm;
            foreach ($values as $column => $value) {
                if ($value === '' || !enrollment_column_exists($pdo, 'users', $column)) continue;
                $param = ':v_' . $column;
                $sets[] = "`$column` = $param";
                $params[$param] = $value;
            }
            if ($sets) $pdo->prepare('UPDATE users SET '.implode(', ', $sets).' WHERE id = :id')->execute($params);
            $userId = (int)$existing['id'];
        } else {
            $columns = [];
            $holders = [];
            $params = [];
            $values = [
                'nome'=>$nome !== '' ? $nome : ($email !== '' ? $email : $telefone),
                'email'=>$email, 'telefone'=>$telefone,
                'codigo_turma'=>$codigoTurma, 'turma_codigo'=>$codigoTurma,
                'data_live'=>$dataLive, 'turma_live_at'=>$dataLive,
                'codigo_live'=>$codigoLive,
            ] + $utm;
            foreach ($values as $column => $value) {
                if (!enrollment_column_exists($pdo, 'users', $column)) continue;
                $columns[] = "`$column`";
                $param = ':v_' . $column;
                $holders[] = $param;
                $params[$param] = $value !== '' ? $value : null;
            }
            if (enrollment_column_exists($pdo, 'users', 'senha_hash')) {
                $columns[] = '`senha_hash`'; $holders[] = ':senha_hash';
                $params[':senha_hash'] = password_hash(preg_replace('/\D+/', '', $telefone) ?: bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
            }
            if (enrollment_column_exists($pdo, 'users', 'created_at')) {
                $columns[] = '`created_at`'; $holders[] = 'NOW()';
            }
            $pdo->prepare('INSERT INTO users ('.implode(',', $columns).') VALUES ('.implode(',', $holders).')')->execute($params);
            $userId = (int)$pdo->lastInsertId();
        }

        // Reenvio gratuito para a mesma turma nao renova o prazo. Nova turma,
        // primeira inscricao e conversao vitalicia continuam sendo historizadas.
        $renewedAccess = $isNew || !$sameTurma || $accessType === 'lifetime' || !empty($input['force_renew']);
        if ($renewedAccess) {
            $logAccessType = ($accessType === 'lifetime' || $hadLifetime) ? 'lifetime' : 'free';
            $pdo->prepare("INSERT INTO inscricao_logs
                (user_id,codigo_turma,utm_source,utm_medium,utm_campaign,utm_term,utm_content,is_novo,access_type,source,created_at)
                VALUES (:uid,:turma,:us,:um,:uc,:ut,:uco,:novo,:access_type,:source,NOW())")
                ->execute([
                    ':uid'=>$userId, ':turma'=>$codigoTurma, ':us'=>$utm['utm_source'] ?: null,
                    ':um'=>$utm['utm_medium'] ?: null, ':uc'=>$utm['utm_campaign'] ?: null,
                    ':ut'=>$utm['utm_term'] ?: null, ':uco'=>$utm['utm_content'] ?: null,
                    ':novo'=>$isNew ? 1 : 0, ':access_type'=>$logAccessType, ':source'=>$source,
                ]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $lifetimeGrant = null;
    if ($accessType === 'lifetime') {
        $transactionCode = trim((string)($input['transaction_code'] ?? ''));
        $isPaid = !empty($input['is_paid']);
        if ($transactionCode === '') $transactionCode = 'grant_' . preg_replace('/[^a-z0-9_-]/i', '_', $source) . '_' . $userId . '_' . bin2hex(random_bytes(6));
        course_access_grant_lifetime(
            $pdo, $userId, $transactionCode, trim((string)($input['offer_code'] ?? '')),
            $codigoTurma, is_array($input['payload'] ?? null) ? $input['payload'] : [],
            $source, $isPaid ? 'paid' : (string)($input['grant_type'] ?? 'integration'), $isPaid
        );
        if (function_exists('adicionar_tag')) adicionar_tag($userId, 'ACESSO_VITALICIO', $source, null);
        $lifetimeGrant = ['transaction_code'=>$transactionCode, 'is_paid'=>$isPaid];
    }

    $effectiveGrant = course_access_lifetime_entitlement($pdo, $userId);
    $effectiveLifetime = $effectiveGrant !== null;
    $effectivePaid = $effectiveGrant && (int)($effectiveGrant['is_paid'] ?? 0) === 1;
    $eventId = 'enrollment:' . $source . ':' . $userId . ':' . bin2hex(random_bytes(8));
    $accessEvent = null;
    if ($accessType === 'lifetime') {
        $accessEvent = 'INSCRICAO_VITALICIA';
    } elseif (!$hadLifetime && $renewedAccess) {
        $accessEvent = 'INSCRICAO_GRATUITA';
    }

    if (function_exists('adicionar_tag')) {
        adicionar_tag($userId, $isNew ? 'INSCRITO' : 'REINSCRITO', $source, null);
        adicionar_tag($userId, ($isNew ? 'INSCRITO_TURMA_' : 'REINSCRITO_TURMA_') . $codigoTurma, $source, null);
        if ($accessEvent !== null) adicionar_tag($userId, $accessEvent, $source, null);
    }

    $st = $pdo->prepare("SELECT COUNT(*) qtd, MIN(created_at) primeira FROM inscricao_logs WHERE user_id = :uid");
    $st->execute([':uid'=>$userId]);
    $history = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $extras = [
        'event_id'=>$eventId,
        'codigo_turma'=>$codigoTurma,
        'codigo_live'=>$codigoLive !== '' ? $codigoLive : $codigoTurma,
        'data_live'=>$dataLive,
        'data_live_iso'=>$dataLive,
        'qtd_inscricoes'=>(int)($history['qtd'] ?? 1),
        'primeira_inscricao'=>$history['primeira'] ?? null,
        'eh_reinscrito'=>$isNew ? 0 : 1,
        'tipo_inscricao'=>$effectiveLifetime ? 'vitalicia' : 'gratuita',
        'tipo_inscricao_solicitada'=>$accessType === 'lifetime' ? 'vitalicia' : 'gratuita',
        'acesso_vitalicio'=>$effectiveLifetime,
        'acesso_pago'=>$effectivePaid,
        'reinscricao_renovou_prazo'=>$renewedAccess,
        'origem'=>$source,
    ];
    if (function_exists('capturar_fluxos_automacao')) {
        try {
            capturar_fluxos_automacao($isNew ? 'INSCRITO' : 'REINSCRITO', $userId, $extras);
            if ($accessEvent !== null) capturar_fluxos_automacao($accessEvent, $userId, $extras);
        } catch (Throwable $e) {
            @error_log('enrollment_register capture: ' . $e->getMessage());
        }
    }
    return [
        'user_id'=>$userId, 'is_new'=>$isNew, 'event'=>$isNew ? 'INSCRITO' : 'REINSCRITO',
        'access_event'=>$accessEvent,
        'codigo_turma'=>$codigoTurma, 'data_live'=>$dataLive, 'codigo_live'=>$codigoLive,
        'access_type'=>$effectiveLifetime ? 'lifetime' : 'free', 'requested_access_type'=>$accessType,
        'extras'=>$extras, 'lifetime_grant'=>$lifetimeGrant,
    ];
}
