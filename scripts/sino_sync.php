<?php
// FILE: scripts/sino_sync.php
// Comando de terminal da integracao com o Sino.
//
//   php scripts/sino_sync.php --teste
//       Chama /v1/ping. Nao cria contatos no Sino.
//   php scripts/sino_sync.php --turma=230926 [--dry-run]
//   php scripts/sino_sync.php --todas-futuras [--dry-run]
//       Carga inicial esta desativada. Alunos so entram pelo bloco de automacao.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/funcoes.php';

$opts = getopt('', ['teste', 'telefone:', 'turma:', 'todas-futuras', 'dry-run']);
$pdo = getPDO();

function out(string $line = ''): void { echo $line . PHP_EOL; }

// ===================== --teste =====================
if (isset($opts['teste'])) {
    if (sino_api_key() === '') { out('SINO_API_KEY nao configurada no .env'); exit(1); }
    out('API: ' . sino_api_url() . '   SINO_ENABLED=' . (sino_enabled() ? 'true' : 'false') . ' (o teste roda mesmo desligado)');

    $ping = sino_request('GET', '/ping');
    out('GET /v1/ping -> HTTP ' . $ping['status']);
    out(json_encode($ping['body'] ?? $ping['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ping['status'] !== 200) exit(1);
    out('Teste concluido. Nenhum contato foi criado no Sino.');
    exit(0);

}

// ===================== CARGA INICIAL =====================
$dryRun = isset($opts['dry-run']);
if (!isset($opts['turma']) && !isset($opts['todas-futuras'])) {
    out('Uso: --teste --telefone=..., --turma=CODIGO ou --todas-futuras [--dry-run]');
    exit(1);
}
if (!$dryRun && !sino_enabled()) { out('SINO_ENABLED=false: nada sera enfileirado. Use --dry-run para simular.'); exit(1); }

$res = sino_initial_load($pdo, $dryRun, isset($opts['turma']) ? (string)$opts['turma'] : null);
if (!empty($res['disabled'])) {
    out((string)($res['message'] ?? 'Carga inicial desativada.'));
    exit(0);
}
if (!$res['turmas'] && !$res['reagendados']) { out('Nenhuma turma encontrada.'); exit(1); }

out(($dryRun ? '[SIMULACAO] ' : '') . 'Carga inicial no Sino (chamada 3 por turma, sem boas-vindas)');
foreach ($res['turmas'] as $t) {
    out(sprintf('  turma %-10s live %s  alunos a enviar: %5d  lotes: %d  ignorados: %s',
        $t['codigo'], $t['data_live'] ?: '-', $t['alunos'], $t['lotes'], $t['ignorados'] ? json_encode($t['ignorados'], JSON_UNESCAPED_UNICODE) : '0'));
}
// Reagendados para live futura cuja turma ja passou vao individualmente (chamada 2).
if (isset($opts['todas-futuras'])) out(sprintf('  reagendados com live futura (turma ja encerrada): %d', $res['reagendados']));

out('');
out(sprintf('Total de alunos %s: %d  (ignorados: %s)',
    $dryRun ? 'que seriam enviados' : 'colocados na fila', $res['total'],
    $res['ignorados'] ? json_encode($res['ignorados'], JSON_UNESCAPED_UNICODE) : '0'));
