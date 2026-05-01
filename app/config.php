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

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}
