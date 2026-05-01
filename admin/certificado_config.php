<?php
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/funcoes.php';

proteger_admin();

include __DIR__ . '/_header.php';

function h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

$pdo = getPDO();

// Garante que existe um registro de configuração (id = 1)
$stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $pdo->exec("INSERT INTO certificate_config (id, created_at, updated_at) VALUES (1, NOW(), NOW())");
    $stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
}

$config = array_merge([
    'front_image'               => null,
    'back_image'                => null,
    'layout_json'               => '',
    'error_html'                => '',
    'success_html'              => '',
    'intro_video_enabled'       => 0,
    'intro_video_url'           => '',
    'senha_video_enabled'       => 0,
    'senha_video_url'           => '',
    'senha_error_video_enabled' => 0,
    'senha_error_video_url'     => '',
    'webhook_error_url'         => '',
    'webhook_emitido_url'       => '',
    'certificado_button_label'  => 'Quero receber meu certificado',
    'certificado_button_link'   => '',
], $config ?: []);

$mensagemOk   = '';
$mensagemErro = '';

$uploadDir    = dirname(__DIR__) . '/uploads/certificados';
$basePublic   = rtrim(BASE_URL, '/');
$baseRoot     = preg_replace('#/public$#', '', $basePublic);
$uploadUrlBase = $baseRoot . '/uploads/certificados';
$verifyBaseUrl = $basePublic . '/verificar_certificado.php';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $errorHtml   = $_POST['error_html']   ?? '';
        $successHtml = $_POST['success_html'] ?? '';

        $introVideoEnabled       = isset($_POST['intro_video_enabled']) ? 1 : 0;
        $introVideoUrl           = trim($_POST['intro_video_url'] ?? '');
        $senhaVideoEnabled       = isset($_POST['senha_video_enabled']) ? 1 : 0;
        $senhaVideoUrl           = trim($_POST['senha_video_url'] ?? '');
        $senhaErrorVideoEnabled  = isset($_POST['senha_error_video_enabled']) ? 1 : 0;
        $senhaErrorVideoUrl      = trim($_POST['senha_error_video_url'] ?? '');

        $webhookErrorUrl   = trim($_POST['webhook_error_url'] ?? '');
        $webhookEmitidoUrl = trim($_POST['webhook_emitido_url'] ?? '');

        $btnLabel = trim($_POST['certificado_button_label'] ?? '');
        $btnLink  = trim($_POST['certificado_button_link'] ?? '');
        $layoutJson = trim($_POST['layout_json'] ?? '');

        $frontImage = $config['front_image'];
        $backImage  = $config['back_image'];

        if (!empty($_FILES['front_image']['name']) && $_FILES['front_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['front_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                throw new RuntimeException('A imagem da frente deve ser PNG ou JPG.');
            }
            $nome = 'cert_front_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['front_image']['tmp_name'], $uploadDir . '/' . $nome)) {
                throw new RuntimeException('Falha ao enviar a imagem da frente.');
            }
            $frontImage = $nome;
        }

        if (!empty($_FILES['back_image']['name']) && $_FILES['back_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['back_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                throw new RuntimeException('A imagem do verso deve ser PNG ou JPG.');
            }
            $nome = 'cert_back_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['back_image']['tmp_name'], $uploadDir . '/' . $nome)) {
                throw new RuntimeException('Falha ao enviar a imagem do verso.');
            }
            $backImage = $nome;
        }

        $sql = "
            UPDATE certificate_config SET
                front_image                  = :front_image,
                back_image                   = :back_image,
                layout_json                  = :layout_json,
                error_html                   = :error_html,
                success_html                 = :success_html,
                intro_video_enabled          = :intro_video_enabled,
                intro_video_url              = :intro_video_url,
                senha_video_enabled          = :senha_video_enabled,
                senha_video_url              = :senha_video_url,
                senha_error_video_enabled    = :senha_error_video_enabled,
                senha_error_video_url        = :senha_error_video_url,
                webhook_error_url            = :webhook_error_url,
                webhook_emitido_url          = :webhook_emitido_url,
                certificado_button_label     = :certificado_button_label,
                certificado_button_link      = :certificado_button_link,
                updated_at                   = NOW()
            WHERE id = 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':front_image'               => $frontImage,
            ':back_image'                => $backImage,
            ':layout_json'               => $layoutJson,
            ':error_html'                => $errorHtml,
            ':success_html'              => $successHtml,
            ':intro_video_enabled'       => $introVideoEnabled,
            ':intro_video_url'           => $introVideoUrl,
            ':senha_video_enabled'       => $senhaVideoEnabled,
            ':senha_video_url'           => $senhaVideoUrl,
            ':senha_error_video_enabled' => $senhaErrorVideoEnabled,
            ':senha_error_video_url'     => $senhaErrorVideoUrl,
            ':webhook_error_url'         => $webhookErrorUrl,
            ':webhook_emitido_url'       => $webhookEmitidoUrl,
            ':certificado_button_label'  => $btnLabel,
            ':certificado_button_link'   => $btnLink,
        ]);

        $mensagemOk = 'Configurações salvas com sucesso!';
        $stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: $config;

    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
    }
}

$layout = ['front' => [], 'back' => []];
if (!empty($config['layout_json'])) {
    $tmp = json_decode($config['layout_json'], true);
    if (is_array($tmp)) {
        $layout['front'] = $tmp['front'] ?? [];
        $layout['back']  = $tmp['back']  ?? [];
    }
}

$frontImageUrl = !empty($config['front_image']) ? $uploadUrlBase . '/' . $config['front_image'] : null;
$backImageUrl  = !empty($config['back_image'])  ? $uploadUrlBase . '/' . $config['back_image']  : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Configuração de Certificado</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #020617;
            --bg-card: #0b1120;
            --border: #1e2d45;
            --text: #e2e8f0;
            --muted: #64748b;
            --primary: #facc15;
            --green: #22c55e;
            --red: #ef4444;
            --blue: #3b82f6;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); font-size:14px; }
        .page-wrap { max-width:1120px; margin:36px auto; padding:0 24px 60px; }

        .page-header { margin-bottom:28px; }
        .page-header h1 { font-size:26px; font-weight:700; margin:0 0 4px; }
        .page-header p { font-size:13px; color:var(--muted); margin:0; }

        .card {
            background:var(--bg-card);
            border-radius:16px;
            border:1px solid var(--border);
            box-shadow:0 8px 32px rgba(0,0,0,.4);
            padding:22px 26px;
            margin-bottom:22px;
        }
        .card-header {
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:18px;
            padding-bottom:14px;
            border-bottom:1px solid var(--border);
        }
        .card-icon {
            width:36px; height:36px;
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:18px;
            flex-shrink:0;
        }
        .card-icon.yellow { background:rgba(250,204,21,.12); }
        .card-icon.green  { background:rgba(34,197,94,.12); }
        .card-icon.blue   { background:rgba(59,130,246,.12); }
        .card-icon.purple { background:rgba(168,85,247,.12); }
        .card-header-text h2 { font-size:16px; font-weight:600; margin:0 0 2px; }
        .card-header-text p  { font-size:12px; color:var(--muted); margin:0; }

        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media(max-width:860px){ .grid-2 { grid-template-columns:1fr; } }

        label.lbl { font-size:12px; font-weight:500; color:var(--muted); display:block; margin-bottom:5px; text-transform:uppercase; letter-spacing:.04em; }
        input[type="text"], input[type="url"], input[type="number"], textarea, select {
            width:100%;
            padding:9px 12px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#07101f;
            color:var(--text);
            font-size:13px;
            outline:none;
            transition:border-color .15s;
        }
        input[type="text"]:focus, input[type="url"]:focus, input[type="number"]:focus, textarea:focus, select:focus {
            border-color:#3b82f6;
        }
        input[type="file"] { color:var(--muted); font-size:12px; }
        textarea { min-height:80px; resize:vertical; }

        .checkbox-row { display:flex; align-items:center; gap:8px; }
        .checkbox-row input[type="checkbox"] { width:16px; height:16px; accent-color:var(--primary); flex-shrink:0; }
        .checkbox-row label { font-size:13px; margin:0; }

        .note { font-size:11.5px; color:var(--muted); margin-top:5px; line-height:1.5; }
        .note code { background:rgba(255,255,255,.06); border-radius:4px; padding:1px 5px; font-size:11px; }

        .btn {
            display:inline-flex; align-items:center; gap:6px;
            border:none;
            background:var(--primary);
            color:#111;
            font-weight:700;
            font-size:13px;
            padding:10px 20px;
            border-radius:999px;
            cursor:pointer;
            text-decoration:none;
        }
        .btn:hover { filter:brightness(1.06); }
        .btn.green { background:var(--green); color:#fff; }
        .btn.blue  { background:var(--blue); color:#fff; }
        .btn.sm    { padding:6px 14px; font-size:12px; }

        .msg { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:999px; font-size:13px; margin-bottom:18px; }
        .msg-ok  { background:rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.35); color:#86efac; }
        .msg-err { background:rgba(239,68,68,.08);  border:1px solid rgba(239,68,68,.35); color:#fca5a5; }

        .spacer { height:14px; }
        .spacer-sm { height:8px; }

        /* ===== CERT CANVAS ===== */
        .cert-canvas-wrap { position:relative; border-radius:12px; border:1px solid var(--border); overflow:hidden; background:#0a1628; width:100%; padding-top:70.7%; }
        .cert-canvas-wrap img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .cert-overlay { position:absolute; inset:0; }
        .var-item {
            position:absolute;
            transform:translate(-50%, -50%); /* must match PDF .field transform */
            padding:4px 10px;
            background:rgba(0,0,0,.65);
            border:1.5px dashed rgba(250,204,21,.75);
            color:#fef3c7;
            font-weight:600;
            border-radius:6px;
            cursor:move;
            white-space:nowrap;
            user-select:none;
            line-height:1.2;
        }
        .var-item.qr-item {
            border-style:solid;
            border-color:rgba(59,130,246,.75);
            color:#bfdbfe;
            background:rgba(0,0,0,.7);
            display:flex; align-items:center; justify-content:center; flex-direction:column;
        }
        .var-remove {
            position:absolute; top:-9px; right:-9px;
            width:18px; height:18px;
            border-radius:999px;
            background:var(--red); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:12px; cursor:pointer; line-height:1;
            z-index:10;
        }

        .layout-tools {
            margin-top:12px;
            display:flex; flex-wrap:wrap; gap:10px; align-items:center;
        }
        .layout-tools select, .layout-tools input[type="number"] { width:auto; min-width:140px; }
        .font-label { font-size:12px; color:var(--muted); white-space:nowrap; }

        .side-label {
            font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.08em;
            color:var(--muted); margin-bottom:8px;
        }
        .no-image-hint {
            padding:24px 16px;
            border:1px dashed var(--border);
            border-radius:10px;
            text-align:center;
            color:var(--muted);
            font-size:13px;
            margin-top:8px;
        }
        .verify-hint {
            font-size:11.5px; color:var(--muted);
            background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.2);
            border-radius:8px; padding:8px 12px; margin-top:10px; line-height:1.6;
        }
        .verify-hint code { color:#93c5fd; background:rgba(59,130,246,.1); border-radius:4px; padding:1px 5px; font-size:11px; }

        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
        .badge-ok  { background:rgba(34,197,94,.1);  color:#86efac; border:1px solid rgba(34,197,94,.3); }
        .badge-off { background:rgba(100,116,139,.1); color:#94a3b8; border:1px solid rgba(100,116,139,.3); }
    </style>
</head>
<body>
<div class="page-wrap">

    <div class="page-header">
        <h1>Configuração de Certificado</h1>
        <p>Layout visual, mensagens, vídeos, QR de autenticação e webhooks para emissão do certificado.</p>
    </div>

    <?php if ($mensagemOk): ?>
        <div class="msg msg-ok">✅ <?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="msg msg-err">⚠️ <?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="form-cert">

        <!-- ===== LAYOUT VISUAL ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon yellow">🎓</div>
                <div class="card-header-text">
                    <h2>Layout visual do certificado</h2>
                    <p>Envie as imagens e posicione as variáveis arrastando no canvas. A posição no canvas equivale exatamente à posição no PDF.</p>
                </div>
            </div>

            <div class="grid-2">
                <!-- FRENTE -->
                <div>
                    <label class="lbl">Imagem da frente (PNG/JPG)</label>
                    <input type="file" name="front_image" accept="image/png,image/jpeg">
                    <?php if ($frontImageUrl): ?>
                        <div class="spacer-sm"></div>
                        <div class="side-label">Frente</div>
                        <div class="cert-canvas-wrap" data-side="front">
                            <img src="<?= h($frontImageUrl) ?>" alt="Frente">
                            <div class="cert-overlay"></div>
                        </div>
                        <div class="layout-tools">
                            <select id="add-front-field" onchange="updateFontLabel('front')">
                                <option value="nome">Nome do aluno</option>
                                <option value="data">Data de emissão</option>
                                <option value="qr">QR Code de autenticação</option>
                            </select>
                            <input type="number" id="add-front-font" min="8" max="400" value="26" step="1">
                            <span class="font-label" id="lbl-front-font">tamanho da fonte (px)</span>
                            <button type="button" class="btn sm" onclick="addVar('front')">+ Adicionar</button>
                        </div>
                        <div class="verify-hint" id="qr-hint-front" style="display:none;">
                            O QR Code aponta para: <code><?= h($verifyBaseUrl) ?>?c=CODIGO</code><br>
                            Acesse essa URL para configurar a página de verificação pública.
                        </div>
                    <?php else: ?>
                        <div class="no-image-hint">Salve a imagem da frente para habilitar o editor de posição.</div>
                    <?php endif; ?>
                </div>

                <!-- VERSO -->
                <div>
                    <label class="lbl">Imagem do verso (PNG/JPG) — opcional</label>
                    <input type="file" name="back_image" accept="image/png,image/jpeg">
                    <?php if ($backImageUrl): ?>
                        <div class="spacer-sm"></div>
                        <div class="side-label">Verso</div>
                        <div class="cert-canvas-wrap" data-side="back">
                            <img src="<?= h($backImageUrl) ?>" alt="Verso">
                            <div class="cert-overlay"></div>
                        </div>
                        <div class="layout-tools">
                            <select id="add-back-field" onchange="updateFontLabel('back')">
                                <option value="nome">Nome do aluno</option>
                                <option value="data">Data de emissão</option>
                                <option value="qr">QR Code de autenticação</option>
                            </select>
                            <input type="number" id="add-back-font" min="8" max="400" value="18" step="1">
                            <span class="font-label" id="lbl-back-font">tamanho da fonte (px)</span>
                            <button type="button" class="btn sm" onclick="addVar('back')">+ Adicionar</button>
                        </div>
                        <div class="verify-hint" id="qr-hint-back" style="display:none;">
                            O QR Code aponta para: <code><?= h($verifyBaseUrl) ?>?c=CODIGO</code>
                        </div>
                    <?php else: ?>
                        <div class="no-image-hint">Salve a imagem do verso para habilitar o editor de posição.</div>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" id="layout_json" name="layout_json" value="<?= h($config['layout_json'] ?? '') ?>">
        </div>

        <!-- ===== MENSAGENS ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon green">💬</div>
                <div class="card-header-text">
                    <h2>Mensagens exibidas ao aluno</h2>
                    <p>Textos HTML exibidos na tela de certificado após o aluno digitar a senha.</p>
                </div>
            </div>
            <div class="grid-2">
                <div>
                    <label class="lbl">Mensagem de sucesso (senha correta)</label>
                    <textarea name="success_html" placeholder="HTML exibido quando a senha está correta."><?= h($config['success_html'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="lbl">Mensagem de erro (senha incorreta)</label>
                    <textarea name="error_html" placeholder="HTML exibido quando a senha está errada."><?= h($config['error_html'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ===== VÍDEOS ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon blue">🎬</div>
                <div class="card-header-text">
                    <h2>Vídeos de orientação</h2>
                    <p>Vídeos exibidos antes da senha ou após tentativa, para guiar o aluno.</p>
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <div class="checkbox-row">
                        <input type="checkbox" id="intro_video_enabled" name="intro_video_enabled" <?= !empty($config['intro_video_enabled']) ? 'checked' : '' ?>>
                        <label for="intro_video_enabled">Exibir vídeo introdutório</label>
                    </div>
                    <div class="spacer-sm"></div>
                    <label class="lbl">URL do vídeo inicial</label>
                    <input type="url" name="intro_video_url" placeholder="https://www.youtube.com/embed/..." value="<?= h($config['intro_video_url'] ?? '') ?>">
                    <div class="note">Aparece antes de o aluno tentar digitar a senha.</div>
                </div>
            </div>

            <div class="spacer"></div>

            <div class="grid-2">
                <div>
                    <div class="checkbox-row">
                        <input type="checkbox" id="senha_video_enabled" name="senha_video_enabled" <?= !empty($config['senha_video_enabled']) ? 'checked' : '' ?>>
                        <label for="senha_video_enabled">Vídeo ao acertar a senha</label>
                    </div>
                    <div class="spacer-sm"></div>
                    <label class="lbl">URL do vídeo (senha correta)</label>
                    <input type="url" name="senha_video_url" placeholder="https://www.youtube.com/embed/..." value="<?= h($config['senha_video_url'] ?? '') ?>">
                </div>
                <div>
                    <div class="checkbox-row">
                        <input type="checkbox" id="senha_error_video_enabled" name="senha_error_video_enabled" <?= !empty($config['senha_error_video_enabled']) ? 'checked' : '' ?>>
                        <label for="senha_error_video_enabled">Vídeo ao errar a senha</label>
                    </div>
                    <div class="spacer-sm"></div>
                    <label class="lbl">URL do vídeo (senha errada)</label>
                    <input type="url" name="senha_error_video_url" placeholder="https://www.youtube.com/embed/..." value="<?= h($config['senha_error_video_url'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- ===== WEBHOOKS ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon purple">🔗</div>
                <div class="card-header-text">
                    <h2>Webhooks e botão de certificado</h2>
                    <p>Disparos automáticos e configuração do botão exibido após a senha correta.</p>
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <label class="lbl">Webhook — senha errada</label>
                    <input type="url" name="webhook_error_url" placeholder="https://..." value="<?= h($config['webhook_error_url'] ?? '') ?>">
                    <div class="note">Chamado quando o aluno erra a senha.</div>
                </div>
                <div>
                    <label class="lbl">Webhook — certificado emitido</label>
                    <input type="url" name="webhook_emitido_url" placeholder="https://..." value="<?= h($config['webhook_emitido_url'] ?? '') ?>">
                    <div class="note">Chamado quando o certificado é emitido (senha correta).</div>
                </div>
            </div>

            <div class="spacer"></div>

            <div class="grid-2">
                <div>
                    <label class="lbl">Texto do botão de certificado</label>
                    <input type="text" name="certificado_button_label" value="<?= h($config['certificado_button_label'] ?? 'Quero receber meu certificado') ?>">
                </div>
                <div>
                    <label class="lbl">Link do botão (automação/PDF)</label>
                    <input type="url" name="certificado_button_link" placeholder="https://..." value="<?= h($config['certificado_button_link'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn">💾 Salvar configurações</button>
            <a href="certificado_preview.php" target="_blank" class="btn green">👁 Gerar PDF de teste</a>
            <a href="<?= h($verifyBaseUrl) ?>?c=CERT_TESTE_DEMO" target="_blank" class="btn blue">🔍 Ver página de verificação</a>
        </div>
    </form>
</div>

<script>
(function() {
    const layoutInit = <?= json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const layout = {
        front: layoutInit.front || [],
        back:  layoutInit.back  || [],
    };

    function syncHidden() {
        const el = document.getElementById('layout_json');
        if (el) el.value = JSON.stringify(layout);
    }

    const fieldLabels = {
        nome: 'Nome do aluno',
        data: 'Data de emissão',
        qr:   'QR Code',
    };

    function updateFontLabel(side) {
        const sel = document.getElementById('add-' + side + '-field');
        const lbl = document.getElementById('lbl-' + side + '-font');
        const hint = document.getElementById('qr-hint-' + side);
        if (!sel || !lbl) return;
        const isQr = sel.value === 'qr';
        lbl.textContent = isQr ? 'tamanho do QR (px)' : 'tamanho da fonte (px)';
        if (hint) hint.style.display = isQr ? 'block' : 'none';
        const fontInput = document.getElementById('add-' + side + '-font');
        if (fontInput) {
            if (isQr && parseInt(fontInput.value) < 60) fontInput.value = 120;
        }
    }

    window.updateFontLabel = updateFontLabel;

    function makeDraggable(el, side, index) {
        const canvas = el.closest('.cert-canvas-wrap');
        if (!canvas) return;
        const overlay = canvas.querySelector('.cert-overlay');
        if (!overlay) return;

        el.addEventListener('mousedown', startDrag);
        el.addEventListener('touchstart', startDrag, {passive: false});

        function startDrag(ev) {
            ev.preventDefault();
            const rect = overlay.getBoundingClientRect();
            const sx = ev.touches ? ev.touches[0].clientX : ev.clientX;
            const sy = ev.touches ? ev.touches[0].clientY : ev.clientY;
            const startLeft = parseFloat(el.style.left) || 50;
            const startTop  = parseFloat(el.style.top)  || 50;

            function move(ev2) {
                ev2.preventDefault();
                const cx = ev2.touches ? ev2.touches[0].clientX : ev2.clientX;
                const cy = ev2.touches ? ev2.touches[0].clientY : ev2.clientY;
                let newLeft = startLeft + ((cx - sx) * 100 / rect.width);
                let newTop  = startTop  + ((cy - sy) * 100 / rect.height);
                newLeft = Math.max(0, Math.min(100, newLeft));
                newTop  = Math.max(0, Math.min(100, newTop));
                el.style.left = newLeft + '%';
                el.style.top  = newTop  + '%';
                if (layout[side][index]) {
                    layout[side][index].x = newLeft;
                    layout[side][index].y = newTop;
                    syncHidden();
                }
            }

            function stop() {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', stop);
                document.removeEventListener('touchmove', move);
                document.removeEventListener('touchend', stop);
            }

            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', stop);
            document.addEventListener('touchmove', move, {passive: false});
            document.addEventListener('touchend', stop);
        }
    }

    function renderSide(side) {
        const canvas = document.querySelector('.cert-canvas-wrap[data-side="' + side + '"]');
        if (!canvas) return;
        const overlay = canvas.querySelector('.cert-overlay');
        if (!overlay) return;

        overlay.innerHTML = '';
        (layout[side] || []).forEach((item, index) => {
            const el = document.createElement('div');
            const isQr = item.field === 'qr';
            el.className = 'var-item' + (isQr ? ' qr-item' : '');
            el.style.left = (item.x ?? 50) + '%';
            el.style.top  = (item.y ?? 50) + '%';

            if (isQr) {
                const sz = Math.max(30, Math.min(200, item.font ?? 120));
                el.style.width  = sz + 'px';
                el.style.height = sz + 'px';
                el.style.padding = '4px';
                el.innerHTML = '<div style="font-size:11px;font-weight:700;">QR</div><div style="font-size:9px;opacity:.75;">autenticação</div>';
            } else {
                el.style.fontSize = (item.font ?? 18) + 'px';
                el.textContent = fieldLabels[item.field] || item.field || 'VAR';
            }

            const rm = document.createElement('div');
            rm.className = 'var-remove';
            rm.textContent = '×';
            rm.addEventListener('click', function(ev) {
                ev.stopPropagation();
                layout[side].splice(index, 1);
                renderSide(side);
                syncHidden();
            });
            el.appendChild(rm);
            overlay.appendChild(el);
            makeDraggable(el, side, index);
        });
    }

    window.addVar = function(side) {
        const fieldSel  = document.getElementById('add-' + side + '-field');
        const fontInput = document.getElementById('add-' + side + '-font');
        if (!fieldSel || !fontInput) return;

        const field = fieldSel.value || 'nome';
        const font  = parseInt(fontInput.value, 10) || (field === 'qr' ? 120 : 20);

        if (!layout[side]) layout[side] = [];
        layout[side].push({
            id: 'v_' + Date.now() + '_' + Math.random().toString(16).slice(2),
            field,
            x: 50,
            y: 50,
            font,
        });
        renderSide(side);
        syncHidden();
    };

    renderSide('front');
    renderSide('back');
    syncHidden();

    document.getElementById('form-cert').addEventListener('submit', syncHidden);
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
