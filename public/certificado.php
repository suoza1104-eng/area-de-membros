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

$stUser = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stUser->execute(['id' => $userId]);
$user = $stUser->fetch();
if (!$user) { header('Location: login.php'); exit; }
$courseAccess = course_access_status($pdo, $userId);
if (!empty($courseAccess['expired'])) {
    header('Location: trilha.php?access_expired=1');
    exit;
}

$stCfgApp = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg   = $stCfgApp->fetch() ?: [];
$primary     = $appCfg['primary_color']    ?? '#facc15';
$secondary   = $appCfg['secondary_color']  ?? '#22c55e';
$bgColor     = $appCfg['background_color'] ?? '#07101f';
$courseTitle = $appCfg['course_title']     ?? 'Trilha de Aulas';
$logoUrl     = $appCfg['logo_url']         ?? '';

$stCfgCert = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$certCfg   = $stCfgCert->fetch() ?: [
    'error_message_html'       => '<strong>Senha inválida.</strong><br>Verifique o código informado e tente novamente.',
    'success_message_html'     => '<strong>Parabéns!</strong><br>Seus dados estão corretos e sua senha foi validada.',
    'senha_video_url'          => '',
    'senha_error_video_url'    => '',
    'webhook_error_url'        => '',
    'webhook_emitido_url'      => '',
    'certificado_button_label' => 'Quero receber meu certificado',
    'certificado_button_link'  => '#',
];

$errorHtml = trim((string)($certCfg['error_html'] ?? ''));
if ($errorHtml === '') {
    $errorHtml = trim((string)($certCfg['error_message_html'] ?? ''));
}
if ($errorHtml === '') {
    $errorHtml = '<strong>Senha inválida.</strong><br>Verifique o código informado e tente novamente.';
}

$successHtml = trim((string)($certCfg['success_html'] ?? ''));
if ($successHtml === '') {
    $successHtml = trim((string)($certCfg['success_message_html'] ?? ''));
}
if ($successHtml === '') {
    $successHtml = '<strong>Parabéns!</strong><br>Seus dados estão corretos e sua senha foi validada.';
}

$stLessons = $pdo->query("SELECT id, conta_para_conclusao, ativo FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
$lessons   = $stLessons->fetchAll();

$stProg = $pdo->prepare("SELECT lesson_id, status FROM lesson_progress WHERE user_id = :uid");
$stProg->execute(['uid' => $userId]);
$progressRows = $stProg->fetchAll();

$progressMap = [];
foreach ($progressRows as $row) {
    $progressMap[(int)$row['lesson_id']] = $row;
}

$totalObrigatorias = 0;
$totalConcluidas   = 0;

foreach ($lessons as $ls) {
    if ((int)$ls['conta_para_conclusao'] === 1) {
        $totalObrigatorias++;
        $lsId = (int)$ls['id'];
        if (isset($progressMap[$lsId]) && $progressMap[$lsId]['status'] === 'completed') {
            $totalConcluidas++;
        }
    }
}

$percent          = $totalObrigatorias > 0 ? (int)round(100 * $totalConcluidas / $totalObrigatorias) : 0;
$temTudoConcluido = ($totalObrigatorias > 0 && $totalConcluidas >= $totalObrigatorias);

function send_cert_webhook(PDO $pdo, ?string $url, string $evento, array $user, array $extra = []): void {
    if (!$url) return;
    $payload     = ['evento' => $evento, 'user' => ['id' => $user['id'] ?? null, 'nome' => $user['nome'] ?? null, 'email' => $user['email'] ?? null, 'telefone' => $user['telefone'] ?? null], 'extra' => $extra, 'timestamp' => date('c')];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $responseBody = '';
    $httpCode     = 0;
    $curlError    = '';
    if (!function_exists('curl_init')) {
        $curlError = 'curl indisponivel';
    } else {
        try {
            $ch = curl_init($url);
            if ($ch === false) {
                $curlError = 'curl_init falhou';
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => $payloadJson,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_NOSIGNAL => 1,
                ]);
                $responseBody = (string)curl_exec($ch);
                $httpCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError    = (string)curl_error($ch);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            $curlError = $e->getMessage();
        }
    }
    try {
        $st = $pdo->prepare("INSERT INTO webhook_logs (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at) VALUES (NULL, :uid, :evento, :payload, :status, :body, :err, NOW())");
        $st->execute(['uid' => $user['id'] ?? null, 'evento' => $evento, 'payload' => $payloadJson, 'status' => $httpCode, 'body' => (string)$responseBody, 'err' => $curlError]);
    } catch (Throwable $e) {}
}

function cert_log_erro(PDO $pdo, string $mensagem, array $contexto = []): void {
    try {
        log_sistema('error', 'certificado', $mensagem, $contexto);
    } catch (Throwable $e) {
        @error_log('certificado: ' . $mensagem . ' | ' . $e->getMessage());
    }
}

function gerar_codigo_certificado(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < 36; $i++) {
        if ($i > 0 && $i % 9 === 0) $result .= '-';
        else $result .= $chars[random_int(0, strlen($chars)-1)];
    }
    return $result;
}

$etapa        = 'form';
$erroConclusao = false;
$erroSenha     = false;
$mensagemErro  = '';
$codigoCert    = null;
$pdfUrl        = null;
$emitidoEm     = null;
$senhaConfirmada = '';
$nomeConfirmacao = trim((string)($user['nome'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$temTudoConcluido) {
        $erroConclusao = true;
        $etapa         = 'erro';
        $mensagemErro  = 'Você ainda não concluiu todas as aulas obrigatórias. Finalize a trilha antes de emitir o certificado.';
    } else {
        $senhaInformada = trim((string)($_POST['senha_certificado'] ?? ''));
        $senhaConfirmada = $senhaInformada;
        $acao = (string)($_POST['acao'] ?? '');
        $senhaOkNaSessao = $acao === 'confirmar_nome'
            && (int)($_SESSION['cert_senha_ok_user_id'] ?? 0) === $userId
            && (int)($_SESSION['cert_senha_ok_until'] ?? 0) >= time();

        // === Determina a senha esperada a partir da configuração do DB ===
        $senhaTipo   = $certCfg['senha_tipo']          ?? 'unica';
        $senhaMode   = $certCfg['senha_mode']          ?? 'fixa';
        $senhaFixa   = trim((string)($certCfg['senha_fixa'] ?? ''));
        $partesFixas = json_decode((string)($certCfg['senha_partes_fixas'] ?? '[]'), true) ?: [];

        // Busca senha variável da turma do aluno (se modo variável)
        $senhaVariavel = '';
        if ($senhaMode === 'variavel') {
            try {
                $stInsc = $pdo->prepare("SELECT turma_id FROM inscricoes WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
                $stInsc->execute(['uid' => $userId]);
                $insc = $stInsc->fetch();
                if ($insc && (int)$insc['turma_id'] > 0) {
                    $stTurma = $pdo->prepare("SELECT senha_certificado FROM turmas WHERE id = :id LIMIT 1");
                    $stTurma->execute(['id' => (int)$insc['turma_id']]);
                    $turma = $stTurma->fetch();
                    $senhaVariavel = trim((string)($turma['senha_certificado'] ?? ''));
                }
            } catch (Throwable $e) {}
        }

        // Monta a senha esperada
        if ($senhaTipo === 'modular') {
            $partes = $partesFixas;
            if ($senhaMode === 'variavel') $partes[] = $senhaVariavel;
            $senhaEsperada = implode('', array_map('trim', $partes));
        } elseif ($senhaMode === 'variavel') {
            $senhaEsperada = $senhaVariavel;
        } else {
            // unica + fixa: prioriza DB; fallback para constante
            $senhaEsperada = $senhaFixa !== '' ? $senhaFixa : (defined('SENHA_CERTIFICADO') ? SENHA_CERTIFICADO : '');
        }

        if (!$senhaOkNaSessao && ($senhaInformada === '' || ($senhaEsperada !== '' && $senhaInformada !== $senhaEsperada) || $senhaEsperada === '')) {
            $erroSenha    = true;
            $etapa        = 'erro';
            $mensagemErro = $errorHtml;
            if (!empty($certCfg['webhook_error_url'])) {
                try {
                    send_cert_webhook($pdo, $certCfg['webhook_error_url'], 'CERT_SENHA_ERRADA', $user, ['motivo' => 'senha_incorreta']);
                } catch (Throwable $e) {
                    cert_log_erro($pdo, 'Falha no webhook de senha incorreta', ['exception' => $e->getMessage(), 'user_id' => $userId]);
                }
            }
            try { disparar_webhooks('CERT_SENHA_ERRADA', (int)($user['id'] ?? 0), ['motivo' => 'senha_incorreta']); } catch (Throwable $e) {}
        } else {
            if ($acao !== 'confirmar_nome') {
                $_SESSION['cert_senha_ok_user_id'] = $userId;
                $_SESSION['cert_senha_ok_until'] = time() + 600;
                $etapa = 'confirmar_nome';
                $nomeConfirmacao = trim((string)($user['nome'] ?? ''));
            } else {
                $nomeCorrigido = trim(preg_replace('/\s+/', ' ', (string)($_POST['nome_certificado'] ?? '')) ?? '');
                if ($nomeCorrigido === '' || strlen($nomeCorrigido) < 3) {
                    $erroSenha = true;
                    $etapa = 'confirmar_nome';
                    $nomeConfirmacao = $nomeCorrigido !== '' ? $nomeCorrigido : trim((string)($user['nome'] ?? ''));
                    $mensagemErro = 'Informe o nome completo para emitir o certificado.';
                } else {
                    if ($nomeCorrigido !== trim((string)($user['nome'] ?? ''))) {
                        $stNome = $pdo->prepare("UPDATE users SET nome = :nome WHERE id = :id LIMIT 1");
                        $stNome->execute(['nome' => $nomeCorrigido, 'id' => $userId]);
                        $user['nome'] = $nomeCorrigido;
                    }
            $pdo->beginTransaction();
            try {
                $stCert = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND course = :course ORDER BY id DESC LIMIT 1");
                $stCert->execute(['uid' => $userId, 'course' => $courseTitle]);
                $certExist = $stCert->fetch();
                $cert      = null;

                if ($certExist && ($certExist['status'] ?? '') === 'emitido') {
                    $cert       = $certExist;
                    $codigoCert = $certExist['codigo_uid'];
                    $emitidoEm  = $certExist['emitido_em'];
                } else {
                    $codigoCert = gerar_codigo_certificado();
                    $emitidoEm  = date('Y-m-d H:i:s');
                    $stIns = $pdo->prepare("INSERT INTO certificates (user_id, course, codigo_uid, emitido_em, status) VALUES (:uid, :course, :codigo, :emitido, 'emitido')");
                    $stIns->execute(['uid' => $userId, 'course' => $courseTitle, 'codigo' => $codigoCert, 'emitido' => $emitidoEm]);
                    $cert = ['id' => (int)$pdo->lastInsertId(), 'user_id' => $userId, 'course' => $courseTitle, 'codigo_uid' => $codigoCert, 'emitido_em' => $emitidoEm, 'status' => 'emitido', 'pdf_url' => null];
                }

                if (!$cert) {
                    $stCert2 = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND course = :course ORDER BY id DESC LIMIT 1");
                    $stCert2->execute(['uid' => $userId, 'course' => $courseTitle]);
                    $cert = $stCert2->fetch() ?: null;
                }

                if ($cert) {
                    $pdfUrl = gerar_pdf_certificado($user, $cert, $certCfg);
                    $stUpd  = $pdo->prepare("UPDATE certificates SET pdf_url = :pdf_url WHERE id = :id");
                    $stUpd->execute(['pdf_url' => $pdfUrl, 'id' => $cert['id']]);
                    $cert['pdf_url'] = $pdfUrl;
                } else {
                    $pdfUrl = null;
                }

                try { adicionar_tag($userId, 'CERT_EMITIDO', 'certificado'); } catch (Throwable $e) {}
                $pdo->commit();

                if (!empty($certCfg['webhook_emitido_url'])) {
                    try {
                        send_cert_webhook($pdo, $certCfg['webhook_emitido_url'], 'CERT_EMITIDO', $user, ['codigo_certificado' => $codigoCert, 'curso' => $courseTitle, 'emitido_em' => $emitidoEm, 'pdf_url' => $pdfUrl]);
                    } catch (Throwable $e) {
                        cert_log_erro($pdo, 'Falha no webhook direto de certificado emitido', ['exception' => $e->getMessage(), 'user_id' => $userId, 'codigo_certificado' => $codigoCert]);
                    }
                }
                try {
                    disparar_webhooks('CERT_EMITIDO', (int)$userId, ['codigo_certificado' => $codigoCert, 'curso' => $courseTitle, 'emitido_em' => $emitidoEm, 'pdf_url' => $pdfUrl]);
                } catch (Throwable $e) {
                    cert_log_erro($pdo, 'Falha ao capturar evento de certificado emitido', ['exception' => $e->getMessage(), 'user_id' => $userId, 'codigo_certificado' => $codigoCert]);
                }

                $etapa = 'sucesso';
                unset($_SESSION['cert_senha_ok_user_id'], $_SESSION['cert_senha_ok_until']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $erroSenha    = true;
                $etapa        = 'erro';
                $mensagemErro = 'Ocorreu um erro ao emitir seu certificado. Tente novamente mais tarde.';
                cert_log_erro($pdo, 'Erro ao emitir certificado', ['exception' => $e->getMessage(), 'user_id' => $userId]);
            }
                }
            }
        }
    }
}

$btnLabel = $certCfg['certificado_button_label'] ?? 'Quero receber meu certificado';
$btnLink  = $certCfg['certificado_button_link']  ?? '#';

$introVideoUrl     = trim((string)($certCfg['intro_video_url']          ?? ''));
$introVideoEnabled = !empty($certCfg['intro_video_enabled']  ?? 0) || $introVideoUrl !== '';
$senhaVideoUrl     = trim((string)($certCfg['senha_video_url']          ?? ''));
$senhaVideoEnabled = !empty($certCfg['senha_video_enabled']  ?? 0) || $senhaVideoUrl !== '';
$erroVideoUrl      = trim((string)($certCfg['senha_error_video_url']    ?? ''));
$erroVideoEnabled  = !empty($certCfg['senha_error_video_enabled'] ?? 0) || $erroVideoUrl !== '';
$incompletoVideoUrl     = trim((string)($certCfg['incompleto_video_url']     ?? ''));
$incompletoVideoEnabled = !empty($certCfg['incompleto_video_enabled'] ?? 0) || $incompletoVideoUrl !== '';

function normalizar_video_url(string $url): string {
    $url = trim($url);
    if ($url === '' || stripos($url, '<iframe') !== false) return $url;
    if (stripos($url, 'youtu.be') !== false || stripos($url, 'youtube.com') !== false) {
        $id = $url;
        if (strpos($url, 'http') === 0) {
            $parts = parse_url($url);
            $host  = $parts['host'] ?? '';
            $path  = $parts['path'] ?? '';
            $query = $parts['query'] ?? '';
            if (stripos($host, 'youtu.be') !== false) { $id = ltrim((string)$path, '/'); }
            elseif (stripos($host, 'youtube.com') !== false) {
                if (!empty($query)) { parse_str($query, $q); if (!empty($q['v'])) $id = (string)$q['v']; }
                if ($id === $url && !empty($path) && strpos($path, '/embed/') !== false) $id = substr($path, strpos($path, '/embed/') + 7);
            }
        }
        if (strpos($id, 'http') === 0) { $parts = explode('/', rtrim($id, '/')); $id = end($parts); }
        return 'https://www.youtube.com/embed/' . $id;
    }
    return $url;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Certificado — <?= h($courseTitle) ?></title>
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
            --danger:  #ef4444;
            --font:    'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --r:       10px;
            --r-xl:    18px;
            --r-full:  999px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }

        /* TOPBAR */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: rgba(7,16,31,.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 16px; height: 56px; gap: 12px;
        }
        .topbar-left { display: flex; align-items: center; gap: 10px; }
        .logo-box {
            width: 34px; height: 34px; border-radius: var(--r);
            background: rgba(250,204,21,.08); border: 1px solid rgba(250,204,21,.15);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; flex-shrink: 0; color: var(--primary);
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; }
        .logo-box svg { width: 17px; height: 17px; }
        .course-name { font-size: 14px; font-weight: 700; }
        .course-sub  { font-size: 11px; color: var(--muted); }
        .btn-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: var(--r-full);
            border: 1px solid var(--primary); background: var(--primary);
            color: #07101f; font-size: 12px; font-weight: 700; font-family: var(--font);
            cursor: pointer; transition: filter .15s, transform .15s;
        }
        .btn-back:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .btn-back svg { width: 13px; height: 13px; }

        /* PAGE */
        .page { max-width: 680px; margin: 0 auto; padding: 20px 16px 48px; }

        /* CARD */
        .cert-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 24px 24px 28px;
            margin-bottom: 16px;
        }
        .cert-icon {
            width: 52px; height: 52px; border-radius: var(--r);
            background: rgba(250,204,21,.1); border: 1px solid rgba(250,204,21,.2);
            display: flex; align-items: center; justify-content: center;
            color: var(--primary); margin-bottom: 16px;
        }
        .cert-icon svg { width: 24px; height: 24px; }
        .cert-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .cert-sub   { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }

        /* PROGRESS */
        .progress-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin-bottom: 6px; }
        .progress-bar-outer {
            height: 6px; border-radius: var(--r-full);
            background: rgba(255,255,255,.06); overflow: hidden; margin-bottom: 4px;
        }
        .progress-bar-inner {
            height: 100%; border-radius: var(--r-full);
            background: var(--primary); width: <?= $percent ?>%;
            transition: width .5s ease;
        }
        .progress-text { font-size: 12px; color: var(--muted); margin-bottom: 20px; }

        /* ALERTS */
        .alert {
            padding: 12px 14px; border-radius: var(--r);
            font-size: 13px; line-height: 1.55; margin-bottom: 16px;
        }
        .alert-ok    { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.25);  color: #86efac; }
        .alert-error { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.25);  color: #fca5a5; }
        .alert-warn  { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25); color: #fcd34d; }

        /* FORM */
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted); margin-bottom: 6px;
        }
        input[type="password"] {
            width: 100%; padding: 10px 13px;
            border-radius: var(--r); border: 1px solid var(--border);
            background: rgba(7,16,31,.8); color: var(--text);
            font-size: 14px; font-family: var(--font);
            outline: none; transition: border-color .15s, box-shadow .15s;
        }
        input[type="password"]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(250,204,21,.1);
        }

        /* BUTTONS */
        .btn-submit {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 12px 20px; border-radius: var(--r-full);
            border: none; background: var(--primary); color: #111827;
            font-weight: 700; font-size: 14px; font-family: var(--font);
            cursor: pointer; transition: filter .15s;
        }
        .btn-submit:hover { filter: brightness(1.07); }
        .btn-submit:disabled { opacity: .7; cursor: not-allowed; filter: none; }
        .btn-submit.is-loading { pointer-events: none; filter: none; }
        .spinner {
            width: 15px; height: 15px; border-radius: var(--r-full);
            border: 2px solid rgba(17,24,39,.3); border-top-color: #111827;
            display: none; animation: spin .8s linear infinite;
        }
        .btn-submit.is-loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* SUCCESS CTA */
        .pulsing-cta { margin-top: 18px; display: flex; justify-content: center; }
        .pulsing-cta a {
            display: inline-flex; align-items: center; gap: 8px;
            min-width: min(100%, 360px);
            justify-content: center;
            padding: 16px 28px; border-radius: var(--r-full);
            background: #facc15; color: #111827;
            border: 1px solid rgba(255,255,255,.45);
            font-size: 16px; font-weight: 800;
            box-shadow: 0 0 0 0 rgba(250,204,21,.55), 0 12px 30px rgba(250,204,21,.24);
            animation: pulseCert 1.05s ease-in-out infinite;
            text-decoration: none;
        }
        .pulsing-cta a:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .pulsing-cta a svg { width: 20px; height: 20px; flex-shrink: 0; }
        @keyframes pulseCert {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(250,204,21,.58), 0 12px 30px rgba(250,204,21,.24);
            }
            50% {
                transform: scale(1.055);
                box-shadow: 0 0 0 14px rgba(250,204,21,0), 0 16px 38px rgba(250,204,21,.36);
            }
        }

        .cert-code {
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            border-radius: var(--r);
            padding: 10px 14px;
            font-size: 12px; color: var(--muted);
            margin-top: 12px; line-height: 1.6;
        }
        .cert-code code {
            font-family: monospace; font-size: 13px;
            color: var(--primary); word-break: break-all;
        }

        /* VIDEO */
        .video-box { margin-top: 16px; border-radius: var(--r); overflow: hidden; background: #000; }
        .video-inner { position: relative; padding-top: 56.25%; }
        .video-inner iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }

        .name-modal-backdrop {
            position: fixed; inset: 0; z-index: 200;
            background: rgba(2, 6, 23, .72);
            display: flex; align-items: center; justify-content: center;
            padding: 18px;
        }
        .name-modal {
            width: min(520px, 100%);
            background: #0d1b33;
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            box-shadow: 0 24px 70px rgba(0,0,0,.38);
            padding: 22px;
        }
        .name-modal h2 {
            font-size: 20px;
            margin-bottom: 8px;
        }
        .name-modal p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
            margin-bottom: 16px;
        }
        .name-modal input {
            width: 100%;
            height: 48px;
            border-radius: var(--r);
            border: 1px solid var(--border);
            background: #07101f;
            color: var(--text);
            padding: 0 14px;
            font-size: 16px;
            font-weight: 700;
            outline: none;
        }
        .name-modal input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(250,204,21,.12);
        }
        .name-modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .name-modal-actions .btn-submit {
            margin-top: 0;
        }
        .name-modal-note {
            margin-top: 10px;
            color: var(--muted);
            font-size: 12px;
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
        <div>
            <div class="course-name"><?= h($courseTitle) ?></div>
            <div class="course-sub">Certificado de conclusão</div>
        </div>
    </div>
    <button type="button" class="btn-back" onclick="window.location.href='trilha.php'">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Voltar
    </button>
</header>

<div class="page">
    <div class="cert-card">
        <div class="cert-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
            </svg>
        </div>
        <div class="cert-title">Emissão do certificado</div>
        <div class="cert-sub">Para receber seu certificado, confirme a senha de liberação e verifique que concluiu todas as aulas obrigatórias.</div>

        <div class="progress-label">Progresso na trilha</div>
        <div class="progress-bar-outer"><div class="progress-bar-inner"></div></div>
        <div class="progress-text"><?= $totalConcluidas ?> de <?= $totalObrigatorias ?> aulas concluídas (<?= $percent ?>%)</div>

        <?php if (!$temTudoConcluido && $etapa === 'form'): ?>
            <div class="alert alert-warn">
                Você ainda não concluiu todas as aulas obrigatórias. Conclua a trilha para emitir o certificado.
            </div>
        <?php endif; ?>

        <?php if ($etapa === 'erro' && $mensagemErro): ?>
            <div class="alert alert-error"><?= $mensagemErro ?></div>
        <?php endif; ?>

        <?php if ($etapa === 'sucesso'): ?>
            <div class="alert alert-ok"><?= $successHtml ?></div>

            <?php if ($codigoCert): ?>
                <div class="cert-code">
                    <strong>Código do certificado:</strong><br>
                    <code><?= h($codigoCert) ?></code><br>
                    <span style="font-size:11px">Guarde este código. Ele poderá ser usado para validação futura.</span>
                </div>
            <?php endif; ?>

            <div class="pulsing-cta">
                <a href="<?= h($btnLink) ?>" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                    <?= h($btnLabel) ?>
                </a>
            </div>
        <?php elseif ($temTudoConcluido): ?>
            <form method="post" action="" id="certForm">
                <div class="form-group">
                    <label class="form-label" for="senha_certificado">Senha do certificado</label>
                    <input type="password" id="senha_certificado" name="senha_certificado" autocomplete="off" placeholder="Digite a senha recebida">
                </div>
                <button type="submit" class="btn-submit" id="btnEmitir">
                    <span class="spinner" aria-hidden="true"></span>
                    <span class="btn-text">Validar e emitir certificado</span>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($etapa === 'form' && !$temTudoConcluido && $incompletoVideoEnabled && $incompletoVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($incompletoVideoUrl, '<iframe') !== false): ?>
                        <?= $incompletoVideoUrl ?>
                    <?php else: ?>
                        <iframe src="<?= h(normalizar_video_url($incompletoVideoUrl)) ?>" title="Vídeo trilha incompleta" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($etapa === 'form' && $temTudoConcluido && $introVideoEnabled && $introVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($introVideoUrl, '<iframe') !== false): ?>
                        <?= $introVideoUrl ?>
                    <?php else: ?>
                        <iframe src="<?= h(normalizar_video_url($introVideoUrl)) ?>" title="Vídeo orientativo" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($etapa === 'sucesso' && $senhaVideoEnabled && $senhaVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($senhaVideoUrl, '<iframe') !== false): ?>
                        <?= $senhaVideoUrl ?>
                    <?php else: ?>
                        <iframe src="<?= h(normalizar_video_url($senhaVideoUrl)) ?>" title="Vídeo após senha correta" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($etapa === 'erro' && $erroSenha && $erroVideoEnabled && $erroVideoUrl !== ''): ?>
            <div class="video-box">
                <div class="video-inner">
                    <?php if (stripos($erroVideoUrl, '<iframe') !== false): ?>
                        <?= $erroVideoUrl ?>
                    <?php else: ?>
                        <iframe src="<?= h(normalizar_video_url($erroVideoUrl)) ?>" title="Vídeo de ajuda (senha incorreta)" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($etapa === 'confirmar_nome'): ?>
<div class="name-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="nameModalTitle">
    <form method="post" action="" class="name-modal" id="nameConfirmForm">
        <input type="hidden" name="acao" value="confirmar_nome">
        <input type="hidden" name="senha_certificado" value="<?= h($senhaConfirmada) ?>">
        <h2 id="nameModalTitle">Confira seu nome</h2>
        <p>Este será o nome impresso no certificado. Corrija se necessário antes de confirmar.</p>
        <?php if ($mensagemErro): ?>
            <div class="alert alert-error" style="margin-bottom:12px"><?= $mensagemErro ?></div>
        <?php endif; ?>
        <label class="form-label" for="nome_certificado">Nome no certificado</label>
        <input type="text" id="nome_certificado" name="nome_certificado" value="<?= h($nomeConfirmacao) ?>" autocomplete="name" required minlength="3">
        <div class="name-modal-note">Ao confirmar, o nome será atualizado no sistema e o certificado será gerado.</div>
        <div class="name-modal-actions">
            <button type="submit" class="btn-submit" id="btnConfirmarNome">
                <span class="spinner" aria-hidden="true"></span>
                <span class="btn-text">Confirmar e gerar certificado</span>
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
(function () {
    var form  = document.getElementById('certForm');
    var confirmForm = document.getElementById('nameConfirmForm');
    var nameInput = document.getElementById('nome_certificado');

    function lockForm(targetForm, buttonId, loadingText) {
        if (!targetForm) return;
        var locked = false;
        targetForm.addEventListener('submit', function (e) {
            if (locked) { e.preventDefault(); return; }
            locked = true;
            var btn = document.getElementById(buttonId);
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
                var txt = btn.querySelector('.btn-text');
                if (txt) txt.textContent = loadingText;
            }
            targetForm.querySelectorAll('input').forEach(function (input) {
                input.setAttribute('readonly', 'readonly');
            });
        });
    }

    lockForm(form, 'btnEmitir', 'Validando senha...');
    lockForm(confirmForm, 'btnConfirmarNome', 'Gerando certificado...');

    if (nameInput) {
        setTimeout(function () {
            nameInput.focus();
            nameInput.select();
        }, 50);
    }
})();
</script>

</body>
</html>
