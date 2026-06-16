<?php
declare(strict_types=1);

// Compatibility entrypoint for old cron paths. Keep the real implementation in
// /cron so every scheduler path runs the same guarded logic.
$target = realpath(__DIR__ . '/../../cron/processar_reagendamentos_live.php');
if (!$target || $target === __FILE__) {
    echo "Cron canonico de reagendamentos nao encontrado.\n";
    exit(1);
}

require $target;
