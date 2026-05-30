<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Endpoint de clique de botões (public):
 * - action=help -> marca tag BOTAO_HELP no aluno + dispara webhook configurado em Aparência (se habilitado)
 * - button_id -> comportamento existente (tag_on_click + webhook_event) mantido de forma compatível
 */

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION['aluno_id'] ?? 0);
if ($userId <= 0) {
    json_out(['ok' => false, 'error' => 'not_logged'], 401);
}
// Libera o lock da sessao (so houve leitura acima): evita prender outras
// requisicoes do mesmo aluno enquanto este faz banco + webhook.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = getPDO();

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE :t");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool)$st->fetch();
}

function detect_user_tags_table(PDO $pdo): array {
    $candidates = [
        ['user_tags', 'user_id', 'tag_id'],
        ['users_tags', 'user_id', 'tag_id'],
        ['user_tag', 'user_id', 'tag_id'],
        ['tags_users', 'user_id', 'tag_id'],
        ['user_tags_rel', 'user_id', 'tag_id'],
    ];
    foreach ($candidates as [$t,$uc,$tc]) {
        if (table_exists($pdo, $t) && col_exists($pdo, $t, $uc) && col_exists($pdo, $t, $tc)) {
            return [$t,$uc,$tc];
        }
    }
    return ['', '', ''];
}

function ensure_tag(PDO $pdo, string $tagName): int {
    if (!table_exists($pdo, 'tags') || !col_exists($pdo,'tags','id')) return 0;
    $nameCol = col_exists($pdo,'tags','nome') ? 'nome' : (col_exists($pdo,'tags','name') ? 'name' : '');
    if ($nameCol === '') return 0;

    $st = $pdo->prepare("SELECT id FROM tags WHERE `$nameCol` = :n LIMIT 1");
    $st->execute([':n'=>$tagName]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    // cria
    $cols = [$nameCol];
    $vals = [':n'];
    $params = [':n'=>$tagName];

    if (col_exists($pdo,'tags','created_at')) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    $sql = "INSERT INTO tags (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

function add_tag_to_user(PDO $pdo, int $userId, string $tagName, string $origem = 'BOTAO_HELP', int $refId = 0): bool {
    // Se existir função do app, usa (mantém padrão do sistema)
    if (function_exists('adicionar_tag')) {
        try {
            adicionar_tag($userId, $tagName, $origem, $refId);
            return true;
        } catch (Throwable $e) {
            // cai pro fallback
        }
    }

    $tagId = ensure_tag($pdo, $tagName);
    if ($tagId <= 0) return false;

    [$t,$uc,$tc] = detect_user_tags_table($pdo);
    if ($t === '') return false;

    // evita duplicar
    $st = $pdo->prepare("SELECT 1 FROM `$t` WHERE `$uc`=:u AND `$tc`=:t LIMIT 1");
    $st->execute([':u'=>$userId, ':t'=>$tagId]);
    if ($st->fetchColumn()) return true;

    // descobre colunas extras (created_at)
    $cols = [$uc, $tc];
    $vals = [':u', ':t'];
    $params = [':u'=>$userId, ':t'=>$tagId];

    if (col_exists($pdo,$t,'created_at')) {
        $cols[]='created_at';
        $vals[]='NOW()';
    }
    $sql = "INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $pdo->prepare($sql)->execute($params);
    return true;
}

function get_setting_safe(string $key, string $default = ''): string {
    if (function_exists('get_setting')) {
        try { return (string)get_setting($key, $default); } catch (Throwable $e) {}
    }
    return $default;
}

function insert_webhook_log(PDO $pdo, array $row): void {
    if (!table_exists($pdo,'webhook_logs')) return;

    // descobre colunas existentes
    $cols = [];
    $vals = [];
    $params = [];
    foreach ($row as $k=>$v) {
        if (col_exists($pdo,'webhook_logs',$k)) {
            $cols[] = $k;
            $vals[] = ':' . $k;
            $params[':' . $k] = $v;
        }
    }
    if (col_exists($pdo,'webhook_logs','created_at') && !isset($row['created_at'])) {
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    if (!$cols) return;

    $sql = "INSERT INTO webhook_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $pdo->prepare($sql)->execute($params);
}

function post_json(string $url, array $payload): array {
    $ch = curl_init($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json, text/plain, */*',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $start = microtime(true);
    $resp = curl_exec($ch);
    $ms = (int)round((microtime(true) - $start)*1000);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => ($err === '' && $code >= 200 && $code < 300),
        'http_code' => $code ?: null,
        'response_body' => is_string($resp) ? $resp : '',
        'error' => $err ?: null,
        'duration_ms' => $ms,
        'payload_json' => $json,
    ];
}

// ============================
// 1) CLICK DO BOTÃO DE AJUDA
// ============================
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === 'help') {
    // 1) marca TAG
    $tagOk = add_tag_to_user($pdo, $userId, 'BOTAO_HELP', 'BOTAO_HELP', 0);

    // 2) webhook configurável (Aparência)
    $enabled = (int)get_setting_safe('help_click_webhook_enabled', '0');
    $url = trim(get_setting_safe('help_click_webhook_url', ''));

    $webhookResult = null;

    if ($enabled === 1 && $url !== '') {
        // monta payload mínimo e consistente
        $st = $pdo->prepare("SELECT id, nome, email, telefone FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$userId]);
        $u = $st->fetch() ?: [];

        // Andamento do aluno (0..100) baseado em aulas obrigatórias (conta_para_conclusao = 1)
        $totalObrigatorias = 0;
        $totalConcluidas = 0;
        $andamento = 0;
        try {
            // Total de aulas obrigatórias ativas
            $stTot = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1");
            if ($stTot) {
                $totalObrigatorias = (int)$stTot->fetchColumn();
            }

            if ($totalObrigatorias > 0) {
                $stConc = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM lesson_progress lp
                    INNER JOIN lessons l ON l.id = lp.lesson_id
                    WHERE lp.user_id = :uid
                      AND lp.status = 'completed'
                      AND l.ativo = 1
                      AND l.conta_para_conclusao = 1
                ");
                $stConc->execute([':uid' => $userId]);
                $totalConcluidas = (int)$stConc->fetchColumn();

                $andamento = (int)round(100 * $totalConcluidas / $totalObrigatorias);
                if ($andamento < 0) $andamento = 0;
                if ($andamento > 100) $andamento = 100;
            } else {
                // se não houver obrigatórias, consideramos 100%
                $andamento = 100;
            }
        } catch (Throwable $e) {
            // deixa 0 mesmo
        }


        $payload = [
            'evento' => 'BOTAO_HELP',
            'timestamp' => date('c'),
            'timestamp_br' => date('d/m/Y H:i:s'),
            'aluno' => [
                'id' => $userId,
                'nome' => (string)($u['nome'] ?? ''),
                'email' => (string)($u['email'] ?? ''),
                'telefone' => preg_replace('/\D+/', '', (string)($u['telefone'] ?? '')),
                'andamento' => $andamento,
                'aulas_concluidas' => $totalConcluidas,
                'aulas_totais' => $totalObrigatorias,
            ],
            // Mantém o padrão que a tela de logs já entende para exibir a URL abaixo do evento
            'turma' => [
                'webhook_live_url' => $url,
            ],
        ];

        $webhookResult = post_json($url, $payload);

        insert_webhook_log($pdo, [
            'evento' => 'BOTAO_HELP',
            'user_id' => $userId,
            'webhook_live_url' => $url,
            'payload_json' => $webhookResult['payload_json'] ?? null,
            'response_status' => $webhookResult['http_code'] ?? null,
            'response_body' => $webhookResult['response_body'] ?? null,
            'error_message' => $webhookResult['error'] ?? null,
            'source' => 'public',
        ]);
    } else {
        // log opcional (sem webhook) - só se existir coluna evento
        
        // Monta payload para log interno (mesmo sem webhook)
        $st = $pdo->prepare("SELECT id, nome, email, telefone FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id'=>$userId]);
        $u = $st->fetch() ?: [];

        // Andamento (0..100)
        $totalObrigatorias = 0;
        $totalConcluidas = 0;
        $andamento = 0;
        try {
            $stTot = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1");
            if ($stTot) $totalObrigatorias = (int)$stTot->fetchColumn();
            if ($totalObrigatorias > 0) {
                $stConc = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM lesson_progress lp
                    INNER JOIN lessons l ON l.id = lp.lesson_id
                    WHERE lp.user_id = :uid
                      AND lp.status = 'completed'
                      AND l.ativo = 1
                      AND l.conta_para_conclusao = 1
                ");
                $stConc->execute([':uid' => $userId]);
                $totalConcluidas = (int)$stConc->fetchColumn();
                $andamento = (int)round(100 * $totalConcluidas / $totalObrigatorias);
                if ($andamento < 0) $andamento = 0;
                if ($andamento > 100) $andamento = 100;
            } else {
                $andamento = 100;
            }
        } catch (Throwable $e) {}

        $payload = [
            'evento' => 'BOTAO_HELP',
            'timestamp' => date('c'),
            'timestamp_br' => date('d/m/Y H:i:s'),
            'aluno' => [
                'id' => $userId,
                'nome' => (string)($u['nome'] ?? ''),
                'email' => (string)($u['email'] ?? ''),
                'telefone' => preg_replace('/\D+/', '', (string)($u['telefone'] ?? '')),
                'andamento' => $andamento,
                'aulas_concluidas' => $totalConcluidas,
                'aulas_totais' => $totalObrigatorias,
            ],
            'turma' => [
                'webhook_live_url' => $url,
            ],
        ];
insert_webhook_log($pdo, [
            'evento' => 'BOTAO_HELP',
            'user_id' => $userId,
            'webhook_live_url' => $url,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'response_status' => null,
            'response_body' => null,
            'error_message' => ($enabled === 1 && $url === '') ? 'Webhook BOTAO_HELP não configurado' : null,
            'source' => 'public',
        ]);
    }

    json_out(['ok'=>true, 'tag'=> $tagOk ? 1 : 0, 'webhook'=> $webhookResult ? ($webhookResult['ok'] ? 1 : 0) : 0]);
}

// ============================
// 2) COMPORTAMENTO EXISTENTE (button_id)
// ============================
$buttonId = (int)($_POST['button_id'] ?? 0);
if ($buttonId <= 0) {
    json_out(['ok' => false, 'error' => 'missing_button_id'], 400);
}

// tenta descobrir tabela de botões
$buttonTables = [
    'public_buttons',
    'botoes_public',
    'botoes',
    'app_buttons',
    'floating_buttons',
    'buttons',
];

$btn = null;
foreach ($buttonTables as $tb) {
    if (!table_exists($pdo, $tb)) continue;
    // precisa ter colunas que o código antigo usa
    if (!col_exists($pdo, $tb, 'id')) continue;
    if (!col_exists($pdo, $tb, 'tag_on_click') && !col_exists($pdo, $tb, 'webhook_event')) continue;

    $st = $pdo->prepare("SELECT * FROM `$tb` WHERE id = :id LIMIT 1");
    $st->execute([':id'=>$buttonId]);
    $row = $st->fetch();
    if ($row) { $btn = $row; break; }
}

if (!$btn) {
    json_out(['ok'=>false, 'error'=>'button_not_found'], 404);
}

if (!empty($btn['tag_on_click'])) {
    add_tag_to_user($pdo, $userId, (string)$btn['tag_on_click'], 'botao', $buttonId);
}
if (!empty($btn['webhook_event']) && function_exists('disparar_webhooks')) {
    try {
        disparar_webhooks((string)$btn['webhook_event'], $userId, ['button_id'=>$buttonId]);
    } catch (Throwable $e) {
        // não quebra clique
    }
}

json_out(['ok'=>true]);
