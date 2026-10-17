<?php
// FILE: app/sino_live.php
// Aviso de live por turma enviado aos "Disparos" do Sino (Automacao -> Disparos).
//
// O Sino ja sabe as etapas, o ritmo, a janela do dia e os numeros: aqui so
//   1) listamos os disparos do Sino para o admin escolher (guardamos o code),
//   2) na hora marcada (turmas.live_disparo_data) filtramos os alunos com os
//      MESMOS filtros do disparo SF (app/live_turma_filters.php),
//   3) mandamos pacotes em sequencia para POST /dispatch-configs/{code}/batch.
//
// O processamento roda pelo cron `lives_sino` (cron/processar_lives_sino.php) em
// duas fases guardadas no banco, para retomar de onde parou se cair:
//   preparando -> le os alunos da turma e aplica os filtros (cursor por user_id)
//   enviando   -> manda um pacote por vez (lotes numerados, Idempotency-Key fixa)
declare(strict_types=1);

require_once __DIR__ . '/sino.php';
require_once __DIR__ . '/live_turma_filters.php';

const SINO_LIVE_BATCH_SIZES      = [500, 1000, 2000, 5000];
const SINO_LIVE_BATCH_DEFAULT    = 1000;
const SINO_LIVE_HTTP_TIMEOUT_S   = 300;
const SINO_LIVE_PAUSE_BETWEEN_S  = 2;
const SINO_LIVE_RETRY_WAITS_S    = [5, 15, 45];
const SINO_LIVE_SCAN_PAGE        = 300;

// ===================== SCHEMA =====================

function sino_live_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    foreach ([
        'sino_live_enabled'     => "ALTER TABLE turmas ADD COLUMN sino_live_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'sino_dispatch_code'    => "ALTER TABLE turmas ADD COLUMN sino_dispatch_code VARCHAR(150) NULL",
        'sino_dispatch_name'    => "ALTER TABLE turmas ADD COLUMN sino_dispatch_name VARCHAR(255) NULL",
        'sino_batch_size'       => "ALTER TABLE turmas ADD COLUMN sino_batch_size INT NOT NULL DEFAULT 1000",
        'sino_remessa'          => "ALTER TABLE turmas ADD COLUMN sino_remessa VARCHAR(150) NULL",
        'sino_live_disparada'   => "ALTER TABLE turmas ADD COLUMN sino_live_disparada TINYINT(1) NOT NULL DEFAULT 0",
        'sino_live_dispatch_id' => "ALTER TABLE turmas ADD COLUMN sino_live_dispatch_id BIGINT UNSIGNED NULL",
    ] as $col => $sql) {
        if (!sino_column_exists($pdo, 'turmas', $col)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS sino_live_dispatches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        turma_id INT NOT NULL,
        turma_codigo VARCHAR(100) NOT NULL,
        dispatch_code VARCHAR(150) NOT NULL,
        dispatch_name VARCHAR(255) NULL,
        remessa VARCHAR(150) NOT NULL,
        batch_size INT NOT NULL DEFAULT 1000,
        status VARCHAR(20) NOT NULL DEFAULT 'preparando',
        scan_cursor BIGINT NOT NULL DEFAULT 0,
        total INT NOT NULL DEFAULT 0,
        pulados_local INT NOT NULL DEFAULT 0,
        pulados_json TEXT NULL,
        lotes_total INT NOT NULL DEFAULT 0,
        lotes_enviados INT NOT NULL DEFAULT 0,
        received INT NOT NULL DEFAULT 0,
        accepted INT NOT NULL DEFAULT 0,
        steps_json TEXT NULL,
        errors_count INT NOT NULL DEFAULT 0,
        sino_status VARCHAR(20) NULL,
        retry_count INT NOT NULL DEFAULT 0,
        last_http SMALLINT NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        prepared_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_sld_turma (turma_id, id),
        KEY idx_sld_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sino_live_recipients (
        dispatch_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL,
        motivo VARCHAR(255) NULL,
        telefone VARCHAR(40) NULL,
        lote INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (dispatch_id, user_id),
        KEY idx_slr_lote (dispatch_id, lote, status),
        KEY idx_slr_status (dispatch_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ===================== API DO SINO =====================

/** GET /dispatch-configs: disparos do tipo API nao arquivados. Sempre consulta na hora. */
function sino_live_list_configs(): array
{
    if (sino_api_key() === '') return ['ok' => false, 'dispatches' => [], 'error' => 'Chave da API do Sino nao configurada.'];
    $res = sino_request('GET', '/dispatch-configs');
    if ($res['status'] !== 200 || !is_array($res['body']['dispatches'] ?? null)) {
        return ['ok' => false, 'dispatches' => [], 'error' => $res['error'] !== '' ? $res['error'] : 'Resposta inesperada do Sino.'];
    }
    return ['ok' => true, 'dispatches' => array_values(array_filter($res['body']['dispatches'], 'is_array')), 'error' => ''];
}

/** GET /dispatch-configs/{code}: dados + progress (por etapa e por remessa). */
function sino_live_get_config(string $code): array
{
    return sino_request('GET', '/dispatch-configs/' . rawurlencode($code));
}

// ===================== REMESSA =====================

function sino_live_auto_remessa(array $turma): string
{
    $codigo = trim((string)($turma['codigo'] ?? ''));
    $ts = strtotime((string)($turma['data_live'] ?? ''));
    return 'turma-' . $codigo . '-' . ($ts ? date('Y-m-d', $ts) : 'sem-data');
}

/** Remessa usada no envio: a digitada pelo admin ou a automatica (turma + data da live). */
function sino_live_remessa(array $turma): string
{
    $manual = trim((string)($turma['sino_remessa'] ?? ''));
    return $manual !== '' ? $manual : sino_live_auto_remessa($turma);
}

function sino_live_batch_size($value): int
{
    $n = (int)$value;
    return in_array($n, SINO_LIVE_BATCH_SIZES, true) ? $n : SINO_LIVE_BATCH_DEFAULT;
}

// ===================== ESTADO DO ENVIO =====================

function sino_live_dispatch(PDO $pdo, int $dispatchId): ?array
{
    if ($dispatchId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM sino_live_dispatches WHERE id = :id LIMIT 1');
    $st->execute([':id' => $dispatchId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sino_live_update(PDO $pdo, int $dispatchId, array $fields): void
{
    if (!$fields) return;
    $set = []; $params = [':id' => $dispatchId];
    foreach ($fields as $col => $value) {
        if ($value === 'NOW()') { $set[] = "`$col` = NOW()"; continue; }
        $set[] = "`$col` = :$col";
        $params[":$col"] = $value;
    }
    $pdo->prepare('UPDATE sino_live_dispatches SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
}

function sino_live_log(string $turmaCodigo, int $http, string $mensagem, array $ctx = []): void
{
    $ok = $http === 0 || ($http >= 200 && $http < 300);
    $linha = '[sino_live] turma=' . $turmaCodigo . ' http=' . $http . ' ' . $mensagem;
    try {
        if (function_exists('log_sistema')) log_sistema($ok ? 'info' : 'error', 'sino_live', $linha, $ctx + ['turma' => $turmaCodigo, 'http' => $http]);
        else @error_log($linha);
    } catch (Throwable $e) {
        @error_log($linha);
    }
}

/** Cria o registro do envio para a turma (remessa e lote congelados neste momento). */
function sino_live_create_dispatch(PDO $pdo, array $turma): int
{
    $pdo->prepare("INSERT INTO sino_live_dispatches (turma_id, turma_codigo, dispatch_code, dispatch_name, remessa, batch_size, status)
                   VALUES (:t, :c, :code, :name, :rem, :bs, 'preparando')")
        ->execute([
            ':t' => (int)$turma['id'],
            ':c' => (string)$turma['codigo'],
            ':code' => trim((string)$turma['sino_dispatch_code']),
            ':name' => trim((string)($turma['sino_dispatch_name'] ?? '')) ?: null,
            ':rem' => sino_live_remessa($turma),
            ':bs' => sino_live_batch_size($turma['sino_batch_size'] ?? SINO_LIVE_BATCH_DEFAULT),
        ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE turmas SET sino_live_dispatch_id = :d WHERE id = :id')->execute([':d' => $id, ':id' => (int)$turma['id']]);
    return $id;
}

// ===================== FASE 1: PUBLICO (mesmos filtros do SF) =====================

/** Nova data da live de um aluno reagendado (mesma regra de user_has_active_live_reschedule). */
function sino_live_reschedule_date(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0 || !table_exists($pdo, 'reagendamentos_live')) return null;
    try {
        $st = $pdo->prepare("
            SELECT new_turma_live_at
              FROM reagendamentos_live
             WHERE user_id = :u
               AND status IN ('reagendado', 'enviado')
               AND new_turma_live_at IS NOT NULL
               AND new_turma_live_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          ORDER BY created_at DESC, id DESC
             LIMIT 1
        ");
        $st->execute([':u' => $userId]);
        $v = (string)($st->fetchColumn() ?: '');
        return $v !== '' ? $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

function sino_live_recipient(PDO $pdo, int $dispatchId, int $userId, string $status, ?string $motivo, ?string $telefone): void
{
    $pdo->prepare("INSERT INTO sino_live_recipients (dispatch_id, user_id, status, motivo, telefone)
                   VALUES (:d, :u, :s, :m, :t)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), motivo = VALUES(motivo), telefone = VALUES(telefone)")
        ->execute([':d' => $dispatchId, ':u' => $userId, ':s' => $status, ':m' => $motivo, ':t' => $telefone]);
}

/**
 * Le os alunos da turma (mesma selecao do disparo SF: users.codigo_turma e,
 * se houver, as tags obrigatorias) e aplica os mesmos filtros de exclusao, na
 * mesma ordem. Avanca por cursor de user_id; devolve true quando terminou.
 */
function sino_live_prepare(PDO $pdo, array $turma, array $dispatch, float $deadline): bool
{
    $dispatchId = (int)$dispatch['id'];
    $codigo = (string)$turma['codigo'];
    $filter = live_filter_config($turma['live_filter_tag_ids'] ?? null);
    $tagRelTable = first_existing_table($pdo, ['user_tags', 'usuarios_tags', 'aluno_tags', 'users_tags', 'tags_users', 'user_tag_rel', 'user_tag_relations']);
    $includeTagIds = $filter['include_any'];
    if ($includeTagIds && !$tagRelTable) {
        // Mesmo comportamento do SF: filtro de tags sem tabela de relacao = ninguem.
        return true;
    }
    $optOutCol = sino_column_exists($pdo, 'users', 'whatsapp_opt_out') ? 'COALESCE(u.whatsapp_opt_out,0)' : '0';
    $bloqCol = sino_column_exists($pdo, 'users', 'bloquear') ? 'COALESCE(u.bloquear,0)' : '0';
    $totalObrigatorias = $filter['exclude_zero'] ? total_aulas_obrigatorias($pdo) : 0;
    $cursor = (int)$dispatch['scan_cursor'];

    while (microtime(true) < $deadline) {
        if ($includeTagIds) {
            $in = implode(',', array_fill(0, count($includeTagIds), '?'));
            $st = $pdo->prepare("SELECT u.id, u.telefone, {$optOutCol} AS opt_out, {$bloqCol} AS bloq
                                   FROM users u JOIN `$tagRelTable` ut ON ut.user_id = u.id
                                  WHERE u.codigo_turma = ? AND u.id > ? AND ut.tag_id IN ($in)
                               GROUP BY u.id ORDER BY u.id ASC LIMIT " . SINO_LIVE_SCAN_PAGE);
            $st->execute(array_merge([$codigo, $cursor], $includeTagIds));
        } else {
            $st = $pdo->prepare("SELECT u.id, u.telefone, {$optOutCol} AS opt_out, {$bloqCol} AS bloq
                                   FROM users u WHERE u.codigo_turma = :c AND u.id > :cur ORDER BY u.id ASC LIMIT " . SINO_LIVE_SCAN_PAGE);
            $st->execute([':c' => $codigo, ':cur' => $cursor]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $uid = (int)$row['id'];
            $tel = trim((string)($row['telefone'] ?? ''));
            $motivo = null;
            if ($filter['exclude_zero'] && (int)calc_andamento($pdo, $uid, $totalObrigatorias)['andamento'] <= 0) $motivo = 'andamento_zero';
            elseif ($filter['exclude_any'] && user_has_any_tag($pdo, $tagRelTable, $uid, $filter['exclude_any'])) $motivo = 'tag_excluida';
            elseif ($filter['exclude_cert'] && user_has_certificate($pdo, $uid)) $motivo = 'certificado_emitido';
            elseif ($filter['exclude_purchase'] && user_has_purchase($pdo, $uid)) $motivo = 'compra_identificada';
            elseif ($filter['exclude_rescheduled'] && user_has_active_live_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) $motivo = 'live_reagendada';
            elseif ((int)$row['opt_out'] === 1) $motivo = 'nao_quer_whatsapp';
            elseif ((int)$row['bloq'] !== 0) $motivo = 'aluno_bloqueado';
            elseif (preg_replace('/\D+/', '', $tel) === '') $motivo = 'sem_telefone';
            sino_live_recipient($pdo, $dispatchId, $uid, $motivo === null ? 'elegivel' : 'pulado', $motivo, $tel !== '' ? $tel : null);
            $cursor = $uid;
        }
        sino_live_update($pdo, $dispatchId, ['scan_cursor' => $cursor]);
        if (count($rows) < SINO_LIVE_SCAN_PAGE) return true;
    }
    return false;
}

/** Numera os lotes (ordem por user_id) e fixa o total da remessa. */
function sino_live_finish_prepare(PDO $pdo, array $dispatch): array
{
    $dispatchId = (int)$dispatch['id'];
    $batch = max(1, (int)$dispatch['batch_size']);
    $st = $pdo->prepare("SELECT user_id FROM sino_live_recipients WHERE dispatch_id = :d AND status = 'elegivel' ORDER BY user_id ASC");
    $st->execute([':d' => $dispatchId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    foreach (array_chunk($ids, $batch) as $i => $chunk) {
        $in = implode(',', $chunk);
        $pdo->prepare("UPDATE sino_live_recipients SET lote = :l WHERE dispatch_id = :d AND user_id IN ($in)")->execute([':l' => $i + 1, ':d' => $dispatchId]);
    }
    $pulados = [];
    $st = $pdo->prepare("SELECT motivo, COUNT(*) c FROM sino_live_recipients WHERE dispatch_id = :d AND status = 'pulado' GROUP BY motivo");
    $st->execute([':d' => $dispatchId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $pulados[(string)$r['motivo']] = (int)$r['c'];
    $total = count($ids);
    $fields = [
        'total' => $total,
        'lotes_total' => (int)ceil($total / $batch),
        'pulados_local' => array_sum($pulados),
        'pulados_json' => json_encode($pulados, JSON_UNESCAPED_UNICODE),
        'prepared_at' => 'NOW()',
        'status' => $total > 0 ? 'enviando' : 'concluido',
    ];
    if ($total === 0) $fields['finished_at'] = 'NOW()';
    sino_live_update($pdo, $dispatchId, $fields);
    return sino_live_dispatch($pdo, $dispatchId) ?? $dispatch;
}

// ===================== FASE 2: PACOTES =====================

/** Contatos do lote, no formato do Sino, com data_live/link_live da live desta turma. */
function sino_live_lote_contacts(PDO $pdo, array $turma, array $dispatch, int $lote, array &$userIds): array
{
    $st = $pdo->prepare("SELECT user_id FROM sino_live_recipients WHERE dispatch_id = :d AND lote = :l AND status = 'elegivel' ORDER BY user_id ASC");
    $st->execute([':d' => (int)$dispatch['id'], ':l' => $lote]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $userIds = [];
    if (!$ids) return [];
    $st = $pdo->query(sino_user_select($pdo) . ' WHERE u.id IN (' . implode(',', $ids) . ') ORDER BY u.id ASC');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    sino_attach_magic_links($pdo, $rows);

    $turmaLive = sino_iso_datetime($turma['data_live'] ?? '');
    $turmaLink = trim((string)($turma['link_live'] ?? ''));
    $repescagem = function_exists('get_setting') ? trim((string)get_setting('reagendar_live_url', '')) : '';
    $filter = live_filter_config($turma['live_filter_tag_ids'] ?? null);
    $contacts = [];
    foreach ($rows as $row) {
        $uid = (int)$row['id'];
        $motivo = null;
        $contact = sino_contact_from_row($row, $motivo);
        if ($contact === null) {
            sino_live_recipient($pdo, (int)$dispatch['id'], $uid, 'pulado', str_replace(' ', '_', (string)$motivo), $row['telefone'] ?? null);
            continue;
        }
        $fields = is_array($contact['fields'] ?? null) ? $contact['fields'] : [];
        // Data da live DESTA turma (o campo do aluno pode estar desatualizado). Reagendados
        // so chegam aqui se o admin marcou "incluir"; neles vale a nova data individual.
        $dataLive = $turmaLive;
        $link = $turmaLink;
        if (empty($filter['exclude_rescheduled']) && ($nova = sino_live_reschedule_date($pdo, $uid)) !== null) {
            $dataLive = sino_iso_datetime($nova) ?? $turmaLive;
            if ($repescagem !== '') $link = $repescagem;
        }
        if ($dataLive !== null) $fields['data_live'] = $dataLive; else unset($fields['data_live']);
        if ($link !== '') $fields['link_live'] = $link;
        $fields['turma'] = (string)$turma['codigo'];
        $contact['fields'] = $fields;
        $contacts[] = $contact;
        $userIds[] = $uid;
    }
    return $contacts;
}

/** Soma o resultado de um pacote nos totais por etapa. */
function sino_live_merge_steps(array $acc, array $steps): array
{
    foreach ($steps as $s) {
        if (!is_array($s)) continue;
        $k = (string)($s['step'] ?? count($acc) + 1);
        $cur = $acc[$k] ?? ['step' => (int)($s['step'] ?? 0), 'flow' => (string)($s['flow'] ?? ''), 'scheduled' => 0, 'skipped' => 0, 'reasons' => []];
        $cur['flow'] = (string)($s['flow'] ?? $cur['flow']);
        $cur['scheduled'] += (int)($s['scheduled'] ?? 0);
        $cur['skipped'] += (int)($s['skipped'] ?? 0);
        foreach ((array)($s['reasons'] ?? []) as $reason => $n) $cur['reasons'][(string)$reason] = ($cur['reasons'][(string)$reason] ?? 0) + (int)$n;
        $acc[$k] = $cur;
    }
    ksort($acc, SORT_NUMERIC);
    return $acc;
}

/**
 * Envia o proximo pacote. Devolve 'ok' (pacote aceito), 'retry' (tentar de
 * novo mais tarde), 'erro' (parado: precisa do admin) ou 'fim' (nada a enviar).
 */
function sino_live_send_next(PDO $pdo, array $turma, array &$dispatch): string
{
    $dispatchId = (int)$dispatch['id'];
    $lote = (int)$dispatch['lotes_enviados'] + 1;
    if ($lote > (int)$dispatch['lotes_total']) return 'fim';
    $code = (string)$dispatch['dispatch_code'];
    $remessa = (string)$dispatch['remessa'];

    $userIds = [];
    $contacts = sino_live_lote_contacts($pdo, $turma, $dispatch, $lote, $userIds);
    if (!$contacts) {
        sino_live_update($pdo, $dispatchId, ['lotes_enviados' => $lote, 'retry_count' => 0]);
        $dispatch['lotes_enviados'] = $lote;
        return 'ok';
    }
    $body = ['remessa' => $remessa, 'total' => (int)$dispatch['total'], 'data' => new \stdClass(), 'contacts' => $contacts];
    $idem = $code . '-' . $remessa . '-lote-' . $lote;
    $res = sino_request('POST', '/dispatch-configs/' . rawurlencode($code) . '/batch', $body, $idem, SINO_LIVE_HTTP_TIMEOUT_S);
    $http = (int)$res['status'];

    if ($http >= 200 && $http < 300) {
        $b = is_array($res['body']) ? $res['body'] : [];
        $errIdx = [];
        foreach ((array)($b['errors'] ?? []) as $e) {
            if (!is_array($e) || !isset($e['index'])) continue;
            $errIdx[(int)$e['index']] = trim((string)($e['error'] ?? 'recusado') . (isset($e['code']) ? ' (' . $e['code'] . ')' : ''));
        }
        foreach ($userIds as $i => $uid) {
            $err = $errIdx[$i] ?? null;
            $pdo->prepare("UPDATE sino_live_recipients SET status = :s, motivo = :m WHERE dispatch_id = :d AND user_id = :u")
                ->execute([':s' => $err === null ? 'enviado' : 'erro', ':m' => $err, ':d' => $dispatchId, ':u' => $uid]);
        }
        $steps = sino_live_merge_steps(json_decode((string)($dispatch['steps_json'] ?? ''), true) ?: [], (array)($b['steps'] ?? []));
        $fields = [
            'lotes_enviados' => $lote,
            'received' => (int)$dispatch['received'] + (int)($b['received'] ?? count($contacts)),
            'accepted' => (int)$dispatch['accepted'] + (int)($b['accepted'] ?? 0),
            'errors_count' => (int)$dispatch['errors_count'] + count($errIdx),
            'steps_json' => json_encode($steps, JSON_UNESCAPED_UNICODE),
            'sino_status' => (string)($b['status'] ?? '') ?: null,
            'retry_count' => 0,
            'last_http' => $http,
            'last_error' => null,
        ];
        sino_live_update($pdo, $dispatchId, $fields);
        $dispatch = array_merge($dispatch, $fields);
        sino_live_log((string)$turma['codigo'], $http, sprintf('lote %d/%d remessa=%s received=%d accepted=%d erros=%d status=%s',
            $lote, (int)$dispatch['lotes_total'], $remessa, (int)($b['received'] ?? 0), (int)($b['accepted'] ?? 0), count($errIdx), (string)($b['status'] ?? '')),
            ['dispatch_id' => $dispatchId, 'code' => $code]);
        return 'ok';
    }

    // Falha: 429/5xx/timeout/rede podem repetir o MESMO lote (mesma chave); o resto para.
    $erro = $res['error'] !== '' ? $res['error'] : ('HTTP ' . $http);
    sino_live_log((string)$turma['codigo'], $http, 'lote ' . $lote . ' falhou: ' . $erro, ['dispatch_id' => $dispatchId, 'code' => $code]);
    if (sino_http_retryable($http)) {
        $tries = (int)$dispatch['retry_count'] + 1;
        if ($tries <= count(SINO_LIVE_RETRY_WAITS_S)) {
            sino_live_update($pdo, $dispatchId, ['retry_count' => $tries, 'last_http' => $http ?: null, 'last_error' => mb_substr($erro, 0, 2000)]);
            $dispatch['retry_count'] = $tries;
            return 'retry';
        }
        $erro = 'Lote ' . $lote . ' falhou ' . $tries . ' vezes: ' . $erro;
    } else {
        $code409 = (string)($res['body']['error']['code'] ?? '');
        $dica = [
            401 => 'Chave da API recusada pelo Sino.',
            404 => 'O disparo nao existe mais no Sino (apagado ou renomeado): escolha outro na configuracao da turma.',
        ][$http] ?? ($code409 === 'not_api' ? 'Esse codigo e de um disparo manual do Sino: escolha um disparo do tipo API.'
            : ($code409 === 'dispatch_closed' ? 'O disparo foi arquivado no Sino.' : ''));
        if ($dica !== '') $erro = $dica . ' (' . $erro . ')';
    }
    sino_live_update($pdo, $dispatchId, ['status' => 'erro', 'last_http' => $http ?: null, 'last_error' => mb_substr($erro, 0, 2000)]);
    $dispatch['status'] = 'erro';
    return 'erro';
}

// ===================== CRON =====================

/** Executado pelo cron a cada minuto (e pelo botao "Retomar"). */
function sino_live_process(PDO $pdo, int $budgetSeconds = 600, int $onlyTurmaId = 0): array
{
    $stats = ['ok' => true, 'turmas' => 0, 'lotes' => 0, 'erros' => 0, 'mensagens' => []];
    if (sino_api_key() === '') return ['ok' => false, 'message' => 'Chave da API do Sino nao configurada.'];
    sino_live_ensure_schema($pdo);
    $deadline = microtime(true) + max(30, $budgetSeconds);

    $where = $onlyTurmaId > 0 ? 'AND t.id = :id' : 'AND t.live_disparo_data <= NOW()';
    $st = $pdo->prepare("SELECT t.* FROM turmas t
                          WHERE t.sino_live_enabled = 1 AND t.sino_live_disparada = 0
                            AND t.sino_dispatch_code IS NOT NULL AND t.sino_dispatch_code <> ''
                            AND t.live_disparo_data IS NOT NULL {$where}
                       ORDER BY t.live_disparo_data ASC, t.id ASC LIMIT 5");
    $st->execute($onlyTurmaId > 0 ? [':id' => $onlyTurmaId] : []);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $turma) {
        if (microtime(true) >= $deadline) break;
        $stats['turmas']++;
        $codigo = (string)$turma['codigo'];
        $dispatch = sino_live_dispatch($pdo, (int)($turma['sino_live_dispatch_id'] ?? 0));
        if ($dispatch && in_array($dispatch['status'], ['erro', 'cancelado'], true)) continue; // espera o admin
        if (!$dispatch || $dispatch['status'] === 'concluido') {
            if (trim((string)($turma['data_live'] ?? '')) === '') {
                $stats['mensagens'][] = "turma {$codigo}: sem data da live";
                continue;
            }
            // Live que ja aconteceu: nao comeca envio novo (evita "a live e hoje" para uma
            // turma antiga ao ligar o Sino nela). Marca como disparada para nao reavaliar.
            if ((int)strtotime((string)$turma['data_live']) <= time()) {
                $pdo->prepare('UPDATE turmas SET sino_live_disparada = 1 WHERE id = :id')->execute([':id' => (int)$turma['id']]);
                sino_live_log($codigo, 0, 'envio ao Sino nao iniciado: a live da turma ja passou (' . $turma['data_live'] . ')');
                $stats['mensagens'][] = "turma {$codigo}: live ja passou";
                continue;
            }
            $dispatch = sino_live_dispatch($pdo, sino_live_create_dispatch($pdo, $turma));
            sino_live_log($codigo, 0, 'envio criado: disparo=' . $dispatch['dispatch_code'] . ' remessa=' . $dispatch['remessa'], ['dispatch_id' => (int)$dispatch['id']]);
        }

        if ($dispatch['status'] === 'preparando') {
            if (!sino_live_prepare($pdo, $turma, $dispatch, $deadline)) continue; // continua no proximo minuto
            $dispatch = sino_live_finish_prepare($pdo, sino_live_dispatch($pdo, (int)$dispatch['id']) ?? $dispatch);
            sino_live_log($codigo, 0, sprintf('publico pronto: %d alunos em %d lote(s), %d fora pelos filtros', (int)$dispatch['total'], (int)$dispatch['lotes_total'], (int)$dispatch['pulados_local']), ['dispatch_id' => (int)$dispatch['id']]);
        }

        while ($dispatch['status'] === 'enviando' && microtime(true) < $deadline) {
            $r = sino_live_send_next($pdo, $turma, $dispatch);
            if ($r === 'ok') {
                $stats['lotes']++;
                if ((int)$dispatch['lotes_enviados'] >= (int)$dispatch['lotes_total']) break;
                sleep(SINO_LIVE_PAUSE_BETWEEN_S);
                continue;
            }
            if ($r === 'retry') {
                $wait = SINO_LIVE_RETRY_WAITS_S[min((int)$dispatch['retry_count'], count(SINO_LIVE_RETRY_WAITS_S)) - 1];
                if (microtime(true) + $wait >= $deadline) break; // tenta de novo no proximo minuto
                sleep($wait);
                continue;
            }
            if ($r === 'erro') $stats['erros']++;
            break;
        }

        if ($dispatch['status'] === 'enviando' && (int)$dispatch['lotes_enviados'] >= (int)$dispatch['lotes_total']) {
            sino_live_update($pdo, (int)$dispatch['id'], ['status' => 'concluido', 'finished_at' => 'NOW()']);
            $dispatch['status'] = 'concluido';
        }
        if ($dispatch['status'] === 'concluido') {
            $pdo->prepare('UPDATE turmas SET sino_live_disparada = 1 WHERE id = :id')->execute([':id' => (int)$turma['id']]);
            sino_live_log($codigo, 0, sprintf('envio concluido: %d recebidos, %d aceitos, %d recusados', (int)$dispatch['received'], (int)$dispatch['accepted'], (int)$dispatch['errors_count']), ['dispatch_id' => (int)$dispatch['id']]);
        }
    }
    return $stats;
}

// ===================== ACOES DO ADMIN =====================

/** Volta um envio parado por erro para a fase em que estava. */
function sino_live_resume(PDO $pdo, int $turmaId): string
{
    sino_live_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT sino_live_dispatch_id FROM turmas WHERE id = :id');
    $st->execute([':id' => $turmaId]);
    $d = sino_live_dispatch($pdo, (int)$st->fetchColumn());
    if (!$d || $d['status'] !== 'erro') return 'Nenhum envio parado por erro nesta turma.';
    sino_live_update($pdo, (int)$d['id'], ['status' => (int)$d['lotes_total'] > 0 || $d['prepared_at'] ? 'enviando' : 'preparando', 'retry_count' => 0, 'last_error' => null]);
    return 'Envio retomado do lote ' . ((int)$d['lotes_enviados'] + 1) . '. O cron continua em ate 1 minuto.';
}

/** Libera um novo envio (nova linha; com a mesma remessa o Sino pula quem ja entrou). */
function sino_live_reset(PDO $pdo, int $turmaId): void
{
    sino_live_ensure_schema($pdo);
    $pdo->prepare('UPDATE turmas SET sino_live_disparada = 0, sino_live_dispatch_id = NULL WHERE id = :id')->execute([':id' => $turmaId]);
}

/** Cancela no Sino (todas as remessas do disparo) e para o envio desta turma. */
function sino_live_cancel(PDO $pdo, int $turmaId): array
{
    sino_live_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM turmas WHERE id = :id');
    $st->execute([':id' => $turmaId]);
    $turma = $st->fetch(PDO::FETCH_ASSOC);
    $d = $turma ? sino_live_dispatch($pdo, (int)($turma['sino_live_dispatch_id'] ?? 0)) : null;
    if (!$d) return ['ok' => false, 'message' => 'Nenhum envio desta turma para cancelar.'];
    // Tira da fila TODOS que ainda nao comecaram, em todas as remessas desse disparo.
    $res = sino_request('POST', '/dispatch-configs/' . rawurlencode((string)$d['dispatch_code']) . '/cancel');
    if ($res['status'] < 200 || $res['status'] >= 300) {
        sino_live_log((string)$turma['codigo'], (int)$res['status'], 'cancelamento falhou: ' . $res['error'], ['dispatch_id' => (int)$d['id']]);
        return ['ok' => false, 'message' => 'O Sino recusou o cancelamento: ' . $res['error']];
    }
    $n = (int)($res['body']['cancelled'] ?? 0);
    sino_live_update($pdo, (int)$d['id'], ['status' => 'cancelado', 'finished_at' => 'NOW()', 'last_error' => 'Cancelado pelo admin: ' . $n . ' tirados da fila do Sino.']);
    $pdo->prepare('UPDATE turmas SET sino_live_disparada = 1 WHERE id = :id')->execute([':id' => $turmaId]);
    sino_live_log((string)$turma['codigo'], (int)$res['status'], 'cancelado pelo admin: ' . $n . ' tirados da fila', ['dispatch_id' => (int)$d['id']]);
    return ['ok' => true, 'message' => $n . ' aluno(s) tirados da fila do Sino.'];
}
