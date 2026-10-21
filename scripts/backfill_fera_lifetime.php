<?php
declare(strict_types=1);

/**
 * Backfill: libera o vitalicio do curso de Iniciacao em Montagem de Quadros para
 * todas as compras FERA aprovadas do historico cujo comprador ja tem conta.
 * As compras novas ficam com o cron vitalicio_bonus (cron/processar_vitalicio_bonus.php).
 *
 * Uso: php scripts/backfill_fera_lifetime.php            (so simula)
 *      php scripts/backfill_fera_lifetime.php --apply    (grava)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so pode ser executado via linha de comando.');
}

require_once __DIR__ . '/../app/course_bonus_lifetime.php';

$apply = in_array('--apply', $argv, true);
$pdo = getPDO();
$result = course_bonus_lifetime_sync_sales($pdo, null, false, !$apply);
// Marca o ponto de partida da varredura de alunos novos do cron.
if ($apply) course_bonus_lifetime_sync_new_users($pdo);
echo json_encode(['apply' => $apply] + $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
