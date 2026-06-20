<?php
declare(strict_types=1);

// Script temporário para diagnosticar a frequência real do Cron do servidor.
// Não carrega o sistema nem acessa o banco: apenas registra cada execução.

date_default_timezone_set('America/Sao_Paulo');

$logDir = __DIR__ . '/../uploads/cron_diagnostico';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    http_response_code(500);
    echo "Não foi possível criar o diretório persistente do teste.\n";
    exit(1);
}

// uploads/ é preservado pelo deploy, enquanto arquivos criados dentro de
// cron/ podem ser removidos pelo rsync --delete.
$logFile = $logDir . '/teste_cron_execucoes.log';
$log = fopen($logFile, 'c+');
if ($log === false || !flock($log, LOCK_EX)) {
    file_put_contents('php://stderr', "Não foi possível obter o lock do teste.\n");
    exit(1);
}

try {
    $now = time();
    $stat = fstat($log);
    $previous = (int)($stat['mtime'] ?? 0);
    $interval = $previous > 0 ? $now - $previous : null;

    $line = sprintf(
        "%s | utc=%s | intervalo=%s | pid=%d | sapi=%s\n",
        date('Y-m-d H:i:s'),
        gmdate('Y-m-d H:i:s'),
        $interval === null ? 'primeira_execucao' : $interval . 's',
        getmypid(),
        PHP_SAPI
    );

    if (fseek($log, 0, SEEK_END) !== 0 || fwrite($log, $line) === false) {
        throw new RuntimeException('Não foi possível gravar cron_teste.log.');
    }
    fflush($log);

    echo $line;
} catch (Throwable $e) {
    file_put_contents('php://stderr', $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($log, LOCK_UN);
    fclose($log);
}
