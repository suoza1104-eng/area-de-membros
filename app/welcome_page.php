<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function welcome_page_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN welcome_token VARCHAR(32) NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN welcome_url TEXT NULL");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uk_users_welcome_token (welcome_token)");
    } catch (Throwable $e) {}
}

function welcome_page_default_settings(): array
{
    return [
        'welcome_page_enabled' => '1',
        'welcome_page_course_name' => 'Nome do Curso',
        'welcome_page_badge_text' => 'Inscricao confirmada',
        'welcome_page_headline' => 'Parabens, {{primeiro_nome}}! Seu acesso esta liberado.',
        'welcome_page_lead' => 'Assista ao video e siga os passos rapidos abaixo para comecar da melhor forma.',
        'welcome_page_video_type' => 'youtube',
        'welcome_page_video_value' => '',
        'welcome_page_video_duration' => '3:24',
        'welcome_page_video_title' => 'Comece por aqui: como aproveitar o curso',
        'welcome_page_video_subtitle' => 'Mensagem do Professor Emerson Leite',
        'welcome_page_button1_label' => 'Entrar no grupo',
        'welcome_page_button1_url' => 'https://chat.whatsapp.com/{{codigo_turma}}',
        'welcome_page_button1_text' => 'Entre no grupo de alunos',
        'welcome_page_button1_desc' => 'E por la que saem avisos importantes, novidades, lives e materiais extras.',
        'welcome_page_button2_label' => 'Baixar aplicativo',
        'welcome_page_button2_url' => '{{app_login_url}}',
        'welcome_page_button2_text' => 'Baixe o aplicativo das aulas',
        'welcome_page_button2_desc' => 'Assista as aulas pelo celular, em qualquer lugar.',
        'welcome_page_button3_label' => 'Ativar notificacoes',
        'welcome_page_button3_url' => '{{notification_url}}',
        'welcome_page_button3_text' => 'Ative as notificacoes do app',
        'welcome_page_button3_desc' => 'Receba avisos quando sair aula nova, live ou comunicado importante.',
        'welcome_page_button4_label' => 'Baixar e-book',
        'welcome_page_button4_url' => '#',
        'welcome_page_button4_text' => 'Baixe seu e-book',
        'welcome_page_button4_desc' => 'Material de apoio para consultar sempre que precisar.',
        'welcome_page_app_url' => rtrim(BASE_URL, '/') . '/aplicativo.php',
        'welcome_page_notification_url' => rtrim(BASE_URL, '/') . '/aplicativo.php?ativar_notificacoes=1',
        'welcome_page_support_url' => '',
        'welcome_page_footer_text' => 'Duvidas sobre o acesso? Toque no botao de suporte no canto da tela.',
    ];
}

function welcome_page_settings(): array
{
    $defaults = welcome_page_default_settings();
    $settings = $defaults;
    try {
        $pdo = getPDO();
        $in = implode(',', array_fill(0, count($defaults), '?'));
        $st = $pdo->prepare("SELECT chave,valor FROM settings WHERE chave IN ($in)");
        $st->execute(array_keys($defaults));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $settings[(string)$row['chave']] = (string)$row['valor'];
        }
    } catch (Throwable $e) {
        foreach ($defaults as $key => $default) {
            $settings[$key] = (string)get_setting($key, $default);
        }
    }
    if (trim((string)($settings['welcome_page_button2_url'] ?? '')) === '{{app_url}}') {
        $settings['welcome_page_button2_url'] = '{{app_login_url}}';
    }
    return $settings;
}

function welcome_page_save_settings(array $data): void
{
    $defaults = welcome_page_default_settings();
    foreach ($defaults as $key => $default) {
        $value = (string)($data[$key] ?? $default);
        if ($key === 'welcome_page_enabled') $value = !empty($data[$key]) ? '1' : '0';
        set_setting($key, $value);
    }
}

function welcome_page_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
}

function welcome_page_public_url(string $token): string
{
    return rtrim(BASE_URL, '/') . '/b.php?w=' . rawurlencode($token);
}

function welcome_page_add_next_to_login_url(string $loginUrl, string $next): string
{
    $loginUrl = trim($loginUrl);
    $next = welcome_page_public_next_path($next);
    if ($loginUrl === '' || $next === '') return $loginUrl;
    return $loginUrl . (str_contains($loginUrl, '?') ? '&' : '?') . 'next=' . rawurlencode($next);
}

function welcome_page_public_next_path(string $path): string
{
    $path = ltrim(trim($path), '/');
    if ($path === '') return '';
    $basePath = trim((string)(parse_url((string)BASE_URL, PHP_URL_PATH) ?: ''), '/');
    return trim($basePath . '/' . $path, '/');
}

function welcome_page_login_next_url(PDO $pdo, int $userId, string $next = 'aplicativo.php'): string
{
    if ($userId <= 0) return '';
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS magic_links (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                token       VARCHAR(64) NOT NULL,
                expires_at  DATETIME NOT NULL,
                one_shot    TINYINT(1) NOT NULL DEFAULT 0,
                used_at     DATETIME NULL,
                created_at  DATETIME NOT NULL DEFAULT NOW(),
                UNIQUE KEY uk_ml_token (token),
                INDEX idx_ml_user (user_id)
            )
        ");
        $st = $pdo->prepare("
            SELECT token
              FROM magic_links
             WHERE user_id = :uid
               AND one_shot = 0
               AND expires_at > DATE_ADD(NOW(), INTERVAL 15 DAY)
             ORDER BY expires_at DESC
             LIMIT 1
        ");
        $st->execute([':uid' => $userId]);
        $token = trim((string)($st->fetchColumn() ?: ''));
        $loginUrl = $token !== '' ? rtrim(BASE_URL, '/') . '/login.php?am=' . rawurlencode($token) : '';
        if ($loginUrl === '' && function_exists('gerar_magic_link')) {
            $loginUrl = gerar_magic_link($userId, 60, false);
        }
        return welcome_page_add_next_to_login_url($loginUrl, $next);
    } catch (Throwable $e) {
        return '';
    }
}

function welcome_page_ensure_user_link(PDO $pdo, int $userId): string
{
    if ($userId <= 0) return '';
    welcome_page_ensure_schema($pdo);

    $st = $pdo->prepare("SELECT welcome_token,welcome_url FROM users WHERE id=:id LIMIT 1");
    $st->execute(['id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $token = trim((string)($row['welcome_token'] ?? ''));
    if ($token === '') {
        for ($i = 0; $i < 8; $i++) {
            $candidate = welcome_page_token();
            try {
                $url = welcome_page_public_url($candidate);
                $pdo->prepare("UPDATE users SET welcome_token=:token,welcome_url=:url WHERE id=:id")
                    ->execute(['token' => $candidate, 'url' => $url, 'id' => $userId]);
                return $url;
            } catch (Throwable $e) {}
        }
        throw new RuntimeException('Nao foi possivel gerar o link de boas-vindas.');
    }

    $url = trim((string)($row['welcome_url'] ?? ''));
    $current = welcome_page_public_url($token);
    if ($url !== $current) {
        try {
            $pdo->prepare("UPDATE users SET welcome_url=:url WHERE id=:id")->execute(['url' => $current, 'id' => $userId]);
        } catch (Throwable $e) {}
        $url = $current;
    }
    return $url;
}

function welcome_page_user_by_token(PDO $pdo, string $token): ?array
{
    welcome_page_ensure_schema($pdo);
    $token = trim($token);
    if (!preg_match('/^[A-Za-z0-9_-]{12,32}$/', $token)) return null;
    $st = $pdo->prepare("SELECT * FROM users WHERE welcome_token=:token LIMIT 1");
    $st->execute(['token' => $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function welcome_page_first_name(array $user): string
{
    $name = trim((string)($user['nome'] ?? ''));
    if ($name === '') $name = trim((string)($user['email'] ?? 'Aluno'));
    $parts = preg_split('/\s+/', $name) ?: [];
    return trim((string)($parts[0] ?? $name)) ?: 'Aluno';
}

function welcome_page_vars(array $user, array $settings, ?PDO $pdo = null): array
{
    $turma = trim((string)($user['codigo_turma'] ?? ($user['turma_codigo'] ?? '')));
    $userId = (int)($user['id'] ?? 0);
    $appLoginUrl = '';
    if ($pdo instanceof PDO && $userId > 0) {
        $appLoginUrl = welcome_page_login_next_url($pdo, $userId, 'aplicativo.php');
    }
    $vars = [
        'id' => (string)$userId,
        'nome' => trim((string)($user['nome'] ?? '')),
        'primeiro_nome' => welcome_page_first_name($user),
        'email' => trim((string)($user['email'] ?? '')),
        'telefone' => trim((string)($user['telefone'] ?? '')),
        'codigo_turma' => $turma,
        'turma' => $turma,
        'data_live' => trim((string)($user['data_live'] ?? ($user['turma_live_at'] ?? ''))),
        'app_url' => (string)($settings['welcome_page_app_url'] ?? ''),
        'app_login_url' => $appLoginUrl !== '' ? $appLoginUrl : (string)($settings['welcome_page_app_url'] ?? ''),
        'notification_url' => (string)($settings['welcome_page_notification_url'] ?? ''),
        'welcome_url' => trim((string)($user['welcome_url'] ?? '')),
    ];
    return $vars;
}

function welcome_page_replace(string $template, array $vars, bool $urlEncode = false): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', static function ($m) use ($vars, $urlEncode) {
        $value = (string)($vars[$m[1]] ?? '');
        return $urlEncode ? rawurlencode($value) : $value;
    }, $template) ?? $template;
}

function welcome_page_replace_url(string $template, array $vars): string
{
    $template = trim($template);
    if (preg_match('/^\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}$/', $template, $m)) {
        return (string)($vars[$m[1]] ?? '');
    }
    return welcome_page_replace($template, $vars, true);
}

function welcome_page_youtube_id(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~^[A-Za-z0-9_-]{8,20}$~', $value)) return $value;
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{8,20})~i', $value, $m)) {
        return $m[1];
    }
    return '';
}

function welcome_page_video_html(array $settings): string
{
    $type = (string)($settings['welcome_page_video_type'] ?? 'youtube');
    $value = trim((string)($settings['welcome_page_video_value'] ?? ''));
    if ($value === '') return '';
    if ($type === 'upload') {
        return '<video controls playsinline preload="metadata" src="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></video>';
    }
    if ($type === 'iframe') {
        if (preg_match('~<iframe\b[^>]*>.*?</iframe>~is', $value, $m)) return $m[0];
        return '';
    }
    $id = welcome_page_youtube_id($value);
    if ($id === '') return '';
    return '<iframe src="https://www.youtube.com/embed/' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '?rel=0&modestbranding=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
}
