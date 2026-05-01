<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_aluno();
$pdo = getPDO();

$alunoId = (int)($_SESSION['aluno_id'] ?? 0);
if ($alunoId <= 0) {
    header('Location: index.php');
    exit;
}

$alunoNome   = (string)($_SESSION['aluno_nome']   ?? 'Aluno');
$turmaCodigo = (string)($_SESSION['turma_codigo'] ?? '');
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
} catch (Throwable $e) {}

$aluno = ['id' => $alunoId, 'nome' => $alunoNome, 'turma_codigo' => $turmaCodigo];

$cfgStmt = $pdo->query("SELECT * FROM app_config LIMIT 1");
$appCfg  = $cfgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$courseTitle     = $appCfg['course_title'] ?? 'Nome do Curso Exemplo';
$logoUrl         = $appCfg['logo_url']     ?? '';
$whatsappHelpUrl = get_setting('whatsapp_help_url', '');

$stLessons = $pdo->query("SELECT * FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
$lessons   = $stLessons->fetchAll(PDO::FETCH_ASSOC);

$stProg = $pdo->prepare("SELECT lesson_id, status FROM lesson_progress WHERE user_id = :uid");
$stProg->execute(['uid' => $alunoId]);
$rowsProg = $stProg->fetchAll(PDO::FETCH_ASSOC);

$mapStatus = [];
foreach ($rowsProg as $r) {
    $mapStatus[(int)$r['lesson_id']] = $r['status'];
}

$totalObrigatorias = 0;
$totalConcluidas   = 0;
foreach ($lessons as $ls) {
    $obrig = (int)($ls['conta_para_conclusao'] ?? 1);
    if ($obrig === 1) {
        $totalObrigatorias++;
        if (($mapStatus[(int)$ls['id']] ?? 'pending') === 'completed') {
            $totalConcluidas++;
        }
    }
}
$percent          = $totalObrigatorias > 0 ? (int)round(($totalConcluidas / $totalObrigatorias) * 100) : 0;
$temTudoConcluido = ($totalObrigatorias > 0 && $totalObrigatorias === $totalConcluidas);

try {
    $stRec     = $pdo->query("SELECT * FROM recommended_courses WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
    $cursosRec = $stRec->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cursosRec = [];
}

$liveDataIso = null;
$liveDataBr  = null;

if (!empty($turmaLiveAt)) {
    try {
        $dtLive = new DateTime($turmaLiveAt);
        $now    = new DateTime('now');
        if ($dtLive > $now) {
            $liveDataIso = $dtLive->format('Y-m-d H:i:s');
            $liveDataBr  = $dtLive->format('d/m/Y H:i');
        }
    } catch (Throwable $e) {}
}

if (!$liveDataIso && $turmaCodigo !== '') {
    try {
        $stTurma = $pdo->prepare("SELECT * FROM turmas WHERE codigo = :cod LIMIT 1");
        $stTurma->execute(['cod' => $turmaCodigo]);
        $turma = $stTurma->fetch(PDO::FETCH_ASSOC);
        if ($turma && !empty($turma['data_live'])) {
            $dtLive = new DateTime($turma['data_live']);
            $now    = new DateTime('now');
            if ($dtLive > $now) {
                $liveDataIso = $dtLive->format('Y-m-d H:i:s');
                $liveDataBr  = $dtLive->format('d/m/Y H:i');
            }
        }
    } catch (Throwable $e) {}
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title><?= h($courseTitle) ?> — Trilha de Aulas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        <?= theme_inline_css_vars(); ?>

        :root {
            --bg:      var(--bg-main, #07101f);
            --card:    var(--bg-card, #0d1b33);
            --border:  #1a2540;
            --primary: var(--primary, #facc15);
            --text:    var(--text-main, #e2e8f0);
            --muted:   var(--text-muted, #64748b);
            --dim:     #475569;
            --success: #22c55e;
            --danger:  #ef4444;
            --font:    'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --r:       10px;
            --r-lg:    14px;
            --r-xl:    18px;
            --r-full:  999px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* ===== TOPBAR ===== */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: rgba(7,16,31,.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 16px;
            height: 58px;
        }
        .topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .logo-box {
            width: 36px; height: 36px;
            border-radius: var(--r);
            background: rgba(250,204,21,.08);
            border: 1px solid rgba(250,204,21,.15);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; flex-shrink: 0; color: var(--primary);
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; }
        .logo-box svg { width: 18px; height: 18px; }
        .course-name {
            font-size: 14px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 220px;
        }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .tb-user { font-size: 12px; color: var(--muted); }
        .tb-user strong { color: var(--text); }
        .tb-logout {
            font-size: 12px; color: var(--muted);
            display: flex; align-items: center; gap: 4px;
            padding: 5px 10px;
            border-radius: var(--r-full);
            border: 1px solid var(--border);
            transition: background .15s, color .15s;
        }
        .tb-logout:hover { background: rgba(255,255,255,.05); color: var(--text); }
        .tb-logout svg { width: 13px; height: 13px; }
        @media (max-width: 480px) { .tb-user { display: none; } }

        /* ===== PAGE CONTENT ===== */
        .page { max-width: 1080px; margin: 0 auto; padding: 16px 16px 48px; }

        /* ===== LIVE BANNER ===== */
        .live-banner {
            display: flex; align-items: center; gap: 10px;
            background: #7f1d1d;
            border: 1px solid #991b1b;
            border-radius: var(--r-lg);
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 13px; color: #fecaca;
        }
        .live-dot {
            width: 9px; height: 9px;
            border-radius: var(--r-full);
            background: #fca5a5;
            flex-shrink: 0;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%   { transform: scale(1);   box-shadow: 0 0 0 0 rgba(252,165,165,.7); }
            70%  { transform: scale(1.5); box-shadow: 0 0 0 8px rgba(252,165,165,0); }
            100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(252,165,165,0); }
        }

        /* ===== PROGRESS CARD ===== */
        .progress-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 20px 22px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
        }
        @media (max-width: 600px) {
            .progress-card { grid-template-columns: 1fr; }
            .cert-btn { width: 100%; justify-content: center; }
        }
        .progress-label {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted); margin-bottom: 4px;
        }
        .progress-title { font-size: 16px; font-weight: 700; margin-bottom: 2px; }
        .progress-sub   { font-size: 12px; color: var(--muted); margin-bottom: 10px; }
        .progress-bar-outer {
            height: 8px; border-radius: var(--r-full);
            background: rgba(255,255,255,.06); overflow: hidden;
        }
        .progress-bar-inner {
            height: 100%; border-radius: var(--r-full);
            background: var(--primary);
            width: <?= $percent ?>%;
            transition: width .5s ease;
        }
        .cert-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 20px; border-radius: var(--r-xl);
            background: var(--primary); color: #111827;
            font-weight: 700; font-size: 14px;
            border: none; cursor: pointer; white-space: nowrap;
            transition: filter .15s;
            text-decoration: none;
        }
        .cert-btn:hover { filter: brightness(1.07); text-decoration: none; }
        .cert-btn-pulse { animation: pulseCert 1.8s infinite; }
        @keyframes pulseCert {
            0%   { box-shadow: 0 0 0 0 rgba(250,204,21,.45); }
            70%  { box-shadow: 0 0 0 14px rgba(250,204,21,0); }
            100% { box-shadow: 0 0 0 0 rgba(250,204,21,0); }
        }

        /* ===== SECTION HEADING ===== */
        .section-heading {
            font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted);
            display: flex; align-items: center; gap: 10px;
            margin: 22px 0 12px;
        }
        .section-heading::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        /* ===== CAROUSEL ===== */
        .carousel-wrap { position: relative; }
        .carousel-track {
            display: flex; gap: 12px;
            overflow-x: auto; scroll-snap-type: x mandatory;
            scroll-behavior: smooth; padding: 4px 2px 12px;
        }
        .carousel-track::-webkit-scrollbar { height: 4px; }
        .carousel-track::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,.1); border-radius: var(--r-full);
        }
        .carousel-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 36px; height: 36px; border-radius: var(--r-full);
            background: var(--card); border: 1px solid var(--border);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 10; box-shadow: 0 4px 16px rgba(0,0,0,.5);
            transition: background .15s;
        }
        .carousel-arrow:hover { background: rgba(255,255,255,.08); }
        .carousel-arrow svg { width: 16px; height: 16px; }
        .carousel-arrow-left  { left: -8px; }
        .carousel-arrow-right { right: -8px; }

        /* ===== LESSON CARD ===== */
        .lesson-card {
            flex: 0 0 calc(50% - 6px);
            max-width: 320px; min-width: 220px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            display: flex; flex-direction: column;
            overflow: hidden; cursor: pointer;
            scroll-snap-align: start;
            transition: border-color .15s, box-shadow .15s;
        }
        .lesson-card:hover { border-color: rgba(250,204,21,.25); box-shadow: 0 8px 30px rgba(0,0,0,.4); }
        .lesson-card.locked { opacity: .7; cursor: not-allowed; }
        .lesson-card.locked:hover { border-color: var(--border); box-shadow: none; }

        .card-thumb {
            position: relative; width: 100%;
            background: #0a1628;
        }
        .card-thumb::before { content: ''; display: block; padding-top: 56.25%; }
        .card-thumb img {
            position: absolute; inset: 0;
            width: 100%; height: 100%; object-fit: cover;
        }
        .thumb-placeholder {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: var(--dim);
        }
        .thumb-placeholder svg { width: 32px; height: 32px; opacity: .4; }
        .lock-overlay {
            position: absolute; inset: 0;
            background: rgba(7,16,31,.7);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; color: var(--text);
        }
        .lock-overlay svg { width: 24px; height: 24px; opacity: .8; }
        .lock-overlay span { font-size: 11px; font-weight: 500; color: var(--muted); text-align: center; padding: 0 12px; }

        .card-body { padding: 12px 14px 14px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
        .card-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); }
        .card-title-text { font-size: 14px; font-weight: 600; flex: 1; line-height: 1.4; }
        .card-footer-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: auto; }

        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: var(--r-full); font-size: 11px; font-weight: 500;
        }
        .badge-done    { background: rgba(34,197,94,.12);  color: #86efac; }
        .badge-pending { background: rgba(100,116,139,.15); color: var(--muted); }
        .badge-locked  { background: rgba(239,68,68,.1);   color: #fca5a5; }

        .btn-go {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 13px; border-radius: var(--r-full);
            border: none; background: var(--primary); color: #111827;
            font-size: 12px; font-weight: 700; font-family: var(--font);
            cursor: pointer; white-space: nowrap;
            transition: filter .15s;
        }
        .btn-go:hover { filter: brightness(1.07); }
        .btn-go:disabled { background: rgba(255,255,255,.1); color: var(--dim); cursor: not-allowed; filter: none; }

        /* ===== REC CARD ===== */
        .rec-card {
            flex: 0 0 calc(50% - 6px);
            max-width: 320px; min-width: 220px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            overflow: hidden; cursor: pointer;
            scroll-snap-align: start;
            display: flex; flex-direction: column;
            transition: border-color .15s, box-shadow .15s;
        }
        .rec-card:hover { border-color: rgba(250,204,21,.25); box-shadow: 0 8px 30px rgba(0,0,0,.4); }
        .rec-body { padding: 12px 14px 14px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .rec-title { font-size: 14px; font-weight: 600; }
        .rec-desc  { font-size: 12px; color: var(--muted); line-height: 1.5; flex: 1; }
        .rec-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 8px; }

        /* ===== FOOTER ===== */
        .page-footer { text-align: center; padding: 16px; font-size: 11px; color: var(--dim); }

        /* ===== WHATSAPP FAB ===== */
        .whatsapp-fab {
            position: fixed; right: 16px; bottom: 20px;
            width: 56px; height: 56px; border-radius: var(--r-full);
            background: #25D366; color: #fff;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 20px rgba(37,211,102,.4);
            z-index: 40; text-decoration: none;
            animation: waPulse 2s infinite;
        }
        .whatsapp-fab svg { width: 30px; height: 30px; }
        @keyframes waPulse {
            0%   { box-shadow: 0 0 0 0 rgba(37,211,102,.5); }
            70%  { box-shadow: 0 0 0 14px rgba(37,211,102,0); }
            100% { box-shadow: 0 0 0 0 rgba(37,211,102,0); }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 600px) {
            .lesson-card, .rec-card { flex: 0 0 85%; max-width: none; }
            .carousel-arrow { display: none; }
        }
        @media (min-width: 900px) {
            .lesson-card, .rec-card { flex: 0 0 260px; max-width: 260px; }
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
    <div class="topbar-left">
        <div class="logo-box">
            <?php if ($logoUrl): ?>
                <img src="<?= h($logoUrl) ?>" alt="Logo">
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
                </svg>
            <?php endif; ?>
        </div>
        <span class="course-name"><?= h($courseTitle) ?></span>
    </div>
    <div class="topbar-right">
        <span class="tb-user">Olá, <strong><?= h($aluno['nome']) ?></strong></span>
        <a href="logout.php" class="tb-logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sair
        </a>
    </div>
</header>

<main class="page">

    <!-- LIVE BANNER -->
    <?php if ($liveDataIso && $liveDataBr): ?>
    <div class="live-banner" id="live-banner">
        <div class="live-dot"></div>
        <div style="flex:1">
            <strong>Aula ao vivo:</strong> <?= h($liveDataBr) ?>
            <div id="live-countdown" style="font-size:12px;margin-top:2px;color:#fca5a5"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- PROGRESS CARD -->
    <div class="progress-card">
        <div>
            <div class="progress-label">Seu progresso</div>
            <div class="progress-title"><?= $percent ?>% concluído</div>
            <div class="progress-sub"><?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas obrigatórias</div>
            <div class="progress-bar-outer">
                <div class="progress-bar-inner"></div>
            </div>
        </div>
        <a href="certificado.php" class="cert-btn <?= $temTudoConcluido ? 'cert-btn-pulse' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px">
                <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
            </svg>
            Emitir Certificado
        </a>
    </div>

    <!-- LESSONS -->
    <div class="section-heading">Suas aulas</div>

    <div class="carousel-wrap">
        <button type="button" class="carousel-arrow carousel-arrow-left" data-target="lessons-carousel" aria-label="Anterior">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>

        <div class="carousel-track" id="lessons-carousel">
        <?php
        $allPrevCompleted = true;
        foreach ($lessons as $idx => $ls):
            $lessonId  = (int)$ls['id'];
            $status    = $mapStatus[$lessonId] ?? 'pending';
            $completed = ($status === 'completed');
            $isUnlocked = ($idx === 0) || $allPrevCompleted || $completed;
            $locked     = !$isUnlocked;
            $thumb      = $ls['thumb_url'] ?? '';
        ?>
        <article class="lesson-card <?= $locked ? 'locked' : '' ?>" data-locked="<?= $locked ? '1' : '0' ?>">
            <div class="card-thumb">
                <?php if ($thumb): ?>
                    <img src="<?= h($thumb) ?>" alt="">
                <?php else: ?>
                    <div class="thumb-placeholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <?php if ($locked): ?>
                <div class="lock-overlay">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    <span>Conclua a aula anterior</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="card-label">Aula <?= $idx + 1 ?></div>
                <div class="card-title-text"><?= h($ls['titulo']) ?></div>
                <div class="card-footer-row">
                    <span class="badge <?= $locked ? 'badge-locked' : ($completed ? 'badge-done' : 'badge-pending') ?>">
                        <?= $locked ? 'Bloqueada' : ($completed ? '✓ Concluída' : 'Pendente') ?>
                    </span>
                    <?php if ($locked): ?>
                        <button type="button" class="btn-go" disabled>Bloqueada</button>
                    <?php else: ?>
                        <form method="get" action="aula.php" style="display:inline">
                            <input type="hidden" name="id" value="<?= $lessonId ?>">
                            <button type="submit" class="btn-go">
                                Assistir
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:11px;height:11px"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
            $allPrevCompleted = $allPrevCompleted && $completed;
        endforeach;
        ?>
        </div>

        <button type="button" class="carousel-arrow carousel-arrow-right" data-target="lessons-carousel" aria-label="Próximo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

    <!-- RECOMMENDED COURSES -->
    <?php if ($cursosRec): ?>
    <div class="section-heading"><?= h($appCfg['paid_courses_title'] ?? 'Conheça nossos cursos') ?></div>

    <div class="carousel-wrap">
        <button type="button" class="carousel-arrow carousel-arrow-left" data-target="rec-carousel" aria-label="Anterior">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>

        <div class="carousel-track" id="rec-carousel">
        <?php foreach ($cursosRec as $c): ?>
            <article class="rec-card">
                <div class="card-thumb">
                    <?php if (!empty($c['thumb_url'])): ?>
                        <img src="<?= h($c['thumb_url']) ?>" alt="">
                    <?php else: ?>
                        <div class="thumb-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="lock-overlay">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                        <span>Conteúdo exclusivo</span>
                    </div>
                </div>
                <div class="rec-body">
                    <div class="rec-title"><?= h($c['titulo']) ?></div>
                    <div class="rec-desc"><?= h($c['descricao']) ?></div>
                    <div class="rec-footer">
                        <span class="badge badge-pending">Curso completo</span>
                        <a href="<?= h($c['checkout_url']) ?>" target="_blank" class="btn-go">
                            Ver detalhes
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:11px;height:11px"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

        <button type="button" class="carousel-arrow carousel-arrow-right" data-target="rec-carousel" aria-label="Próximo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
    <?php endif; ?>

</main>

<footer class="page-footer">
    <?= h($appCfg['footer_text'] ?? 'professoremersonleite.com') ?>
</footer>

<?php if ($whatsappHelpUrl): ?>
<a href="<?= h($whatsappHelpUrl) ?>" class="whatsapp-fab" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
    <svg viewBox="0 0 32 32" fill="none">
        <circle cx="16" cy="16" r="16" fill="#25D366"/>
        <path fill="#fff" d="M21.4 18.7l-1.6-1.6a1 1 0 0 0-1.04-.24l-1.15.38c-.72-.4-1.3-.93-1.8-1.64-.28-.4-.5-.83-.66-1.26l.38-1.15a1 1 0 0 0-.24-1.04l-1.6-1.6a1 1 0 0 0-1.41 0l-.86.86c-.47.47-.7 1.14-.6 1.8.14.96.54 2.26 1.62 3.76 1.08 1.5 2.37 2.62 3.33 3.26.62.41 1.45.83 2.19 1.04.64.18 1.32.02 1.8-.45l.86-.86a1 1 0 0 0 0-1.41z"/>
    </svg>
</a>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Carousels
    document.querySelectorAll('.carousel-arrow').forEach(function (btn) {
        var id  = btn.getAttribute('data-target');
        var el  = id ? document.getElementById(id) : null;
        if (!el) return;
        var dir = btn.classList.contains('carousel-arrow-left') ? -1 : 1;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var amt = Math.max(240, el.clientWidth * 0.85);
            try { el.scrollTo({ left: el.scrollLeft + dir * amt, behavior: 'smooth' }); }
            catch(err) { el.scrollLeft += dir * amt; }
        });
    });

    // Lesson card click
    document.querySelectorAll('.lesson-card').forEach(function (card) {
        var locked = card.getAttribute('data-locked') === '1';
        var idInput = card.querySelector('input[name="id"]');
        var href = idInput ? 'aula.php?id=' + encodeURIComponent(idInput.value) : null;
        card.addEventListener('click', function (e) {
            if (e.target.closest('button, a, input, label')) return;
            if (locked) { alert('Aula bloqueada. Conclua a aula anterior para liberar.'); return; }
            if (href) window.location.href = href;
        });
    });

    // Rec card click
    document.querySelectorAll('.rec-card').forEach(function (card) {
        var link = card.querySelector('a');
        if (!link) return;
        card.addEventListener('click', function (e) {
            if (e.target.closest('button, a, input, label')) return;
            window.location.href = link.href;
        });
    });
});
</script>

<?php if ($liveDataIso): ?>
<script>
(function() {
    var target  = new Date("<?= $liveDataIso ?>").getTime();
    var elText  = document.getElementById('live-countdown');
    var elBanner = document.getElementById('live-banner');
    function update() {
        var diff = target - Date.now();
        if (diff <= 0) { if (elBanner) elBanner.style.display = 'none'; return; }
        var h = Math.floor(diff / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        if (elText) elText.textContent = 'Faltam ' + h + 'h ' + String(m).padStart(2,'0') + 'min ' + String(s).padStart(2,'0') + 's';
    }
    update();
    setInterval(update, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
