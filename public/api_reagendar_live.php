<?php
// FILE: public/api_reagendar_live.php
// Atualiza turma/data da live do aluno (SEM TOKEN). Autoriza via sessão logada OU sessão guest criada pelo link.
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

function rl_log(string $msg, string $file = 'reagendar_live_api.log'): void {
    $dir = __DIR__ . '/../app/error_log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    $path = $dir . '/' . $file;
    if (@file_put_contents($path, $line, FILE_APPEND) === false) {
        error_log('[reagendar_live_api] ' . $msg);
    }
}

function rl_json(bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function rl_fmt_dt($value): ?string {
    $v = is_string($value) ? trim($value) : '';
    if ($v === '') return null;
    try {
        $dt = new DateTimeImmutable($v);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $v;
    }
}


function rl_norm_phone(?string $v): string {
    $v = (string)$v;
    $digits = preg_replace('/\D+/', '', $v) ?: '';
    if (strlen($digits) > 11) $digits = substr($digits, -11);
    return $digits;
}

function rl_get_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    if (function_exists('get_setting')) {
        try {
            $v = get_setting($key);
            if ($v === null || $v === '') return $default;
            return (string)$v;
        } catch (\Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave = :k LIMIT 1");
        $st->execute(['k' => $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        $val = (string)($row['valor'] ?? '');
        return $val !== '' ? $val : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function rl_table_exists(PDO $pdo, string $t): bool {
    try{
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute(['t' => $t]);
        return (bool)$st->fetchColumn();
    }catch(\Throwable $e){ return false; }
}

function rl_col_exists(PDO $pdo, string $table, string $col): bool {
    try{
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute(['c' => $col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    }catch(\Throwable $e){ return false; }
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    rl_json(false, 'Payload inválido.');
}

$codigo = trim((string)($data['codigo_turma'] ?? ''));
$dtReq  = trim((string)($data['data_live'] ?? ''));

if ($codigo === '' || $dtReq === '') {
    rl_json(false, 'Dados incompletos.');
}

// Autoriza: aluno logado OU sessão guest válida
$alunoId = (int)($_SESSION['aluno_id'] ?? 0);
$guestId = (int)($_SESSION['reagendar_guest_uid'] ?? 0);
$guestExp = (int)($_SESSION['reagendar_guest_exp'] ?? 0);

if ($alunoId <= 0) {
    if ($guestId <= 0 || $guestExp <= time()) {
        rl_log("Não autorizado. codigo={$codigo}");
        rl_json(false, 'Sessão expirada. Abra o link novamente.');
    }
    $alunoId = $guestId;
}

try {
    // Carrega aluno
    $stU = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stU->execute(['id' => $alunoId]);
    $user = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$user) rl_json(false, 'Aluno não encontrado.');

    // Carrega turma (fonte da verdade)
    $stT = $pdo->prepare("SELECT * FROM turmas WHERE codigo = :c LIMIT 1");
    $stT->execute(['c' => $codigo]);
    $turma = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$turma) rl_json(false, 'Turma inválida.');

    $dataLive = (string)($turma['data_live'] ?? '');
    if ($dataLive === '') rl_json(false, 'Turma sem data de live.');

    // Valida: deve ser futura
    $now = new DateTimeImmutable('now');
    $dLive = new DateTimeImmutable($dataLive);
    if ($dLive < $now) rl_json(false, 'Esta live já passou.');

    // Regra: ignorar janela_inicio/janela_fim (reagendamento sempre permitido para lives futuras)

    $pdo->beginTransaction();

    $oldCodigo = (string)($user['codigo_turma'] ?? '');
    $oldCodigo2 = (string)($user['turma_codigo'] ?? '');
    $oldLiveAt = (string)($user['turma_live_at'] ?? ($user['data_live'] ?? ''));

    // Monta UPDATE dinâmico (colunas podem variar)
    $sets = [];
    $params = ['id' => $alunoId];

    if (rl_col_exists($pdo, 'users', 'codigo_turma')) {
        $sets[] = "codigo_turma = :codigo_turma";
        $params['codigo_turma'] = $codigo;
    }
    if (rl_col_exists($pdo, 'users', 'turma_codigo')) {
        $sets[] = "turma_codigo = :turma_codigo";
        $params['turma_codigo'] = $codigo;
    }
    if (rl_col_exists($pdo, 'users', 'turma_live_at')) {
        $sets[] = "turma_live_at = :turma_live_at";
        $params['turma_live_at'] = $dataLive;
    }
    if (rl_col_exists($pdo, 'users', 'data_live')) {
        $sets[] = "data_live = :data_live";
        $params['data_live'] = $dataLive;
    }

    if (!$sets) {
        $pdo->rollBack();
        rl_json(false, 'Estrutura do banco incompatível (colunas do aluno não encontradas).');
    }

    $sqlUp = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
    $stUp = $pdo->prepare($sqlUp);
    $stUp->execute($params);

    // Grava histórico (se tabela existir)
    $webhookUrl = (string)rl_get_setting($pdo, 'reagendar_webhook_url', '');
    $histTable = 'reagendamentos_live';
    if (rl_table_exists($pdo, $histTable)) {
        $cols = [];
        $vals = [];
        $hp = [];

        $possible = [
            'user_id' => $alunoId,
            'old_codigo_turma' => ($oldCodigo !== '' ? $oldCodigo : $oldCodigo2),
            'new_codigo_turma' => $codigo,
            'old_turma_live_at' => ($oldLiveAt !== '' ? $oldLiveAt : null),
            'new_turma_live_at' => $dataLive,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            'webhook_url' => ($webhookUrl !== '' ? $webhookUrl : null),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        foreach ($possible as $c => $v) {
            if (rl_col_exists($pdo, $histTable, $c)) {
                $cols[] = "`$c`";
                $vals[] = ":$c";
                $hp[$c] = $v;
            }
        }

        if ($cols) {
            $stH = $pdo->prepare("INSERT INTO `$histTable` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
            $stH->execute($hp);
        }
    }

    $pdo->commit();

    // Dispara webhook (fora da transação)
    
// Resolve codigo_live (slug) da turma nova e (se possível) da turma antiga
$codigoLiveNovo = null;
if (isset($turma['codigo_live'])) {
    $v = trim((string)$turma['codigo_live']);
    $codigoLiveNovo = ($v !== '' ? $v : null);
}

$codigoLiveAntigo = null;
$turmaAntigaCodigo = ($oldCodigo !== '' ? $oldCodigo : $oldCodigo2);
if ($turmaAntigaCodigo !== '') {
    try {
        // Se a coluna não existir, apenas ignora
        $stChk = $pdo->prepare("SHOW COLUMNS FROM `turmas` LIKE 'codigo_live'");
        $stChk->execute();
        if ($stChk->fetch(PDO::FETCH_ASSOC)) {
            $stOld = $pdo->prepare("SELECT codigo_live FROM turmas WHERE codigo = :c LIMIT 1");
            $stOld->execute([':c' => $turmaAntigaCodigo]);
            $vv = $stOld->fetchColumn();
            $vv = is_string($vv) ? trim($vv) : '';
            $codigoLiveAntigo = ($vv !== '' ? $vv : null);
        }
    } catch (Throwable $e) {
        // ignora
    }
}

$payload = [
        'evento' => 'LIVE_REAGENDADA',
        'codigo_live' => $codigoLiveNovo,
                'data_live' => $dLive->format('d/m/Y H:i'),
'aluno' => [
            'id' => $user['id'] ?? null,
            'nome' => $user['nome'] ?? null,
            'email' => $user['email'] ?? null,
            'telefone' => $user['telefone'] ?? null,
        ],
        'reagendamento' => [
            'turma_antiga' => ($oldCodigo !== '' ? $oldCodigo : $oldCodigo2),
            'turma_nova' => $codigo,
            'codigo_live_antigo' => $codigoLiveAntigo,
            'codigo_live_novo' => $codigoLiveNovo,
            'live_antiga' => (rl_fmt_dt($oldLiveAt) ?? $oldLiveAt),
            'live_nova' => $dLive->format('d/m/Y H:i'),
        ],
        'timestamp' => date('c'),
    ];

    $webhookOk = null;
    $webhookErr = null;

    if ($webhookUrl !== '') {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $webhookUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            $webhookOk = ($code >= 200 && $code < 300);
            if (!$webhookOk) {
                $webhookErr = "HTTP $code " . ($err ?: substr((string)$resp, 0, 300));
            }
            rl_log("Webhook reagendar: url={$webhookUrl} code={$code} ok=" . ($webhookOk ? '1' : '0'));
        } catch (\Throwable $e) {
            $webhookOk = false;
            $webhookErr = $e->getMessage();
            rl_log("Webhook erro: " . $e->getMessage());
        }
    }

    // Se existir webhook_logs do sistema, registra também (opcional)
    if (rl_table_exists($pdo, 'webhook_logs')) {
        try{
            $stL = $pdo->prepare("
                INSERT INTO webhook_logs (evento, user_id, url, http_code, ok, erro, payload_json, created_at)
                VALUES (:evento, :user_id, :url, :http_code, :ok, :erro, :payload_json, NOW())
            ");
            $stL->execute([
                'evento' => 'LIVE_REAGENDADA',
        'codigo_live' => $codigoLiveNovo,
                        'data_live' => $dLive->format('d/m/Y H:i'),
'user_id' => $alunoId,
                'url' => $webhookUrl,
                'http_code' => $webhookOk === null ? null : ($webhookOk ? 200 : 500),
                'ok' => $webhookOk === null ? 1 : ($webhookOk ? 1 : 0),
                'erro' => $webhookErr,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        }catch(\Throwable $e){
            // ignora
        }
    }

    rl_log("Reagendou: user={$alunoId} turma={$codigo} live={$dataLive}");
    rl_json(true, 'Reagendado com sucesso.', [
        'turma' => $codigo,
        'live_nova' => $dLive->format('d/m/Y H:i'),
        'webhook_ok' => $webhookOk,
        'webhook_error' => $webhookErr,
    ]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    rl_log("Erro: " . $e->getMessage());
    rl_json(false, 'Erro interno: ' . $e->getMessage());
}
