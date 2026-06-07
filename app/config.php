<?php
// FILE: app/config.php
declare(strict_types=1);

// Timezone padrão (evita divergência entre site e CRON)
date_default_timezone_set('America/Sao_Paulo');

// Detecta execução via CRON/CLI
$isCli = (PHP_SAPI === 'cli');

// Detecta ambiente local (apenas quando estiver acessando via navegador)
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (!$isCli) && (strpos($host, 'localhost') !== false);

// Erros: mostra apenas em localhost; em produção/CRON, loga sem "quebrar" headers
ini_set('display_errors', $isLocal ? '1' : '0');
ini_set('display_startup_errors', $isLocal ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Sessão: só faz sentido no navegador (CRON não usa sessão)
if (!$isCli && session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_start();
    }
}

/**
 * Sempre usa o BANCO REMOTO (HostGator),
 * mas troca apenas as URLs (BASE_URL) conforme o host.
 */

// Dados do banco remoto
define('DB_HOST', '108.167.132.40');
define('DB_NAME', 'prof2543_area_membros');
define('DB_USER', 'prof2543_area_membros');
define('DB_PASS', 'Emerson00*');

// Descobre se está rodando local (VS) ou no servidor
$host    = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (strpos($host, 'localhost') !== false);

// URLs base da aplicação
if ($isLocal) {
    // Quando estiver testando no VS / localhost
    define('BASE_URL',       'http://localhost/area_membros/public');
    define('BASE_URL_ADMIN', 'http://localhost/area_membros/admin');
} else {
    // Quando estiver no seu domínio
    define('BASE_URL',       'https://professoremersonleite.com/area_membros/public');
    define('BASE_URL_ADMIN', 'https://professoremersonleite.com/area_membros/admin');
}

// Senha padrão do certificado (pode trocar depois)
define('SENHA_CERTIFICADO', 'FERA2025');
define('APP_VERSION', 'v15');

/**
 * Credenciais do ADMIN (login da área administrativa)
 * Você pode mudar depois para algo mais seguro.
 */
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');

/**
 * Retorna instância única de PDO
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Evita que uma conexao lenta com o banco remoto trave a pagina
            // por minutos (o TCP connect padrao no Linux pode chegar a ~127s).
            PDO::ATTR_TIMEOUT            => 5,
        ];

        // Tenta conectar com 1 retry rapido para hiccups de rede transitorios.
        $tentativas = 0;
        while (true) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
                break;
            } catch (PDOException $e) {
                $tentativas++;
                if ($tentativas >= 2) throw $e;
                usleep(300000); // 0,3s antes de tentar de novo
            }
        }
    }

    return $pdo;
}

// ── Monitor de requisições lentas (diagnóstico) ──────────────────────────────
// Registra em app/error_log/slow_requests.log toda requisição que passar de
// SLOW_REQ_THRESHOLD segundos, para identificar gargalos reais sem precisar
// reproduzir na frente do usuário. Não altera o comportamento da página.
if (!defined('SLOW_REQ_MONITOR')) {
    define('SLOW_REQ_MONITOR', 1);
    register_shutdown_function(static function () {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if ($start === null) return;
        $elapsed = microtime(true) - (float)$start;
        if ($elapsed < 3.0) return; // só registra o que estiver lento
        $dir = __DIR__ . '/error_log';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $uri  = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? PHP_SAPI);
        $line = sprintf(
            "[%s] %.2fs %s %s (mem %.1fMB)\n",
            date('Y-m-d H:i:s'),
            $elapsed,
            $_SERVER['REQUEST_METHOD'] ?? PHP_SAPI,
            $uri,
            memory_get_peak_usage(true) / 1048576
        );
        @file_put_contents($dir . '/slow_requests.log', $line, FILE_APPEND);
    });
}
