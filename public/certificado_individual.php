<?php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/certificados_individuais.php';

$pdo = getPDO();
ci_ensure_schema($pdo);

function cip_h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$verify = trim((string)($_GET['v'] ?? ''));
if ($verify !== '') {
    $st = $pdo->prepare("SELECT i.*,t.name template_name,t.description template_description FROM individual_certificate_issues i JOIN individual_certificate_templates t ON t.id=i.template_id WHERE i.public_token=:t LIMIT 1");
    $st->execute(['t' => $verify]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Validar certificado</title><style>body{margin:0;background:#07101f;color:#e2e8f0;font-family:system-ui;display:grid;place-items:center;min-height:100vh}.card{width:min(560px,92vw);background:#0d1b33;border:1px solid #1e293b;border-radius:16px;padding:28px}.ok{color:#86efac}.muted{color:#94a3b8}</style></head><body><main class="card"><?php if($issue): ?><h1 class="ok">Certificado valido</h1><p><strong><?= cip_h($issue['full_name']) ?></strong></p><p><?= cip_h($issue['template_name']) ?></p><p class="muted">Gerado em <?= cip_h($issue['generated_at'] ?: $issue['created_at']) ?></p><?php if(!empty($issue['pdf_url'])): ?><p><a style="color:#facc15" href="<?= cip_h($issue['pdf_url']) ?>" target="_blank">Abrir PDF</a></p><?php endif; ?><?php else: ?><h1>Certificado nao encontrado</h1><p class="muted">Confira o codigo informado.</p><?php endif; ?></main></body></html>
    <?php
    exit;
}

$slug = trim((string)($_GET['t'] ?? ''));
$template = $slug !== '' ? ci_find_template_by_slug($pdo, $slug) : null;
if (!$template) {
    http_response_code(404);
    echo 'Certificado nao encontrado.';
    exit;
}

$cfg = ci_decode_form_config((string)($template['form_config_json'] ?? ''));
$imgBase = ci_upload_base_url();
$illustration = !empty($template['illustration_image']) ? $imgBase . '/' . $template['illustration_image'] : '';
$errors = [];
$generated = null;
$pdfUrl = '';
$redirectUrl = trim((string)($template['redirect_url'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['full_name'] ?? '')) ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    $passwordRequired = !empty($cfg['require_password']);
    $passwordOk = !$passwordRequired;

    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $nameLength < 3) $errors[] = 'Informe seu nome completo.';
    if (!empty($cfg['collect_email']) && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) $errors[] = 'Informe um email valido.';
    if (!empty($cfg['collect_phone']) && $phone === '') $errors[] = 'Informe seu telefone.';
    if ($passwordRequired) {
        $hash = (string)($template['password_hash'] ?? '');
        $passwordOk = $hash !== '' && password_verify($password, $hash);
        if (!$passwordOk) $errors[] = trim((string)($template['error_message'] ?? 'Senha incorreta. Confira e tente novamente.'));
    }

    if ($errors) {
        ci_log($pdo, (int)$template['id'], null, 'warning', $passwordOk ? 'validation_error' : 'password_error', $passwordOk ? 'Erro de validacao no formulario' : 'Senha incorreta', ['name' => $name, 'email' => $email]);
    } else {
        $issueId = 0;
        try {
            $token = ci_code();
            $pdo->beginTransaction();
            $st = $pdo->prepare("
                INSERT INTO individual_certificate_issues
                (template_id, public_token, full_name, email, phone, status, password_ok, ip_address, user_agent, generated_at)
                VALUES (:template_id,:token,:name,:email,:phone,'generated',:password_ok,:ip,:ua,NOW())
            ");
            $st->execute([
                'template_id' => (int)$template['id'],
                'token' => $token,
                'name' => $name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'password_ok' => $passwordOk ? 1 : 0,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $issueId = (int)$pdo->lastInsertId();
            $issue = [
                'id' => $issueId,
                'template_id' => (int)$template['id'],
                'public_token' => $token,
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
            $pdfUrl = ci_generate_pdf($pdo, $template, $issue);
            $pdo->prepare("UPDATE individual_certificate_issues SET pdf_url=:pdf WHERE id=:id")->execute(['pdf' => $pdfUrl, 'id' => $issueId]);
            $pdo->commit();
            $generated = $issue + ['pdf_url' => $pdfUrl];
            ci_log($pdo, (int)$template['id'], $issueId, 'info', 'certificate_generated', 'Certificado gerado', ['name' => $name, 'pdf_url' => $pdfUrl]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($issueId > 0) {
                try { $pdo->prepare("UPDATE individual_certificate_issues SET status='error', error_message=:e WHERE id=:id")->execute(['e' => $e->getMessage(), 'id' => $issueId]); } catch (Throwable $ignored) {}
            }
            $errors[] = 'Nao foi possivel gerar o certificado agora. Tente novamente.';
            ci_log($pdo, (int)$template['id'], $issueId ?: null, 'error', 'generation_error', 'Erro ao gerar certificado', ['error' => $e->getMessage()]);
        }
    }
}

$buttonLabel = trim((string)($template['button_label'] ?? 'Baixar certificado')) ?: 'Baixar certificado';
$successMessage = trim((string)($template['success_message'] ?? 'Certificado gerado com sucesso.'));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= cip_h((string)$cfg['page_title']) ?></title>
<style>
body{margin:0;min-height:100vh;background:#07101f;color:#e2e8f0;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;display:grid;place-items:center;padding:24px}.wrap{width:min(920px,100%);display:grid;grid-template-columns:<?= !empty($cfg['show_illustration']) && $illustration ? '1fr 1fr' : '1fr' ?>;gap:18px;align-items:stretch}.card{background:#0d1b33;border:1px solid #1e293b;border-radius:18px;padding:26px;box-shadow:0 24px 70px rgba(0,0,0,.24)}.media{overflow:hidden;padding:0}.media img{width:100%;height:100%;object-fit:cover;display:block}h1{margin:0 0 8px;font-size:28px}.muted{color:#94a3b8;line-height:1.5}.field{margin-top:14px}label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:6px}input{width:100%;box-sizing:border-box;background:#07101f;border:1px solid #263448;color:#e2e8f0;border-radius:10px;padding:12px;font-size:15px}button,.btn{width:100%;box-sizing:border-box;border:0;border-radius:999px;background:#facc15;color:#111827;font-weight:900;padding:13px 18px;margin-top:18px;text-align:center;text-decoration:none;display:block}.alert{border-radius:10px;padding:12px 14px;margin:14px 0;line-height:1.45}.err{background:#7f1d1d;color:#fecaca}.ok{background:#064e3b;color:#bbf7d0}.code{font-family:monospace;color:#facc15;word-break:break-all}.secondary{background:#1f2937;color:#e2e8f0;border:1px solid #334155}@media(max-width:760px){.wrap{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
<?php if (!empty($cfg['show_illustration']) && $illustration): ?><section class="card media"><img src="<?= cip_h($illustration) ?>" alt=""></section><?php endif; ?>
<section class="card">
    <h1><?= cip_h((string)$cfg['page_title']) ?></h1>
    <p class="muted"><?= nl2br(cip_h((string)$cfg['page_description'])) ?></p>

    <?php foreach ($errors as $e): ?><div class="alert err"><?= cip_h($e) ?></div><?php endforeach; ?>

    <?php if ($generated): ?>
        <div class="alert ok"><?= nl2br(cip_h($successMessage)) ?></div>
        <p class="muted">Codigo: <span class="code"><?= cip_h($generated['public_token']) ?></span></p>
        <?php if (($template['action_mode'] ?? 'download') === 'redirect' && $redirectUrl !== ''): ?>
            <a class="btn" href="<?= cip_h($redirectUrl) ?>"> <?= cip_h($buttonLabel) ?> </a>
            <a class="btn secondary" href="<?= cip_h($generated['pdf_url']) ?>" target="_blank">Abrir PDF</a>
        <?php else: ?>
            <a class="btn" href="<?= cip_h($generated['pdf_url']) ?>" target="_blank"><?= cip_h($buttonLabel) ?></a>
        <?php endif; ?>
    <?php else: ?>
        <form method="post">
            <div class="field"><label>Nome completo</label><input name="full_name" required autocomplete="name" value="<?= cip_h((string)($_POST['full_name'] ?? '')) ?>"></div>
            <?php if (!empty($cfg['collect_email'])): ?><div class="field"><label>Email</label><input name="email" type="email" autocomplete="email" value="<?= cip_h((string)($_POST['email'] ?? '')) ?>"></div><?php endif; ?>
            <?php if (!empty($cfg['collect_phone'])): ?><div class="field"><label>Telefone</label><input name="phone" autocomplete="tel" value="<?= cip_h((string)($_POST['phone'] ?? '')) ?>"></div><?php endif; ?>
            <?php if (!empty($cfg['require_password'])): ?><div class="field"><label>Senha</label><input name="password" type="password" autocomplete="off" required></div><?php endif; ?>
            <button type="submit">Gerar certificado</button>
        </form>
    <?php endif; ?>
</section>
</main>
</body>
</html>
