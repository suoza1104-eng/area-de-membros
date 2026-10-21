<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

// Quem compra a Formacao FERA (Formacao Eletricista Residencial de Alto Nivel /
// Comunidade FERA) ganha, como bonus da propria compra, o acesso vitalicio ao
// curso de Iniciacao em Montagem de Quadros. O vitalicio pertence ao aluno
// (course_lifetime_access), entao basta casar a venda aprovada com a conta dele.
// As colunas de v_sales_master usam utf8mb4_unicode_ci: a comparacao ignora
// acento e caixa ("Nivel" = "Nível").
const COURSE_BONUS_LIFETIME_SOURCE = 'fera_bonus';
const COURSE_BONUS_LIFETIME_GRANT_TYPE = 'bonus';
const COURSE_BONUS_LIFETIME_LAST_USER_SETTING = 'fera_bonus_last_user_id';

function course_bonus_lifetime_product_sql(string $alias = 's'): string
{
    return "({$alias}.product_name = 'Formação Eletricista Residencial de Alto Nível'
            OR {$alias}.product_name LIKE 'COMUNIDADE FERA%')
        AND {$alias}.status IN ('APPROVED', 'COMPLETE', 'COMPLETED', 'PAID')";
}

function course_bonus_lifetime_find_user(PDO $pdo, string $email, string $phone, bool $phoneFallback): ?array
{
    $email = trim(mb_strtolower($email));
    if ($email !== '') {
        $st = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $st->execute([':email' => $email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user) return $user;
    }
    // users.telefone nao tem indice: so vale a pena para poucas vendas recentes.
    if ($phoneFallback && trim($phone) !== '') {
        return course_access_find_user_by_purchase($pdo, null, '', $phone);
    }
    return null;
}

/** Libera o vitalicio bonus para uma venda FERA aprovada. Idempotente. */
function course_bonus_lifetime_grant_sale(PDO $pdo, array $sale, bool $phoneFallback = false, bool $dryRun = false, ?array $user = null): array
{
    $transactionCode = trim((string)($sale['transaction_code'] ?? ''));
    if ($transactionCode === '') return ['granted' => false, 'reason' => 'missing_transaction_code'];

    // O upsert de course_access_grant_lifetime moveria para outra conta um acesso
    // ja liberado por esta transacao; uma venda libera uma vez so.
    $st = $pdo->prepare("SELECT user_id FROM course_lifetime_access WHERE transaction_code = :tx LIMIT 1");
    $st->execute([':tx' => $transactionCode]);
    $existingUserId = $st->fetchColumn();
    if ($existingUserId !== false) {
        return ['granted' => false, 'reason' => 'transaction_already_granted', 'user_id' => (int)$existingUserId, 'transaction_code' => $transactionCode];
    }

    $user = $user ?: course_bonus_lifetime_find_user(
        $pdo,
        (string)($sale['buyer_email'] ?? ''),
        (string)($sale['buyer_phone'] ?? ''),
        $phoneFallback
    );
    if (!$user) return ['granted' => false, 'reason' => 'user_not_found', 'transaction_code' => $transactionCode];

    $userId = (int)$user['id'];
    if (course_access_lifetime_entitlement($pdo, $userId)) {
        return ['granted' => false, 'reason' => 'already_lifetime', 'user_id' => $userId, 'transaction_code' => $transactionCode];
    }
    if ($dryRun) {
        return ['granted' => false, 'reason' => 'dry_run', 'would_grant' => true, 'user_id' => $userId,
            'blocked' => (int)($user['bloquear'] ?? 0) !== 0, 'transaction_code' => $transactionCode];
    }

    course_access_grant_lifetime(
        $pdo,
        $userId,
        $transactionCode,
        '',
        '',
        [
            'motivo' => 'Bonus da compra da Formacao FERA',
            'provider' => $sale['provider'] ?? null,
            'product_name' => $sale['product_name'] ?? null,
            'sale_date' => $sale['sale_date'] ?? null,
        ],
        COURSE_BONUS_LIFETIME_SOURCE,
        COURSE_BONUS_LIFETIME_GRANT_TYPE,
        true
    );

    // Grava a tag sem passar por adicionar_tag(): la a ACESSO_VITALICIO dispara o
    // gatilho "LEAD QUALIFICADO MCQDC" da Meta, e comprador da FERA nao e lead do
    // desbloqueio de R$24,90 (no backfill seriam milhares de conversoes falsas).
    try {
        $tagId = (int)obter_ou_criar_tag_id('ACESSO_VITALICIO');
        if ($tagId > 0) adicionar_tag_ao_usuario($userId, $tagId, COURSE_BONUS_LIFETIME_SOURCE, null);
    } catch (Throwable $e) {
        @error_log('course_bonus_lifetime tag: ' . $e->getMessage());
    }

    return ['granted' => true, 'user_id' => $userId, 'transaction_code' => $transactionCode];
}

/**
 * Varre vendas FERA aprovadas que ainda nao geraram vitalicio.
 * $days = null percorre todo o historico (backfill).
 */
function course_bonus_lifetime_sync_sales(PDO $pdo, ?int $days = 7, bool $phoneFallback = true, bool $dryRun = false, int $limit = 0): array
{
    course_access_ensure_schema($pdo);
    $where = course_bonus_lifetime_product_sql('s') . " AND cla.id IS NULL";
    if ($days !== null) $where .= " AND s.sale_date >= NOW() - INTERVAL " . max(1, $days) . " DAY";
    // transaction_code tem collation diferente nas duas tabelas (erro 1267 sem COLLATE).
    $sql = "SELECT s.provider, s.transaction_code, s.product_name, s.buyer_email, s.buyer_phone, s.sale_date
              FROM v_sales_master s
              LEFT JOIN course_lifetime_access cla
                ON cla.transaction_code COLLATE utf8mb4_unicode_ci = s.transaction_code COLLATE utf8mb4_unicode_ci
             WHERE {$where}
             ORDER BY s.sale_date ASC";
    if ($limit > 0) $sql .= " LIMIT " . $limit;
    $sales = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summary = ['sales' => count($sales), 'granted' => 0, 'would_grant' => 0, 'blocked_unlocked' => 0,
        'already_lifetime' => 0, 'user_not_found' => 0, 'errors' => 0];
    $seenUsers = [];
    foreach ($sales as $sale) {
        try {
            $result = course_bonus_lifetime_grant_sale($pdo, $sale, $phoneFallback, $dryRun);
        } catch (Throwable $e) {
            $summary['errors']++;
            @error_log('course_bonus_lifetime_sync_sales ' . ($sale['transaction_code'] ?? '') . ': ' . $e->getMessage());
            continue;
        }
        $uid = (int)($result['user_id'] ?? 0);
        // Mesmo aluno com duas compras FERA conta uma vez so no dry-run.
        if ($uid > 0 && isset($seenUsers[$uid])) continue;
        if ($uid > 0) $seenUsers[$uid] = true;
        if (!empty($result['granted'])) $summary['granted']++;
        elseif (!empty($result['would_grant'])) {
            $summary['would_grant']++;
            if (!empty($result['blocked'])) $summary['blocked_unlocked']++;
        } elseif (($result['reason'] ?? '') === 'already_lifetime') $summary['already_lifetime']++;
        elseif (($result['reason'] ?? '') === 'user_not_found') $summary['user_not_found']++;
    }
    return $summary;
}

/**
 * Contas criadas depois da compra (comprou a FERA e so depois se inscreveu no
 * curso gratuito): confere os alunos novos desde a ultima rodada.
 */
function course_bonus_lifetime_sync_new_users(PDO $pdo, int $batch = 500): array
{
    course_access_ensure_schema($pdo);
    $last = (int)(get_setting(COURSE_BONUS_LIFETIME_LAST_USER_SETTING, '0') ?: 0);
    if ($last <= 0) {
        // Primeira rodada: o historico ja foi coberto pelo backfill de vendas.
        $max = (int)($pdo->query("SELECT COALESCE(MAX(id), 0) FROM users")->fetchColumn() ?: 0);
        set_setting(COURSE_BONUS_LIFETIME_LAST_USER_SETTING, (string)$max);
        return ['users' => 0, 'granted' => 0, 'initialized_at' => $max];
    }

    $st = $pdo->prepare("SELECT * FROM users WHERE id > :last ORDER BY id ASC LIMIT " . max(1, $batch));
    $st->execute([':last' => $last]);
    $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$users) return ['users' => 0, 'granted' => 0];

    $byEmail = [];
    foreach ($users as $user) {
        $email = trim(mb_strtolower((string)($user['email'] ?? '')));
        if ($email !== '') $byEmail[$email] = $user;
    }

    $granted = 0;
    if ($byEmail) {
        $holders = [];
        $params = [];
        foreach (array_keys($byEmail) as $i => $email) {
            $holders[] = ':e' . $i;
            $params[':e' . $i] = $email;
        }
        $st = $pdo->prepare("SELECT s.provider, s.transaction_code, s.product_name, s.buyer_email, s.buyer_phone, s.sale_date
                               FROM v_sales_master s
                              WHERE s.buyer_email IN (" . implode(',', $holders) . ")
                                AND " . course_bonus_lifetime_product_sql('s') . "
                              ORDER BY s.sale_date ASC");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sale) {
            $user = $byEmail[trim(mb_strtolower((string)$sale['buyer_email']))] ?? null;
            if (!$user) continue;
            try {
                $result = course_bonus_lifetime_grant_sale($pdo, $sale, false, false, $user);
                if (!empty($result['granted'])) $granted++;
            } catch (Throwable $e) {
                @error_log('course_bonus_lifetime_sync_new_users ' . $user['id'] . ': ' . $e->getMessage());
            }
        }
    }

    set_setting(COURSE_BONUS_LIFETIME_LAST_USER_SETTING, (string)(int)end($users)['id']);
    return ['users' => count($users), 'granted' => $granted];
}
