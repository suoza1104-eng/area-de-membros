<?php
declare(strict_types=1);

/**
 * Cadastro de aluno pela API (v1) — usado pelo Sino para colocar no curso
 * gratuito quem chega pelo anuncio de WhatsApp.
 *
 * - aluno novo: mesma inscricao do formulario (enrollment_register + eventos);
 * - aluno existente: nada muda (nem turma, nem prazo, nem acesso);
 * - chave de idempotencia: a mesma chave devolve a mesma resposta gravada;
 * - modo teste: valida e responde como se fosse cadastrar, sem gravar aluno.
 */

require_once __DIR__ . '/student_api.php';
require_once __DIR__ . '/enrollment_service.php';
require_once __DIR__ . '/welcome_page.php';

const STUDENT_REGISTER_DEFAULT_RATE = 20;
const STUDENT_REGISTER_COURSES = ['IMQ'];
const STUDENT_REGISTER_SOURCE = 'sino_whatsapp';

function student_register_ensure_schema(PDO $pdo): void
{
    student_api_ensure_schema($pdo);
    schema_once('student_api_register', '1', static function () use ($pdo): void {
        foreach ([
            "ALTER TABLE student_api_keys ADD COLUMN scope_register TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE student_api_keys ADD COLUMN register_rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT " . STUDENT_REGISTER_DEFAULT_RATE,
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_api_registrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_id INT UNSIGNED NOT NULL,
                idempotency_key VARCHAR(120) NOT NULL,
                user_id INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL,
                http_status SMALLINT UNSIGNED NOT NULL,
                curso VARCHAR(20) NULL,
                sino_lead_id BIGINT NULL,
                origem VARCHAR(80) NULL,
                origem_detalhes LONGTEXT NULL,
                response_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_student_api_reg_idem (key_id, idempotency_key),
                KEY idx_student_api_reg_user (user_id),
                KEY idx_student_api_reg_lead (sino_lead_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // A integracao foi pedida pelo Sino: as chaves dele ja nascem com a permissao.
        $pdo->exec("UPDATE student_api_keys SET scope_register = 1
            WHERE status = 'active' AND (LOWER(name) LIKE '%sino%' OR LOWER(COALESCE(notes,'')) LIKE '%sino%')");
    });
}

/* ------------------------------------------------------------- validacao */

/** Nome completo (vai para o certificado). Devolve o nome limpo ou '' se invalido. */
function student_register_clean_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 150) return '';
    if (!preg_match("/^[\p{L}][\p{L}'´`.\- ]*$/u", $name)) return ''; // sem digitos, @ ou simbolos
    $words = array_filter(explode(' ', $name), static fn($w) => preg_match('/\p{L}{2,}/u', $w));
    return count($words) >= 2 ? $name : '';
}

/**
 * Telefone brasileiro como fica gravado: DDD + numero, sem 55. Celular que
 * chega sem o nono digito (comum no WhatsApp) ganha o 9. '' se invalido.
 */
function student_register_clean_phone(string $phone): string
{
    $local = student_api_normalize_phone($phone);
    if (!preg_match('/^[1-9]{2}\d{8,9}$/', $local)) return '';
    if (strlen($local) === 10 && in_array($local[2], ['6', '7', '8', '9'], true)) {
        $local = substr($local, 0, 2) . '9' . substr($local, 2);
    }
    if (strlen($local) === 11 && $local[2] !== '9') return '';
    return $local;
}

/* ----------------------------------------------------------------- links */

/** Magic link reutilizavel do aluno (o mesmo que o Sino ja recebe): valido por mais 15 dias, senao cria um de 60. */
function student_register_magic_link(PDO $pdo, int $userId, bool $create): array
{
    $find = $pdo->prepare("SELECT token, expires_at FROM magic_links
        WHERE user_id = :u AND one_shot = 0 AND expires_at > DATE_ADD(NOW(), INTERVAL 15 DAY)
        ORDER BY expires_at DESC LIMIT 1");
    try {
        $find->execute([':u' => $userId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row && $create && gerar_magic_link($userId, 60, false) !== '') {
            $find->execute([':u' => $userId]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $row = null; // tabela ainda nao existe (so no modo teste)
    }
    if (!$row) return ['url' => null, 'expires_at' => null];
    return [
        'url' => rtrim(BASE_URL, '/') . '/login.php?am=' . $row['token'],
        'expires_at' => student_api_dt($row['expires_at']),
    ];
}

/** Link do grupo da turma: o mesmo botao "Entrar no grupo" da pagina de boas-vindas. */
function student_register_group_url(string $codigoTurma): ?string
{
    if ($codigoTurma === '') return null;
    $template = trim((string)(welcome_page_settings()['welcome_page_button1_url'] ?? ''));
    $url = welcome_page_replace($template, ['codigo_turma' => $codigoTurma, 'turma' => $codigoTurma]);
    return preg_match('~^https://~i', $url) ? $url : null;
}

/** Bloco "links" da resposta. Com $create=false (modo teste) so le o que ja existe. */
function student_register_links(PDO $pdo, array $user, bool $create): array
{
    $uid = (int)$user['id'];
    $magic = student_register_magic_link($pdo, $uid, $create);
    $welcome = trim((string)($user['welcome_url'] ?? ''));
    if ($create) {
        try { $welcome = welcome_page_ensure_user_link($pdo, $uid); } catch (Throwable $e) {}
    }
    return [
        'magic_link' => $magic['url'],
        'magic_link_expira_em' => $magic['expires_at'],
        'app_login_url' => $magic['url'] !== null ? welcome_page_add_next_to_login_url($magic['url'], 'aplicativo.php') : null,
        'welcome_url' => $welcome !== '' ? $welcome : null,
        'grupo_whatsapp_url' => student_register_group_url(course_access_user_turma_code($user)),
    ];
}

/* ------------------------------------------------------------ modo teste */

/** Perfil de como o aluno ficaria se fosse cadastrado agora (modo teste, aluno novo). */
function student_register_simulated_profile(array $turma, string $nome, string $email, string $telefone): array
{
    $now = new DateTimeImmutable('now');
    $dataLive = trim((string)($turma['data_live'] ?? ''));
    $enabled = (int)($turma['access_deadline_enabled'] ?? 0) === 1;
    $days = max(1, (int)($turma['access_deadline_days'] ?? 30));
    $expires = null;
    if ($enabled) {
        $start = ($turma['access_deadline_start'] ?? 'cadastro') === 'live' && $dataLive !== ''
            ? new DateTimeImmutable($dataLive)
            : $now;
        $expires = $start->modify('+' . $days . ' days')->format(DateTimeInterface::ATOM);
    }
    $checkout = course_access_checkout_url((string)($turma['lifetime_checkout_url'] ?? ''),
        ['nome' => $nome, 'email' => $email, 'telefone' => $telefone]);
    return [
        'id' => null,
        'nome' => $nome,
        'email' => $email,
        'telefone' => $telefone,
        'telefone_normalizado' => $telefone,
        'telefone_whatsapp' => student_api_whatsapp_phone($telefone),
        'cadastrado_em' => $now->format(DateTimeInterface::ATOM),
        'turma_atual' => [
            'codigo' => (string)$turma['codigo'],
            'codigo_live' => ($turma['codigo_live'] ?? '') !== '' ? (string)$turma['codigo_live'] : null,
            'data_live' => student_api_dt($dataLive),
            'live_ja_aconteceu' => $dataLive !== '' ? strtotime($dataLive) <= time() : null,
        ],
        'acesso' => [
            'bloqueado' => false,
            'motivo_bloqueio' => null,
            'prazo_de_acesso_ativo' => $enabled,
            'dias_de_acesso' => $enabled ? $days : null,
            'acesso_expira_em' => $expires,
            'prazo_expirado' => false,
            'vitalicio' => false,
            'link_compra_vitalicio' => $checkout !== '' ? $checkout : null,
        ],
    ];
}
