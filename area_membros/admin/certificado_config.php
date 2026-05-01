<?php
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/funcoes.php';

proteger_admin();

// abre layout padrão do painel (menu lateral + topo)
include __DIR__ . '/_header.php';

/**
 * Helper simples para escapar HTML
 */
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

// Valores padrão
$config = array_merge([
    'front_image'                    => null,
    'back_image'                     => null,
    'layout_json'                    => '',
    'error_html'                     => '',
    'success_html'                   => '',
    // vídeo introdutório (antes da senha)
    'intro_video_enabled'            => 0,
    'intro_video_url'                => '',
    // vídeos após tentativa de senha
    'senha_video_enabled'            => 0,
    'senha_video_url'                => '',
    'senha_error_video_enabled'      => 0,
    'senha_error_video_url'          => '',
    'webhook_error_url'              => '',
    'webhook_emitido_url'           => '',
    'certificado_button_label'       => 'Quero receber meu certificado',
    'certificado_button_link'        => '',
], $config ?: []);

$mensagemOk   = '';
$mensagemErro = '';

// ===== Diretório/URL para imagens do certificado =====
$uploadDir = dirname(__DIR__) . '/uploads/certificados';

// BASE_URL costuma ser .../area_membros/public
$basePublic = rtrim(BASE_URL, '/');
// Sobe um nível, tirando o /public → .../area_membros
$baseRoot   = preg_replace('#/public$#', '', $basePublic);

// URL pública correta para as imagens de certificado
$uploadUrlBase = $baseRoot . '/uploads/certificados';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- MENSAGENS TEXTUAIS ---
        $errorHtml   = $_POST['error_html']   ?? '';
        $successHtml = $_POST['success_html'] ?? '';

        // --- VÍDEOS / FLAGS ---
        $introVideoEnabled       = isset($_POST['intro_video_enabled']) ? 1 : 0;
        $introVideoUrl           = trim($_POST['intro_video_url'] ?? '');
        $senhaVideoEnabled       = isset($_POST['senha_video_enabled']) ? 1 : 0;
        $senhaVideoUrl           = trim($_POST['senha_video_url'] ?? '');
        $senhaErrorVideoEnabled  = isset($_POST['senha_error_video_enabled']) ? 1 : 0;
        $senhaErrorVideoUrl      = trim($_POST['senha_error_video_url'] ?? '');

        // --- WEBHOOKS ---
        $webhookErrorUrl   = trim($_POST['webhook_error_url'] ?? '');
        $webhookEmitidoUrl = trim($_POST['webhook_emitido_url'] ?? '');

        // --- BOTÃO DE CERTIFICADO ---
        $btnLabel = trim($_POST['certificado_button_label'] ?? '');
        $btnLink  = trim($_POST['certificado_button_link'] ?? '');

        // --- LAYOUT (JSON com variáveis) ---
        $layoutJson = trim($_POST['layout_json'] ?? '');

        // --- IMAGENS (FRENTE / VERSO) ---
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

        // Atualiza tudo
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

        $mensagemOk = 'Configurações de certificado salvas com sucesso!';

        // Recarrega config
        $stmt   = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: $config;

    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
    }
}

// ---- Layout carregado para o JS ----
$layout = [
    'front' => [],
    'back'  => [],
];
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
        body {
            margin:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:#020617;
            color:#e5e7eb;
        }
        .page {
            max-width:1100px;
            margin:40px auto;
            padding:24px;
        }
        .title {
            font-size:28px;
            font-weight:700;
            margin-bottom:4px;
        }
        .subtitle {
            font-size:14px;
            color:#9ca3af;
            margin-bottom:24px;
        }
        .card {
            background:#020617;
            border-radius:18px;
            border:1px solid #1f2937;
            box-shadow:0 18px 40px rgba(15,23,42,0.75);
            padding:20px 22px;
            margin-bottom:24px;
        }
        .card h2 {
            font-size:18px;
            margin:0 0 4px;
        }
        .card .card-subtitle {
            font-size:13px;
            color:#9ca3af;
            margin-bottom:14px;
        }
        .grid-2 {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;
        }
        @media (max-width: 900px) {
            .grid-2 { grid-template-columns:1fr; }
        }
        label {
            font-size:13px;
            font-weight:500;
            display:block;
            margin-bottom:6px;
        }
        input[type="text"],
        input[type="url"],
        textarea,
        select {
            width:100%;
            padding:8px 10px;
            border-radius:10px;
            border:1px solid #1f2937;
            background:#020617;
            color:#e5e7eb;
            font-size:13px;
            box-sizing:border-box;
        }
        textarea {
            min-height:90px;
            resize:vertical;
        }
        .row {
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            align-items:center;
            margin-bottom:10px;
        }
        .row > * {
            flex:1;
        }
        .row .shrink { flex:0 0 auto; }
        .checkbox-label {
            display:flex;
            align-items:center;
            gap:8px;
            font-size:13px;
        }
        .checkbox-label input[type="checkbox"] {
            width:16px;
            height:16px;
        }
        .msg-ok, .msg-erro {
            border-radius:999px;
            padding:10px 16px;
            font-size:13px;
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:16px;
        }
        .msg-ok {
            background:rgba(34,197,94,0.1);
            border:1px solid rgba(34,197,94,0.5);
            color:#bbf7d0;
        }
        .msg-erro {
            background:rgba(248,113,113,0.1);
            border:1px solid rgba(248,113,113,0.5);
            color:#fecaca;
        }
        .btn {
            border:none;
            background:#facc15;
            color:#111827;
            font-weight:600;
            padding:10px 18px;
            border-radius:999px;
            cursor:pointer;
            font-size:14px;
            margin-top:8px;
        }
        .btn:hover { filter:brightness(1.04); }

        .small-note {
            font-size:12px;
            color:#9ca3af;
        }

        /* ====== LAYOUT DO CERTIFICADO ====== */
        .cert-layout {
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .cert-side-title {
            font-size:14px;
            font-weight:600;
            margin-top:10px;
            margin-bottom:6px;
        }
        .cert-canvas {
            position:relative;
            border-radius:14px;
            border:1px solid #1f2937;
            overflow:hidden;
            background:#020617;
            max-width:100%;
            /* mantém proporção próxima de A4 paisagem */
            width:100%;
            padding-top:70%; /* ~0.707 = 1/√2, proporção do A4 */
        }
        .cert-canvas img {
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover;
        }
        .cert-overlay {
            position:absolute;
            inset:0;
        }
        .var-item {
            position:absolute;
            padding:4px 8px 4px 8px;
            background:rgba(0,0,0,0.65);
            border:1px dashed rgba(250,204,21,0.75);
            color:#fef3c7;
            font-size:16px;
            font-weight:600;
            border-radius:6px;
            cursor:move;
            white-space:nowrap;
        }
        .var-item .var-remove {
            position:absolute;
            top:-8px;
            right:-8px;
            width:16px;
            height:16px;
            border-radius:999px;
            background:#ef4444;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:11px;
            cursor:pointer;
        }
        .layout-tools {
            margin-top:8px;
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            align-items:center;
            font-size:13px;
        }
        .layout-tools select,
        .layout-tools input {
            width:auto;
            min-width:120px;
        }
        .layout-hint {
            font-size:12px;
            color:#9ca3af;
            margin-top:4px;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="title">Configuração de Certificado</div>
    <div class="subtitle">
        Configure layout visual (frente e verso), mensagens, vídeos e webhooks usados na emissão do certificado.
    </div>

    <?php if ($mensagemOk): ?>
        <div class="msg-ok">✅ <?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="msg-erro">⚠️ <?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <form method="post" action="" enctype="multipart/form-data" id="form-certificado">

        <!-- ================= LAYOUT VISUAL (FRENTE / VERSO) ================= -->
        <div class="card">
            <h2>Layout visual do certificado (frente e verso)</h2>
            <div class="card-subtitle">
                Envie as imagens do certificado e adicione variáveis <strong>Nome do aluno</strong> e <strong>Data de emissão</strong>.
                Arraste as caixinhas para posicionar exatamente onde deverão sair no PDF.
            </div>

            <div class="cert-layout grid-2">
                <!-- FRENTE -->
                <div>
                    <label for="front_image">Imagem da frente (PNG/JPG)</label>
                    <input type="file" name="front_image" id="front_image" accept="image/png, image/jpeg, image/jpg">
                    <div class="layout-hint">
                        Após enviar a imagem e salvar, o editor de variáveis abaixo será atualizado.
                    </div>

                    <?php if ($frontImageUrl): ?>
                        <div class="cert-side-title">Frente</div>
                        <div class="cert-canvas" data-side="front">
                            <img src="<?= h($frontImageUrl) ?>" alt="Frente do certificado">
                            <div class="cert-overlay"></div>
                        </div>

                        <div class="layout-tools">
                            <span>Adicionar variável na frente:</span>
                            <select id="add-front-field">
                                <option value="nome">Nome do aluno</option>
                                <option value="data">Data de emissão</option>
                            </select>
                            <input type="number" id="add-front-font" min="8" max="80" value="26" step="1" />
                            <span class="small-note">tamanho da fonte (px)</span>
                            <button type="button" class="btn" style="padding:6px 14px;font-size:12px;" onclick="addVar('front')">
                                + Adicionar variável
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="layout-hint" style="margin-top:12px;">
                            👉 Ainda não há imagem da frente configurada. Envie a imagem acima e clique em
                            <strong>Salvar configurações de certificado</strong> para liberar o editor.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- VERSO -->
                <div>
                    <label for="back_image">Imagem do verso (PNG/JPG)</label>
                    <input type="file" name="back_image" id="back_image" accept="image/png, image/jpeg, image/jpg">
                    <div class="layout-hint">
                        Se o certificado for frente e verso, envie a imagem do verso e posicione as variáveis abaixo.
                    </div>

                    <?php if ($backImageUrl): ?>
                        <div class="cert-side-title">Verso</div>
                        <div class="cert-canvas" data-side="back">
                            <img src="<?= h($backImageUrl) ?>" alt="Verso do certificado">
                            <div class="cert-overlay"></div>
                        </div>

                        <div class="layout-tools">
                            <span>Adicionar variável no verso:</span>
                            <select id="add-back-field">
                                <option value="nome">Nome do aluno</option>
                                <option value="data">Data de emissão</option>
                            </select>
                            <input type="number" id="add-back-font" min="8" max="80" value="18" step="1" />
                            <span class="small-note">tamanho da fonte (px)</span>
                            <button type="button" class="btn" style="padding:6px 14px;font-size:12px;" onclick="addVar('back')">
                                + Adicionar variável
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="layout-hint" style="margin-top:12px;">
                            (Opcional) Envie a imagem de verso e salve para habilitar o editor de variáveis do verso.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" id="layout_json" name="layout_json"
                   value="<?= h($config['layout_json'] ?? '') ?>">
        </div>

        <!-- ================= MENSAGENS / VÍDEOS ================= -->
        <div class="card">
            <h2>Mensagens e vídeos na emissão do certificado</h2>
            <div class="card-subtitle">
                Textos exibidos na tela de senha, mensagens de erro/acerto e vídeos de orientação.
            </div>

            <div class="grid-2">
                <div>
                    <label for="success_html">Mensagem de sucesso (senha correta)</label>
                    <textarea name="success_html" id="success_html" placeholder="HTML exibido quando a senha está correta.">
<?= h($config['success_html'] ?? '') ?></textarea>
                </div>
                <div>
                    <label for="error_html">Mensagem de erro (senha incorreta)</label>
                    <textarea name="error_html" id="error_html" placeholder="HTML exibido quando a senha está errada.">
<?= h($config['error_html'] ?? '') ?></textarea>
                </div>
            </div>

            
            <div class="grid-2" style="margin-top:16px;">
                <div>
                    <div class="checkbox-label">
                        <input type="checkbox" id="intro_video_enabled" name="intro_video_enabled"
                            <?= !empty($config['intro_video_enabled']) ? 'checked' : '' ?>>
                        <label for="intro_video_enabled" style="margin:0;">Exibir vídeo orientativo inicial</label>
                    </div>
                    <label for="intro_video_url" style="margin-top:6px;">URL / embed do vídeo inicial</label>
                    <input type="url" name="intro_video_url" id="intro_video_url"
                           placeholder="https://www.youtube.com/embed/... ou código &lt;iframe&gt; completo"
                           value="<?= h($config['intro_video_url'] ?? '') ?>">
                    <div class="small-note">
                        Este vídeo aparece quando o aluno abre a tela de certificado, antes de tentar digitar a senha.
                    </div>
                </div>
            </div>

<div class="grid-2" style="margin-top:16px;">
                <div>
                    <div class="checkbox-label">
                        <input type="checkbox" id="senha_video_enabled" name="senha_video_enabled"
                            <?= !empty($config['senha_video_enabled']) ? 'checked' : '' ?>>
                        <label for="senha_video_enabled" style="margin:0;">Exibir vídeo orientativo (senha correta)</label>
                    </div>
                    <label for="senha_video_url" style="margin-top:6px;">URL / embed do vídeo (senha correta)</label>
                    <input type="url" name="senha_video_url" id="senha_video_url"
                           placeholder="https://www.youtube.com/embed/..."
                           value="<?= h($config['senha_video_url'] ?? '') ?>">
                    <div class="small-note">Se marcado, será exibido abaixo da mensagem de sucesso.</div>
                </div>

                <div>
                    <div class="checkbox-label">
                        <input type="checkbox" id="senha_error_video_enabled" name="senha_error_video_enabled"
                            <?= !empty($config['senha_error_video_enabled']) ? 'checked' : '' ?>>
                        <label for="senha_error_video_enabled" style="margin:0;">Exibir vídeo orientativo (senha errada)</label>
                    </div>
                    <label for="senha_error_video_url" style="margin-top:6px;">URL / embed do vídeo (senha errada)</label>
                    <input type="url" name="senha_error_video_url" id="senha_error_video_url"
                           placeholder="https://www.youtube.com/embed/..."
                           value="<?= h($config['senha_error_video_url'] ?? '') ?>">
                    <div class="small-note">Se marcado, aparece quando o aluno erra a senha.</div>
                </div>
            </div>
        </div>

        <!-- ================= WEBHOOKS / BOTÃO ================= -->
        <div class="card">
            <h2>Webhooks e botão de envio do certificado</h2>
            <div class="card-subtitle">
                URLs que serão chamadas quando o aluno errar/acertar a senha e texto do botão para receber o certificado.
            </div>

            <div class="grid-2">
                <div>
                    <label for="webhook_error_url">Webhook (senha errada)</label>
                    <input type="url" name="webhook_error_url" id="webhook_error_url"
                           placeholder="https://seusistema.com/webhook-erro"
                           value="<?= h($config['webhook_error_url'] ?? '') ?>">
                    <div class="small-note">
                        Chamado quando o aluno erra a senha. Você pode registrar tentativa, disparar alerta etc.
                    </div>
                </div>

                <div>
                    <label for="webhook_emitido_url">Webhook (certificado emitido)</label>
                    <input type="url" name="webhook_emitido_url" id="webhook_emitido_url"
                           placeholder="https://seusistema.com/webhook-certificado"
                           value="<?= h($config['webhook_emitido_url'] ?? '') ?>">
                    <div class="small-note">
                        Chamado quando o certificado é emitido (senha correta e fluxo finalizado).
                    </div>
                </div>
            </div>

            <div style="margin-top:18px;">
                <label for="certificado_button_label">Texto do botão para receber certificado</label>
                <input type="text" name="certificado_button_label" id="certificado_button_label"
                       value="<?= h($config['certificado_button_label'] ?? 'Quero receber meu certificado') ?>">

                <label for="certificado_button_link" style="margin-top:10px;">Link do botão (URL da automação / envio do PDF)</label>
                <input type="url" name="certificado_button_link" id="certificado_button_link"
                       placeholder="https://seusistema.com/link-de-certificado"
                       value="<?= h($config['certificado_button_link'] ?? '') ?>">

                <div class="small-note" style="margin-top:6px;">
                    Esse botão aparece após o aluno acertar a senha. Você pode apontar para uma automação,
                    página de obrigado, etc.
                </div>

                <div style="margin-top:12px;">
                    <span class="small-note">Pré-visualização do botão:</span><br>
                    <button type="button" class="btn" style="border-radius:999px;padding:8px 14px;">
                        ✅ <?= h($config['certificado_button_label'] ?? 'Quero receber meu certificado') ?>
                    </button>
                    <div class="small-note" style="margin-top:4px;">
                        Link configurado: <?= h($config['certificado_button_link'] ?? '#') ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;align-items:center;margin-top:16px;">
            <button type="submit" class="btn">Salvar configurações de certificado</button>

            <a href="certificado_preview.php"
               target="_blank"
               class="btn"
               style="background:#22c55e;">
                Gerar certificado de teste
            </a>

            <span class="small-note">
                O teste usa um aluno fictício só para pré-visualização. O layout é o mesmo dos alunos reais.
            </span>
        </div>
    </form>
</div>

<script>
(function() {
    const layoutInitial = <?= json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const layout = {
        front: layoutInitial.front || [],
        back: layoutInitial.back || []
    };

    function syncHidden() {
        const hidden = document.getElementById('layout_json');
        if (hidden) {
            hidden.value = JSON.stringify(layout);
        }
    }

    const fieldLabels = {
        nome: 'Nome do aluno',
        data: 'Data de emissão'
    };

    function makeDraggable(el, side) {
        const canvas = el.closest('.cert-canvas');
        if (!canvas) return;

        const overlay = canvas.querySelector('.cert-overlay');
        if (!overlay) return;

        function startDrag(ev) {
            ev.preventDefault();

            const rect = overlay.getBoundingClientRect();
            const startX = (ev.touches ? ev.touches[0].clientX : ev.clientX);
            const startY = (ev.touches ? ev.touches[0].clientY : ev.clientY);

            const startLeft = parseFloat(el.style.left) || 50;
            const startTop  = parseFloat(el.style.top)  || 50;

            function move(ev2) {
                ev2.preventDefault();
                const cx = (ev2.touches ? ev2.touches[0].clientX : ev2.clientX);
                const cy = (ev2.touches ? ev2.touches[0].clientY : ev2.clientY);

                let dx = cx - startX;
                let dy = cy - startY;

                let newLeft = startLeft + (dx * 100 / rect.width);
                let newTop  = startTop  + (dy * 100 / rect.height);

                newLeft = Math.max(0, Math.min(100, newLeft));
                newTop  = Math.max(0, Math.min(100, newTop));

                el.style.left = newLeft + '%';
                el.style.top  = newTop  + '%';

                const index = parseInt(el.dataset.index, 10);
                if (!isNaN(index) && layout[side][index]) {
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
            document.addEventListener('touchmove', move, {passive:false});
            document.addEventListener('touchend', stop);
        }

        el.addEventListener('mousedown', startDrag);
        el.addEventListener('touchstart', startDrag, {passive:false});
    }

    function renderSide(side) {
        const canvas = document.querySelector('.cert-canvas[data-side="' + side + '"]');
        if (!canvas) return;

        const overlay = canvas.querySelector('.cert-overlay');
        if (!overlay) return;

        overlay.innerHTML = '';
        (layout[side] || []).forEach((item, index) => {
            const el = document.createElement('div');
            el.className = 'var-item';
            el.dataset.index = index;
            el.style.left = (item.x ?? 50) + '%';
            el.style.top  = (item.y ?? 50) + '%';
            el.style.fontSize = (item.font ?? 18) + 'px';
            el.textContent = fieldLabels[item.field] || item.field || 'VAR';

            const close = document.createElement('div');
            close.className = 'var-remove';
            close.textContent = '×';
            close.addEventListener('click', function(ev) {
                ev.stopPropagation();
                layout[side].splice(index, 1);
                renderSide(side);
                syncHidden();
            });

            el.appendChild(close);
            overlay.appendChild(el);
            makeDraggable(el, side);
        });
    }

    window.addVar = function(side) {
        const fieldSel = document.getElementById('add-' + side + '-field');
        const fontInp  = document.getElementById('add-' + side + '-font');
        if (!fieldSel || !fontInp) return;

        const field = fieldSel.value || 'nome';
        const font  = parseInt(fontInp.value, 10) || 18;

        if (!layout[side]) layout[side] = [];
        layout[side].push({
            id: 'v_' + Date.now() + '_' + Math.random().toString(16).slice(2),
            field: field,
            x: 50,
            y: 50,
            font: font
        });

        renderSide(side);
        syncHidden();
    };

    renderSide('front');
    renderSide('back');
    syncHidden();

    const form = document.getElementById('form-certificado');
    if (form) {
        form.addEventListener('submit', function() {
            syncHidden();
        });
    }
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>

</body>
</html>
