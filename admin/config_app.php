<?php
// FILE: admin/config_app.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_admin();
$pdo = getPDO();

$mensagemOk = '';
$mensagemErro = '';

// Carrega config atual (id=1)
try {
    $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
    $config = $st->fetch();
    if (!$config) {
        $pdo->exec("
            INSERT INTO app_config (id, course_title, primary_color, secondary_color, background_color, certificado_cta_label, paid_courses_title)
            VALUES (1, 'Trilha de Aulas', '#facc15', '#22c55e', '#020617', 'Emitir Certificado', 'Conheça nossos cursos pagos')
            ON DUPLICATE KEY UPDATE id = id
        ");
        $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
        $config = $st->fetch();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseTitle      = trim((string)($_POST['course_title'] ?? ''));
    $primaryColor     = trim((string)($_POST['primary_color'] ?? ''));
    $secondaryColor   = trim((string)($_POST['secondary_color'] ?? ''));
    $backgroundColor  = trim((string)($_POST['background_color'] ?? ''));
    $logoUrl          = trim((string)($_POST['logo_url'] ?? ''));
    $certCtaLabel     = trim((string)($_POST['certificado_cta_label'] ?? ''));
    $paidCoursesTitle = trim((string)($_POST['paid_courses_title'] ?? ''));

    if ($courseTitle === '') {
        $mensagemErro = 'Informe o título do curso.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE app_config
                SET course_title = :course_title,
                    primary_color = :primary_color,
                    secondary_color = :secondary_color,
                    background_color = :background_color,
                    logo_url = :logo_url,
                    certificado_cta_label = :cert_cta,
                    paid_courses_title = :paid_title,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                'course_title' => $courseTitle,
                'primary_color' => $primaryColor ?: '#facc15',
                'secondary_color' => $secondaryColor ?: '#22c55e',
                'background_color' => $backgroundColor ?: '#020617',
                'logo_url' => $logoUrl,
                'cert_cta' => $certCtaLabel ?: 'Emitir Certificado',
                'paid_title' => $paidCoursesTitle ?: 'Conheça nossos cursos pagos',
            ]);

            $mensagemOk = 'Configurações salvas com sucesso.';
            // Recarrega config
            $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
            $config = $st->fetch();
        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$menu = 'config_app';
include __DIR__ . '/_header.php';
?>

<div style="max-width:860px">
    <?php if ($mensagemOk): ?>
        <div class="alert alert-ok mb-3"><?= h($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="alert alert-error mb-3"><?= h($mensagemErro) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="">
            <div class="section-label">Identidade visual</div>

            <div class="form-group">
                <label class="form-label" for="course_title">Título principal do curso (aparece na trilha)</label>
                <input type="text" id="course_title" name="course_title"
                       value="<?= h($config['course_title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="logo_url">URL da logo (PNG / JPG)</label>
                <input type="text" id="logo_url" name="logo_url"
                       value="<?= h($config['logo_url'] ?? '') ?>">
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="primary_color">Cor primária (botões, destaques)</label>
                    <input type="text" id="primary_color" name="primary_color"
                           placeholder="#facc15"
                           value="<?= h($config['primary_color'] ?? '#facc15') ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="secondary_color">Cor de acento (progresso, tags)</label>
                    <input type="text" id="secondary_color" name="secondary_color"
                           placeholder="#22c55e"
                           value="<?= h($config['secondary_color'] ?? '#22c55e') ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:180px">
                    <label class="form-label" for="background_color">Cor de fundo da área de membros</label>
                    <input type="text" id="background_color" name="background_color"
                           placeholder="#020617"
                           value="<?= h($config['background_color'] ?? '#020617') ?>">
                </div>
            </div>

            <div class="section-label">Certificado &amp; Cursos pagos</div>

            <div class="form-group">
                <label class="form-label" for="certificado_cta_label">Texto do botão de certificado na trilha</label>
                <input type="text" id="certificado_cta_label" name="certificado_cta_label"
                       placeholder="Emitir Certificado"
                       value="<?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="paid_courses_title">Título da seção de cursos pagos na trilha</label>
                <input type="text" id="paid_courses_title" name="paid_courses_title"
                       placeholder="Conheça nossos cursos pagos"
                       value="<?= h($config['paid_courses_title'] ?? 'Conheça nossos cursos pagos') ?>">
            </div>

            <button type="submit" class="btn btn-primary">Salvar configurações</button>
        </form>
    </div>

    <div class="card" style="margin-top:4px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:12px">Pré-visualização rápida</div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-radius:10px;background:var(--bg);border:1px dashed var(--border)">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:38px;height:38px;border-radius:999px;background:var(--bg-hover);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--primary);font-size:15px;flex-shrink:0">
                    <?php if (!empty($config['logo_url'])): ?>
                        <img src="<?= h($config['logo_url']) ?>" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--text)"><?= h($config['course_title'] ?? '') ?></div>
                    <div style="font-size:11px;color:var(--muted)">Exemplo de como aparece para o aluno</div>
                </div>
            </div>
            <span style="padding:5px 12px;border-radius:999px;background:var(--primary);color:#111827;font-size:11px;font-weight:700;white-space:nowrap">
                <?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>
            </span>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
