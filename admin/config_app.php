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

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Configuração da Área de Membros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#020617;
            --card:#020617;
            --border:#1f2937;
            --primary:#facc15;
            --text:#e5e7eb;
            --muted:#9ca3af;
            --danger:#ef4444;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:#020617;
            color:var(--text);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit;}
        .page{
            max-width:900px;
            margin:0 auto;
            padding:16px 12px 32px;
        }
        .title{
            font-size:18px;
            font-weight:bold;
            margin-bottom:4px;
        }
        .subtitle{
            font-size:12px;
            color:var(--muted);
            margin-bottom:14px;
        }
        .card{
            background:var(--card);
            border-radius:16px;
            border:1px solid var(--border);
            padding:16px 14px 18px;
            box-shadow:0 14px 32px rgba(0,0,0,.45);
        }
        .section-title{
            font-size:14px;
            font-weight:bold;
            margin-bottom:8px;
            margin-top:10px;
        }
        .field{
            margin-bottom:10px;
        }
        label{
            display:block;
            font-size:12px;
            color:var(--muted);
            margin-bottom:3px;
        }
        input[type="text"]{
            width:100%;
            padding:7px 9px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            font-size:13px;
        }
        .row{
            display:flex;
            gap:10px;
        }
        .col-2{flex:1;}
        .btn{
            margin-top:10px;
            padding:9px 14px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .btn:hover{
            filter:brightness(1.05);
        }
        .msg-ok{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(34,197,94,.12);
            border:1px solid #22c55e;
            color:#bbf7d0;
            font-size:12px;
        }
        .msg-erro{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(239,68,68,.12);
            border:1px solid #ef4444;
            color:#fecaca;
            font-size:12px;
        }
        .preview-bar{
            margin-top:12px;
            padding:8px 10px;
            border-radius:10px;
            background:#020617;
            border:1px dashed var(--border);
            font-size:12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }
        .preview-logo{
            width:40px;
            height:40px;
            border-radius:999px;
            background:#020617;
            border:1px solid var(--border);
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            font-size:16px;
            color:var(--primary);
        }
        .preview-logo img{
            max-width:100%;
            max-height:100%;
            object-fit:contain;
        }
        .preview-course{
            font-weight:bold;
            font-size:13px;
        }
        .preview-cert{
            padding:5px 10px;
            border-radius:999px;
            background:var(--primary);
            color:#111827;
            font-size:11px;
            font-weight:bold;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="title">Configuração da Área de Membros</div>
    <div class="subtitle">Ajuste título do curso, logo e paleta de cores usada nas telas dos alunos.</div>

    <div class="card">
        <?php if ($mensagemOk): ?>
            <div class="msg-ok"><?= h($mensagemOk) ?></div>
        <?php endif; ?>
        <?php if ($mensagemErro): ?>
            <div class="msg-erro"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="section-title">Identidade visual</div>

            <div class="field">
                <label for="course_title">Título principal do curso (aparece na trilha)</label>
                <input type="text" id="course_title" name="course_title"
                       value="<?= h($config['course_title'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="logo_url">URL da logo (PNG / JPG)</label>
                <input type="text" id="logo_url" name="logo_url"
                       value="<?= h($config['logo_url'] ?? '') ?>">
            </div>

            <div class="row">
                <div class="field col-2">
                    <label for="primary_color">Cor primária (botões, destaques)</label>
                    <input type="text" id="primary_color" name="primary_color"
                           placeholder="#facc15"
                           value="<?= h($config['primary_color'] ?? '#facc15') ?>">
                </div>
                <div class="field col-2">
                    <label for="secondary_color">Cor de acento (progresso, tags)</label>
                    <input type="text" id="secondary_color" name="secondary_color"
                           placeholder="#22c55e"
                           value="<?= h($config['secondary_color'] ?? '#22c55e') ?>">
                </div>
            </div>

            <div class="field">
                <label for="background_color">Cor de fundo da área de membros</label>
                <input type="text" id="background_color" name="background_color"
                       placeholder="#020617"
                       value="<?= h($config['background_color'] ?? '#020617') ?>">
            </div>

            <div class="section-title">Certificado & cursos pagos</div>

            <div class="field">
                <label for="certificado_cta_label">Texto do botão de certificado na trilha</label>
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

            <button type="submit" class="btn">Salvar configurações</button>
        </form>

        <div class="section-title" style="margin-top:14px;">Pré-visualização rápida</div>
        <div class="preview-bar">
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="preview-logo">
                    <?php if (!empty($config['logo_url'])): ?>
                        <img src="<?= h($config['logo_url']) ?>" alt="Logo">
                    <?php else: ?>
                        EL
                    <?php endif; ?>
                </div>
                <div>
                    <div class="preview-course"><?= h($config['course_title'] ?? '') ?></div>
                    <div style="font-size:11px;color:var(--muted);">Exemplo de como aparece para o aluno</div>
                </div>
            </div>
            <div class="preview-cert">
                🎓 <?= h($config['certificado_cta_label'] ?? 'Emitir Certificado') ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
