<?php
declare(strict_types=1);

function course_access_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $st->execute([':column' => $column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function course_access_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $turmaColumns = [
        'access_deadline_enabled' => "ALTER TABLE turmas ADD COLUMN access_deadline_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'access_deadline_days' => "ALTER TABLE turmas ADD COLUMN access_deadline_days INT NOT NULL DEFAULT 30",
        'access_deadline_start' => "ALTER TABLE turmas ADD COLUMN access_deadline_start VARCHAR(20) NOT NULL DEFAULT 'cadastro'",
        'access_countdown_enabled' => "ALTER TABLE turmas ADD COLUMN access_countdown_enabled TINYINT(1) NOT NULL DEFAULT 1",
        'lifetime_checkout_url' => "ALTER TABLE turmas ADD COLUMN lifetime_checkout_url VARCHAR(1000) NULL",
        'lifetime_offer_codes' => "ALTER TABLE turmas ADD COLUMN lifetime_offer_codes VARCHAR(500) NULL",
        'access_expired_message' => "ALTER TABLE turmas ADD COLUMN access_expired_message TEXT NULL",
    ];
    foreach ($turmaColumns as $column => $sql) {
        if (!course_access_column_exists($pdo, 'turmas', $column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_lifetime_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            turma_codigo VARCHAR(100) NULL,
            offer_code VARCHAR(200) NULL,
            transaction_code VARCHAR(200) NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'webhook',
            payload_json LONGTEXT NULL,
            granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_course_lifetime_transaction (transaction_code),
            KEY idx_course_lifetime_user (user_id),
            KEY idx_course_lifetime_turma (turma_codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function course_access_user_turma_code(array $user): string
{
    foreach (['codigo_turma', 'turma_codigo'] as $column) {
        $value = trim((string)($user[$column] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function course_access_offer_codes(?string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim((string)$raw)) ?: [];
    return array_values(array_unique(array_filter(array_map('trim', $parts), static fn($v) => $v !== '')));
}

function course_access_checkout_url(string $baseUrl, array $user): string
{
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') return '';

    $params = [];
    $name = trim((string)($user['nome'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($user['telefone'] ?? '')) ?? '';
    if (strlen($phone) >= 12 && str_starts_with($phone, '55')) {
        $phone = substr($phone, 2);
    }
    // Celulares brasileiros antigos podem estar salvos como DDD + 8 dígitos.
    // Para faixas móveis (6–9), inclui o nono dígito exigido no checkout.
    if (strlen($phone) === 10 && preg_match('/^[1-9]{2}[6-9]/', $phone)) {
        $phone = substr($phone, 0, 2) . '9' . substr($phone, 2);
    }
    if ($name !== '') $params['name'] = $name;
    if ($email !== '') $params['email'] = $email;
    if (strlen($phone) >= 10) {
        $params['phoneac'] = substr($phone, 0, 2);
        $params['phonenumber'] = substr($phone, 2);
    }
    if (!$params) return $baseUrl;

    $fragment = '';
    $fragmentPos = strpos($baseUrl, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($baseUrl, $fragmentPos);
        $baseUrl = substr($baseUrl, 0, $fragmentPos);
    }
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    if (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&')) $separator = '';
    return $baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $fragment;
}

function course_access_status(PDO $pdo, int $userId): array
{
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
    ];
    if ($userId <= 0) return $default;

    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) return $default;

        $turmaCodigo = course_access_user_turma_code($user);
        if ($turmaCodigo === '') return $default;

        $st = $pdo->prepare("SELECT * FROM turmas WHERE codigo = :codigo LIMIT 1");
        $st->execute([':codigo' => $turmaCodigo]);
        $turma = $st->fetch(PDO::FETCH_ASSOC);
        if (!$turma) return $default;

        $default['turma_codigo'] = $turmaCodigo;
        $default['checkout_url'] = course_access_checkout_url(
            (string)($turma['lifetime_checkout_url'] ?? ''),
            $user
        );
        $default['message'] = trim((string)($turma['access_expired_message'] ?? ''));
        $default['offer_codes'] = course_access_offer_codes((string)($turma['lifetime_offer_codes'] ?? ''));
        $default['countdown_enabled'] = (int)($turma['access_countdown_enabled'] ?? 1) === 1;

        $st = $pdo->prepare("SELECT granted_at, transaction_code, offer_code
            FROM course_lifetime_access
            WHERE user_id = :user_id
            ORDER BY granted_at DESC, id DESC
            LIMIT 1");
        $st->execute([':user_id' => $userId]);
        if ($grant = $st->fetch(PDO::FETCH_ASSOC)) {
            $default['lifetime'] = true;
            $default['lifetime_granted_at'] = $grant['granted_at'] ?? null;
            $default['lifetime_transaction_code'] = $grant['transaction_code'] ?? null;
            return $default;
        }

        $enabled = (int)($turma['access_deadline_enabled'] ?? 0) === 1;
        $days = max(1, (int)($turma['access_deadline_days'] ?? 30));
        $startMode = (string)($turma['access_deadline_start'] ?? 'cadastro');
        $default['enabled'] = $enabled;
        if (!$enabled) return $default;

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
            try {
                $st = $pdo->prepare("SELECT created_at
                    FROM inscricao_logs
                    WHERE user_id = :user_id
                      AND codigo_turma = :codigo
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1");
                $st->execute([':user_id' => $userId, ':codigo' => $turmaCodigo]);
                $startAt = (string)($st->fetchColumn() ?: '');
            } catch (Throwable $e) {}
            if (!$startAt) $startAt = (string)($user['created_at'] ?? '');
        }
        if (!$startAt) return $default;

        $start = new DateTimeImmutable($startAt);
        $expires = $start->modify('+' . $days . ' days');
        $now = new DateTimeImmutable('now');
        $remaining = $expires->getTimestamp() - $now->getTimestamp();
        $default['start_at'] = $start->format('Y-m-d H:i:s');
        $default['expires_at'] = $expires->format('Y-m-d H:i:s');
        $default['expires_at_iso'] = $expires->format(DateTimeInterface::ATOM);
        $default['remaining_seconds'] = max(0, $remaining);
        $default['expired'] = $remaining <= 0;
        $default['allowed'] = $remaining > 0;
        return $default;
    } catch (Throwable $e) {
        @error_log('course_access_status: ' . $e->getMessage());
        return $default;
    }
}

function course_access_grant_lifetime(
    PDO $pdo,
    int $userId,
    string $transactionCode,
    string $offerCode = '',
    string $turmaCodigo = '',
    array $payload = []
): bool {
    course_access_ensure_schema($pdo);
    $transactionCode = trim($transactionCode);
    if ($userId <= 0 || $transactionCode === '') {
        throw new InvalidArgumentException('Usuario ou codigo da transacao invalido.');
    }
    if ($turmaCodigo === '') {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $turmaCodigo = course_access_user_turma_code($st->fetch(PDO::FETCH_ASSOC) ?: []);
    }
    $st = $pdo->prepare("
        INSERT INTO course_lifetime_access
            (user_id, turma_codigo, offer_code, transaction_code, source, payload_json, granted_at)
        VALUES
            (:user_id, :turma_codigo, :offer_code, :transaction_code, 'webhook', :payload_json, NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            turma_codigo = VALUES(turma_codigo),
            offer_code = VALUES(offer_code),
            payload_json = VALUES(payload_json)
    ");
    return $st->execute([
        ':user_id' => $userId,
        ':turma_codigo' => $turmaCodigo !== '' ? $turmaCodigo : null,
        ':offer_code' => $offerCode !== '' ? $offerCode : null,
        ':transaction_code' => $transactionCode,
        ':payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}
