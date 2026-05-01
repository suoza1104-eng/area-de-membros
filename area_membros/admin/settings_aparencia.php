<?php
// FILE: admin/settings_aparencia.php
// Tela unificada: aparência + configurações gerais do app
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_admin();
$menu = 'aparencia';

$pdo = getPDO();

$mensagemOk  = '';
$mensagemErro = '';

// =========================
// 1) Carrega config base
// =========================
try {
    $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
    $config = $st->fetch();
    if (!$config) {
        $config = [
            'course_title'          => 'Trilha de Aulas',
            'primary_color'         => '#facc15',
            'secondary_color'       => '#22c55e',
            'background_color'      => '#020617',
            'logo_url'              => '',
            'certificado_cta_label' => 'Emitir Certificado',
            'paid_courses_title'    => 'Conheça nossos cursos pagos',
        ];
    }
} catch (Throwable $e) {
    $config = [
        'course_title'          => 'Trilha de Aulas',
        'primary_color'         => '#facc15',
        'secondary_color'       => '#22c55e',
        'background_color'      => '#020617',
        'logo_url'              => '',
        'certificado_cta_label' => 'Emitir Certificado',
        'paid_courses_title'    => 'Conheça nossos cursos pagos',
    ];
}

// =========================
// 2) Carrega tema (settings)
// =========================
$bg_main_default   = $config['background_color'] ?? '#0b1120';
$primary_default   = $config['primary_color']     ?? '#facc15';
$secondary_default = $config['secondary_color']   ?? '#38bdf8';

$bg_main = get_setting('theme_bg_main', $bg_main_default);
$bg_card = get_setting('theme_bg_card', '#020617');
$primary = get_setting('theme_primary', $primary_default);
$secondary = get_setting('theme_secondary', $secondary_default);
$text     = get_setting('theme_text', '#f9fafb');

$logo_setting = get_setting('theme_logo_url', '');
$logo_url     = $logo_setting !== '' ? $logo_setting : ($config['logo_url'] ?? '');

$whatsapp_help_url = get_setting('whatsapp_help_url', '');

// Reagendamento de live (calendário)
$reagendar_next_lives_count = (int)get_setting('reagendar_next_lives_count', '3');
$reagendar_webhook_url = get_setting('reagendar_webhook_url', '');
$reagendar_token_ttl_hours = (int)get_setting('reagendar_token_ttl_hours', '72');


// =========================
// 3) PROCESSA POST
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Cores do tema
    $bg_main_post = trim((string)($_POST['bg_main'] ?? $bg_main));
    $bg_card_post = trim((string)($_POST['bg_card'] ?? $bg_card));
    $primary_post = trim((string)($_POST['primary'] ?? $primary));
    $secondary_post = trim((string)($_POST['secondary'] ?? $secondary));
    $text_post = trim((string)($_POST['text'] ?? $text));

    // Configurações gerais
    $courseTitle      = trim((string)($_POST['course_title'] ?? ($config['course_title'] ?? '')));
    $certCtaLabel     = trim((string)($_POST['certificado_cta_label'] ?? ($config['certificado_cta_label'] ?? '')));
    $paidCoursesTitle = trim((string)($_POST['paid_courses_title'] ?? ($config['paid_courses_title'] ?? '')));
    $whatsHelpPost    = trim((string)($_POST['whatsapp_help_url'] ?? $whatsapp_help_url));

    // Logo (começa com valor atual)
    $logoUrlPost = $logo_url;

    // Upload de logo (opcional)
    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $dir = __DIR__ . '/../uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = 'logo_' . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $_FILES['logo']['name']);
        $dest = $dir . '/' . $name;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
            $urlBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
            $url = $urlBase . '/uploads/' . $name;
            set_setting('theme_logo_url', $url);
            $logoUrlPost = $url;
        }
    }

    if ($courseTitle === '') {
        $mensagemErro = 'Informe o título principal do curso.';
    } else {
        try {
            // Salva tema (settings)
            set_setting('theme_bg_main', $bg_main_post !== '' ? $bg_main_post : '#0b1120');
            set_setting('theme_bg_card', $bg_card_post !== '' ? $bg_card_post : '#020617');
            set_setting('theme_primary', $primary_post !== '' ? $primary_post : '#facc15');
            set_setting('theme_secondary', $secondary_post !== '' ? $secondary_post : '#38bdf8');
            set_setting('theme_text', $text_post !== '' ? $text_post : '#f9fafb');
            set_setting('whatsapp_help_url', $whatsHelpPost);

            // Reagendamento de live (calendário)
            $reagCountPost = (int)($_POST['reagendar_next_lives_count'] ?? $reagendar_next_lives_count);
            if ($reagCountPost < 1) $reagCountPost = 1;
            if ($reagCountPost > 10) $reagCountPost = 10;
            $reagWebhookPost = trim((string)($_POST['reagendar_webhook_url'] ?? $reagendar_webhook_url));
            $reagTtlPost = (int)($_POST['reagendar_token_ttl_hours'] ?? $reagendar_token_ttl_hours);
            if ($reagTtlPost < 1) $reagTtlPost = 1;
            if ($reagTtlPost > 720) $reagTtlPost = 720; // até 30 dias
            set_setting('reagendar_next_lives_count', (string)$reagCountPost);
            set_setting('reagendar_webhook_url', $reagWebhookPost);
            set_setting('reagendar_token_ttl_hours', (string)$reagTtlPost);


            // Sincroniza com app_config (cores + logo + textos)
            $stmt = $pdo->prepare("
                UPDATE app_config
                SET course_title          = :course_title,
                    primary_color         = :primary_color,
                    secondary_color       = :secondary_color,
                    background_color      = :background_color,
                    logo_url              = :logo_url,
                    certificado_cta_label = :cert_cta,
                    paid_courses_title    = :paid_title,
                    updated_at            = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                'course_title'    => $courseTitle,
                'primary_color'   => $primary_post   ?: $primary_default,
                'secondary_color' => $secondary_post ?: $secondary_default,
                'background_color'=> $bg_main_post   ?: $bg_main_default,
                'logo_url'        => $logoUrlPost,
                'cert_cta'        => $certCtaLabel   ?: 'Emitir Certificado',
                'paid_title'      => $paidCoursesTitle ?: 'Conheça nossos cursos pagos',
            ]);

            $mensagemOk = 'Configurações salvas com sucesso.';

            // Recarrega config e tema após salvar
            $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
            $config = $st->fetch();

            $bg_main = get_setting('theme_bg_main', $config['background_color'] ?? '#0b1120');
            $bg_card = get_setting('theme_bg_card', '#020617');
            $primary = get_setting('theme_primary', $config['primary_color'] ?? '#facc15');
            $secondary = get_setting('theme_secondary', $config['secondary_color'] ?? '#38bdf8');
            $text     = get_setting('theme_text', '#f9fafb');
            $whatsapp_help_url = get_setting('whatsapp_help_url', '');

// Reagendamento de live (calendário)
$reagendar_next_lives_count = (int)get_setting('reagendar_next_lives_count', '3');
$reagendar_webhook_url = get_setting('reagendar_webhook_url', '');
$reagendar_token_ttl_hours = (int)get_setting('reagendar_token_ttl_hours', '72');


            $logo_setting = get_setting('theme_logo_url', '');
            $logo_url     = $logo_setting !== '' ? $logo_setting : ($config['logo_url'] ?? '');

        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

// Helper de escape
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

include __DIR__ . '/_header.php';
?>
<style>
    /* ======================================================
       Layout / Organização (sem alterar lógica PHP/POST)
       ====================================================== */
    .ap-wrap{max-width:1180px;margin:0 auto;}
    .ap-title{display:flex;flex-direction:column;gap:6px;margin-bottom:12px;}
    .ap-title h3{margin:0;}
    .ap-sub{margin:0;color:#9ca3af;font-size:12px;}

    .ap-sections{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;margin-top:12px;}
    .ap-section{grid-column:span 12;background:rgba(2,6,23,.55);border:1px solid #1f2937;border-radius:14px;padding:14px;}
    .ap-section h4{margin:0 0 10px 0;font-size:14px;font-weight:700;}

    .ap-grid{display:grid;gap:12px;}
    .ap-grid.cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
    .ap-grid.cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
    .ap-grid.auto{grid-template-columns:repeat(auto-fit,minmax(190px,1fr));}

    .ap-field{display:flex;flex-direction:column;gap:6px;min-width:0;}
    .ap-field label{font-size:13px;font-weight:600;}
    .ap-help{font-size:11px;color:#9ca3af;line-height:1.35;}

    .ap-field input[type="text"],
    .ap-field input[type="number"],
    .ap-field input[type="url"]{
        width:100%;
        padding:9px 10px;
        border-radius:10px;
        border:1px solid #374151;
        background:#020617;
        color:#e5e7eb;
        font-size:13px;
        outline:none;
    }
    .ap-field input[type="text"]:focus,
    .ap-field input[type="number"]:focus,
    .ap-field input[type="url"]:focus{border-color:#64748b;box-shadow:0 0 0 3px rgba(148,163,184,.18);}

    .ap-color{display:flex;align-items:center;gap:10px;}
    .ap-color input[type="color"]{width:48px;height:38px;border:none;background:transparent;padding:0;}
    .ap-color code{font-size:12px;color:#cbd5e1;background:#0b1220;border:1px solid #1f2937;padding:6px 8px;border-radius:10px;}

    .ap-brand{display:grid;grid-template-columns:1.4fr .6fr;gap:14px;align-items:center;}
    .ap-logo-preview{display:flex;flex-direction:column;gap:8px;align-items:flex-start;}

    .preview-logo{
        width:46px;
        height:46px;
        border-radius:999px;
        background:#020617;
        border:1px solid #1f2937;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:13px;
        font-weight:800;
        color:#e5e7eb;
        overflow:hidden;
    }
    .preview-logo img{width:100%;height:100%;object-fit:contain;}

    .ap-info{
        margin-top:10px;
        padding:10px 12px;
        border-radius:12px;
        border:1px dashed #334155;
        background:rgba(2,6,23,.35);
        color:#cbd5e1;
        font-size:12px;
        line-height:1.5;
    }
    .ap-info code{white-space:normal;word-break:break-all;}

    .ap-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px;}
    .ap-actions button{padding:10px 14px;border-radius:12px;font-weight:700;}

    /* Pré-visualização */
    .preview-bar{
        padding:10px 12px;
        border-radius:14px;
        border:1px dashed #1f2937;
        font-size:12px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
    }
    .preview-course{font-size:13px;font-weight:700;}
    .preview-cert{padding:7px 12px;border-radius:999px;color:#111827;font-size:11px;font-weight:800;white-space:nowrap;}

    /* Responsivo */
    @media (max-width: 820px){
        .ap-grid.cols-2{grid-template-columns:1fr;}
        .ap-grid.cols-3{grid-template-columns:1fr;}
        .ap-brand{grid-template-columns:1fr;}
        .ap-actions{justify-content:stretch;}
        .ap-actions button{width:100%;}
        .preview-bar{flex-direction:column;align-items:flex-start;}
        .preview-cert{width:100%;text-align:center;}
    }
</style>

<div class="ap-wrap">
    <div class="card">
        <div class="ap-title">
            <h3>Aparência e configuração geral da área de membros</h3>
            <p class="ap-sub">Organize cores, logo, textos e configurações do calendário sem mexer na lógica do sistema.</p>
        </div>

        <?php if ($mensagemOk): ?>
            <div class="msg-ok"><?= h($mensagemOk) ?></div>
        <?php endif; ?>
        <?php if ($mensagemErro): ?>
            <div class="msg-erro"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <div class="ap-sections">

                <!-- Cores / Tema -->
                <section class="ap-section">
                    <h4>🎨 Cores do tema</h4>
                    <div class="ap-grid auto">
                        <div class="ap-field">
                            <label>Cor de fundo principal</label>
                            <div class="ap-color">
                                <input type="color" name="bg_main" value="<?= h($bg_main) ?>">
                                <code><?= h($bg_main) ?></code>
                            </div>
                        </div>
                        <div class="ap-field">
                            <label>Cor dos cards</label>
                            <div class="ap-color">
                                <input type="color" name="bg_card" value="<?= h($bg_card) ?>">
                                <code><?= h($bg_card) ?></code>
                            </div>
                        </div>
                        <div class="ap-field">
                            <label>Cor primária</label>
                            <div class="ap-color">
                                <input type="color" name="primary" value="<?= h($primary) ?>">
                                <code><?= h($primary) ?></code>
                            </div>
                        </div>
                        <div class="ap-field">
                            <label>Cor secundária</label>
                            <div class="ap-color">
                                <input type="color" name="secondary" value="<?= h($secondary) ?>">
                                <code><?= h($secondary) ?></code>
                            </div>
                        </div>
                        <div class="ap-field">
                            <label>Cor do texto principal</label>
                            <div class="ap-color">
                                <input type="color" name="text" value="<?= h($text) ?>">
                                <code><?= h($text) ?></code>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Logo / Marca -->
                <section class="ap-section">
                    <h4>🧩 Logo e marca</h4>
                    <div class="ap-brand">
                        <div class="ap-field">
                            <label>Logo (para a trilha)</label>
                            <input type="file" name="logo" accept="image/*">
                            <div class="ap-help">Se não enviar, será usado o logo atual salvo no sistema.</div>
                        </div>
                        <div class="ap-logo-preview">
                            <div class="preview-logo">
                                <?php if (!empty($logo_url)): ?>
                                    <img src="<?= h($logo_url) ?>" alt="Logo">
                                <?php else: ?>
                                    EL
                                <?php endif; ?>
                            </div>
                            <div class="ap-help">Pré-visualização do logo na trilha</div>
                        </div>
                    </div>
                </section>

                <!-- Textos principais -->
                <section class="ap-section">
                    <h4>📝 Textos da trilha</h4>
                    <div class="ap-grid cols-3">
                        <div class="ap-field">
                            <label for="course_title">Título principal do curso</label>
                            <input type="text" id="course_title" name="course_title"
                                   placeholder="Nome do Curso Exemplo"
                                   value="<?= h($config['course_title'] ?? '') ?>">
                            <div class="ap-help">Aparece na parte superior da trilha para o aluno.</div>
                        </div>

                        <div class="ap-field">
                            <label for="certificado_cta_label">Texto do botão de certificado</label>
                            <input type="text" id="certificado_cta_label" name="certificado_cta_label"
                                   placeholder="Emitir Certificado"
                                   value="<?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>">
                        </div>

                        <div class="ap-field">
                            <label for="paid_courses_title">Título da seção de cursos pagos na trilha</label>
                            <input type="text" id="paid_courses_title" name="paid_courses_title"
                                   placeholder="Conheça nossos cursos pagos"
                                   value="<?= h($config['paid_courses_title'] ?? 'Conheça nossos cursos pagos') ?>">
                        </div>
                    </div>
                </section>

                <!-- Suporte WhatsApp -->
                <section class="ap-section">
                    <h4>💬 Suporte</h4>
                    <div class="ap-grid cols-2">
                        <div class="ap-field">
                            <label for="whatsapp_help_url">Link do botão de ajuda (WhatsApp) na trilha</label>
                            <input type="text" id="whatsapp_help_url" name="whatsapp_help_url"
                                   placeholder="https://wa.me/55..."
                                   value="<?= h($whatsapp_help_url) ?>">
                            <div class="ap-help">Deixe em branco para ocultar o botão flutuante de ajuda na trilha.</div>
                        </div>
                    </div>
                </section>

                <!-- Reagendamento de Live -->
                <section class="ap-section">
                    <h4>📅 Reagendamento de Live (Calendário)</h4>

                    <div class="ap-grid cols-2">
                        <div class="ap-field">
                            <label for="reagendar_next_lives_count">Quantas próximas lives mostrar para o aluno</label>
                            <input type="number" min="1" max="10" id="reagendar_next_lives_count" name="reagendar_next_lives_count" value="<?= (int)$reagendar_next_lives_count ?>">
                            <div class="ap-help">Ex.: 1 = só a próxima. 3 = as 3 próximas.</div>
                        </div>

                        <div class="ap-field">
                            <label for="reagendar_token_ttl_hours">Validade do link de reagendamento (horas)</label>
                            <input type="number" min="1" max="720" id="reagendar_token_ttl_hours" name="reagendar_token_ttl_hours" value="<?= (int)$reagendar_token_ttl_hours ?>">
                            <div class="ap-help">Após expirar, é necessário gerar um novo link para o aluno.</div>
                        </div>
                    </div>

                    <div class="ap-grid cols-2" style="margin-top:12px;">
                        <div class="ap-field">
                            <label for="reagendar_webhook_url">Webhook ao reagendar (opcional)</label>
                            <input type="url" id="reagendar_webhook_url" name="reagendar_webhook_url" value="<?= h($reagendar_webhook_url) ?>" placeholder="https://...">
                            <div class="ap-help">Disparado quando o aluno confirma a nova live. Envia dados do aluno + nova turma + novo horário (dd/mm/aaaa hh:mm).</div>
                        </div>

                        <?php
                            $publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
                            $reagendarLinkExemploToken = $publicBase . '/public/reagendar_live.php?t=SEU_TOKEN';
                            $reagendarLinkExemploAuto  = $publicBase . '/public/reagendar_live.php?email=EMAIL_DO_ALUNO&telefone=TELEFONE_DO_ALUNO';
                        ?>
                        <div class="ap-info">
                            <strong>Link da página (para enviar ao aluno)</strong><br>
                            Opção 1 (com token): <code><?= h($reagendarLinkExemploToken) ?></code><br>
                            Opção 2 (automática): <code><?= h($reagendarLinkExemploAuto) ?></code> <span style="color:#9ca3af;">(gera o token sozinho)</span><br>
                            Gerador manual (admin): <code><?= h(BASE_URL_ADMIN . '/reagendar_link.php?user_id=ID_DO_ALUNO') ?></code>
                        </div>
                    </div>
                </section>
            </div>

            <div class="ap-actions">
                <button type="submit">Salvar configurações</button>
            </div>
        </form>
    </div>

    <!-- Pré-visualização rápida -->
    <div class="card" style="margin-top:16px;">
        <div style="font-weight:800;margin-bottom:10px;">Pré-visualização rápida</div>
        <div class="preview-bar" style="background: <?= h($bg_card) ?>;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <div class="preview-logo">
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?= h($logo_url) ?>" alt="Logo">
                    <?php else: ?>
                        EL
                    <?php endif; ?>
                </div>
                <div style="min-width:0;">
                    <div class="preview-course"><?= h($config['course_title'] ?? '') ?></div>
                    <div class="ap-help">Exemplo de como aparece para o aluno</div>
                </div>
            </div>
            <div class="preview-cert" style="background: <?= h($primary) ?>;">
                🎓 <?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
