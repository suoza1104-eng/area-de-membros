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
    <title><?= h($lessonTitle) ?> - Aula</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg: <?= h($bgColor) ?>;
            --card:#020617;
            --border:#1f2937;
            --primary: <?= h($primary) ?>;
            --secondary: <?= h($secondary) ?>;
            --text:#e5e7eb;
            --muted:#9ca3af;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:var(--bg);
            color:var(--text);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit;}
        .page{
            max-width:1120px;
            margin:0 auto;
            padding:16px 12px 32px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
        }
        .logo-area{
            display:flex;
            align-items:center;
            gap:10px;
        }
        .logo-circle{
            width:40px;
            height:40px;
            border-radius:999px;
            border:1px solid var(--border);
            background:#020617;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            font-size:18px;
            color:var(--primary);
        }
        .logo-circle img{
            max-width:100%;
            max-height:100%;
            object-fit:contain;
        }
        .course-title{
            font-size:16px;
            font-weight:bold;
        }
        .course-sub{
            font-size:11px;
            color:var(--muted);
        }

        .btn-back{
            border:none;
            padding:8px 16px;
            border-radius:999px;
            background:var(--primary);
            color:#111827;
            cursor:pointer;
            font-size:12px;
            font-weight:bold;
            display:inline-flex;
            align-items:center;
            gap:6px;
            box-shadow:0 10px 30px rgba(0,0,0,.6);
        }
        .btn-back span.icon{
            font-size:14px;
        }
        .btn-back:hover{
            filter:brightness(1.05);
        }

        .progress-card{
            background:#020617;
            border-radius:16px;
            border:1px solid var(--border);
            padding:12px 12px 14px;
            margin-bottom:16px;
            box-shadow:0 14px 32px rgba(0,0,0,.45);
        }
        .progress-title{
            font-size:13px;
            font-weight:bold;
            margin-bottom:4px;
        }
        .progress-text{
            font-size:11px;
            color:var(--muted);
            margin-bottom:6px;
        }
        .progress-bar{
            width:100%;
            height:8px;
            border-radius:999px;
            background:#020617;
            border:1px solid #111827;
            overflow:hidden;
        }
        .progress-bar-fill{
            height:100%;
            width:0;
            border-radius:999px;
            background:var(--secondary);
            transition:width .35s ease;
        }

        .lesson-wrapper{
            background:#020617;
            border-radius:16px;
            border:1px solid var(--border);
            box-shadow:0 18px 40px rgba(0,0,0,.55);
            padding:12px 12px 18px;
            margin-bottom:18px;
        }
        .lesson-header{
            margin-bottom:8px;
        }
        .lesson-tag{
            font-size:11px;
            color:var(--muted);
            margin-bottom:2px;
        }
        .lesson-title{
            font-size:16px;
            font-weight:bold;
        }
        .video-wrapper{
            background:#0b1120;
            border-radius:12px;
            border:1px solid #111827;
            overflow:hidden;
            margin-top:10px;
        }
        .video-inner{
            position:relative;
            padding-top:56.25%; /* 16:9 */
        }
        .video-inner iframe{
            position:absolute;
            top:0;left:0;
            width:100%;
            height:100%;
            border:0;
        }
        .video-placeholder{
            padding:40px 16px;
            text-align:center;
            font-size:14px;
            color:var(--muted);
        }

        .lesson-actions{
            margin-top:12px;
            display:flex;
            justify-content:flex-end;
        }
        .btn-done{
            border:none;
            padding:9px 16px;
            border-radius:999px;
            font-size:12px;
            font-weight:bold;
            display:inline-flex;
            align-items:center;
            gap:6px;
            cursor:pointer;
        }
        .btn-done span.icon{font-size:14px;}
        .btn-done.pending{
            background:var(--primary);
            color:#111827;
        }
        .btn-done.done{
            background:#15803d;
            color:#ecfdf5;
            cursor:default;
        }

        /* Seção Próximas aulas */
        .section-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-top:4px;
            margin-bottom:8px;
        }
        .section-title{
            font-size:14px;
            font-weight:bold;
        }
        .carousel{
            position:relative;
        }
        .carousel-track{
            display:flex;
            gap:12px;
            overflow-x:auto;
            scroll-behavior:smooth;
            padding-bottom:4px;
        }
        .carousel-track::-webkit-scrollbar{
            height:6px;
        }
        .carousel-track::-webkit-scrollbar-thumb{
            background:#111827;
            border-radius:999px;
        }
        .carousel-arrow{
            position:absolute;
            top:50%;
            transform:translateY(-50%);
            width:32px;
            height:32px;
            border-radius:999px;
            border:none;
            background:#020617;
            color:var(--text);
            box-shadow:0 8px 16px rgba(0,0,0,.6);
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            z-index:10;
        }
        .carousel-arrow.left{left:-4px;}
        .carousel-arrow.right{right:-4px;}
        .carousel-arrow:hover{filter:brightness(1.08);}

        .lesson-card{
            flex:0 0 260px;
            max-width:260px;
            background:#020617;
            border-radius:16px;
            border:1px solid var(--border);
            box-shadow:0 14px 32px rgba(0,0,0,.45);
            cursor:pointer;
            display:flex;
            flex-direction:column;
            overflow:hidden;
        }
        .lesson-card.locked{
            cursor:default;
            opacity:.7;
        }
        .lesson-card.current{
            border-color:var(--primary);
        }
        .lesson-header-card{
            padding:10px 12px 6px;
        }
        .lesson-tag-card{
            font-size:11px;
            color:var(--muted);
            margin-bottom:2px;
        }
        .lesson-title-card{
            font-size:13px;
            font-weight:bold;
        }
        .lesson-thumb-card{
            position:relative;
            width:100%;
            background:#020617;
            border-top:1px solid #111827;
            border-bottom:1px solid #111827;
            overflow:hidden;
        }
        /* Fallback universal p/ manter proporção 1:1 (quadrado) */
        .lesson-thumb-card::before{
            content:"";
            display:block;
            padding-top:100%;
        }
        .lesson-thumb-card img{
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover; /* não distorce; corta só o excedente */
            display:block;
        }
        .lesson-thumb-card > span{
            position:absolute;
            inset:0;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .lesson-lock{
            position:absolute;
            inset:0;
            display:flex;
            align-items:center;
            justify-content:center;
            background:linear-gradient(to bottom, rgba(0,0,0,.6), rgba(0,0,0,.9));
            color:#facc15;
            font-size:26px;
        }
        .lesson-footer-card{
            padding:10px 12px 12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
        }
        .status-pill{
            font-size:11px;
            padding:3px 7px;
            border-radius:999px;
        }
        .status-ok{
            background:rgba(34,197,94,.2);
            color:#bbf7d0;
        }
        .status-pending{
            background:rgba(148,163,184,.2);
            color:#e5e7eb;
        }
        .btn-lesson{
            border:none;
            border-radius:999px;
            padding:6px 10px;
            font-size:11px;
            font-weight:bold;
            background:var(--primary);
            color:#111827;
            cursor:pointer;
        }
        .btn-lesson.disabled{
            background:#111827;
            color:#6b7280;
            cursor:default;
        }

        /* Conteúdo extra */
        .extra-box{
            margin-top:16px;
            background:#020617;
            border-radius:16px;
            border:1px solid var(--border);
            padding:14px 12px 16px;
            box-shadow:0 14px 32px rgba(0,0,0,.45);
            font-size:13px;
        }
        .extra-title{
            font-weight:bold;
            margin-bottom:6px;
        }

        
        /* Cursos recomendados - carrossel */
        .rec-section-title{
            margin-top:22px;
            margin-bottom:8px;
            font-size:14px;
            font-weight:600;
            color:var(--muted);
        }
        .rec-carousel{
            position:relative;
            margin:0 -4px;
            margin-bottom:4px;
        }
        .rec-track{
            display:flex;
            gap:12px;
            overflow-x:auto;
            padding:4px;
            scroll-snap-type:x mandatory;
            -webkit-overflow-scrolling:touch;
        }
        .rec-card-link{
            text-decoration:none;
            color:inherit;
        }
        .rec-card{
            background:#020617;
            border-radius:18px;
            border:1px solid var(--border);
            padding:10px 12px 12px;
            box-shadow:0 14px 32px rgba(0,0,0,.45);
            display:flex;
            flex-direction:column;
            min-width:260px;
            max-width:260px;
            flex-shrink:0;
            scroll-snap-align:start;
        }
        .rec-thumb{
            position:relative;
            width:100%;
            border-radius:14px;
            overflow:hidden;
            margin-bottom:8px;
            background:#020617;
            color:var(--muted);
            font-size:12px;
        }
        .rec-thumb::before{
            content:"";
            display:block;
            padding-top:100%;
        }
        .rec-thumb img{
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .rec-thumb > span{
            position:absolute;
            inset:0;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .rec-body{
            flex:1;
            display:flex;
            flex-direction:column;
        }
        .rec-title{
            font-size:14px;
            font-weight:600;
            margin-bottom:4px;
        }
        .rec-desc{
            font-size:12px;
            color:var(--muted);
            margin-bottom:8px;
        }
        .rec-footer{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
            margin-top:auto;
        }
        .rec-badge{
            font-size:11px;
            padding:4px 8px;
            border-radius:999px;
            background:rgba(148,163,184,.18);
            color:#e5e7eb;
        }
        .rec-cta{
            font-size:12px;
            font-weight:600;
            color:#111827;
            background:var(--primary);
            border-radius:999px;
            padding:6px 10px;
            white-space:nowrap;
        }
        .rec-arrow{
            position:absolute;
            top:50%;
            transform:translateY(-50%);
            width:32px;
            height:32px;
            border-radius:999px;
            border:none;
            background:rgba(15,23,42,.9);
            color:#e5e7eb;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            box-shadow:0 10px 22px rgba(0,0,0,.7);
        }
        .rec-arrow-left{
            left:-4px;
        }
        .rec-arrow-right{
            right:-4px;
        }
        @media (max-width:720px){
            .rec-card{
                min-width:220px;
                max-width:220px;
            }
            .rec-arrow{
                width:28px;
                height:28px;
            }
        }

@media (max-width:768px){
            .topbar{
                flex-direction:column;
                align-items:flex-start;
                gap:8px;
            }
            .btn-back{
                align-self:flex-start;
            }
            .lesson-card{
                flex-basis:220px;
                max-width:220px;
            }
            .carousel-arrow.left{left:0;}
            .carousel-arrow.right{right:0;}
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

        
</style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <div class="logo-area">
            <div class="logo-circle">
                <?php if ($logoUrl): ?>
                    <img src="<?= h($logoUrl) ?>" alt="Logo">
                <?php else: ?>
                    EL
                <?php endif; ?>
            </div>
            <div>
                <div class="course-title"><?= h($courseTitle) ?></div>
                <div class="course-sub">Assistindo: <?= h($lessonTitle) ?></div>
            </div>
        </div>

        <button type="button" class="btn-back" onclick="window.location.href='trilha.php'">
            <span class="icon">←</span>
            <span>Voltar para a trilha</span>
        </button>
    </div>

    <div class="progress-card">
        <div class="progress-title">Progresso no treinamento</div>
        <div class="progress-text">
            Você concluiu <?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas obrigatórias (<?= $percent ?>%)
        </div>
        <div class="progress-bar">
            <div class="progress-bar-fill" style="width: <?= $percent ?>%;"></div>
        </div>
    </div>

    <div class="lesson-wrapper">
        <div class="lesson-header">
            <div class="lesson-tag">AULA <?= $lessonOrder ?></div>
            <div class="lesson-title"><?= h($lessonTitle) ?></div>
        </div>

        <div class="video-wrapper">
            <?php if ($hasEmbedTags && !empty($videoUrl)): ?>
                <div class="video-embed-raw">
                    <?= $videoUrl /* código embed/JS completo, vem do admin */ ?>
                </div>
            <?php elseif ($videoType === 'embed' && !empty($videoUrl)): ?>
                <div class="video-embed-raw">
                    <?= $videoUrl /* embed completo, vem do admin */ ?>
                </div>
            <?php elseif ($videoType === 'youtube' && !empty($videoUrl)): ?>
                <?php
                    $embedSrc = youtube_embed_src($videoUrl);
                ?>
                <div class="video-inner">
                    <iframe
                        src="<?= h($embedSrc) ?>"
                        title="YouTube video player"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen>
                    </iframe>
                </div>
            <?php else: ?>
                <div class="video-placeholder">
                    Nenhum vídeo configurado para esta aula ainda.
                </div>
            <?php endif; ?>
        </div>

        <div class="lesson-actions">
            <?php if ($isCurrentCompleted): ?>
                <button type="button" class="btn-done done" disabled>
                    <span class="icon">✓</span>
                    <span>Aula concluída</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn-done pending" id="btn-concluir-aula"
                        data-lesson-id="<?= (int)$lessonId ?>">
                    <span class="icon">✔</span>
                    <span>Marcar aula como concluída</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lessons): ?>
        <div class="section-header">
            <div class="section-title">Próximas aulas</div>
        </div>

        <div class="carousel">
            <button type="button" class="carousel-arrow left" data-dir="-1">&#9664;</button>
            <div class="carousel-track">
                <?php foreach ($lessons as $ls):
                    $lsId = (int)$ls['id'];
                    $slug  = $ls['slug'];
                    $titulo= $ls['titulo'];
                    $ordem = (int)$ls['ordem'];
                    $thumb = $ls['thumb_url'] ?? '';
                    $isCompleted = isset($progressMap[$lsId]) && $progressMap[$lsId]['status'] === 'completed';
                    $isLocked    = !($unlockMap[$lsId] ?? false);
                    $linkAula    = 'aula.php?slug=' . urlencode($slug);

                    $isCurrent = ($lsId === $lessonId);
                ?>
                    <div class="lesson-card <?= $isLocked ? 'locked' : '' ?> <?= $isCurrent ? 'current' : '' ?>"
                         data-link="<?= h($linkAula) ?>">
                        <div class="lesson-header-card">
                            <div class="lesson-tag-card">AULA <?= $ordem ?></div>
                            <div class="lesson-title-card"><?= h($titulo) ?></div>
                        </div>
                        <div class="lesson-thumb-card">
                            <?php if (!empty($thumb)): ?>
                                <img src="<?= h($thumb) ?>" alt="">
                            <?php else: ?>
                                <span style="font-size:11px;color:var(--muted);">Sem imagem</span>
                            <?php endif; ?>
                            <?php if ($isLocked): ?>
                                <div class="lesson-lock">🔒</div>
                            <?php endif; ?>
                        </div>
                        <div class="lesson-footer-card">
                            <?php if ($isCompleted): ?>
                                <div class="status-pill status-ok">Concluída ✓</div>
                            <?php elseif ($isLocked): ?>
                                <div class="status-pill status-pending">Bloqueada</div>
                            <?php else: ?>
                                <div class="status-pill status-pending">Pendente</div>
                            <?php endif; ?>

                            <?php if ($isLocked): ?>
                                <button type="button" class="btn-lesson disabled no-card-nav" data-link="<?= h($linkAula) ?>">Bloqueada</button>
                            <?php else: ?>
                                <button type="button" class="btn-lesson no-card-nav"
                                        data-link="<?= h($linkAula) ?>">Ir para a aula</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="carousel-arrow right" data-dir="1">&#9654;</button>
        </div>
    <?php endif; ?>

    <?php if (!empty(trim($htmlExtra))): ?>
        <div class="extra-box">
            <div class="extra-title">Conteúdo extra da aula</div>
            <div class="extra-body">
                <?= $htmlExtra /* HTML controlado pelo admin */ ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($recommendedCourses)): ?>
        <div class="rec-section-title">Conheça nossos cursos pagos</div>
        <div class="rec-carousel">
            <button type="button" class="rec-arrow rec-arrow-left" aria-label="Ver cursos anteriores">&#10094;</button>
            <div class="rec-track" id="rec-track">
                <?php foreach ($recommendedCourses as $rc): ?>
                    <a href="<?= h($rc['checkout_url']) ?>" target="_blank" class="rec-card-link">
                        <div class="rec-card">
                            <div class="rec-thumb">
                                <?php if (!empty($rc['thumb_url'])): ?>
                                    <img src="<?= h($rc['thumb_url']) ?>" alt="">
                                <?php else: ?>
                                    <span>Sem imagem</span>
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
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <button type="button" class="rec-arrow rec-arrow-right" aria-label="Ver próximos cursos">&#10095;</button>
        </div>
    <?php endif; ?>


</div>

<?php if ($whatsappHelpUrl): ?>
<a href="<?= h($whatsappHelpUrl) ?>" class="whatsapp-fab" data-help-fab="1"
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
// Botão "Marcar aula como concluída"
(function(){
    const btn = document.getElementById('btn-concluir-aula');
    if (!btn) return;

function unlockNextLessonCard(){
    const current = document.querySelector('.lesson-card.current');
    if (!current) return;

    // Atualiza visual do card atual
    const pillCur = current.querySelector('.lesson-footer-card .status-pill');
    if (pillCur){
        pillCur.textContent = 'Concluída ✓';
        pillCur.classList.remove('status-pending');
        pillCur.classList.add('status-ok');
    }
    const btnCur = current.querySelector('.lesson-footer-card .btn-lesson');
    if (btnCur){
        btnCur.textContent = 'Ir para a aula';
        btnCur.classList.remove('disabled');
    }

    // Desbloqueia o próximo card (se estiver bloqueado)
    let next = current.nextElementSibling;
    while (next && !next.classList.contains('lesson-card')) next = next.nextElementSibling;
    if (!next) return;
    if (!next.classList.contains('locked')) return;

    next.classList.remove('locked');

    const lock = next.querySelector('.lesson-lock');
    if (lock) lock.remove();

    const pill = next.querySelector('.lesson-footer-card .status-pill');
    if (pill){
        pill.textContent = 'Pendente';
        pill.classList.remove('status-ok');
        pill.classList.add('status-pending');
    }

    const btnNext = next.querySelector('.lesson-footer-card .btn-lesson');
    if (btnNext){
        btnNext.textContent = 'Ir para a aula';
        btnNext.classList.remove('disabled');
        // garante data-link no botão (mesmo que já exista)
        const link = next.getAttribute('data-link') || btnNext.getAttribute('data-link') || '';
        if (link) btnNext.setAttribute('data-link', link);
    }
}

    btn.addEventListener('click', function(){
        const id = btn.getAttribute('data-lesson-id');
        if (!id) return;

        btn.disabled = true;
        btn.style.opacity = '0.7';

        fetch('api_concluir_aula.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'lesson_id='+encodeURIComponent(id)
        })
        .then(r => r.json().catch(()=>null))
        .then(data => {
            if (data && data.ok) {
                btn.classList.remove('pending');
                btn.classList.add('done');
                btn.innerHTML = '<span class="icon">✓</span><span>Aula concluída</span>';
                btn.disabled = true;
                try { unlockNextLessonCard(); } catch(e) {}
            } else {
                alert('Não foi possível marcar a aula como concluída. Tente novamente.');
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        })
        .catch(function(){
            alert('Erro de comunicação com o servidor.');
            btn.disabled = false;
            btn.style.opacity = '1';
        });
    });
})();

// Clique no card inteiro (sem pegar os botões internos)
document.querySelectorAll('.lesson-card[data-link]').forEach(function(card){
    card.addEventListener('click', function(e){
        if (card.classList.contains('locked')) return;
        if (e.target.closest('.no-card-nav')) return;
        const link = card.getAttribute('data-link');
        if (link) window.location.href = link;
    });
});

// Botões "Ir para a aula" nos cards
document.querySelectorAll('.btn-lesson[data-link]').forEach(function(btn){
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        if (btn.classList.contains('disabled')) return;
        const link = btn.getAttribute('data-link');
        if (link) window.location.href = link;
    });
});

// Carrossel com limite (sem espaço em branco)
document.querySelectorAll('.carousel').forEach(function(carousel){
    const track = carousel.querySelector('.carousel-track');
    if (!track) return;

    function scrollByDir(dir){
        const amount = track.clientWidth * 0.8;
        const maxScroll = track.scrollWidth - track.clientWidth;
        let target = track.scrollLeft + dir * amount;
        if (target < 0) target = 0;
        if (target > maxScroll) target = maxScroll;
        track.scrollTo({ left: target, behavior: 'smooth' });
    }

    carousel.querySelectorAll('.carousel-arrow').forEach(function(btn){
        btn.addEventListener('click', function(){
            const dir = parseInt(btn.getAttribute('data-dir'), 10) || 1;
            scrollByDir(dir);
        });
    });
});


// Carrossel de cursos recomendados (usa .rec-carousel e .rec-arrow)
(function(){
    const rec = document.querySelector('.rec-carousel');
    if (!rec) return;
    const track = rec.querySelector('.rec-track');
    if (!track) return;

    const btnPrev = rec.querySelector('.rec-arrow-left');
    const btnNext = rec.querySelector('.rec-arrow-right');

    function scrollByDir(dir){
        const card = track.querySelector('.rec-card');
        const step = card ? (card.offsetWidth + 12) : (track.clientWidth * 0.8);
        const maxScroll = track.scrollWidth - track.clientWidth;
        let target = track.scrollLeft + dir * step;
        if (target < 0) target = 0;
        if (target > maxScroll) target = maxScroll;
        track.scrollTo({ left: target, behavior: 'smooth' });
    }

    if (btnPrev) btnPrev.addEventListener('click', function(){ scrollByDir(-1); });
    if (btnNext) btnNext.addEventListener('click', function(){ scrollByDir(1); });
})();

</script>

<script>
(function(){
  let sent = false;
  function fireHelpClick(){
    if (sent) return;
    sent = true;
    const endpoint = "/area_membros/public/api_click_botao.php";
    const body = "action=help";
    try {
      if (navigator.sendBeacon) {
        const blob = new Blob([body], {type:"application/x-www-form-urlencoded"});
        navigator.sendBeacon(endpoint, blob);
        return;
      }
    } catch(e){}
    try {
      fetch(endpoint, {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body, keepalive:true})
        .catch(()=>{});
    } catch(e){}
  }

  document.addEventListener("click", function(e){
    const a = e.target.closest('a.whatsapp-fab[data-help-fab="1"]');
    if (!a) return;

    // Dispara o registro em background sem atrapalhar o clique
    fireHelpClick();

    // (Opcional) garante alguns ms para o beacon sair antes de abrir o WhatsApp no celular
    // sem mudar o comportamento visual
    if (!a.dataset.helpDelayed) {
      a.dataset.helpDelayed = "1";
      e.preventDefault();
      const href = a.href;
      setTimeout(()=>{ window.open(href, a.target || "_blank"); }, 120);
    }
  }, true);
})();
</script>

</body>
</html>
