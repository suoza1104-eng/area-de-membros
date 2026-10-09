<?php
// FILE: scripts/sino_sync.php
// Comando de terminal da integracao com o Sino.
//
//   php scripts/sino_sync.php --teste --telefone=31XXXXXXXXX
//       Chama /v1/ping e envia um contato de teste para POST /v1/contacts (nunca o fluxo).
//   php scripts/sino_sync.php --turma=230926 [--dry-run]
//   php scripts/sino_sync.php --todas-futuras [--dry-run]
//       Carga inicial: coloca na fila (chamada 3, lote por turma) os alunos das
//       turmas com live no futuro. Nao dispara boas-vindas. Com --dry-run so conta.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/funcoes.php';

$opts = getopt('', ['teste', 'telefone:', 'turma:', 'todas-futuras', 'dry-run']);
$pdo = getPDO();

function out(string $line = ''): void { echo $line . PHP_EOL; }

// ===================== --teste =====================
if (isset($opts['teste'])) {
    $telefone = trim((string)($opts['telefone'] ?? ''));
    if (preg_replace('/\D+/', '', $telefone) === '') { out('Informe --telefone=31XXXXXXXXX'); exit(1); }
    if (sino_api_key() === '') { out('SINO_API_KEY nao configurada no .env'); exit(1); }
    out('API: ' . sino_api_url() . '   SINO_ENABLED=' . (sino_enabled() ? 'true' : 'false') . ' (o teste roda mesmo desligado)');

    $ping = sino_request('GET', '/ping');
    out('GET /v1/ping -> HTTP ' . $ping['status']);
    out(json_encode($ping['body'] ?? $ping['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($ping['status'] !== 200) exit(1);

    $body = [
        'phone' => $telefone,
        'name' => 'Teste Integração',
        'externalId' => 'teste-integracao',
        'fields' => [
            'turma' => 'teste',
            'data_live' => (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->modify('+3 days')->format(DATE_ATOM),
        ],
        'tags' => ['teste_integracao'],
    ];
    out('');
    out('POST /v1/contacts');
    out(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $res = sino_request('POST', '/contacts', $body, 'teste-integracao-' . sino_body_hash($body));
    out('-> HTTP ' . $res['status']);
    out(json_encode($res['body'] ?? $res['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    exit($res['status'] >= 200 && $res['status'] < 300 ? 0 : 1);
}

// ===================== CARGA INICIAL =====================
$dryRun = isset($opts['dry-run']);
if (!isset($opts['turma']) && !isset($opts['todas-futuras'])) {
    out('Uso: --teste --telefone=..., --turma=CODIGO ou --todas-futuras [--dry-run]');
    exit(1);
}
if (!$dryRun && !sino_enabled()) { out('SINO_ENABLED=false: nada sera enfileirado. Use --dry-run para simular.'); exit(1); }
if (!$dryRun) sino_ensure_schema($pdo);

if (isset($opts['turma'])) {
    $st = $pdo->prepare("SELECT codigo, data_live FROM turmas WHERE codigo = :c LIMIT 1");
    $st->execute([':c' => trim((string)$opts['turma'])]);
} else {
    $st = $pdo->query("SELECT codigo, data_live FROM turmas WHERE data_live > NOW() ORDER BY data_live");
}
$turmas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$turmas) { out('Nenhuma turma encontrada.'); exit(1); }

out(($dryRun ? '[SIMULACAO] ' : '') . 'Carga inicial no Sino (chamada 3 por turma, sem boas-vindas)');
$total = 0;
$ignoradosTotal = [];
foreach ($turmas as $t) {
    $codigo = (string)$t['codigo'];
    $qtd = 0; $lotes = 0; $after = 0; $ign = [];
    do {
        $ignLote = [];
        $lote = sino_turma_contacts($pdo, $codigo, $after, SINO_BATCH_MAX, $ignLote);
        foreach ($ignLote as $k => $v) $ign[$k] = ($ign[$k] ?? 0) + $v;
        $qtd += count($lote['contacts']);
        if ($lote['contacts']) $lotes++;
        $after = $lote['last_id'];
    } while ($lote['rows'] >= SINO_BATCH_MAX);
    foreach ($ign as $k => $v) $ignoradosTotal[$k] = ($ignoradosTotal[$k] ?? 0) + $v;
    $total += $qtd;
    out(sprintf('  turma %-10s live %s  alunos a enviar: %5d  lotes: %d  ignorados: %s',
        $codigo, (string)($t['data_live'] ?? '-'), $qtd, $lotes, $ign ? json_encode($ign, JSON_UNESCAPED_UNICODE) : '0'));
    if (!$dryRun && $qtd > 0) sino_turma_atualizada($codigo, 'carga_inicial');
}

// Alunos reagendados para uma live futura cuja turma original ja passou:
// nao entram no lote de nenhuma turma futura, entao vao individualmente (chamada 2).
$reag = [];
if (isset($opts['todas-futuras'])) {
    $st = $pdo->query("SELECT * FROM (" . sino_user_select($pdo) . ") x
                        WHERE x.user_live > NOW() AND (x.turma_live IS NULL OR x.turma_live <= NOW())");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $motivo = null;
        if (sino_contact_from_row($row, $motivo) === null) { $ignoradosTotal[$motivo] = ($ignoradosTotal[$motivo] ?? 0) + 1; continue; }
        $reag[] = (int)$row['id'];
        if (!$dryRun) sino_aluno_atualizado((int)$row['id']);
    }
    out(sprintf('  reagendados com live futura (turma ja encerrada): %d', count($reag)));
}

out('');
out(sprintf('Total de alunos %s: %d  (ignorados: %s)',
    $dryRun ? 'que seriam enviados' : 'colocados na fila', $total + count($reag),
    $ignoradosTotal ? json_encode($ignoradosTotal, JSON_UNESCAPED_UNICODE) : '0'));
