<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function ci_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS individual_certificate_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            description TEXT NULL,
            slug VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            front_image VARCHAR(255) NULL,
            back_image VARCHAR(255) NULL,
            illustration_image VARCHAR(255) NULL,
            layout_json LONGTEXT NULL,
            form_config_json LONGTEXT NULL,
            password_hash VARCHAR(255) NULL,
            action_mode VARCHAR(20) NOT NULL DEFAULT 'download',
            redirect_url VARCHAR(700) NOT NULL DEFAULT '',
            button_label VARCHAR(120) NOT NULL DEFAULT 'Baixar certificado',
            success_message TEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_ci_slug (slug),
            KEY idx_ci_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS individual_certificate_issues (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            public_token VARCHAR(80) NOT NULL,
            full_name VARCHAR(180) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(60) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'generated',
            password_ok TINYINT(1) NOT NULL DEFAULT 0,
            pdf_url VARCHAR(700) NULL,
            error_message TEXT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            generated_at DATETIME NULL,
            UNIQUE KEY uk_ci_issue_token (public_token),
            KEY idx_ci_issue_template (template_id, created_at),
            KEY idx_ci_issue_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS individual_certificate_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NULL,
            issue_id BIGINT UNSIGNED NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            event VARCHAR(80) NOT NULL,
            message VARCHAR(500) NOT NULL,
            context_json LONGTEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ci_log_template (template_id, created_at),
            KEY idx_ci_log_event (event, created_at),
            KEY idx_ci_log_level (level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ci_log(PDO $pdo, ?int $templateId, ?int $issueId, string $level, string $event, string $message, array $context = []): void
{
    try {
        ci_ensure_schema($pdo);
        $st = $pdo->prepare("
            INSERT INTO individual_certificate_logs
            (template_id, issue_id, level, event, message, context_json, ip_address)
            VALUES (:template_id, :issue_id, :level, :event, :message, :context, :ip)
        ");
        $st->execute([
            'template_id' => $templateId ?: null,
            'issue_id' => $issueId ?: null,
            'level' => $level,
            'event' => $event,
            'message' => function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500),
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {}
}

function ci_slugify(string $value): string
{
    $value = trim($value);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') $value = $ascii;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 90) : 'certificado';
}

function ci_unique_slug(PDO $pdo, string $name, int $ignoreId = 0): string
{
    $base = ci_slugify($name);
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $sql = "SELECT id FROM individual_certificate_templates WHERE slug=:slug" . ($ignoreId > 0 ? " AND id<>:id" : "") . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $params = ['slug' => $slug];
        if ($ignoreId > 0) $params['id'] = $ignoreId;
        $st->execute($params);
        if (!$st->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

function ci_default_form_config(): array
{
    return [
        'collect_email' => true,
        'collect_phone' => false,
        'require_password' => true,
        'show_illustration' => false,
        'page_title' => 'Gerar certificado',
        'page_description' => 'Informe seus dados para gerar o certificado.',
    ];
}

function ci_decode_form_config(?string $json): array
{
    $cfg = json_decode((string)$json, true);
    if (!is_array($cfg)) $cfg = [];
    return $cfg + ci_default_form_config();
}

function ci_public_url(array $template): string
{
    return rtrim(BASE_URL, '/') . '/certificado_individual.php?t=' . urlencode((string)$template['slug']);
}

function ci_upload_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/certificados_individuais';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function ci_upload_base_url(): string
{
    return preg_replace('#/public$#', '', rtrim(BASE_URL, '/')) . '/uploads/certificados_individuais';
}

function ci_store_upload(string $field, string $prefix): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        throw new RuntimeException('Imagem invalida. Use PNG, JPG ou WEBP.');
    }
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file((string)$_FILES[$field]['tmp_name'], ci_upload_dir() . '/' . $name)) {
        throw new RuntimeException('Falha ao enviar imagem.');
    }
    return $name;
}

function ci_find_template(PDO $pdo, int $id): ?array
{
    ci_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM individual_certificate_templates WHERE id=:id LIMIT 1");
    $st->execute(['id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ci_find_template_by_slug(PDO $pdo, string $slug): ?array
{
    ci_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM individual_certificate_templates WHERE slug=:slug AND status='active' LIMIT 1");
    $st->execute(['slug' => $slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ci_code(): string
{
    return bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4));
}

function ci_qr_data_uri(string $data, int $size): string
{
    if (!function_exists('curl_init')) return '';
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($data) . '&format=png';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return $raw && strlen((string)$raw) > 100 ? 'data:image/png;base64,' . base64_encode((string)$raw) : '';
}

function ci_generate_pdf(PDO $pdo, array $template, array $issue): string
{
    $autoload = __DIR__ . '/../vendor/dompdf/autoload.inc.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Biblioteca Dompdf nao encontrada no servidor.');
    }
    require_once $autoload;

    $layout = json_decode((string)($template['layout_json'] ?? ''), true);
    if (!is_array($layout)) $layout = ['front' => [], 'back' => []];
    $baseUrl = ci_upload_base_url();
    $front = !empty($template['front_image']) ? $baseUrl . '/' . $template['front_image'] : '';
    $back = !empty($template['back_image']) ? $baseUrl . '/' . $template['back_image'] : '';
    $verifyUrl = rtrim(BASE_URL, '/') . '/certificado_individual.php?v=' . urlencode((string)$issue['public_token']);
    $date = date('d/m/Y', strtotime((string)($issue['generated_at'] ?? 'now')));
    $values = [
        'nome' => (string)$issue['full_name'],
        'email' => (string)($issue['email'] ?? ''),
        'telefone' => (string)($issue['phone'] ?? ''),
        'data' => $date,
        'data_emissao' => $date,
        'certificado' => (string)$template['name'],
        'descricao' => (string)($template['description'] ?? ''),
        'codigo' => (string)$issue['public_token'],
        'qr' => $verifyUrl,
    ];

    $render = static function (string $img, array $items) use ($values): void {
        ?>
        <div class="page">
            <?php if ($img): ?><img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" class="bg"><?php endif; ?>
            <?php foreach ($items as $item):
                $field = (string)($item['field'] ?? '');
                $x = (float)($item['x'] ?? 50);
                $y = (float)($item['y'] ?? 50);
                $font = (int)($item['font'] ?? 22);
                $family = htmlspecialchars((string)($item['fontFamily'] ?? 'DejaVu Sans'), ENT_QUOTES, 'UTF-8');
                if ($field === 'qr'):
                    $pct = max(5, min(60, $font > 100 ? (int)round($font / 1122 * 100) : $font));
                    $px = (int)round(1122 * $pct / 100);
                    $qr = ci_qr_data_uri((string)$values['qr'], max(220, $px * 2));
                    ?>
                    <div class="qr" style="left:<?= $x ?>%;top:<?= $y ?>%;">
                        <?php if ($qr): ?><img src="<?= $qr ?>" width="<?= $px ?>" height="<?= $px ?>"><?php else: ?><div style="width:<?= $px ?>px;height:<?= $px ?>px;border:1px solid #999;background:#eee">QR</div><?php endif; ?>
                    </div>
                <?php else:
                    $text = $values[$field] ?? '';
                    if ($text === '') continue;
                    ?>
                    <div class="field" style="left:<?= $x ?>%;top:<?= $y ?>%;font-size:<?= $font ?>px;font-family:<?= $family ?>;"><?= htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; endforeach; ?>
        </div>
        <?php
    };

    ob_start();
    ?>
    <!doctype html><html><head><meta charset="utf-8"><style>
        @page{size:A4 landscape;margin:0}html,body{margin:0;padding:0;height:100%;font-family:"DejaVu Sans",Arial,sans-serif}.page{position:relative;width:100%;height:100%;overflow:hidden;background:#f8fafc}.bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.field{position:absolute;transform:translate(-50%,-50%);font-weight:bold;color:#000;white-space:nowrap}.qr{position:absolute;transform:translate(-50%,-50%)}
    </style></head><body>
    <?php
    if ($front || !empty($layout['front'])) $render($front, is_array($layout['front'] ?? null) ? $layout['front'] : []);
    if ($back || !empty($layout['back'])) $render($back, is_array($layout['back'] ?? null) ? $layout['back'] : []);
    if (!$front && !$back && empty($layout['front']) && empty($layout['back'])) {
        ?><div class="page"><div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center"><div><h1><?= htmlspecialchars((string)$template['name'], ENT_QUOTES, 'UTF-8') ?></h1><h2><?= htmlspecialchars((string)$issue['full_name'], ENT_QUOTES, 'UTF-8') ?></h2><p>Emitido em <?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></p></div></div></div><?php
    }
    ?>
    </body></html>
    <?php
    $html = ob_get_clean();
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $dir = dirname(__DIR__) . '/uploads/certificados_individuais_pdf';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = 'ci_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)$issue['public_token']) . '_' . substr(md5((string)$template['layout_json']), 0, 8) . '.pdf';
    file_put_contents($dir . '/' . $file, $dompdf->output());
    return preg_replace('#/public$#', '', rtrim(BASE_URL, '/')) . '/uploads/certificados_individuais_pdf/' . $file;
}
