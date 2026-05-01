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
    /* Ajuste visual apenas para o bloco de pré-visualização */
    .preview-bar{
        margin-top:12px;
        padding:8px 10px;
        border-radius:10px;
        background:#020617;
        border:1px dashed #1f2937;
        font-size:12px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
    }
    .preview-left{
        display:flex;
        align-items:center;
        gap:8px;
    }
    .preview-logo{
        width:32px;
        height:32px;
        border-radius:999px;
        background:var(--bg);
        border:1px solid var(--border);
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:12px;
        font-weight:bold;
        color:var(--text);
        overflow:hidden;
    }
    .preview-logo img{
        width:100%;
        height:100%;
        object-fit:contain;
    }
    .preview-course{
        font-size:13px;
        font-weight:600;
    }
    .preview-cert{
        padding:5px 10px;
        border-radius:999px;
        background:#facc15;
        color:#111827;
        font-size:11px;
        font-weight:bold;
    }
    .field{
        margin-bottom:10px;
    }
    .field label{
        font-size:13px;
        font-weight:600;
        display:block;
        margin-bottom:3px;
    }
    .field input[type="text"],
    .field input[type="number"],
    .field input[type="url"]{
        width:100%;
        padding:6px 8px;
        border-radius:6px;
        border:1px solid var(--border-light);
        background:var(--bg);
        color:var(--text);
        font-size:13px;
    }
    .field-small{
        font-size:11px;
        color:#9ca3af;
        margin-top:2px;
    }
</style>

<div class="card">
    <h3>Aparência e configuração geral da área de membros</h3>

    <?php if ($mensagemOk): ?>
        <div class="msg-ok"><?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="msg-erro"><?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <!-- Bloco de cores / tema -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <div>
                <label>Cor de fundo principal</label><br>
                <input type="color" name="bg_main" value="<?= h($bg_main) ?>">
            </div>
            <div>
                <label>Cor dos cards</label><br>
                <input type="color" name="bg_card" value="<?= h($bg_card) ?>">
            </div>
            <div>
                <label>Cor primária</label><br>
                <input type="color" name="primary" value="<?= h($primary) ?>">
            </div>
            <div>
                <label>Cor secundária</label><br>
                <input type="color" name="secondary" value="<?= h($secondary) ?>">
            </div>
            <div>
                <label>Cor do texto principal</label><br>
                <input type="color" name="text" value="<?= h($text) ?>">
            </div>
        </div>

        <hr style="margin:16px 0; border-color:#1f2937;">

        <!-- Bloco de logo -->
        <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:16px;align-items:center;">
            <div>
                <label>Logo (para a trilha)</label><br>
                <input type="file" name="logo" accept="image/*">
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
                    Se não enviar, será usado o logo atual salvo no sistema.
                </div>
            </div>
            <div>
                <div class="preview-logo">
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?= h($logo_url) ?>" alt="Logo">
                    <?php else: ?>
                        EL
                    <?php endif; ?>
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Pré-visualização do logo na trilha</div>
            </div>
        </div>

        <hr style="margin:16px 0; border-color:#1f2937;">

        <!-- Bloco de textos principais -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <div class="field">
                <label for="course_title">Título principal do curso</label>
                <input type="text" id="course_title" name="course_title"
                       placeholder="Nome do Curso Exemplo"
                       value="<?= h($config['course_title'] ?? '') ?>">
                <div class="field-small">Aparece na parte superior da trilha para o aluno.</div>
            </div>

            <div class="field">
                <label for="certificado_cta_label">Texto do botão de certificado</label>
                <input type="text" id="certificado_cta_label" name="certificado_cta_label"
                       placeholder="Emitir Certificado"
                       value="<?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>">
            </div>

            <div class="field">
                <label for="paid_courses_title">Título da seção de cursos pagos na trilha</label>
                <input type="text" id="paid_courses_title" name="paid_courses_title"
                       placeholder="Conheça nossos cursos pagos"
                       value="<?= h($config['paid_courses_title'] ?? 'Conheça nossos cursos pagos') ?>">
            </div>
            <div class="field">
                <label for="whatsapp_help_url">Link do botão de ajuda (WhatsApp) na trilha</label>
                <input type="text" id="whatsapp_help_url" name="whatsapp_help_url"
                       placeholder="https://wa.me/55..."
                       value="<?= h($whatsapp_help_url) ?>">
                <div class="field-small">
                    Deixe em branco para ocultar o botão flutuante de
        <div class="card">
            <h2>Reagendamento de Live (Calendário)</h2>

            <div class="field">
                <label for="reagendar_next_lives_count">Quantas próximas lives mostrar para o aluno</label>
                <input type="number" min="1" max="10" id="reagendar_next_lives_count" name="reagendar_next_lives_count" value="<?= (int)$reagendar_next_lives_count ?>">
                <div class="field-small">Ex.: 1 = só a próxima. 3 = as 3 próximas.</div>
            </div>

            <div class="field">
                <label for="reagendar_token_ttl_hours">Validade do link de reagendamento (horas)</label>
                <input type="number" min="1" max="720" id="reagendar_token_ttl_hours" name="reagendar_token_ttl_hours" value="<?= (int)$reagendar_token_ttl_hours ?>">
                <div class="field-small">Após expirar, é necessário gerar um novo link para o aluno.</div>
            </div>

            <div class="field">
                <label for="reagendar_webhook_url">Webhook ao reagendar (opcional)</label>
                <input type="url" id="reagendar_webhook_url" name="reagendar_webhook_url" value="<?= h($reagendar_webhook_url) ?>" placeholder="https://...">
                <div class="field-small">Disparado quando o aluno confirma a nova live. Envia dados do aluno + nova turma + novo horário (dd/mm/aaaa hh:mm).</div>
            </div>

            <?php
                $publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
                $reagendarLinkExemploToken = $publicBase . '/public/reagendar_live.php?t=SEU_TOKEN';
                $reagendarLinkExemploAuto  = $publicBase . '/public/reagendar_live.php?email=EMAIL_DO_ALUNO&telefone=TELEFONE_DO_ALUNO';
            ?>
            <div class="field">
                <label>Link da página (para enviar ao aluno)</label>
                <div class="field-small">
                    Opção 1 (com token): <code><?= h($reagendarLinkExemploToken) ?></code><br>
                    Opção 2 (automática): <code><?= h($reagendarLinkExemploAuto) ?></code> <span style="color:#9ca3af;">(gera o token sozinho)</span><br>
                    Gerador manual (admin): <code><?= h(BASE_URL_ADMIN . '/reagendar_link.php?user_id=ID_DO_ALUNO') ?></code>
                </div>
            </div>
        </div>

        <div style="margin-top:12px;">
            <button type="submit">Salvar configurações</button>
        </div>
    </form>
</div>

<!-- Pré-visualização rápida -->
<div class="card" style="margin-top:16px;">
    <div class="section-title">Pré-visualização rápida</div>
    <div class="preview-bar" style="background: <?= h($bg_card) ?>;">
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="preview-logo">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= h($logo_url) ?>" alt="Logo">
                <?php else: ?>
                    EL
                <?php endif; ?>
            </div>
            <div>
                <div class="preview-course"><?= h($config['course_title'] ?? '') ?></div>
                <div style="font-size:11px;color:#9ca3af;">Exemplo de como aparece para o aluno</div>
            </div>
        </div>
        <div class="preview-cert" style="background: <?= h($primary) ?>;">
            🎓 <?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
