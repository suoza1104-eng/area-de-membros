<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_aluno();
$pdo = getPDO();

// ========== Carrega dados do aluno ==========
$alunoId = (int)($_SESSION['aluno_id'] ?? 0);
if ($alunoId <= 0) {
    header('Location: index.php');
    exit;
}

// Dados básicos a partir da sessão
$alunoNome    = (string)($_SESSION['aluno_nome']   ?? 'Aluno');
$turmaCodigo  = (string)($_SESSION['turma_codigo'] ?? '');

// Refina dados com base na tabela users (turma_codigo + turma_live_at)
$turmaLiveAt = null;
try {
    $stUser = $pdo->prepare("SELECT turma_codigo, turma_live_at FROM users WHERE id = :id LIMIT 1");
    $stUser->execute(['id' => $alunoId]);
    if ($rowUser = $stUser->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($rowUser['turma_codigo']) && $turmaCodigo === '') {
            $turmaCodigo = (string)$rowUser['turma_codigo'];
        }
        if (!empty($rowUser['turma_live_at'])) {
            $turmaLiveAt = (string)$rowUser['turma_live_at'];
        }
    }
} catch (Throwable $e) {
    // Se der erro nessa consulta, seguimos só com os dados de sessão
}

$aluno = [
    'id'           => $alunoId,
    'nome'         => $alunoNome,
    'turma_codigo' => $turmaCodigo,
];

// ========== Config da aplicação (título do curso, logo etc.) ==========
$cfgStmt = $pdo->query("SELECT * FROM app_config LIMIT 1");
$appCfg = $cfgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$courseTitle = $appCfg['course_title'] ?? 'Nome do Curso Exemplo';
$logoUrl     = $appCfg['logo_url']     ?? '';
$whatsappHelpUrl = get_setting('whatsapp_help_url', '');

// ========== Aulas e progresso ==========
$stLessons = $pdo->query("SELECT * FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
$lessons   = $stLessons->fetchAll(PDO::FETCH_ASSOC);

$stProg = $pdo->prepare("SELECT lesson_id, status FROM lesson_progress WHERE user_id = :uid");
$stProg->execute(['uid' => $alunoId]);
$rowsProg = $stProg->fetchAll(PDO::FETCH_ASSOC);

$mapStatus = [];
foreach ($rowsProg as $r) {
    $mapStatus[(int)$r['lesson_id']] = $r['status'];
}

// Conta obrigatórias / concluídas
$totalObrigatorias = 0;
$totalConcluidas   = 0;
foreach ($lessons as $ls) {
    // No seu banco o campo é conta_para_conclusao
    $obrig = (int)($ls['conta_para_conclusao'] ?? 1);
    if ($obrig === 1) {
        $totalObrigatorias++;
        $st = $mapStatus[(int)$ls['id']] ?? 'pending';
        if ($st === 'completed') {
            $totalConcluidas++;
        }
    }
}
$percent = $totalObrigatorias > 0
    ? (int)round(($totalConcluidas / $totalObrigatorias) * 100)
    : 0;
$temTudoConcluido = ($totalObrigatorias > 0 && $totalObrigatorias === $totalConcluidas);

// ========== Cursos recomendados (pagos) ==========
try {
    $stRec = $pdo->query("SELECT * FROM recommended_courses WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
    $cursosRec = $stRec->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cursosRec = [];
}

// ========== Live da turma do aluno ==========
$liveDataIso   = null;
$liveDataBr    = null;

// 1º: se existir turma_live_at no próprio usuário, usamos ela
if (!empty($turmaLiveAt)) {
    try {
        $dtLive = new DateTime($turmaLiveAt);
        $now    = new DateTime('now');
        if ($dtLive > $now) {
            $liveDataIso   = $dtLive->format('Y-m-d H:i:s');
            $liveDataBr    = $dtLive->format('d/m/Y H:i');
        }
    } catch (Throwable $e) {
        // Se der erro no parse da data, ignora
    }
}

// 2º: se não houver turma_live_at mas houver turma_codigo, busca na tabela turmas
if (!$liveDataIso && $turmaCodigo !== '') {
    try {
        $stTurma = $pdo->prepare("SELECT * FROM turmas WHERE codigo = :cod LIMIT 1");
        $stTurma->execute(['cod' => $turmaCodigo]);
        $turma = $stTurma->fetch(PDO::FETCH_ASSOC);

        if ($turma && !empty($turma['data_live'])) {
            $dtLive = new DateTime($turma['data_live']);
            $now    = new DateTime('now');
            if ($dtLive > $now) {
                $liveDataIso   = $dtLive->format('Y-m-d H:i:s');
                $liveDataBr    = $dtLive->format('d/m/Y H:i');
            }
        }
    } catch (Throwable $e) {
        // sem banner se der erro
    }
}

// helper de escape
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Trilha de Aulas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        <?= theme_inline_css_vars(); ?>

        :root {
            --header-height: 64px;
        }

        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: system-ui, -apple-system,
                BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            overflow-x: hidden; /* evita rolagem horizontal no mobile */
        }
        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 16px 8px;
            max-width: 1040px;
            width: 100%;
            margin: 0 auto;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .logo-circle {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            overflow: hidden;
            background: #020617;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .course-texts {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .course-title {
            font-size: 18px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .course-sub {
            font-size: 13px;
            color: var(--text-muted);
        }
        .topbar-right {
            font-size: 13px;
            color: var(--text-muted);
        }
        .topbar-right a {
            color: var(--primary);
        }

        .content {
            flex: 1;
            max-width: 1040px;
            width: 100%;
            margin: 0 auto;
            padding: 8px 16px 32px;
        }

        /* Barra de live */
        .live-banner {
            margin-bottom: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            background: #b91c1c;
            color: #fee2e2;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
        }
        .live-line-primary {
            font-weight: 600;
            margin-bottom: 2px;
        }
        .live-line-secondary {
            font-size: 12px;
        }
        .live-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #fecaca;
            box-shadow: 0 0 0 0 rgba(254,202,202,0.8);
            animation: pulse 1.5s infinite;
        }
        .live-text {
            flex: 1;
        }
        .live-text strong {
            font-weight: 700;
        }

        @keyframes pulse {
            0% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(254,202,202,0.7); }
            70%{ transform: scale(1.6); box-shadow: 0 0 0 10px rgba(254,202,202,0); }
            100%{transform: scale(1);   box-shadow: 0 0 0 0 rgba(254,202,202,0); }
        }

        /* Card principal */
        .main-card {
            background: var(--bg-card);
            border-radius: 18px;
            border: 1px solid var(--border-subtle);
            padding: 14px 16px 16px;
            margin-bottom: 16px;
        }

        /* Cabeçalho de progresso + botão de certificado */
        .progress-header {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
            align-items: center;
            gap: 10px;
        }
        .progress-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .progress-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .progress-bar-outer {
            position: relative;
            width: 100%;
            height: 16px;
            border-radius: 999px;
            background: #020617;
            overflow: hidden;
        }
        .progress-bar-inner {
            height: 100%;
            border-radius: 999px;
            background: var(--primary);
            width: <?= $percent ?>%;
            transition: width 0.4s ease;
        }
        .progress-bar-label {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            color: #111827;
            pointer-events: none;
        }

        .btn-certificado {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 10px;
            border-radius: 16px;
            background: var(--primary);
            color: #111827;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-certificado .icon {
            font-size: 18px;
        }
        .btn-certificado-pulse {
            animation: pulseCert 1.5s infinite;
        }
        @keyframes pulseCert {
            0%   { transform: scale(1);   box-shadow: 0 0 0 0 rgba(250, 204, 21, 0.5); }
            70%  { transform: scale(1.04); box-shadow: 0 0 0 12px rgba(250, 204, 21, 0); }
            100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(250, 204, 21, 0); }
        }

        /* Seções */
        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin: 18px 2px 8px;
        }

        /* Wrapper genérico de carrossel */
        .carousel-wrapper {
            position: relative;
        }

        /* Listas (carrosséis) */
        .lessons-grid,
        .rec-list {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 4px 2px 10px;
            scroll-snap-type: x mandatory;
            scroll-padding-left: 2px;
            scroll-behavior: smooth;
        }
        .lessons-grid::-webkit-scrollbar,
        .rec-list::-webkit-scrollbar {
            height: 6px;
        }
        .lessons-grid::-webkit-scrollbar-thumb,
        .rec-list::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: rgba(148,163,184,0.6);
        }

        .lesson-card {
            position: relative;
            background: #020617;
            border-radius: 18px;
            border: 1px solid var(--border-subtle);
            padding: 10px 12px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 0 0 88%;
            max-width: 400px;
            scroll-snap-align: start;
            cursor: pointer;
        }
        .lesson-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--text-muted);
        }
        .lesson-title {
            font-size: 15px;
            font-weight: 600;
        }
        .lesson-thumb {
margin-top: 4px;
margin-bottom: 8px;
border-radius: 14px;
border: 1px solid rgba(15,23,42,0.8);
background: radial-gradient(circle at top left, #0f172a, #020617);
overflow: hidden;
position: relative;
width: 100%;
        }
        .lesson-thumb::before {
            content: "";
            display: block;
            padding-top: 100%;
        }

.lesson-thumb img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        

/* Bloqueio de aulas (trilha) */
.lesson-card.lesson-locked {
    cursor: not-allowed;
    opacity: 0.92;
}
.lesson-thumb .lesson-locked-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: rgba(15,23,42,0.62);
    color: #f9fafb;
    text-align: center;
    padding: 10px;
}
.lesson-thumb .lesson-locked-overlay-icon {
    font-size: 26px;
}
.lesson-thumb .lesson-locked-overlay-text {
    font-size: 12px;
    font-weight: 600;
}
.badge-locked {
    background: rgba(185,28,28,0.18);
    color: #fecaca;
}
.btn-lesson.btn-lesson-locked {
    background: rgba(148,163,184,0.20);
    color: #e5e7eb;
    cursor: not-allowed;
}
.lesson-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
        }
        .badge-ok {
            background: rgba(22,163,74,0.18);
            color: #bbf7d0;
        }
        .badge-pending {
            background: rgba(148,163,184,0.18);
            color: #e5e7eb;
        }
        .btn-lesson {
            padding: 7px 12px;
            border-radius: 999px;
            border: none;
            background: var(--primary);
            color:#111827;
            font-size: 13px;
            font-weight: 600;
            cursor:pointer;
            white-space: nowrap;
        }

        /* Cursos recomendados */
        .rec-card {
            background: #020617;
            border-radius: 18px;
            border: 1px solid var(--border-subtle);
            padding: 10px 12px 12px;
            flex: 0 0 88%;
            max-width: 400px;
            scroll-snap-align: start;
            cursor: pointer;
        }
        .rec-thumb {
border-radius: 14px;
overflow: hidden;
margin-bottom: 8px;
background: radial-gradient(circle at top left, #0f172a, #020617);
display:flex;
align-items:center;
justify-content:center;
position: relative;
width: 100%;
        }
        .rec-lock {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 30px;
            height: 30px;
            border-radius: 999px;
            background: rgba(15,23,42,0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 8px rgba(0,0,0,0.6);
            font-size: 16px;
        }
        .rec-lock span {
            line-height: 1;
        }
        .rec-locked-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: rgba(15,23,42,0.6);
            color: #f9fafb;
            text-align: center;
            padding: 8px;
        }
        .rec-locked-overlay-icon {
            font-size: 26px;
        }
        .rec-locked-overlay-text {
            font-size: 12px;
            font-weight: 500;
        }
        .rec-thumb::before {
            content: "";
            display: block;
            padding-top: 100%;
        }

.rec-thumb img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .rec-title {
            font-size: 15px;
            font-weight:600;
            margin-bottom:4px;
        }
        .rec-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom:6px;
        }
        .rec-footer {
            display:flex;
            justify-content: space-between;
            align-items:center;
            gap: 8px;
        }
        .rec-pill {
            font-size:11px;
            padding:3px 8px;
            border-radius:999px;
            background:rgba(148,163,184,0.18);
            color:#e5e7eb;
        }
        .btn-rec {
            padding:7px 12px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-size:13px;
            font-weight:600;
            cursor:pointer;
            text-decoration:none;
            white-space:nowrap;
        }

        /* Setas de carrossel (bolinhas maiores) */
        .carousel-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: 2px solid rgba(248,250,252,0.85);
            background: rgba(15,23,42,0.96);
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(0,0,0,0.6);
            z-index: 10;
        }
        .carousel-arrow span {
            font-size: 20px;
            line-height: 1;
        }
        .carousel-arrow-left {
            left: 6px;
        }
        .carousel-arrow-right {
            right: 6px;
        }

        footer {
            text-align:center;
            padding:16px;
            font-size:11px;
            color:var(--text-muted);
        }

        /* Botão flutuante de WhatsApp na trilha (estilo bolha com telefone) */
        .whatsapp-fab {
            position: fixed;
            right: 16px;
            bottom: 18px;
            width: 64px;
            height: 64px;
            border-radius: 999px;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            z-index: 40;
            animation: whatsPulse 1.6s infinite;
        }
        .whatsapp-fab-icon {
            width: 100%;
            height: 100%;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 3px #ffffff;
            background: #25D366;
        }
        .whatsapp-fab-icon svg {
            width: 70%;
            height: 70%;
            display: block;
        }
        @keyframes whatsPulse {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37,211,102,0.55);
            }
            60% {
                transform: scale(1.12);
                box-shadow: 0 0 0 16px rgba(37,211,102,0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37,211,102,0);
            }
        }

        /* Responsivo */
        
@media (max-width: 560px) {
    .lesson-card,
    .rec-card {
        flex: 0 0 60%;
    }
}

@media (max-width: 720px) {
            .topbar {
                flex-direction: row;
                align-items: flex-start;
                gap: 8px;
            }
            .progress-header {
                grid-template-columns: 1fr;
                align-items: stretch;
            }
            .btn-certificado {
                width: 100%;
            }
            .lesson-card,
            .rec-card {
                flex: 0 0 86%;
            }
            .carousel-arrow {
                width: 34px;
                height: 34px;
            }
        }

        @media (min-width: 1024px) {
            .content {
                max-width: 1180px;
            }
            .lesson-card,
            .rec-card {
                flex: 0 0 320px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="topbar-left">
            <div class="logo-circle">
                <?php if ($logoUrl): ?>
                    <img src="<?= h($logoUrl) ?>" alt="Logo">
                <?php else: ?>
                    <span>4E</span>
                <?php endif; ?>
            </div>
            <div class="course-texts">
                <div class="course-title"><?= h($courseTitle) ?></div>
                <div class="course-sub">
                    Bem-vindo, <?= h($aluno['nome'] ?? 'Aluno') ?>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <a href="logout.php">sair</a>
        </div>
    </header>

    <main class="content">
        <?php if ($liveDataIso && $liveDataBr): ?>
            <div class="live-banner" id="live-banner">
                <div class="live-dot"></div>
                <div class="live-text" id="live-text">
                    <div class="live-line-primary">
                        Sua aula ao vivo será: <strong><?= h($liveDataBr) ?></strong>
                    </div>
                    <div class="live-line-secondary" id="live-countdown-text">
                        Faltam -- horas -- minutos -- segundos
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section class="main-card">
            <div class="progress-header">
                <div>
                    <div class="progress-title">Progresso no treinamento</div>
                    <div class="progress-sub">
                        Você concluiu <?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas obrigatórias (<?= $percent ?>%)
                    </div>
                    <div class="progress-bar-outer">
                        <div class="progress-bar-inner"></div>
                        <span class="progress-bar-label"><?= $percent ?>%</span>
                    </div>
                </div>
                <div style="text-align:right;">
                    <a href="certificado.php"
                       class="btn-certificado <?= $temTudoConcluido ? 'btn-certificado-pulse' : '' ?>">
                        <span class="icon">🎓</span>
                        <span>Emitir Certificado</span>
                    </a>
                </div>
            </div>
        </section>

        <h2 class="section-title">Suas aulas</h2>
        <div class="carousel-wrapper">
            <button type="button"
                    class="carousel-arrow carousel-arrow-left"
                    data-target="lessons-carousel"
                    aria-label="Ver aulas anteriores">
                <span>&lsaquo;</span>
            </button>
            
<section class="lessons-grid" id="lessons-carousel">
        <?php
        $allPrevCompleted = true;
        foreach ($lessons as $idx => $ls):
            $lessonId = (int)($ls['id'] ?? 0);
                    $status = $mapStatus[$lessonId] ?? 'pending';
                    $completed = ($status === 'completed');

            // Regra de desbloqueio:
            // - Primeira aula sempre liberada
            // - Próximas só liberam se TODAS as anteriores estiverem concluídas
            // - Aulas já concluídas continuam acessíveis
            $isUnlocked = ($idx === 0) || $allPrevCompleted || $completed;
            $locked = !$isUnlocked;

            $thumb = $ls['thumb_url'] ?? '';
            $aulaNumero = $idx + 1;
        ?>
            <article class="lesson-card <?= $locked ? 'lesson-locked' : '' ?>" data-locked="<?= $locked ? '1' : '0' ?>">
                <div class="lesson-label">AULA <?= $aulaNumero ?></div>
                <div class="lesson-title"><?= h($ls['titulo']) ?></div>
                <div class="lesson-thumb">
                    <?php if ($thumb): ?>
                        <img src="<?= h($thumb) ?>" alt="">
                    <?php else: ?>
                        Sem imagem
                    <?php endif; ?>

                    <?php if ($locked): ?>
                        <div class="lesson-locked-overlay" aria-hidden="true">
                            <div class="lesson-locked-overlay-icon">🔒</div>
                            <div class="lesson-locked-overlay-text">Conclua a aula anterior para liberar</div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="lesson-footer">
                    <span class="badge <?= $locked ? 'badge-locked' : ($completed ? 'badge-ok' : 'badge-pending') ?>">
                        <?php
                        if ($locked) {
                            echo 'Bloqueada';
                        } else {
                            echo $completed ? 'Concluída ✓' : 'Pendente';
                        }
                        ?>
                    </span>

                    <?php if ($locked): ?>
                        <button class="btn-lesson btn-lesson-locked" type="button" disabled>Bloqueada</button>
                    <?php else: ?>
                        <form method="get" action="aula.php">
                            <input type="hidden" name="id" value="<?= (int)$ls['id'] ?>">
                            <button class="btn-lesson" type="submit">Ir para a aula</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php
            // Após a primeira aula pendente, bloqueia o restante (sequencial).
            $allPrevCompleted = $allPrevCompleted && $completed;
        endforeach;
        ?>
    </section>
            <button type="button"
                    class="carousel-arrow carousel-arrow-right"
                    data-target="lessons-carousel"
                    aria-label="Ver próximas aulas">
                <span>&rsaquo;</span>
            </button>
        </div>

        <?php if ($cursosRec): ?>
            <h2 class="section-title"><?= h($appCfg['paid_courses_title'] ?? 'Conheça nossos cursos pagos') ?></h2>
            <div class="carousel-wrapper">
                <button type="button"
                        class="carousel-arrow carousel-arrow-left"
                        data-target="rec-carousel"
                        aria-label="Ver cursos anteriores">
                    <span>&lsaquo;</span>
                </button>
                <section class="rec-list" id="rec-carousel">
                    <?php foreach ($cursosRec as $c): ?>
                        <article class="rec-card">
                            <div class="rec-thumb">
                                <?php if (!empty($c['thumb_url'])): ?>
                                    <img src="<?= h($c['thumb_url']) ?>" alt="">
                                <?php else: ?>
                                    Sem imagem
                                <?php endif; ?>
                                <div class="rec-locked-overlay">
                                    <div class="rec-locked-overlay-icon">🔒</div>
                                    <div class="rec-locked-overlay-text">Este conteúdo não está disponível nesta área</div>
                                </div>
                            </div>
                            <div class="rec-title"><?= h($c['titulo']) ?></div>
                            <div class="rec-desc"><?= h($c['descricao']) ?></div>
                            <div class="rec-footer">
                                <span class="rec-pill">Curso completo</span>
                                <a href="<?= h($c['checkout_url']) ?>" target="_blank" class="btn-rec">
                                    Ver detalhes
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
                <button type="button"
                        class="carousel-arrow carousel-arrow-right"
                        data-target="rec-carousel"
                        aria-label="Ver próximos cursos">
                    <span>&rsaquo;</span>
                </button>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <?= h($appCfg['footer_text'] ?? 'professoremersonleite.com') ?>
    </footer>
</div>

<?php if ($whatsappHelpUrl): ?>
<a href="<?= h($whatsappHelpUrl) ?>" class="whatsapp-fab"
   target="_blank" rel="noopener noreferrer"
   aria-label="Falar com suporte no WhatsApp">
    <span class="whatsapp-fab-icon" aria-hidden="true">
        <!-- Ícone estilo WhatsApp (bolha verde com telefone branco) -->
        <svg viewBox="0 0 32 32">
            <circle cx="16" cy="16" r="15" fill="#ffffff"/>
            <circle cx="16" cy="16" r="13" fill="#25D366"/>
            <path fill="#ffffff" d="M21.4 18.7l-1.6-1.6a1 1 0 0 0-1.04-.24l-1.15.38c-.72-.4-1.3-.93-1.8-1.64-.28-.4-.5-.83-.66-1.26l.38-1.15a1 1 0 0 0-.24-1.04l-1.6-1.6a1 1 0 0 0-1.41 0l-.86.86c-.47.47-.7 1.14-.6 1.8.14.96.54 2.26 1.62 3.76 1.08 1.5 2.37 2.62 3.33 3.26.62.41 1.45.83 2.19 1.04.64.18 1.32.02 1.8-.45l.86-.86a1 1 0 0 0 0-1.41z"/>
        </svg>
    </span>
</a>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ====== CARROSSEIS: setas esquerda/direita ======
    document.querySelectorAll('.carousel-arrow').forEach(function (btn) {
        var targetId = btn.getAttribute('data-target');
        if (!targetId) return;

        var container = document.getElementById(targetId);
        if (!container) return;

        var dir = btn.classList.contains('carousel-arrow-left') ? -1 : 1;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var amount = Math.max(240, container.clientWidth * 0.9);
            var nextLeft = container.scrollLeft + (dir * amount);

            try {
                container.scrollTo({ left: nextLeft, behavior: 'smooth' });
            } catch (err) {
                container.scrollLeft = nextLeft;
            }
        }, { passive: false });
    });

    // ====== AULAS: card inteiro clicável (respeitando bloqueio) ======
    document.querySelectorAll('.lesson-card').forEach(function (card) {
        var locked = card.getAttribute('data-locked') === '1';

        // Descobre o link da aula (aula.php?id=...)
        var href = null;
        var idInput = card.querySelector('input[name="id"]');
        if (idInput && idInput.value) {
            href = 'aula.php?id=' + encodeURIComponent(idInput.value);
        } else {
            // fallback: se em algum momento virar <a>, pega o href
            var a = card.querySelector('a[href]');
            if (a) href = a.getAttribute('href');
        }

        card.addEventListener('click', function (e) {
            // não intercepta cliques em botões/links/inputs (inclui as setas do carrossel)
            if (e.target.closest('button, a, input, label, select, textarea')) return;

            if (locked) {
                alert('Aula bloqueada. Conclua a aula anterior para liberar.');
                return;
            }
            if (href) {
                window.location.href = href;
            }
        });
    });

    // ====== CURSOS RECOMENDADOS: card inteiro clicável ======
    document.querySelectorAll('.rec-card').forEach(function (card) {
        var link = card.querySelector('a.btn-rec');
        if (!link) link = card.querySelector('a[href]');
        if (!link) return;

        card.addEventListener('click', function (e) {
            if (e.target.closest('button, a, input, label, select, textarea')) return;
            window.location.href = link.href;
        });
    });

});
</script>


<?php if ($liveDataIso): ?>
<script>
(function() {
    const target = new Date("<?= $liveDataIso ?>").getTime();
    const elText  = document.getElementById('live-countdown-text');
    const elBanner = document.getElementById('live-banner');

    function update() {
        const now  = Date.now();
        const diff = target - now;

        if (diff <= 0) {
            if (elBanner) {
                elBanner.style.display = 'none';
            }
            return;
        }

        const totalSeconds = Math.floor(diff / 1000);
        const totalHours   = Math.floor(totalSeconds / 3600);
        const mins         = Math.floor((totalSeconds % 3600) / 60);
        const secs         = totalSeconds % 60;

        if (elText) {
            elText.textContent =
                'Faltam ' +
                totalHours.toString() + ' horas ' +
                mins.toString().padStart(2,'0') + ' minutos ' +
                secs.toString().padStart(2,'0') + ' segundos';
        }
    }

    update();
    setInterval(update, 1000);
})();</script>
<?php endif; ?>
</body>
</html>
