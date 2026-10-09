<?php
// FILE: admin/_integracoes_sino_acoes.php
// Aba "Sino (WhatsApp)" da tela Integracoes: acoes do formulario e dados da aba.
// Incluido por integracoes.php ANTES do _header.php (pode redirecionar).
declare(strict_types=1);

$snCanWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$snCanWrite) {
    $snPerms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $snCanWrite = !empty($snPerms['integracoes']['escrever']);
}
if (empty($_SESSION['sino_csrf'])) $_SESSION['sino_csrf'] = bin2hex(random_bytes(24));
$snCsrf = (string)$_SESSION['sino_csrf'];

$snNotice = (string)($_GET['msg'] ?? '');
$snError = '';
$snTest = null;
$snLoad = null;

$snAction = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with($snAction, 'sino_')) {
    try {
        if (!$snCanWrite) throw new RuntimeException('Sem permissao para alterar a integracao.');
        if (!hash_equals($snCsrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.');

        if ($snAction === 'sino_save') {
            $url = rtrim(trim((string)($_POST['api_url'] ?? '')), '/');
            if ($url !== '' && !preg_match('~^https://~i', $url)) throw new RuntimeException('A URL da API precisa comecar com https://');
            set_setting('sino_api_url', $url);
            $key = trim((string)($_POST['api_key'] ?? ''));
            if ($key !== '') set_setting('sino_api_key', $key); // em branco = mantem a chave atual
            if (!empty($_POST['remove_key'])) set_setting('sino_api_key', '');
            set_setting('sino_enabled', !empty($_POST['enabled']) ? 'true' : 'false');
            header('Location: integracoes.php?tab=sino&msg=' . rawurlencode('Configuracao salva.'));
            exit;
        }
        if ($snAction === 'sino_test') {
            $snTest = sino_request('GET', '/ping');
        }
        if ($snAction === 'sino_load_preview') {
            $snLoad = sino_initial_load($pdo, true) + ['dry_run' => true];
        }
        if ($snAction === 'sino_load_run') {
            if (!sino_enabled()) throw new RuntimeException('Ative a integracao antes de enviar a carga inicial.');
            $snLoad = sino_initial_load($pdo, false) + ['dry_run' => false];
        }
    } catch (Throwable $e) {
        $snError = $e->getMessage();
    }
}

$snApiUrl = sino_api_url();
$snApiKey = sino_api_key();
$snEnabled = sino_enabled();
$snEnvKeys = array_filter(['SINO_API_URL', 'SINO_API_KEY', 'SINO_ENABLED'], 'sino_config_from_env');

$snQueue = ['pendente' => 0, 'enviado' => 0, 'erro_definitivo' => 0, 'ignorado' => 0];
$snErrors = [];
$snLastSent = '';
try {
    sino_ensure_schema($pdo);
    foreach ($pdo->query("SELECT status, COUNT(*) qtd FROM sino_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $snQueue[(string)$r['status']] = (int)$r['qtd'];
    }
    $snLastSent = (string)$pdo->query("SELECT MAX(enviado_em) FROM sino_outbox WHERE status = 'enviado'")->fetchColumn();
    $snErrors = $pdo->query("SELECT id, tipo, ref, status, tentativas, ultimo_http, ultimo_erro, criado_em
                               FROM sino_outbox
                              WHERE ultimo_erro IS NOT NULL AND ultimo_erro <> '' AND status <> 'enviado'
                           ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
