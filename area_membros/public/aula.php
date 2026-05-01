<?php
// FILE: public/aula.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_aluno();
$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function youtube_embed_src(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (strpos($value, 'http') !== 0 && strpos($value, '<') === false) {
        return 'https://www.youtube.com/embed/' . $value;
    }
    if (stripos($value, 'youtube.com/embed') !== false) return $value;
    if (strpos($value, 'http') === 0) {
        $parts = parse_url($value);
        $host  = $parts['host'] ?? '';
        $path  = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';
        if (stripos($host, 'youtu.be') !== false) return 'https://www.youtube.com/embed/' . ltrim((string)$path, '/');
        if (stripos($host, 'youtube.com') !== false) {
            if (!empty($query)) { parse_str($query, $q); if (!empty($q['v'])) return 'https://www.youtube.com/embed/' . $q['v']; }
            if (!empty($path) && strpos($path, '/embed/') !== false) return 'https://www.youtube.com/embed/' . substr($path, strpos($path, '/embed/') + 7);
        }
    }
    return $value;
}

$userId = (int)($_SESSION['aluno_id'] ?? 0);

$stUser = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stUser->execute(['id' => $userId]);
$user = $stUser->fetch();
if (!$user) { header('Location: login.php'); exit; }

$stCfg = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg = $stCfg->fetch() ?: [];

$primary         = $appCfg['primary_color']    ?? '#facc15';
$secondary       = $appCfg['secondary_color']  ?? '#22c55e';
$bgColor         = $appCfg['background_color'] ?? '#07101f';
$courseTitle     = $appCfg['course_title']     ?? 'Trilha de Aulas';
$logoUrl         = $appCfg['logo_url']         ?? '';
$whatsappHelpUrl = get_setting('whatsapp_help_url', '');

$lessonSlug = $_GET['slug'] ?? '';
$lessonId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lesson     = null;

if ($lessonId > 0) {
    $st = $pdo->prepare("SELECT * FROM lessons WHERE id = :id LIMIT 1");
    $st->execute(['id' => $lessonId]);
    $lesson = $st->fetch();
} elseif ($lessonSlug !== '') {
    $st = $pdo->prepare("SELECT * FROM lessons WHERE slug = :slug LIMIT 1");
    $st->execute(['slug' => $lessonSlug]);
    $lesson = $st->fetch();
}

if (!$lesson) { header('Location: trilha.php'); exit; }

$lessonId    = (int)$lesson['id'];
$lessonSlug  = $lesson['slug'] ?? '';
$lessonTitle = $lesson['titulo'] ?? '';
$lessonOrder = (int)($lesson['ordem'] ?? 0);
$videoType   = $lesson['video_type'] ?? 'youtube';
$videoUrl    = trim((string)($lesson['video_url'] ?? ''));
$htmlExtra   = $lesson['html_extra'] ?? '';

if ($videoType === 'html') $videoType = 'embed';

$hasEmbedTags = false;
if ($videoUrl !== '') {
    $lower = strtolower($videoUrl);
    if (strpos($lower, '<iframe') !== false || strpos($lower, '<script') !== false) {
        $hasEmbedTags = true;
        $videoType    = 'embed';
    }
}

$stLessons = $pdo->query("SELECT id, titulo, slug, ordem, thumb_url, conta_para_conclusao, ativo FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
$lessons   = $stLessons->fetchAll();

$stProg = $pdo->prepare("SELECT lesson_id, status FROM lesson_progress WHERE user_id = :uid");
$stProg->execute(['uid' => $userId]);
$progressRows = $stProg->fetchAll();

$progressMap = [];
foreach ($progressRows as $row) {
    $progressMap[(int)$row['lesson_id']] = $row;
}

$unlockMap      = [];
$lessonById     = [];
$allPrevDone    = true;
$firstPendingId = null;

foreach ($lessons as $ls) {
    $lsId = (int)$ls['id'];
    $lessonById[$lsId] = $ls;
    $isDone = isset($progressMap[$lsId]) && (($progressMap[$lsId]['status'] ?? '') === 'completed');
    $unlockMap[$lsId] = $allPrevDone;
    if (!$isDone && $firstPendingId === null) $firstPendingId = $lsId;
    $allPrevDone = $allPrevDone && $isDone;
}

if (!($unlockMap[$lessonId] ?? false)) {
    $dest = 'trilha.php';
    if ($firstPendingId && !empty($lessonById[$firstPendingId]['slug'])) {
        $dest = 'aula.php?slug=' . urlencode((string)$lessonById[$firstPendingId]['slug']);
    }
    header('Location: ' . $dest);
    exit;
}

$totalObrigatorias = 0;
$totalConcluidas   = 0;
$maxOrdemConcluida = 0;

foreach ($lessons as $ls) {
    if ((int)$ls['conta_para_conclusao'] === 1) {
        $totalObrigatorias++;
        $lsId = (int)$ls['id'];
        $isCompleted = isset($progressMap[$lsId]) && $progressMap[$lsId]['status'] === 'completed';
        if ($isCompleted) {
            $totalConcluidas++;
            $maxOrdemConcluida = max($maxOrdemConcluida, (int)$ls['ordem']);
        }
    }
}

if ($totalObrigatorias <= 0 || $totalConcluidas >= $totalObrigatorias) {
    $ordemLiberadaMax = PHP_INT_MAX;
} else {
    $ordemLiberadaMax = max(1, $maxOrdemConcluida + 1);
}
$percent = $totalObrigatorias > 0 ? (int)round(100 * $totalConcluidas / $totalObrigatorias) : 0;

$recommendedCourses = [];
try {
    $stRec = $pdo->query("SELECT * FROM recommended_courses WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
    if ($stRec) $recommendedCourses = $stRec->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$isCurrentCompleted = isset($progressMap[$lessonId]) && $progressMap[$lessonId]['status'] === 'completed';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title><?= h($lessonTitle) ?> — <?= h($courseTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:      <?= h($bgColor) ?>;
            --card:    #0d1b33;
            --border:  #1a2540;
            --primary: <?= h($primary) ?>;
            --success: <?= h($secondary) ?>;
            --text:    #e2e8f0;
            --muted:   #64748b;
            --dim:     #475569;
            --font:    'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --r:       10px;
            --r-lg:    14px;
            --r-xl:    18px;
            --r-full:  999px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
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
            background: rgba(7,16,31,.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 16px; height: 56px; gap: 12px;
        }
        .topbar-left { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
        .logo-box {
            width: 34px; height: 34px; border-radius: var(--r);
            background: rgba(250,204,21,.08); border: 1px solid rgba(250,204,21,.15);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; flex-shrink: 0; color: var(--primary);
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; }
        .logo-box svg { width: 17px; height: 17px; }
        .course-info { min-width: 0; }
        .course-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
        .lesson-sub  { font-size: 11px; color: var(--muted); }
        .btn-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: var(--r-full);
            border: 1px solid var(--border); background: transparent;
            color: var(--muted); font-size: 12px; font-weight: 500; font-family: var(--font);
            cursor: pointer; flex-shrink: 0;
            transition: background .15s, color .15s;
        }
        .btn-back:hover { background: rgba(255,255,255,.06); color: var(--text); }
        .btn-back svg { width: 13px; height: 13px; }

        /* ===== PAGE ===== */
        .page { max-width: 1060px; margin: 0 auto; padding: 16px 16px 48px; }

        /* ===== PROGRESS ===== */
        .progress-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 14px 18px;
            margin-bottom: 16px;
        }
        .progress-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
        .progress-label { font-size: 12px; font-weight: 500; color: var(--muted); }
        .progress-pct   { font-size: 12px; font-weight: 700; color: var(--primary); }
        .progress-bar-outer {
            height: 6px; border-radius: var(--r-full);
            background: rgba(255,255,255,.06); overflow: hidden;
        }
        .progress-bar-inner {
            height: 100%; border-radius: var(--r-full);
            background: var(--primary);
            width: <?= $percent ?>%;
            transition: width .5s ease;
        }

        /* ===== LESSON WRAPPER ===== */
        .lesson-wrapper {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .lesson-header-bar {
            padding: 14px 18px 0;
        }
        .lesson-tag   { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 4px; }
        .lesson-title { font-size: 18px; font-weight: 700; }

        /* VIDEO */
        .video-wrapper { background: #000; border-top: 1px solid var(--border); margin-top: 14px; }
        .video-inner {
            position: relative; padding-top: 56.25%;
        }
        .video-inner iframe {
            position: absolute; inset: 0;
            width: 100%; height: 100%; border: 0;
        }
        .video-embed-raw { line-height: 0; }
        .video-placeholder {
            padding: 50px 20px; text-align: center;
            font-size: 14px; color: var(--muted);
        }

        /* ACTIONS */
        .lesson-actions {
            padding: 14px 18px;
            display: flex; align-items: center; justify-content: flex-end;
        }
        .btn-done {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 18px; border-radius: var(--r-full);
            font-size: 13px; font-weight: 700; font-family: var(--font);
            border: none; cursor: pointer;
            transition: filter .15s;
        }
        .btn-done svg { width: 15px; height: 15px; }
        .btn-done.pending { background: var(--primary); color: #111827; }
        .btn-done.pending:hover { filter: brightness(1.07); }
        .btn-done.done { background: rgba(34,197,94,.15); color: #86efac; cursor: default; border: 1px solid rgba(34,197,94,.25); }

        /* ===== SECTION HEADING ===== */
        .section-heading {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted);
            display: flex; align-items: center; gap: 10px;
            margin: 20px 0 12px;
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
        .carousel-track::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: var(--r-full); }
        .carousel-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 34px; height: 34px; border-radius: var(--r-full);
            background: var(--card); border: 1px solid var(--border);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 10;
            box-shadow: 0 4px 14px rgba(0,0,0,.5);
        }
        .carousel-arrow svg { width: 14px; height: 14px; }
        .carousel-arrow.left  { left: -8px; }
        .carousel-arrow.right { right: -8px; }

        /* Lesson mini-card in carousel */
        .lesson-card {
            flex: 0 0 230px; max-width: 230px;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            overflow: hidden; cursor: pointer;
            scroll-snap-align: start;
            display: flex; flex-direction: column;
            transition: border-color .15s;
        }
        .lesson-card:hover { border-color: rgba(250,204,21,.25); }
        .lesson-card.current { border-color: var(--primary); }
        .lesson-card.locked  { opacity: .6; cursor: not-allowed; }
        .lesson-card.locked:hover { border-color: var(--border); }

        .lc-thumb {
            position: relative; width: 100%; background: #0a1628;
        }
        .lc-thumb::before { content: ''; display: block; padding-top: 56.25%; }
        .lc-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .lc-thumb-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
        .lc-thumb-placeholder svg { width: 22px; height: 22px; opacity: .3; }
        .lc-lock {
            position: absolute; inset: 0;
            background: linear-gradient(to bottom, rgba(0,0,0,.5), rgba(0,0,0,.8));
            display: flex; align-items: center; justify-content: center;
            color: var(--primary);
        }
        .lc-lock svg { width: 20px; height: 20px; }

        .lc-body { padding: 9px 12px 10px; display: flex; flex-direction: column; gap: 6px; }
        .lc-tag    { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); }
        .lc-title  { font-size: 12px; font-weight: 600; line-height: 1.35; }
        .lc-footer { display: flex; align-items: center; justify-content: space-between; gap: 6px; }

        .status-pill {
            font-size: 10.5px; padding: 2px 7px; border-radius: var(--r-full);
        }
        .status-ok      { background: rgba(34,197,94,.12); color: #86efac; }
        .status-pending { background: rgba(100,116,139,.15); color: var(--muted); }

        .btn-lesson {
            border: none; border-radius: var(--r-full);
            padding: 5px 10px; font-size: 11px; font-weight: 700;
            background: var(--primary); color: #111827;
            cursor: pointer; font-family: var(--font);
            transition: filter .15s;
        }
        .btn-lesson:hover { filter: brightness(1.07); }
        .btn-lesson.disabled { background: rgba(255,255,255,.08); color: var(--dim); cursor: not-allowed; filter: none; }

        /* Extra content */
        .extra-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 16px 18px;
            margin-bottom: 16px;
            font-size: 13px; line-height: 1.6;
        }
        .extra-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin-bottom: 10px; }

        /* Rec card */
        .rec-card {
            flex: 0 0 240px; max-width: 240px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            overflow: hidden; scroll-snap-align: start;
            display: flex; flex-direction: column;
        }
        .rec-thumb { position: relative; width: 100%; background: #0a1628; }
        .rec-thumb::before { content: ''; display: block; padding-top: 56.25%; }
        .rec-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .rec-body  { padding: 10px 13px 12px; flex: 1; display: flex; flex-direction: column; gap: 5px; }
        .rec-title { font-size: 13px; font-weight: 600; }
        .rec-desc  { font-size: 11px; color: var(--muted); flex: 1; line-height: 1.4; }
        .rec-footer { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 8px; }
        .rec-badge { font-size: 10px; padding: 2px 7px; border-radius: var(--r-full); background: rgba(100,116,139,.15); color: var(--muted); }
        .rec-cta {
            font-size: 11px; font-weight: 700; color: #111827;
            background: var(--primary); border-radius: var(--r-full);
            padding: 5px 10px; white-space: nowrap;
        }

        /* WhatsApp FAB */
        .whatsapp-fab {
            position: fixed; right: 16px; bottom: 20px;
            width: 54px; height: 54px; border-radius: var(--r-full);
            background: #25D366; display: flex; align-items: center; justify-content: center;
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

        @media (max-width: 600px) {
            .lesson-card { flex: 0 0 85%; max-width: none; }
            .rec-card    { flex: 0 0 75%; max-width: none; }
            .carousel-arrow { display: none; }
            .course-name { max-width: 140px; }
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
        <div class="course-info">
            <div class="course-name"><?= h($courseTitle) ?></div>
            <div class="lesson-sub">Assistindo: <?= h($lessonTitle) ?></div>
        </div>
    </div>
    <button type="button" class="btn-back" onclick="window.location.href='trilha.php'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Trilha
    </button>
</header>

<div class="page">

    <!-- PROGRESS -->
    <div class="progress-card">
        <div class="progress-row">
            <span class="progress-label"><?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas concluídas</span>
            <span class="progress-pct"><?= $percent ?>%</span>
        </div>
        <div class="progress-bar-outer">
            <div class="progress-bar-inner"></div>
        </div>
    </div>

    <!-- LESSON -->
    <div class="lesson-wrapper">
        <div class="lesson-header-bar">
            <div class="lesson-tag">Aula <?= $lessonOrder ?></div>
            <div class="lesson-title"><?= h($lessonTitle) ?></div>
        </div>

        <div class="video-wrapper">
            <?php if ($hasEmbedTags && !empty($videoUrl)): ?>
                <div class="video-embed-raw"><?= $videoUrl ?></div>
            <?php elseif ($videoType === 'embed' && !empty($videoUrl)): ?>
                <div class="video-embed-raw"><?= $videoUrl ?></div>
            <?php elseif ($videoType === 'youtube' && !empty($videoUrl)): ?>
                <div class="video-inner">
                    <iframe src="<?= h(youtube_embed_src($videoUrl)) ?>"
                            title="YouTube video player"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen></iframe>
                </div>
            <?php else: ?>
                <div class="video-placeholder">Nenhum vídeo configurado para esta aula.</div>
            <?php endif; ?>
        </div>

        <div class="lesson-actions">
            <?php if ($isCurrentCompleted): ?>
                <button type="button" class="btn-done done" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Aula concluída
                </button>
            <?php else: ?>
                <button type="button" class="btn-done pending" id="btn-concluir-aula"
                        data-lesson-id="<?= $lessonId ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Marcar como concluída
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- EXTRA CONTENT -->
    <?php if (!empty(trim($htmlExtra))): ?>
    <div class="extra-box">
        <div class="extra-title">Conteúdo extra</div>
        <div><?= $htmlExtra ?></div>
    </div>
    <?php endif; ?>

    <!-- LESSONS CAROUSEL -->
    <?php if ($lessons): ?>
    <div class="section-heading">Todas as aulas</div>
    <div class="carousel-wrap">
        <button type="button" class="carousel-arrow left" data-dir="-1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="carousel-track">
        <?php foreach ($lessons as $ls):
            $lsId        = (int)$ls['id'];
            $slug        = $ls['slug'];
            $titulo      = $ls['titulo'];
            $ordem       = (int)$ls['ordem'];
            $thumb       = $ls['thumb_url'] ?? '';
            $isCompleted = isset($progressMap[$lsId]) && $progressMap[$lsId]['status'] === 'completed';
            $isLocked    = !($unlockMap[$lsId] ?? false);
            $linkAula    = 'aula.php?slug=' . urlencode($slug);
            $isCurrent   = ($lsId === $lessonId);
        ?>
            <div class="lesson-card <?= $isLocked ? 'locked' : '' ?> <?= $isCurrent ? 'current' : '' ?>"
                 data-link="<?= h($linkAula) ?>">
                <div class="lc-thumb">
                    <?php if ($thumb): ?>
                        <img src="<?= h($thumb) ?>" alt="">
                    <?php else: ?>
                        <div class="lc-thumb-placeholder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                        </div>
                    <?php endif; ?>
                    <?php if ($isLocked): ?>
                        <div class="lc-lock">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="lc-body">
                    <div class="lc-tag">Aula <?= $ordem ?></div>
                    <div class="lc-title"><?= h($titulo) ?></div>
                    <div class="lc-footer">
                        <?php if ($isCompleted): ?>
                            <span class="status-pill status-ok">Concluída ✓</span>
                        <?php else: ?>
                            <span class="status-pill status-pending"><?= $isLocked ? 'Bloqueada' : 'Pendente' ?></span>
                        <?php endif; ?>
                        <?php if ($isLocked): ?>
                            <button type="button" class="btn-lesson disabled no-card-nav" data-link="<?= h($linkAula) ?>">Bloqueada</button>
                        <?php else: ?>
                            <button type="button" class="btn-lesson no-card-nav" data-link="<?= h($linkAula) ?>">Assistir</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="carousel-arrow right" data-dir="1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <!-- RECOMMENDED COURSES -->
    <?php if (!empty($recommendedCourses)): ?>
    <div class="section-heading">Conheça nossos cursos</div>
    <div class="carousel-wrap">
        <button type="button" class="carousel-arrow left" data-dir="-1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="carousel-track" id="rec-track">
        <?php foreach ($recommendedCourses as $rc): ?>
            <a href="<?= h($rc['checkout_url']) ?>" target="_blank" class="rec-card">
                <div class="rec-thumb">
                    <?php if (!empty($rc['thumb_url'])): ?>
                        <img src="<?= h($rc['thumb_url']) ?>" alt="">
                    <?php endif; ?>
                </div>
                <div class="rec-body">
                    <div class="rec-title"><?= h($rc['titulo']) ?></div>
                    <?php if (!empty($rc['descricao'])): ?>
                        <div class="rec-desc"><?= nl2br(h($rc['descricao'])) ?></div>
                    <?php endif; ?>
                    <div class="rec-footer">
                        <span class="rec-badge">Curso completo</span>
                        <span class="rec-cta">Ver detalhes</span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
        <button type="button" class="carousel-arrow right" data-dir="1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
    <?php endif; ?>

</div>

<?php if ($whatsappHelpUrl): ?>
<a href="<?= h($whatsappHelpUrl) ?>" class="whatsapp-fab" data-help-fab="1"
   target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
    <svg viewBox="0 0 32 32" fill="none">
        <circle cx="16" cy="16" r="16" fill="#25D366"/>
        <path fill="#fff" d="M21.4 18.7l-1.6-1.6a1 1 0 0 0-1.04-.24l-1.15.38c-.72-.4-1.3-.93-1.8-1.64-.28-.4-.5-.83-.66-1.26l.38-1.15a1 1 0 0 0-.24-1.04l-1.6-1.6a1 1 0 0 0-1.41 0l-.86.86c-.47.47-.7 1.14-.6 1.8.14.96.54 2.26 1.62 3.76 1.08 1.5 2.37 2.62 3.33 3.26.62.41 1.45.83 2.19 1.04.64.18 1.32.02 1.8-.45l.86-.86a1 1 0 0 0 0-1.41z"/>
    </svg>
</a>
<?php endif; ?>

<script>
// ===== "Marcar como concluída" =====
(function(){
    var btn = document.getElementById('btn-concluir-aula');
    if (!btn) return;

    function unlockNextLessonCard() {
        var current = document.querySelector('.lesson-card.current');
        if (!current) return;

        var pillCur = current.querySelector('.lc-footer .status-pill');
        if (pillCur) { pillCur.textContent = 'Concluída ✓'; pillCur.className = 'status-pill status-ok'; }
        var btnCur = current.querySelector('.lc-footer .btn-lesson');
        if (btnCur) { btnCur.textContent = 'Assistir'; btnCur.classList.remove('disabled'); }

        var next = current.nextElementSibling;
        while (next && !next.classList.contains('lesson-card')) next = next.nextElementSibling;
        if (!next || !next.classList.contains('locked')) return;

        next.classList.remove('locked');
        var lock = next.querySelector('.lc-lock');
        if (lock) lock.remove();

        var pill = next.querySelector('.lc-footer .status-pill');
        if (pill) { pill.textContent = 'Pendente'; pill.className = 'status-pill status-pending'; }
        var btnN = next.querySelector('.lc-footer .btn-lesson');
        if (btnN) { btnN.textContent = 'Assistir'; btnN.classList.remove('disabled'); }
    }

    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-lesson-id');
        if (!id) return;
        btn.disabled = true;
        btn.style.opacity = '0.7';

        fetch('api_concluir_aula.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'lesson_id=' + encodeURIComponent(id)
        })
        .then(function(r) { return r.json().catch(function(){ return null; }); })
        .then(function(data) {
            if (data && data.ok) {
                btn.className = 'btn-done done';
                btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:15px;height:15px"><polyline points="20 6 9 17 4 12"/></svg> Aula concluída';
                btn.disabled = true;
                try { unlockNextLessonCard(); } catch(e) {}
            } else {
                alert('Não foi possível marcar a aula como concluída. Tente novamente.');
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        })
        .catch(function() {
            alert('Erro de comunicação com o servidor.');
            btn.disabled = false;
            btn.style.opacity = '1';
        });
    });
})();

// ===== Card click =====
document.querySelectorAll('.lesson-card[data-link]').forEach(function(card) {
    card.addEventListener('click', function(e) {
        if (card.classList.contains('locked')) return;
        if (e.target.closest('.no-card-nav')) return;
        var link = card.getAttribute('data-link');
        if (link) window.location.href = link;
    });
});

document.querySelectorAll('.btn-lesson[data-link]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (btn.classList.contains('disabled')) return;
        var link = btn.getAttribute('data-link');
        if (link) window.location.href = link;
    });
});

// ===== Carousels =====
document.querySelectorAll('.carousel-wrap').forEach(function(wrap) {
    var track = wrap.querySelector('.carousel-track');
    if (!track) return;
    wrap.querySelectorAll('.carousel-arrow').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var dir = parseInt(btn.getAttribute('data-dir'), 10) || 1;
            var amt = track.clientWidth * 0.8;
            var max = track.scrollWidth - track.clientWidth;
            var t   = Math.min(Math.max(track.scrollLeft + dir * amt, 0), max);
            track.scrollTo({ left: t, behavior: 'smooth' });
        });
    });
});

// ===== WhatsApp FAB beacon =====
(function(){
    var sent = false;
    document.addEventListener('click', function(e) {
        var a = e.target.closest('a.whatsapp-fab[data-help-fab="1"]');
        if (!a) return;
        if (!sent) {
            sent = true;
            var endpoint = '/area_membros/public/api_click_botao.php';
            var body = 'action=help';
            try {
                if (navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], {type:'application/x-www-form-urlencoded'})); return; }
            } catch(ex) {}
            try { fetch(endpoint, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body, keepalive:true}).catch(function(){}); } catch(ex) {}
        }
        if (!a.dataset.helpDelayed) {
            a.dataset.helpDelayed = '1';
            e.preventDefault();
            var href = a.href;
            setTimeout(function(){ window.open(href, a.target || '_blank'); }, 120);
        }
    }, true);
})();
</script>

</body>
</html>
