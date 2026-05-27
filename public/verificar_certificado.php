<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = getPDO();

$codigoUid = trim((string)($_GET['c'] ?? ''));

$cert = null;
$user = null;
$notFound = false;

if ($codigoUid !== '' && $codigoUid !== 'CERT_TESTE_DEMO') {
    try {
        $st = $pdo->prepare("SELECT * FROM certificates WHERE codigo_uid = :c LIMIT 1");
        $st->execute([':c' => $codigoUid]);
        $cert = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($cert) {
            $stU = $pdo->prepare("SELECT nome, email FROM users WHERE id = :id LIMIT 1");
            $stU->execute([':id' => (int)($cert['user_id'] ?? 0)]);
            $user = $stU->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $notFound = true;
        }
    } catch (Throwable $e) {
        $notFound = true;
    }
} elseif ($codigoUid === 'CERT_TESTE_DEMO') {
    // Demo para preview da página
    $cert = [
        'codigo_uid' => 'CERT_TESTE_DEMO',
        'course'     => 'Curso Demonstração',
        'emitido_em' => date('Y-m-d H:i:s'),
        'status'     => 'emitido',
    ];
    $user = ['nome' => 'Aluno Demonstração', 'email' => 'demo@exemplo.com'];
} else {
    $notFound = true;
}

$stCfg = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg = $stCfg->fetch() ?: [];

$primary    = $appCfg['primary_color']    ?? '#facc15';
$bgColor    = $appCfg['background_color'] ?? '#07101f';
$logoUrl    = $appCfg['logo_url']         ?? '';
$courseTitle = $appCfg['course_title']    ?? '';

$dataEmissao = '';
if ($cert && !empty($cert['emitido_em'])) {
    $dataEmissao = date('d/m/Y', strtotime($cert['emitido_em']));
}
$curso = !empty($cert['course']) ? $cert['course'] : ($courseTitle ?: 'Não informado');
$pdfUrl = ($cert && !empty($cert['pdf_url'])) ? trim((string)$cert['pdf_url']) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Verificação de Certificado</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: <?= h($bgColor) ?>;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #0b1120;
            border-radius: 20px;
            border: 1px solid #1e2d45;
            box-shadow: 0 24px 64px rgba(0,0,0,.6);
            max-width: 520px;
            width: 100%;
            overflow: hidden;
        }
        .card-top {
            background: linear-gradient(135deg, #0f1f3d 0%, #0b1120 100%);
            padding: 32px 32px 24px;
            text-align: center;
            border-bottom: 1px solid #1e2d45;
        }
        .logo {
            max-height: 52px;
            max-width: 180px;
            margin-bottom: 16px;
            object-fit: contain;
        }
        .icon-badge {
            width: 64px; height: 64px;
            border-radius: 999px;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px;
            margin: 0 auto 16px;
        }
        .icon-valid { background: rgba(34,197,94,.12); border: 2px solid rgba(34,197,94,.3); }
        .icon-invalid { background: rgba(239,68,68,.12); border: 2px solid rgba(239,68,68,.3); }
        .status-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 6px;
        }
        .status-sub {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .card-body { padding: 28px 32px; }
        .info-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #1a2740;
        }
        .info-row:last-child { border-bottom: none; }
        .info-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .info-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
        .info-value { font-size: 15px; font-weight: 600; word-break: break-word; }
        .codigo-box {
            margin-top: 20px;
            background: rgba(255,255,255,.03);
            border: 1px solid #1e2d45;
            border-radius: 10px;
            padding: 12px 16px;
            font-family: monospace;
            font-size: 13px;
            color: #94a3b8;
            word-break: break-all;
            text-align: center;
        }
        .card-footer {
            padding: 16px 32px;
            text-align: center;
            border-top: 1px solid #1e2d45;
            font-size: 12px;
            color: #475569;
        }
        .valid-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.3);
            color: #86efac; border-radius: 999px; padding: 4px 14px;
            font-size: 13px; font-weight: 600; margin-top: 10px;
        }
        .invalid-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5; border-radius: 999px; padding: 4px 14px;
            font-size: 13px; font-weight: 600; margin-top: 10px;
        }
        .download-cert {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 16px;
            padding: 12px 18px;
            border-radius: 10px;
            background: <?= h($primary) ?>;
            color: #07101f;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,.18);
        }
        .download-cert:hover { filter: brightness(1.06); }
    </style>
</head>
<body>
<div class="card">
    <div class="card-top">
        <?php if ($logoUrl): ?>
            <img src="<?= h($logoUrl) ?>" class="logo" alt="Logo">
        <?php endif; ?>

        <?php if ($cert && !$notFound): ?>
            <div class="icon-badge icon-valid">✅</div>
            <div class="status-title">Certificado válido</div>
            <div class="status-sub">Este certificado foi emitido e é autêntico.</div>
            <div class="valid-pill">✓ Autenticidade confirmada</div>
        <?php else: ?>
            <div class="icon-badge icon-invalid">❌</div>
            <div class="status-title">Certificado não encontrado</div>
            <div class="status-sub">
                <?php if ($codigoUid === ''): ?>
                    Nenhum código foi informado. Acesse a URL via QR Code do certificado.
                <?php else: ?>
                    O código informado não foi encontrado em nosso sistema.
                <?php endif; ?>
            </div>
            <div class="invalid-pill">✗ Não autenticado</div>
        <?php endif; ?>
    </div>

    <?php if ($cert && !$notFound): ?>
    <div class="card-body">
        <div class="info-row">
            <div class="info-icon">👤</div>
            <div>
                <div class="info-label">Nome do aluno</div>
                <div class="info-value"><?= h($user['nome'] ?? 'Não informado') ?></div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">📚</div>
            <div>
                <div class="info-label">Curso / Treinamento</div>
                <div class="info-value"><?= h($curso) ?></div>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon">📅</div>
            <div>
                <div class="info-label">Data de emissão</div>
                <div class="info-value"><?= h($dataEmissao ?: 'Não informada') ?></div>
            </div>
        </div>
        <div class="codigo-box">
            Código: <?= h($codigoUid) ?>
        </div>
        <?php if ($pdfUrl !== ''): ?>
            <a class="download-cert" href="<?= h($pdfUrl) ?>" download target="_blank" rel="noopener">
                Baixar certificado em PDF
            </a>
        <?php endif; ?>
    </div>
    <?php elseif ($codigoUid !== ''): ?>
    <div class="card-body" style="text-align:center;padding:32px;">
        <div style="color:#64748b;font-size:14px;">
            Código consultado: <code style="color:#94a3b8;"><?= h($codigoUid) ?></code>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-footer">
        Sistema de verificação de certificados &nbsp;·&nbsp; <?= h($appCfg['course_title'] ?? '') ?>
    </div>
</div>
</body>
</html>
