<?php
// FILE: public/certificado.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/certificado_pdf.php';

proteger_aluno();
$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$userId = (int)($_SESSION['aluno_id'] ?? 0);

// Carrega aluno
$stUser = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stUser->execute(['id' => $userId]);
$user = $stUser->fetch();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Config visual (cores / título)
$stCfgApp = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg = $stCfgApp->fetch() ?: [
    'course_title'          => 'Trilha de Aulas',
    'primary_color'         => '#facc15',
    'secondary_color'       => '#22c55e',
    'background_color'      => '#020617',
    'logo_url'              => '',
    'certificado_cta_label' => 'Emitir Certificado',
];
$primary   = $appCfg['primary_color']   ?? '#facc15';
$secondary = $appCfg['secondary_color'] ?? '#22c55e';
$bgColor   = $appCfg['background_color'] ?? '#020617';
$courseTitle = $appCfg['course_title'] ?? 'Trilha de Aulas';
$logoUrl  = $appCfg['logo_url'] ?? '';

// Config do certificado (mensagens, vídeos, webhooks, botão)
$stCfgCert = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$certCfg = $stCfgCert->fetch() ?: [
    'error_message_html'       => '<strong>Senha inválida.</strong><br>Verifique o código informado e tente novamente.',
    'success_message_html'     => '<strong>Parabéns!</strong><br>Seus dados estão corretos e sua senha foi validada.',
    'senha_video_url'          => '',
    'senha_error_video_url'    => '',
    'webhook_error_url'        => '',
    'webhook_emitido_url'      => '',
    'certificado_button_label' => 'Quero receber meu certificado',
    'certificado_button_link'  => '#',
];

// ==== Verifica conclusão das aulas obrigatórias ====
$stLessons = $pdo->query("
    SELECT id, conta_para_conclusao, ativo
    FROM lessons
    WHERE ativo = 1
    ORDER BY ordem ASC, id ASC
");
$lessons = $stLessons->fetchAll();

$stProg = $pdo->prepare("
    SELECT lesson_id, status
    FROM lesson_progress
    WHERE user_id = :uid
");
$stProg->execute(['uid' => $userId]);
$progressRows = $stProg->fetchAll();

$progressMap = [];
foreach ($progressRows as $row) {
    $progressMap[(int)$row['lesson_id']] = $row;
}

$totalObrigatorias = 0;
$totalConcluidas  = 0;

foreach ($lessons as $ls) {
    if ((int)$ls['conta_para_conclusao'] === 1) {
        $totalObrigatorias++;
        $lsId = (int)$ls['id'];
        $isCompleted = isset($progressMap[$lsId]) && $progressMap[$lsId]['status'] === 'completed';
        if ($isCompleted) {
            $totalConcluidas++;
        }
    }
}

$percent = 0;
if ($totalObrigatorias > 0) {
    $percent = (int)round(100 * $totalConcluidas / $totalObrigatorias);
}
$temTudoConcluido = ($totalObrigatorias > 0 && $totalConcluidas >= $totalObrigatorias);

// ==== helpers ====
function send_cert_webhook(PDO $pdo, ?string $url, string $evento, array $user, array $extra = []): void {
    if (!$url) return;

    $payload = [
        'evento' => $evento,
        'user'   => [
            'id'      => $user['id'] ?? null,
            'nome'    => $user['nome'] ?? null,
            'email'   => $user['email'] ?? null,
            'telefone'=> $user['telefone'] ?? null,
        ],
        'extra'  => $extra,
        'timestamp' => date('c'),
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payloadJson,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $responseBody = curl_exec($ch);
    $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    try {
        $st = $pdo->prepare("
            INSERT INTO webhook_logs (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
            VALUES (NULL, :uid, :evento, :payload, :status, :body, :err, NOW())
        ");
        $st->execute([
            'uid'    => $user['id'] ?? null,
            'evento' => $evento,
            'payload'=> $payloadJson,
            'status' => $httpCode,
            'body'   => (string)$responseBody,
            'err'    => $curlError,
        ]);
    } catch (Throwable $e) {
        // se der erro no log, ignora
    }
}

function gerar_codigo_certificado(): string {
    // 36 caracteres, letras minúsculas, números e traços
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $len   = 36;
    $result = '';
    for ($i = 0; $i < $len; $i++) {
        if ($i > 0 && $i % 9 === 0) {
            $result .= '-';
        } else {
            $result .= $chars[random_int(0, strlen($chars)-1)];
        }
    }
    return $result;
}

// ==== fluxo da tela ====
$etapa = 'form'; // form | erro | sucesso
$erroConclusao = false;
$erroSenha = false;
$mensagemErro = '';
$codigoCert = null;
$pdfUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) verificar se concluiu todas as aulas
    if (!$temTudoConcluido) {
        $erroConclusao = true;
        $etapa = 'erro';
        $mensagemErro = 'Você ainda não concluiu todas as aulas obrigatórias. Finalize a trilha antes de emitir o certificado.';
    } else {
        // 2) validar senha
        $senhaInformada = trim((string)($_POST['senha_certificado'] ?? ''));

        if ($senhaInformada === '' || $senhaInformada !== SENHA_CERTIFICADO) {
            $erroSenha = true;
            $etapa = 'erro';
            $mensagemErro = $certCfg['error_message_html'] ?? 'Senha inválida.';

            // webhook de senha errada
            if (!empty($certCfg['webhook_error_url'])) {
                send_cert_webhook(
                    $pdo,
                    $certCfg['webhook_error_url'],
                    'CERT_SENHA_ERRADA',
                    $user,
                    ['motivo' => 'senha_incorreta']
                );
            }
 

            // Dispara também via sistema de webhooks por evento (tabela webhooks)
            try {
                disparar_webhooks('CERT_SENHA_ERRADA', (int)($user['id'] ?? 0), [
                    'motivo' => 'senha_incorreta',
                ]);
            } catch (Throwable $e) {
                // não interrompe o fluxo da página
            }
        } else {
            // 3) senha correta + aulas concluídas => emitir certificado
            $pdo->beginTransaction();
            try {
                // Busca certificado existente
                $stCert = $pdo->prepare("
                    SELECT *
                    FROM certificates
                    WHERE user_id = :uid
                      AND course = :course
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stCert->execute([
                    'uid'    => $userId,
                    'course' => $courseTitle,
                ]);
                $certExist = $stCert->fetch();

                $cert = null;

                if ($certExist && ($certExist['status'] ?? '') === 'emitido') {
                    // Já existe certificado emitido, reutiliza
                    $cert       = $certExist;
                    $codigoCert = $certExist['codigo_uid'];
                    $emitidoEm  = $certExist['emitido_em'];
                } else {
                    // Não existe: cria um novo
                    $codigoCert = gerar_codigo_certificado();
                    $emitidoEm  = date('Y-m-d H:i:s');

                    $stIns = $pdo->prepare("
                        INSERT INTO certificates (user_id, course, codigo_uid, emitido_em, status)
                        VALUES (:uid, :course, :codigo, :emitido, 'emitido')
                    ");
                    $stIns->execute([
                        'uid'    => $userId,
                        'course' => $courseTitle,
                        'codigo' => $codigoCert,
                        'emitido'=> $emitidoEm,
                    ]);

                    $certId = (int)$pdo->lastInsertId();

                    $cert = [
                        'id'         => $certId,
                        'user_id'    => $userId,
                        'course'     => $courseTitle,
                        'codigo_uid' => $codigoCert,
                        'emitido_em' => $emitidoEm,
                        'status'     => 'emitido',
                        'pdf_url'    => null,
                    ];
                }

                // Garante que temos um array de certificado
                if (!$cert) {
                    // fallback: recarrega do banco
                    $stCert2 = $pdo->prepare("
                        SELECT *
                        FROM certificates
                        WHERE user_id = :uid
                          AND course = :course
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stCert2->execute([
                        'uid'    => $userId,
                        'course' => $courseTitle,
                    ]);
                    $cert = $stCert2->fetch() ?: null;
                }

                if ($cert) {
                    // ===== GERAR / ATUALIZAR PDF DO CERTIFICADO =====
                    $pdfUrl = gerar_pdf_certificado($user, $cert, $certCfg);

                    // Atualiza pdf_url na tabela
                    $stUpd = $pdo->prepare("
                        UPDATE certificates
                           SET pdf_url = :pdf_url
                         WHERE id = :id
                    ");
                    $stUpd->execute([
                        'pdf_url' => $pdfUrl,
                        'id'      => $cert['id'],
                    ]);

                    // Atualiza array em memória
                    $cert['pdf_url'] = $pdfUrl;
                } else {
                    // Se por algum motivo não tiver cert, evita erro
                    $pdfUrl = null;
                }

                // Tag do aluno
                try {
                    adicionar_tag($userId, 'CERT_EMITIDO', 'certificado');
                } catch (Throwable $e) {
                    // não aborta por causa de tag
                }

                $pdo->commit();

                // Dispara webhook de certificado emitido (com link do PDF)
                if (!empty($certCfg['webhook_emitido_url'])) {
                    send_cert_webhook(
                        $pdo,
                        $certCfg['webhook_emitido_url'],
                        'CERT_EMITIDO',
                        $user,
                        [
                            'codigo_certificado' => $codigoCert,
                            'curso'              => $courseTitle,
                            'emitido_em'         => $emitidoEm,
                            'pdf_url'            => $pdfUrl,
                        ]
                    );
                }

                // Dispara também via sistema de webhooks por evento (tabela webhooks)
                try {
                    disparar_webhooks('CERT_EMITIDO', (int)$userId, [
                        'codigo_certificado' => $codigoCert,
                        'curso'              => $courseTitle,
                        'emitido_em'         => $emitidoEm,
                        'pdf_url'            => $pdfUrl,
                    ]);
                } catch (Throwable $e) {
                    // não interrompe o fluxo da página
                }

                $etapa = 'sucesso';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $erroSenha = true;
                $etapa = 'erro';
                $mensagemErro = 'Ocorreu um erro ao emitir seu certificado. Tente novamente mais tarde.';
                log_sistema('error', 'certificado', 'Erro ao emitir certificado', ['exception' => $e->getMessage()]);
            }
        }
    }
}

$btnLabel = $certCfg['certificado_button_label'] ?? 'Quero receber meu certificado';
$btnLink  = $certCfg['certificado_button_link'] ?? '#';

// URLs dos vídeos (se tiver, mostra)
$introVideoUrl     = trim((string)($certCfg['intro_video_url'] ?? ''));
$introVideoEnabled = !empty($certCfg['intro_video_enabled'] ?? 0) || $introVideoUrl !== '';

$senhaVideoUrl     = trim((string)($certCfg['senha_video_url'] ?? ''));
$senhaVideoEnabled = !empty($certCfg['senha_video_enabled'] ?? 0) || $senhaVideoUrl !== '';

$erroVideoUrl      = trim((string)($certCfg['senha_error_video_url'] ?? ''));
$erroVideoEnabled  = !empty($certCfg['senha_error_video_enabled'] ?? 0) || $erroVideoUrl !== '';

/**
 * Normaliza uma URL de vídeo "solta" para uso em iframe.
 * - Se for YouTube (watch, youtu.be, embed), converte para /embed/ID
 * - Caso contrário, retorna a própria URL
 */
function normalizar_video_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    // Se já parece ser um iframe completo, apenas retorna como está (não deveria cair aqui)
    if (stripos($url, '<iframe') !== false) {
        return $url;
    }

    // Tenta tratar casos de YouTube
    if (stripos($url, 'youtu.be') !== false || stripos($url, 'youtube.com') !== false) {
        $raw = $url;
        $id  = $raw;

        if (strpos($raw, 'http') === 0) {
            $parts = parse_url($raw);
            $host  = $parts['host'] ?? '';
            $path  = $parts['path'] ?? '';
            $query = $parts['query'] ?? '';

            if (stripos($host, 'youtu.be') !== false) {
                $id = ltrim((string)$path, '/');
            } elseif (stripos($host, 'youtube.com') !== false) {
                if (!empty($query)) {
                    parse_str($query, $q);
                    if (!empty($q['v'])) {
                        $id = (string)$q['v'];
                    }
                }
                if ($id === $raw && !empty($path) && strpos($path, '/embed/') !== false) {
                    $id = (string)substr($path, strpos($path, '/embed/') + 7);
                }
            }
        }

        // Se id ainda for uma URL inteira, tenta extrair último segmento
        if (strpos($id, 'http') === 0 && strpos($id, '/') !== false) {
            $parts = explode('/', rtrim($id, '/'));
            $id    = end($parts);
        }

        return 'https://www.youtube.com/embed/' . $id;
    }

    // Caso geral (Vimeo, Vturb, etc.), usamos a URL como está
    return $url;
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Certificado - <?= h($courseTitle) ?></title>
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
            --danger:#ef4444;
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
            max-width:720px;
            margin:0 auto;
            padding:16px 12px 32px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
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
            padding:7px 12px;
            border-radius:999px;
            background:#020617;
            color:var(--muted);
            cursor:pointer;
            font-size:11px;
            display:inline-flex;
            align-items:center;
            gap:4px;
            border:1px solid #111827;
        }
        .btn-back span.icon{font-size:13px;}
        .btn-back:hover{
            filter:brightness(1.1);
        }

        .card{
            background:#020617;
            border-radius:16px;
            border:1px solid var(--border);
            padding:14px 12px 18px;
            box-shadow:0 18px 40px rgba(0,0,0,.55);
            margin-bottom:14px;
        }
        .card-title{
            font-size:18px;
            font-weight:bold;
            margin-bottom:4px;
        }
        .card-sub{
            font-size:16px;
            color:var(--muted);
            margin-bottom:10px;
        }
        .field{
            margin-bottom:14px;
        }
        label{
            display:block;
            font-size:16px;
            color:var(--muted);
            margin-bottom:4px;
        }
        input[type="password"]{
            width:100%;
            padding:8px 10px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            font-size:13px;
        }
        .btn-primary{
            margin-top:4px;
            padding:9px 14px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .btn-primary:hover{filter:brightness(1.05);}
        .btn-primary{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
        }
        .btn-primary:disabled{
            cursor:not-allowed;
            opacity:.92;
        }
        .btn-primary.is-loading{
            pointer-events:none;
            filter:none;
        }
        .spinner{
            width:14px;
            height:14px;
            border-radius:999px;
            border:2px solid rgba(17,24,39,.35);
            border-top-color:#111827;
            display:none;
            animation:spin .8s linear infinite;
        }
        .btn-primary.is-loading .spinner{
            display:inline-block;
        }
        @keyframes spin{
            to{transform:rotate(360deg);}
        }

        .alert-ok{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(34,197,94,.12);
            border:1px solid #22c55e;
            color:#bbf7d0;
            font-size:12px;
        }
        .alert-erro{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(239,68,68,.12);
            border:1px solid #ef4444;
            color:#fecaca;
            font-size:12px;
        }
        .progress-text{
            font-size:14px;
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
            margin-bottom:10px;
        }
        .progress-bar-fill{
            height:100%;
            width:<?= $percent ?>%;
            border-radius:999px;
            background:var(--secondary);
            transition:width .35s ease;
        }

        .video-box{
            margin-top:10px;
            border-radius:12px;
            border:1px solid #111827;
            overflow:hidden;
            background:#020617;
        }
        .video-inner{
            position:relative;
            padding-top:56.25%;
        }
        .video-inner iframe{
            position:absolute;
            top:0;left:0;
            width:100%;
            height:100%;
            border:0;
        }
        .pulsing-cta{
            margin-top:10px;
            display:flex;
            justify-content:center;
        }
        .pulsing-cta a{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            padding:11px 18px;
            border-radius:999px;
            background:#16a34a;
            color:#ecfdf5;
            font-size:14px;
            font-weight:bold;
            text-decoration:none;
            box-shadow:0 0 0 0 rgba(34,197,94,.5);
            animation:pulse 1.6s infinite;
        }
        .pulsing-cta span.icon{
            font-size:18px;
        }
        @keyframes pulse{
            0%{transform:scale(1);box-shadow:0 0 0 0 rgba(34,197,94,.5);}
            50%{transform:scale(1.04);box-shadow:0 0 0 12px rgba(34,197,94,0);}
            100%{transform:scale(1);box-shadow:0 0 0 0 rgba(34,197,94,0);}
        }
        .cert-info{
            font-size:12px;
            color:var(--muted);
            margin-top:8px;
        }

        @media (max-width:768px){
            .topbar{
                flex-direction:column;
                align-items:flex-start;
                gap:8px;
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
                <div class="course-sub">Certificado de conclusão</div>
            </div>
        </div>

        <button type="button" class="btn-back" onclick="window.location.href='trilha.php'">
            <span class="icon">←</span>
            <span>Voltar para trilha</span>
        </button>
    </div>

    <div class="card">
        <div class="card-title">Verificação para emissão do certificado</div>
        <div class="card-sub">
            Para emitir seu certificado, confirme a senha de liberação e garanta que concluiu todas as aulas obrigatórias.
        </div>

        <div class="progress-text">
            Seu progresso: <?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas obrigatórias (<?= $percent ?>%)
        </div>
        <div class="progress-bar">
            <div class="progress-bar-fill"></div>
        </div>

        <?php if ($etapa === 'erro' && $mensagemErro): ?>
            <div class="alert-erro">
                <?= $mensagemErro ?>
            </div>
        <?php endif; ?>

        <?php if ($etapa === 'sucesso'): ?>
            <div class="alert-ok">
                <?= $certCfg['success_message_html'] ?? 'Certificado liberado com sucesso.' ?>
            </div>
            <?php if ($codigoCert): ?>
                <div class="cert-info">
                    <strong>Código do certificado:</strong> <?= h($codigoCert) ?><br>
                    Guarde este código. Ele poderá ser usado para validação futura.
                </div>
            <?php endif; ?>

            <div class="pulsing-cta">
                <a href="<?= h($btnLink) ?>" target="_blank" rel="noopener">
                    <span class="icon">🎓</span>
                    <span><?= h($btnLabel) ?></span>
                </a>
            </div>
        <?php else: ?>
            <form method="post" action="" id="certForm">
                <div class="field">
                    <label for="senha_certificado">Senha do certificado</label>
                    <input type="password" id="senha_certificado" name="senha_certificado" autocomplete="off">
                </div>
                <button type="submit" class="btn-primary" id="btnEmitir">
                    <span class="spinner" aria-hidden="true"></span>
                    <span class="btn-text">Validar senha e emitir certificado</span>
                </button>
            </form>
        <?php endif; ?>

        <?php
        // Prioriza qual vídeo mostrar conforme a etapa:
        // - etapa "form": vídeo introdutório (se habilitado)
        // - etapa "sucesso": vídeo de senha correta (se habilitado)
        // - etapa "erro" com $erroSenha: vídeo de senha incorreta (se habilitado)
        ?>
        <?php if ($etapa === 'form' && $introVideoEnabled && $introVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($introVideoUrl, '<iframe') !== false): ?>
                        <?= $introVideoUrl ?>
                    <?php else: ?>
                        <iframe
                            src="<?= h(normalizar_video_url($introVideoUrl)) ?>"
                            title="Vídeo orientativo"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen>
                        </iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($etapa === 'sucesso' && $senhaVideoEnabled && $senhaVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($senhaVideoUrl, '<iframe') !== false): ?>
                        <?= $senhaVideoUrl ?>
                    <?php else: ?>
                        <iframe
                            src="<?= h(normalizar_video_url($senhaVideoUrl)) ?>"
                            title="Vídeo após senha correta"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen>
                        </iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($etapa === 'erro' && $erroSenha && $erroVideoEnabled && $erroVideoUrl !== ''): ?>
            <div class="video-box" style="margin-top:12px;">
                <div class="video-inner">
                    <?php if (stripos($erroVideoUrl, '<iframe') !== false): ?>
                        <?= $erroVideoUrl ?>
                    <?php else: ?>
                        <iframe
                            src="<?= h(normalizar_video_url($erroVideoUrl)) ?>"
                            title="Vídeo de ajuda (senha incorreta)"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen>
                        </iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('certForm');
    if (!form) return;

    const btn = document.getElementById('btnEmitir');
    const input = document.getElementById('senha_certificado');
    let locked = false;

    form.addEventListener('submit', function (e) {
        if (locked) {
            e.preventDefault();
            return;
        }
        locked = true;

        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');

            const txt = btn.querySelector('.btn-text');
            if (txt) txt.textContent = 'Gerando certificado...';
        }

        if (input) {
            input.setAttribute('readonly', 'readonly');
        }
    });
})();
</script>

</body>
</html>
