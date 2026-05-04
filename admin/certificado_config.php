<?php
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/funcoes.php';

proteger_admin();

$menu = 'certificado';

include __DIR__ . '/_header.php';

function h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

$pdo = getPDO();

// === Migração: colunas de senha ===
foreach ([
    "ALTER TABLE certificate_config ADD COLUMN senha_tipo VARCHAR(20) NOT NULL DEFAULT 'unica'",
    "ALTER TABLE certificate_config ADD COLUMN senha_mode VARCHAR(20) NOT NULL DEFAULT 'fixa'",
    "ALTER TABLE certificate_config ADD COLUMN senha_fixa VARCHAR(255) NOT NULL DEFAULT ''",
    "ALTER TABLE certificate_config ADD COLUMN senha_partes_fixas TEXT NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (Throwable $e) {}
}
try { $pdo->exec("ALTER TABLE turmas ADD COLUMN senha_certificado VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}

// Garante registro id = 1
$stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $pdo->exec("INSERT INTO certificate_config (id, created_at, updated_at) VALUES (1, NOW(), NOW())");
    $stmt  = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
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
    'certificado_button_label'  => 'Quero receber meu certificado',
    'certificado_button_link'   => '',
    'senha_tipo'                => 'unica',
    'senha_mode'                => 'fixa',
    'senha_fixa'                => '',
    'senha_partes_fixas'        => '[]',
], $config ?: []);

$mensagemOk   = '';
$mensagemErro = '';

$uploadDir     = dirname(__DIR__) . '/uploads/certificados';
$basePublic    = rtrim(BASE_URL, '/');
$baseRoot      = preg_replace('#/public$#', '', $basePublic);
$uploadUrlBase = $baseRoot . '/uploads/certificados';
$verifyBaseUrl = $basePublic . '/verificar_certificado.php';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $errorHtml   = $_POST['error_html']   ?? '';
        $successHtml = $_POST['success_html'] ?? '';

        $introVideoEnabled      = isset($_POST['intro_video_enabled']) ? 1 : 0;
        $introVideoUrl          = trim($_POST['intro_video_url'] ?? '');
        $senhaVideoEnabled      = isset($_POST['senha_video_enabled']) ? 1 : 0;
        $senhaVideoUrl          = trim($_POST['senha_video_url'] ?? '');
        $senhaErrorVideoEnabled = isset($_POST['senha_error_video_enabled']) ? 1 : 0;
        $senhaErrorVideoUrl     = trim($_POST['senha_error_video_url'] ?? '');

        $btnLabel   = trim($_POST['certificado_button_label'] ?? '');
        $btnLink    = trim($_POST['certificado_button_link'] ?? '');
        $layoutJson = trim($_POST['layout_json'] ?? '');

        $senhaTipo       = in_array($_POST['senha_tipo'] ?? '', ['unica','modular'], true) ? $_POST['senha_tipo'] : 'unica';
        $senhaMode       = in_array($_POST['senha_mode'] ?? '', ['fixa','variavel'], true) ? $_POST['senha_mode'] : 'fixa';
        $senhaFixa       = trim($_POST['senha_fixa'] ?? '');
        $senhaPartesFixas = trim($_POST['senha_partes_fixas'] ?? '[]');
        // validate JSON
        if (!is_array(json_decode($senhaPartesFixas, true))) $senhaPartesFixas = '[]';

        $frontImage = $config['front_image'];
        $backImage  = $config['back_image'];

        if (!empty($_FILES['front_image']['name']) && $_FILES['front_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['front_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) throw new RuntimeException('A imagem da frente deve ser PNG ou JPG.');
            $nome = 'cert_front_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['front_image']['tmp_name'], $uploadDir . '/' . $nome)) throw new RuntimeException('Falha ao enviar a imagem da frente.');
            $frontImage = $nome;
        }

        if (!empty($_FILES['back_image']['name']) && $_FILES['back_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['back_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) throw new RuntimeException('A imagem do verso deve ser PNG ou JPG.');
            $nome = 'cert_back_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['back_image']['tmp_name'], $uploadDir . '/' . $nome)) throw new RuntimeException('Falha ao enviar a imagem do verso.');
            $backImage = $nome;
        }

        $stmt = $pdo->prepare("
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
                certificado_button_label     = :certificado_button_label,
                certificado_button_link      = :certificado_button_link,
                senha_tipo                   = :senha_tipo,
                senha_mode                   = :senha_mode,
                senha_fixa                   = :senha_fixa,
                senha_partes_fixas           = :senha_partes_fixas,
                updated_at                   = NOW()
            WHERE id = 1
        ");
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
            ':certificado_button_label'  => $btnLabel,
            ':certificado_button_link'   => $btnLink,
            ':senha_tipo'                => $senhaTipo,
            ':senha_mode'                => $senhaMode,
            ':senha_fixa'                => $senhaFixa,
            ':senha_partes_fixas'        => $senhaPartesFixas,
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

$initPartes = json_decode($config['senha_partes_fixas'] ?? '[]', true);
if (!is_array($initPartes)) $initPartes = [];
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
            --purple: #a855f7;
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
            display:flex; align-items:center; gap:10px;
            margin-bottom:18px; padding-bottom:14px;
            border-bottom:1px solid var(--border);
        }
        .card-icon {
            width:36px; height:36px; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:18px; flex-shrink:0;
        }
        .card-icon.yellow { background:rgba(250,204,21,.12); }
        .card-icon.green  { background:rgba(34,197,94,.12); }
        .card-icon.blue   { background:rgba(59,130,246,.12); }
        .card-icon.purple { background:rgba(168,85,247,.12); }
        .card-icon.orange { background:rgba(249,115,22,.12); }
        .card-header-text h2 { font-size:16px; font-weight:600; margin:0 0 2px; }
        .card-header-text p  { font-size:12px; color:var(--muted); margin:0; }

        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media(max-width:860px){ .grid-2 { grid-template-columns:1fr; } }

        label.lbl { font-size:12px; font-weight:500; color:var(--muted); display:block; margin-bottom:5px; text-transform:uppercase; letter-spacing:.04em; }
        input[type="text"], input[type="url"], input[type="number"], input[type="password"], textarea, select {
            width:100%; padding:9px 12px; border-radius:10px;
            border:1px solid var(--border); background:#07101f; color:var(--text);
            font-size:13px; outline:none; transition:border-color .15s;
        }
        input[type="text"]:focus, input[type="url"]:focus, input[type="number"]:focus,
        input[type="password"]:focus, textarea:focus, select:focus { border-color:#3b82f6; }
        input[type="file"] { color:var(--muted); font-size:12px; }
        textarea { min-height:80px; resize:vertical; }

        .checkbox-row { display:flex; align-items:center; gap:8px; }
        .checkbox-row input[type="checkbox"] { width:16px; height:16px; accent-color:var(--primary); flex-shrink:0; }
        .checkbox-row label { font-size:13px; margin:0; }

        .note { font-size:11.5px; color:var(--muted); margin-top:5px; line-height:1.5; }
        .note code { background:rgba(255,255,255,.06); border-radius:4px; padding:1px 5px; font-size:11px; }

        .btn {
            display:inline-flex; align-items:center; gap:6px;
            border:none; background:var(--primary); color:#111; font-weight:700;
            font-size:13px; padding:10px 20px; border-radius:999px; cursor:pointer; text-decoration:none;
        }
        .btn:hover { filter:brightness(1.06); }
        .btn.green  { background:var(--green); color:#fff; }
        .btn.blue   { background:var(--blue);  color:#fff; }
        .btn.red    { background:var(--red);   color:#fff; }
        .btn.ghost  { background:rgba(255,255,255,.06); color:var(--text); border:1px solid var(--border); }
        .btn.sm     { padding:6px 14px; font-size:12px; }

        .msg { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:999px; font-size:13px; margin-bottom:18px; }
        .msg-ok  { background:rgba(34,197,94,.08);  border:1px solid rgba(34,197,94,.35);  color:#86efac; }
        .msg-err { background:rgba(239,68,68,.08);   border:1px solid rgba(239,68,68,.35);  color:#fca5a5; }
        .info-box {
            background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.2);
            border-radius:10px; padding:10px 14px; font-size:12px; color:#93c5fd; line-height:1.6;
        }
        .info-box a { color:var(--primary); }

        .spacer { height:14px; }
        .spacer-sm { height:8px; }

        /* ===== CERT CANVAS ===== */
        .cert-canvas-wrap {
            position:relative; border-radius:12px; border:1px solid var(--border);
            overflow:hidden; background:#0a1628; width:100%; padding-top:70.7%;
        }
        .cert-canvas-wrap img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .cert-overlay { position:absolute; inset:0; }
        .var-item {
            position:absolute;
            transform:translate(-50%, -50%);
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
        .var-item.selected {
            border-style:solid;
            border-color:rgba(250,204,21,1);
            box-shadow:0 0 0 2px rgba(250,204,21,.35);
        }
        .var-item.qr-item {
            border-style:solid;
            border-color:rgba(59,130,246,.75);
            color:#bfdbfe;
            background:rgba(0,0,0,.7);
            display:flex; align-items:center; justify-content:center; flex-direction:column;
        }
        .var-item.qr-item.selected {
            border-color:rgba(59,130,246,1);
            box-shadow:0 0 0 2px rgba(59,130,246,.35);
        }
        .var-remove {
            position:absolute; top:-9px; right:-9px;
            width:18px; height:18px; border-radius:999px;
            background:var(--red); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:12px; cursor:pointer; line-height:1; z-index:10;
        }

        .layout-tools {
            margin-top:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
        }
        .layout-tools select, .layout-tools input[type="number"] { width:auto; min-width:130px; }
        .font-label { font-size:12px; color:var(--muted); white-space:nowrap; display:block; margin-bottom:4px; }

        .field-group { display:flex; flex-direction:column; }

        .edit-panel {
            margin-top:10px; background:rgba(250,204,21,.05);
            border:1px solid rgba(250,204,21,.2); border-radius:10px;
            padding:12px 14px;
        }
        .edit-panel-title { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px; }
        .edit-panel-fields { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
        .edit-panel-fields label.lbl { margin-bottom:4px; }
        .edit-panel-fields input[type="number"], .edit-panel-fields select { width:120px; }

        .side-label {
            font-size:12px; font-weight:600; text-transform:uppercase;
            letter-spacing:.08em; color:var(--muted); margin-bottom:8px;
        }
        .no-image-hint {
            padding:24px 16px; border:1px dashed var(--border); border-radius:10px;
            text-align:center; color:var(--muted); font-size:13px; margin-top:8px;
        }
        .verify-hint {
            font-size:11.5px; color:var(--muted);
            background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.2);
            border-radius:8px; padding:8px 12px; margin-top:10px; line-height:1.6;
        }
        .verify-hint code { color:#93c5fd; background:rgba(59,130,246,.1); border-radius:4px; padding:1px 5px; font-size:11px; }

        /* ===== SENHA ===== */
        .radio-group { display:flex; gap:12px; flex-wrap:wrap; }
        .radio-pill { display:flex; align-items:center; gap:6px; cursor:pointer; }
        .radio-pill input[type="radio"] { accent-color:var(--primary); width:15px; height:15px; }
        .radio-pill span { font-size:13px; }

        .partes-list { display:flex; flex-direction:column; gap:8px; margin:10px 0; }
        .parte-row { display:flex; gap:8px; align-items:center; }
        .parte-row input { flex:1; }
        .parte-row .parte-num { font-size:11px; color:var(--muted); min-width:48px; }
        .btn-rm { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#fca5a5; border-radius:8px; padding:6px 10px; cursor:pointer; font-size:12px; }
        .btn-rm:hover { background:rgba(239,68,68,.2); }
        .btn-add-parte { background:rgba(59,130,246,.12); border:1px solid rgba(59,130,246,.3); color:#93c5fd; border-radius:8px; padding:7px 14px; cursor:pointer; font-size:12px; font-weight:600; }
        .btn-add-parte:hover { background:rgba(59,130,246,.2); }
    </style>
</head>
<body>
<div class="page-wrap">

    <div class="page-header">
        <h1>Configuração de Certificado</h1>
        <p>Layout visual, senha de liberação, mensagens, vídeos e botão do certificado.</p>
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
                    <p>Envie as imagens e posicione as variáveis arrastando. Clique em um elemento para editar tamanho e fonte.</p>
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

                        <div id="edit-panel-front" class="edit-panel" style="display:none;">
                            <div class="edit-panel-title">Editar: <strong id="edit-label-front"></strong></div>
                            <div class="edit-panel-fields">
                                <div class="field-group">
                                    <label class="lbl" id="edit-size-lbl-front">Tamanho (px)</label>
                                    <input type="number" id="edit-size-front" min="8" max="400" step="1" style="width:100px;" oninput="applyEditSize('front')">
                                </div>
                                <div class="field-group" id="edit-family-wrap-front">
                                    <label class="lbl">Família da fonte</label>
                                    <select id="edit-family-front" style="width:160px;" onchange="applyEditFamily('front')">
                                        <option value="DejaVu Sans">DejaVu Sans (padrão)</option>
                                        <option value="DejaVu Serif">DejaVu Serif</option>
                                        <option value="DejaVu Sans Mono">DejaVu Sans Mono</option>
                                    </select>
                                </div>
                                <button type="button" class="btn ghost sm" onclick="clearSelection('front')">✓ Fechar</button>
                            </div>
                        </div>

                        <div class="layout-tools">
                            <div class="field-group">
                                <span class="font-label">Campo</span>
                                <select id="add-front-field" onchange="updateFontLabel('front')">
                                    <option value="nome">Nome do aluno</option>
                                    <option value="data_emissao">Data de emissão</option>
                                    <option value="curso">Nome do curso</option>
                                    <option value="qr">QR Code de autenticação</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <span class="font-label" id="lbl-front-font">Tamanho (px)</span>
                                <input type="number" id="add-front-font" min="8" max="400" value="26" step="1" style="width:90px;">
                            </div>
                            <div class="field-group" id="family-wrap-front">
                                <span class="font-label">Fonte</span>
                                <select id="add-front-family" style="width:150px;">
                                    <option value="DejaVu Sans">DejaVu Sans</option>
                                    <option value="DejaVu Serif">DejaVu Serif</option>
                                    <option value="DejaVu Sans Mono">Mono</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <span class="font-label">&nbsp;</span>
                                <button type="button" class="btn sm" onclick="addVar('front')">+ Adicionar</button>
                            </div>
                        </div>
                        <div class="verify-hint" id="qr-hint-front" style="display:none;">
                            QR aponta para: <code><?= h($verifyBaseUrl) ?>?c=CODIGO</code>
                        </div>
                    <?php else: ?>
                        <div class="no-image-hint">Salve a imagem da frente para habilitar o editor.</div>
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

                        <div id="edit-panel-back" class="edit-panel" style="display:none;">
                            <div class="edit-panel-title">Editar: <strong id="edit-label-back"></strong></div>
                            <div class="edit-panel-fields">
                                <div class="field-group">
                                    <label class="lbl" id="edit-size-lbl-back">Tamanho (px)</label>
                                    <input type="number" id="edit-size-back" min="8" max="400" step="1" style="width:100px;" oninput="applyEditSize('back')">
                                </div>
                                <div class="field-group" id="edit-family-wrap-back">
                                    <label class="lbl">Família da fonte</label>
                                    <select id="edit-family-back" style="width:160px;" onchange="applyEditFamily('back')">
                                        <option value="DejaVu Sans">DejaVu Sans (padrão)</option>
                                        <option value="DejaVu Serif">DejaVu Serif</option>
                                        <option value="DejaVu Sans Mono">DejaVu Sans Mono</option>
                                    </select>
                                </div>
                                <button type="button" class="btn ghost sm" onclick="clearSelection('back')">✓ Fechar</button>
                            </div>
                        </div>

                        <div class="layout-tools">
                            <div class="field-group">
                                <span class="font-label">Campo</span>
                                <select id="add-back-field" onchange="updateFontLabel('back')">
                                    <option value="nome">Nome do aluno</option>
                                    <option value="data_emissao">Data de emissão</option>
                                    <option value="curso">Nome do curso</option>
                                    <option value="qr">QR Code de autenticação</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <span class="font-label" id="lbl-back-font">Tamanho (px)</span>
                                <input type="number" id="add-back-font" min="8" max="400" value="18" step="1" style="width:90px;">
                            </div>
                            <div class="field-group" id="family-wrap-back">
                                <span class="font-label">Fonte</span>
                                <select id="add-back-family" style="width:150px;">
                                    <option value="DejaVu Sans">DejaVu Sans</option>
                                    <option value="DejaVu Serif">DejaVu Serif</option>
                                    <option value="DejaVu Sans Mono">Mono</option>
                                </select>
                            </div>
                            <div class="field-group">
                                <span class="font-label">&nbsp;</span>
                                <button type="button" class="btn sm" onclick="addVar('back')">+ Adicionar</button>
                            </div>
                        </div>
                        <div class="verify-hint" id="qr-hint-back" style="display:none;">
                            QR aponta para: <code><?= h($verifyBaseUrl) ?>?c=CODIGO</code>
                        </div>
                    <?php else: ?>
                        <div class="no-image-hint">Salve a imagem do verso para habilitar o editor.</div>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" id="layout_json" name="layout_json" value="<?= h($config['layout_json'] ?? '') ?>">
        </div>

        <!-- ===== SENHA ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon orange">🔐</div>
                <div class="card-header-text">
                    <h2>Senha do certificado</h2>
                    <p>Defina o modelo de senha para liberar a emissão do certificado.</p>
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <label class="lbl">Tipo de senha</label>
                    <div class="radio-group">
                        <label class="radio-pill">
                            <input type="radio" name="senha_tipo" value="unica" id="tipo-unica"
                                <?= ($config['senha_tipo'] ?? 'unica') === 'unica' ? 'checked' : '' ?>
                                onchange="updateSenhaUI()">
                            <span>Senha única</span>
                        </label>
                        <label class="radio-pill">
                            <input type="radio" name="senha_tipo" value="modular" id="tipo-modular"
                                <?= ($config['senha_tipo'] ?? '') === 'modular' ? 'checked' : '' ?>
                                onchange="updateSenhaUI()">
                            <span>Modular (2+ partes combinadas)</span>
                        </label>
                    </div>
                    <div class="note">Modular: o aluno digita todas as partes separadas para desbloquear o certificado.</div>
                </div>
                <div>
                    <label class="lbl">Modo</label>
                    <div class="radio-group">
                        <label class="radio-pill">
                            <input type="radio" name="senha_mode" value="fixa" id="mode-fixa"
                                <?= ($config['senha_mode'] ?? 'fixa') === 'fixa' ? 'checked' : '' ?>
                                onchange="updateSenhaUI()">
                            <span>Fixa (igual para todos)</span>
                        </label>
                        <label class="radio-pill">
                            <input type="radio" name="senha_mode" value="variavel" id="mode-variavel"
                                <?= ($config['senha_mode'] ?? '') === 'variavel' ? 'checked' : '' ?>
                                onchange="updateSenhaUI()">
                            <span>Variável (por turma)</span>
                        </label>
                    </div>
                    <div class="note">Variável: a senha (ou última parte) é configurada em cada turma.</div>
                </div>
            </div>

            <div class="spacer"></div>

            <!-- unica + fixa -->
            <div id="senha-unica-fixa" style="display:none;">
                <label class="lbl">Senha de liberação</label>
                <input type="text" name="senha_fixa" id="senha_fixa"
                    value="<?= h($config['senha_fixa'] ?? '') ?>"
                    placeholder="Ex.: FERA2025" autocomplete="off" style="max-width:360px;">
            </div>

            <!-- unica + variavel -->
            <div id="senha-unica-variavel" style="display:none;">
                <div class="info-box">
                    ℹ️ Senha variável por turma: configure a senha em cada turma na tela de <strong>Turmas</strong>.
                    O aluno usará a senha definida na turma em que está inscrito.
                </div>
            </div>

            <!-- modular + fixa -->
            <div id="senha-modular-fixa" style="display:none;">
                <label class="lbl">Partes da senha (combinadas para desbloquear)</label>
                <div class="partes-list" id="partes-list-fixa"></div>
                <button type="button" class="btn-add-parte" onclick="addParte('fixa')">+ Adicionar parte</button>
                <div class="note">O aluno deve digitar cada parte corretamente para emitir o certificado.</div>
            </div>

            <!-- modular + variavel -->
            <div id="senha-modular-variavel" style="display:none;">
                <label class="lbl">Partes fixas (a última virá da turma)</label>
                <div class="partes-list" id="partes-list-variavel"></div>
                <button type="button" class="btn-add-parte" onclick="addParte('variavel')">+ Adicionar parte fixa</button>
                <div class="note">
                    A <strong>última parte</strong> é configurada por turma. As demais são fixas e valem para todos.
                </div>
                <div class="spacer-sm"></div>
                <div class="info-box">
                    ℹ️ Configure a última parte (variável) em cada turma na tela de <strong>Turmas</strong>.
                </div>
            </div>

            <input type="hidden" id="senha_partes_fixas" name="senha_partes_fixas" value="<?= h($config['senha_partes_fixas'] ?? '[]') ?>">
        </div>

        <!-- ===== MENSAGENS ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon green">💬</div>
                <div class="card-header-text">
                    <h2>Mensagens exibidas ao aluno</h2>
                    <p>Textos HTML exibidos após o aluno digitar a senha.</p>
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

        <!-- ===== BOTÃO ===== -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon purple">🔗</div>
                <div class="card-header-text">
                    <h2>Botão de certificado</h2>
                    <p>Botão exibido após a senha correta (link para automação ou download do PDF).</p>
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <label class="lbl">Texto do botão</label>
                    <input type="text" name="certificado_button_label" value="<?= h($config['certificado_button_label'] ?? 'Quero receber meu certificado') ?>">
                </div>
                <div>
                    <label class="lbl">Link do botão</label>
                    <input type="url" name="certificado_button_link" placeholder="https://..." value="<?= h($config['certificado_button_link'] ?? '') ?>">
                </div>
            </div>

            <div class="spacer"></div>
            <div class="info-box">
                🔗 Os webhooks de certificado (CERT_EMITIDO, CERT_SENHA_ERRADA) são configurados na
                <a href="webhooks.php">tela de Webhooks</a>.
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
    // ===== LAYOUT CANVAS =====
    const layoutInit = <?= json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const layout = {
        front: layoutInit.front || [],
        back:  layoutInit.back  || [],
    };

    const selected = { front: null, back: null };

    function syncHidden() {
        const el = document.getElementById('layout_json');
        if (el) el.value = JSON.stringify(layout);
    }

    const fontFamilyCss = {
        'DejaVu Sans':      '"DejaVu Sans", sans-serif',
        'DejaVu Serif':     '"DejaVu Serif", serif',
        'DejaVu Sans Mono': '"DejaVu Sans Mono", monospace',
    };

    const fieldLabels = {
        nome:         'Nome do aluno',
        data:         'Data de emissão',
        data_emissao: 'Data de emissão',
        curso:        'Nome do curso',
        qr:           'QR Code',
    };

    // Converts old pixel-based QR size (>100) to percentage; new values are already %.
    function qrToPercent(font) {
        const v = font ?? 15;
        return v > 100 ? Math.round(v / 1122 * 100) : v;
    }

    window.updateFontLabel = function(side) {
        const sel  = document.getElementById('add-' + side + '-field');
        const lbl  = document.getElementById('lbl-' + side + '-font');
        const hint = document.getElementById('qr-hint-' + side);
        const famW = document.getElementById('family-wrap-' + side);
        if (!sel || !lbl) return;
        const isQr = sel.value === 'qr';
        lbl.textContent = isQr ? 'Tamanho QR (% da largura)' : 'Tamanho (px)';
        if (hint) hint.style.display = isQr ? 'block' : 'none';
        if (famW) famW.style.display  = isQr ? 'none'  : 'flex';
        const fontInput = document.getElementById('add-' + side + '-font');
        if (fontInput && isQr) {
            fontInput.min = '5';
            fontInput.max = '60';
            const cur = parseInt(fontInput.value, 10);
            // if current value looks like old pixels, reset to sensible % default
            if (isNaN(cur) || cur > 60) fontInput.value = 15;
        } else if (fontInput) {
            fontInput.min = '8';
            fontInput.max = '400';
        }
    };

    function selectItem(side, index) {
        const canvas = document.querySelector('.cert-canvas-wrap[data-side="' + side + '"]');
        if (!canvas) return;
        // deselect all on this side
        canvas.querySelectorAll('.var-item').forEach(el => el.classList.remove('selected'));
        selected[side] = index;
        const item = (layout[side] || [])[index];
        if (!item) return;

        const el = canvas.querySelectorAll('.var-item')[index];
        if (el) el.classList.add('selected');

        const panel = document.getElementById('edit-panel-' + side);
        const lbl   = document.getElementById('edit-label-' + side);
        const szInp = document.getElementById('edit-size-' + side);
        const szLbl = document.getElementById('edit-size-lbl-' + side);
        const famW  = document.getElementById('edit-family-wrap-' + side);
        const famSel= document.getElementById('edit-family-' + side);

        if (panel) panel.style.display = 'block';
        if (lbl)   lbl.textContent = fieldLabels[item.field] || item.field;
        const isQr = item.field === 'qr';
        if (szInp) {
            szInp.value = isQr ? qrToPercent(item.font) : (item.font ?? 18);
            szInp.min   = isQr ? '5' : '8';
            szInp.max   = isQr ? '60' : '400';
        }
        if (szLbl) szLbl.textContent = isQr ? 'Tamanho QR (% da largura)' : 'Tamanho (px)';
        if (famW)  famW.style.display = isQr ? 'none' : 'block';
        if (famSel && item.fontFamily) {
            famSel.value = item.fontFamily;
        }
    }

    window.clearSelection = function(side) {
        selected[side] = null;
        const canvas = document.querySelector('.cert-canvas-wrap[data-side="' + side + '"]');
        if (canvas) canvas.querySelectorAll('.var-item').forEach(el => el.classList.remove('selected'));
        const panel = document.getElementById('edit-panel-' + side);
        if (panel) panel.style.display = 'none';
    };

    window.applyEditSize = function(side) {
        const idx = selected[side];
        if (idx === null || idx === undefined) return;
        const item = (layout[side] || [])[idx];
        if (!item) return;
        const val = parseInt(document.getElementById('edit-size-' + side)?.value, 10);
        if (!isNaN(val)) {
            item.font = val;
            syncHidden();
            renderSide(side);
            // re-select after re-render
            setTimeout(() => selectItem(side, idx), 0);
        }
    };

    window.applyEditFamily = function(side) {
        const idx = selected[side];
        if (idx === null || idx === undefined) return;
        const item = (layout[side] || [])[idx];
        if (!item) return;
        const val = document.getElementById('edit-family-' + side)?.value;
        if (val) {
            item.fontFamily = val;
            syncHidden();
            renderSide(side);
            setTimeout(() => selectItem(side, idx), 0);
        }
    };

    function makeDraggable(el, side, index) {
        const canvas = el.closest('.cert-canvas-wrap');
        if (!canvas) return;
        const overlay = canvas.querySelector('.cert-overlay');
        if (!overlay) return;

        el.addEventListener('click', function(ev) {
            ev.stopPropagation();
            selectItem(side, index);
        });

        el.addEventListener('mousedown', startDrag);
        el.addEventListener('touchstart', startDrag, {passive: false});

        function startDrag(ev) {
            // don't drag on remove button
            if (ev.target.classList.contains('var-remove')) return;
            ev.preventDefault();
            const rect = overlay.getBoundingClientRect();
            const sx = ev.touches ? ev.touches[0].clientX : ev.clientX;
            const sy = ev.touches ? ev.touches[0].clientY : ev.clientY;
            const startLeft = parseFloat(el.style.left) || 50;
            const startTop  = parseFloat(el.style.top)  || 50;
            let moved = false;

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
                moved = true;
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
                const pct = Math.max(5, Math.min(60, qrToPercent(item.font)));
                const sz  = Math.round(canvas.offsetWidth * pct / 100);
                el.style.width  = sz + 'px';
                el.style.height = sz + 'px';
                el.style.padding = '4px';
                el.innerHTML = '<div style="font-size:11px;font-weight:700;">QR</div><div style="font-size:9px;opacity:.75;">' + pct + '% larg.</div>';
            } else {
                el.style.fontSize   = (item.font ?? 18) + 'px';
                el.style.fontFamily = fontFamilyCss[item.fontFamily] || '"DejaVu Sans", sans-serif';
                el.textContent = fieldLabels[item.field] || item.field || 'VAR';
            }

            if (selected[side] === index) el.classList.add('selected');

            const rm = document.createElement('div');
            rm.className = 'var-remove';
            rm.textContent = '×';
            rm.addEventListener('click', function(ev) {
                ev.stopPropagation();
                layout[side].splice(index, 1);
                if (selected[side] === index) clearSelection(side);
                else if (selected[side] > index) selected[side]--;
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
        const famSel    = document.getElementById('add-' + side + '-family');
        if (!fieldSel || !fontInput) return;

        const field      = fieldSel.value || 'nome';
        const font       = parseInt(fontInput.value, 10) || (field === 'qr' ? 15 : 20);
        const fontFamily = (field === 'qr') ? 'DejaVu Sans' : (famSel ? famSel.value : 'DejaVu Sans');

        if (!layout[side]) layout[side] = [];
        layout[side].push({
            id: 'v_' + Date.now() + '_' + Math.random().toString(16).slice(2),
            field,
            x: 50,
            y: 50,
            font,
            fontFamily,
        });
        renderSide(side);
        syncHidden();
    };

    // close selection when clicking on canvas background
    document.querySelectorAll('.cert-canvas-wrap').forEach(function(canvas) {
        canvas.querySelector('.cert-overlay')?.addEventListener('click', function() {
            const side = canvas.dataset.side;
            if (side) clearSelection(side);
        });
    });

    renderSide('front');
    renderSide('back');
    syncHidden();

    document.getElementById('form-cert').addEventListener('submit', function() {
        syncHidden();
        syncPartes();
    });

    // ===== SENHA UI =====
    window.updateSenhaUI = function() {
        const tipo = document.querySelector('input[name="senha_tipo"]:checked')?.value || 'unica';
        const mode = document.querySelector('input[name="senha_mode"]:checked')?.value || 'fixa';

        const zones = {
            'senha-unica-fixa':      tipo === 'unica'    && mode === 'fixa',
            'senha-unica-variavel':  tipo === 'unica'    && mode === 'variavel',
            'senha-modular-fixa':    tipo === 'modular'  && mode === 'fixa',
            'senha-modular-variavel':tipo === 'modular'  && mode === 'variavel',
        };
        Object.entries(zones).forEach(([id, show]) => {
            const el = document.getElementById(id);
            if (el) el.style.display = show ? 'block' : 'none';
        });
    };

    // ===== PARTES FIXAS =====
    function syncPartes() {
        const partes = [];
        document.querySelectorAll('.parte-input').forEach(inp => {
            const v = inp.value.trim();
            if (v) partes.push(v);
        });
        document.getElementById('senha_partes_fixas').value = JSON.stringify(partes);
    }

    window.syncPartes = syncPartes;

    window.addParte = function(context, val = '') {
        const listId = context === 'fixa' ? 'partes-list-fixa' : 'partes-list-variavel';
        const list = document.getElementById(listId);
        if (!list) return;

        const row = document.createElement('div');
        row.className = 'parte-row';
        const num = list.children.length + 1;
        row.innerHTML = `<span class="parte-num">Parte ${num}</span>
            <input type="text" class="parte-input" value="${val.replace(/"/g,'&quot;')}" placeholder="Senha ou parte ${num}" style="width:auto;flex:1;">
            <button type="button" class="btn-rm" onclick="this.parentElement.remove();updateParteNums(this.closest('.partes-list'));syncPartes()">×</button>`;
        row.querySelector('.parte-input').addEventListener('input', syncPartes);
        list.appendChild(row);
        syncPartes();
    };

    window.updateParteNums = function(list) {
        if (!list) return;
        list.querySelectorAll('.parte-num').forEach((span, i) => {
            span.textContent = 'Parte ' + (i + 1);
        });
        list.querySelectorAll('.parte-input').forEach((inp, i) => {
            inp.placeholder = 'Senha ou parte ' + (i + 1);
        });
    };

    // init partes
    const initPartes = <?= json_encode($initPartes, JSON_UNESCAPED_UNICODE) ?>;
    const senhaMode  = <?= json_encode($config['senha_mode'] ?? 'fixa') ?>;
    initPartes.forEach(p => addParte(senhaMode === 'variavel' ? 'variavel' : 'fixa', p));

    updateSenhaUI();
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>
