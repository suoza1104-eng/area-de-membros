<?php
declare(strict_types=1);

/**
 * Indices das telas lentas do admin (analise de 22/10/2026). Idempotente: so cria
 * o que falta. ADD INDEX no InnoDB/MySQL 5.7 e online (a tabela segue gravavel).
 *
 * Uso: php scripts/perf_indexes_2026_10.php            (so mostra)
 *      php scripts/perf_indexes_2026_10.php --apply    (cria)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so pode ser executado via linha de comando.');
}

require_once __DIR__ . '/../app/funcoes.php';

$indexes = [
    // Reagendamentos de live: eventos (acessou/oferta/compra) por aluno.
    ['live_event_recebimentos', 'idx_ler_user', '(user_id, status, event_id)'],
    // Torpedo de voz: fila aberta x tentativas do job.
    ['voice_call_attempts', 'idx_voice_attempt_job', '(automation_job_id)'],
    // E-mail: contagens por modelo, filtro por data e por fluxo.
    ['email_messages', 'idx_em_tplver', '(template_version_id)'],
    ['email_messages', 'idx_em_created', '(created_at)'],
    ['email_messages', 'idx_em_flow', '(flow_id, template_version_id)'],
    // Vendas nao atribuidas: venda -> aluno ja casado.
    ['attribution_sales', 'idx_attr_sales_tx', '(transaction_code)'],
];

$apply = in_array('--apply', $argv, true);
$pdo = getPDO();
foreach ($indexes as [$table, $name, $cols]) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i");
        $st->execute([':t' => $table, ':i' => $name]);
        if ((int)$st->fetchColumn() > 0) { echo "ja existe  {$table}.{$name}\n"; continue; }
        if (!$apply) { echo "faltando   {$table}.{$name} {$cols}\n"; continue; }
        $t = microtime(true);
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}");
        printf("criado     %s.%s %s (%.1fs)\n", $table, $name, $cols, microtime(true) - $t);
    } catch (Throwable $e) {
        echo "ERRO       {$table}.{$name}: " . $e->getMessage() . "\n";
    }
}
