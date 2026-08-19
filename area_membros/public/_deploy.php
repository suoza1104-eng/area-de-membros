<?php
/**
 * Webhook de deploy automático — GitHub push -> HostGator.
 *
 * Fluxo: o GitHub envia um POST a cada push. Este script valida a assinatura
 * HMAC (segredo compartilhado), e então atualiza o repositório no servidor e
 * sincroniza para a pasta pública (mesmas tarefas do .cpanel.yml).
 *
 * Segurança: só executa se a assinatura X-Hub-Signature-256 bater com o
 * segredo. Sem assinatura válida -> 403. O repositório é privado.
 */
declare(strict_types=1);

// O segredo NÃO fica no repositório. Ele é lido de um arquivo fora da área
// pública (crie uma vez via cPanel File Manager). Veja DEPLOY_SETUP.md.
const SECRET_FILE = '/home1/prof2543/.deploy_secret';
const REPO_DIR    = '/home1/prof2543/repositories/area-de-membros';
const DEPLOY_DIR  = '/home1/prof2543/public_html/area_membros';
const DEPLOY_LOG  = '/home1/prof2543/repositories/area-de-membros/deploy.log';

function dep_log(string $msg): void {
    @file_put_contents(DEPLOY_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

header('Content-Type: text/plain; charset=utf-8');

$secret = is_readable(SECRET_FILE) ? trim((string)file_get_contents(SECRET_FILE)) : '';
if ($secret === '') {
    http_response_code(500);
    dep_log('ERRO: arquivo de segredo ausente ou vazio em ' . SECRET_FILE);
    echo 'misconfigured';
    exit;
}

$raw   = file_get_contents('php://input') ?: '';
$sig   = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT']      ?? '';

// 1) Valida a assinatura HMAC do GitHub
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if ($sig === '' || !hash_equals($expected, $sig)) {
    http_response_code(403);
    dep_log('REJEITADO: assinatura invalida (event=' . $event . ')');
    echo 'forbidden';
    exit;
}

// 2) Ping de teste do GitHub
if ($event === 'ping') {
    dep_log('ping OK');
    echo 'pong';
    exit;
}

// 3) Só interessa push na branch main
if ($event !== 'push') {
    http_response_code(202);
    dep_log('evento ignorado: ' . $event);
    echo 'ignored';
    exit;
}
$payload = json_decode($raw, true) ?: [];
if (($payload['ref'] ?? '') !== 'refs/heads/main') {
    dep_log('ref ignorada: ' . ($payload['ref'] ?? '(vazio)'));
    echo 'branch ignored';
    exit;
}

// 4) Responde imediatamente e roda o deploy em segundo plano
echo 'deploy iniciado';
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
}

if (!function_exists('exec')) {
    dep_log('ERRO: exec() esta desabilitado no PHP — use o cron (deploy.sh)');
    exit;
}

dep_log('deploy iniciado (push main)');
$cmds = [
    'cd ' . escapeshellarg(REPO_DIR) . ' && git fetch --all -q 2>&1',
    'cd ' . escapeshellarg(REPO_DIR) . ' && git reset --hard origin/main 2>&1',
    '/usr/bin/rsync -a --delete --no-perms --exclude=.git --exclude=uploads/ --exclude=vendor/ '
        . escapeshellarg(REPO_DIR . '/') . ' ' . escapeshellarg(DEPLOY_DIR . '/') . ' 2>&1',
    '/bin/chmod -R u=rwX,go=rX ' . escapeshellarg(DEPLOY_DIR) . ' 2>&1',
];
foreach ($cmds as $c) {
    $out = [];
    $rc  = 0;
    @exec($c, $out, $rc);
    dep_log('rc=' . $rc . ' :: ' . $c . ' :: ' . implode(' | ', array_slice($out, 0, 4)));
}
dep_log('deploy concluido');
