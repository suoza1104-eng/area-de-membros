<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/voice_torpedo.php';
require_once __DIR__ . '/../app/automation_flows.php';
proteger_admin();

$pdo = getPDO();
voice_ensure_schema($pdo);
automation_flows_ensure_schema($pdo);
$menu = 'torpedo_voz';
$page_title = 'Torpedo de Voz';
$actor = (string)($_SESSION['equipe_nome'] ?? 'Administrador');
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['torpedo_voz']['escrever']);
}
if (empty($_SESSION['voice_admin_csrf'])) $_SESSION['voice_admin_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['voice_admin_csrf'];
$diagnostic = null;
$syncResult = null;

function vv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function vv_check(string $csrf): void { if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.'); }
function vv_dt($v): string {
    $value=trim((string)$v);if($value==='')return '';
    $ts=strtotime($value);return $ts?date('d/m/Y H:i:s',$ts):$value;
}
function vv_voice_status_label(string $status): string {
    return ['api_requested'=>'Pedido enviado','api_accepted'=>'Aceita pela Telnyx','initiated'=>'Chamada iniciada','ringing'=>'Chamando','answered'=>'Atendida','playing'=>'Audio em execucao','finished'=>'Finalizada','failed'=>'Falhou','created'=>'Criada'][$status] ?? $status;
}
function vv_voice_event_label(string $event, string $normalized = ''): string {
    $key = $normalized !== '' ? $normalized : $event;
    return [
        'initiated'=>'Chamada criada na operadora','ringing'=>'Telefone chamando','answered'=>'Aluno atendeu','answered_human'=>'Atendimento humano detectado','answered_machine'=>'Caixa postal detectada',
        'audio_started'=>'Audio comecou a tocar','audio_completed'=>'Audio terminou','completed'=>'Chamada encerrada','busy'=>'Destino ocupado','no_answer'=>'Nao atendeu','rejected'=>'Chamada rejeitada','failed'=>'Chamada falhou','interacted'=>'Aluno pressionou uma tecla',
        'call.initiated'=>'Chamada criada na operadora','call.ringing'=>'Telefone chamando','call.answered'=>'Aluno atendeu','call.speak.started'=>'TTS comecou','call.speak.ended'=>'TTS terminou','call.playback.started'=>'Audio comecou','call.playback.ended'=>'Audio terminou','call.hangup'=>'Chamada encerrada','call.cost'=>'Custo calculado',
    ][$key] ?? $event;
}
function vv_voice_event_detail(array $event): string {
    $normalized=(string)($event['normalized_event'] ?? '');$type=(string)($event['event_type'] ?? '');
    return [
        'initiated'=>'A Telnyx aceitou iniciar a ligacao e devolveu os identificadores da chamada.',
        'ringing'=>'O telefone de destino recebeu toque. Ainda nao significa que alguem atendeu.',
        'answered'=>'A chamada foi atendida. A partir daqui o sistema tenta tocar o audio ou TTS.',
        'audio_started'=>'O audio/TTS foi iniciado dentro da chamada.',
        'audio_completed'=>'O audio/TTS chegou ao fim. O sistema solicita o encerramento da chamada em seguida.',
        'completed'=>'A chamada foi encerrada pela Telnyx ou pelo comando de desligar do sistema.',
        'busy'=>'O destino retornou ocupado.',
        'no_answer'=>'A chamada terminou sem atendimento.',
        'rejected'=>'A chamada foi recusada pelo destino ou pela rede.',
        'failed'=>'A chamada falhou antes de concluir o fluxo.',
        'interacted'=>'A chamada recebeu uma interacao do aluno por tecla/DTMF.',
    ][$normalized] ?? ('Evento recebido da Telnyx: ' . ($type ?: 'sem tipo informado') . '.');
}
function vv_voice_stage_defs(): array {
    return [
        ['key'=>'api','label'=>'Pedido enviado','field'=>'created_at','hint'=>'O sistema pediu a chamada para a Telnyx.'],
        ['key'=>'initiated','label'=>'Operadora iniciou','field'=>'started_at','event'=>'initiated','hint'=>'A Telnyx iniciou a ligacao.'],
        ['key'=>'ringing','label'=>'Chamou no telefone','field'=>'ringing_at','event'=>'ringing','hint'=>'O telefone recebeu toque.'],
        ['key'=>'answered','label'=>'Foi atendida','field'=>'answered_at','event'=>'answered','hint'=>'Alguem atendeu a chamada.'],
        ['key'=>'audio_started','label'=>'Audio iniciou','field'=>'audio_started_at','event'=>'audio_started','hint'=>'O audio/TTS comecou a tocar.'],
        ['key'=>'audio_completed','label'=>'Audio terminou','field'=>'audio_ended_at','event'=>'audio_completed','hint'=>'O audio/TTS chegou ao final.'],
        ['key'=>'ended','label'=>'Ligacao encerrou','field'=>'ended_at','event'=>'completed','hint'=>'A chamada terminou ou falhou.'],
    ];
}
function vv_voice_stage_done(array $call, array $events, array $stage): bool {
    if (!empty($stage['field']) && trim((string)($call[$stage['field']] ?? '')) !== '') return true;
    $want=(string)($stage['event'] ?? '');if($want==='')return false;
    foreach($events as $e) if((string)($e['normalized_event'] ?? '')===$want) return true;
    if($want==='completed' && in_array((string)($call['status'] ?? ''), ['finished','failed'], true)) return true;
    return false;
}
function vv_voice_stage_inferred(array $call, array $events, array $stage): bool {
    if (($stage['key'] ?? '') !== 'ringing' || vv_voice_stage_done($call,$events,$stage)) return false;
    if (trim((string)($call['answered_at'] ?? '')) !== '') return true;
    foreach($events as $e) if(in_array((string)($e['normalized_event'] ?? ''), ['answered','audio_started','audio_completed','completed'], true)) return true;
    return false;
}
function vv_voice_stage_text(array $call, array $events, array $stage): string {
    if (vv_voice_stage_done($call,$events,$stage)) return vv_dt($call[$stage['field'] ?? ''] ?? '') ?: 'Evento recebido';
    if (vv_voice_stage_inferred($call,$events,$stage)) return 'Inferido: foi atendida';
    return (string)($stage['hint'] ?? '');
}
function vv_media_meta(array $m): array { $meta=json_decode((string)($m['metadata_json'] ?? '{}'), true); return is_array($meta)?$meta:[]; }
function vv_media_description(array $m): string { $meta=vv_media_meta($m); return trim((string)($meta['description'] ?? '')); }
function vv_date_ymd(string $value): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : ''; }
function vv_duration_label(int $seconds): string {
    if ($seconds <= 0) return 'zerada';
    if ($seconds < 60) return $seconds . 's';
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) return $minutes . ' min';
    $hours = intdiv($minutes, 60); $rest = $minutes % 60;
    return $hours . 'h' . ($rest > 0 ? ' ' . $rest . 'min' : '');
}
function vv_voice_job_label(array $graph, string $nodeId): string {
    foreach (($graph['nodes'] ?? []) as $node) {
        if ((string)($node['id'] ?? '') !== $nodeId) continue;
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        return trim((string)($config['label'] ?? '')) ?: 'Chamada de voz';
    }
    return $nodeId;
}
function vv_voice_job_is_voice(array $graph, string $nodeId): bool {
    foreach (($graph['nodes'] ?? []) as $node) {
        if ((string)($node['id'] ?? '') === $nodeId) return (string)($node['type'] ?? '') === 'voice';
    }
    return false;
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Sem permissao de escrita.');
        vv_check($csrf);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_provider') {
            voice_save_telnyx_config($pdo, $_POST, $actor);
            $message = 'Configuracao Telnyx salva.';
        } elseif ($action === 'test_connection') {
            $diagnostic = voice_telnyx_diagnostics($pdo);
            $message = !empty($diagnostic['ready']) ? 'Diagnostico concluido: conexao pronta.' : 'Diagnostico concluido com pendencias.';
        } elseif ($action === 'save_number') {
            $provider = voice_provider($pdo);
            $phone = voice_normalize_e164((string)($_POST['phone_e164'] ?? ''), (string)($provider['public']['default_country_code'] ?? '55'));
            if ($phone === '') throw new InvalidArgumentException('Informe um numero em E.164 valido.');
            if (!empty($_POST['is_default'])) $pdo->exec("UPDATE voice_phone_numbers SET is_default=0");
            $pdo->prepare("INSERT INTO voice_phone_numbers(provider_id,phone_e164,friendly_name,country,region,type,source_type,inbound_enabled,outbound_enabled,is_default,verification_status,connection_id,outbound_profile_id,status,metadata_json)
                VALUES(:p,:phone,:name,:country,:region,:type,:source,:inbound,:outbound,:def,:ver,:conn,:profile,'active',:meta)
                ON DUPLICATE KEY UPDATE friendly_name=VALUES(friendly_name),country=VALUES(country),region=VALUES(region),type=VALUES(type),source_type=VALUES(source_type),inbound_enabled=VALUES(inbound_enabled),outbound_enabled=VALUES(outbound_enabled),is_default=VALUES(is_default),verification_status=VALUES(verification_status),connection_id=VALUES(connection_id),outbound_profile_id=VALUES(outbound_profile_id),metadata_json=VALUES(metadata_json)")
                ->execute([
                    'p'=>(int)$provider['id'],
                    'phone'=>$phone,
                    'name'=>trim((string)($_POST['friendly_name'] ?? '')),
                    'country'=>trim((string)($_POST['country'] ?? 'BR')),
                    'region'=>trim((string)($_POST['region'] ?? '')),
                    'type'=>trim((string)($_POST['type'] ?? 'voice')),
                    'source'=>in_array(($_POST['source_type'] ?? 'manual'), ['telnyx_owned','verified_external','ported','sip','manual'], true) ? (string)$_POST['source_type'] : 'manual',
                    'inbound'=>!empty($_POST['inbound_enabled']) ? 1 : 0,
                    'outbound'=>!empty($_POST['outbound_enabled']) ? 1 : 0,
                    'def'=>!empty($_POST['is_default']) ? 1 : 0,
                    'ver'=>trim((string)($_POST['verification_status'] ?? 'manual')),
                    'conn'=>trim((string)($_POST['connection_id'] ?? '')),
                    'profile'=>trim((string)($_POST['outbound_profile_id'] ?? '')),
                    'meta'=>voice_json(['notes'=>trim((string)($_POST['notes'] ?? ''))]),
                ]);
            $message = 'Numero salvo.';
        } elseif ($action === 'sync_numbers') {
            $syncResult = voice_telnyx_sync_phone_numbers($pdo, $actor);
            $message = 'Sincronizacao Telnyx concluida: ' . (int)$syncResult['seen'] . ' numero(s) lido(s), ' . (int)$syncResult['created'] . ' criado(s), ' . (int)$syncResult['updated'] . ' atualizado(s).';
        } elseif ($action === 'add_suppression') {
            $provider = voice_provider($pdo);
            $phone = voice_normalize_e164((string)($_POST['phone_e164'] ?? ''), (string)($provider['public']['default_country_code'] ?? '55'));
            if ($phone === '') throw new InvalidArgumentException('Telefone invalido.');
            $pdo->prepare("INSERT INTO voice_suppression_list(phone_e164,reason,source,notes,created_by) VALUES(:p,:r,'admin',:n,:a) ON DUPLICATE KEY UPDATE reason=VALUES(reason),notes=VALUES(notes),permanent=1")
                ->execute(['p'=>$phone,'r'=>trim((string)($_POST['reason'] ?? 'manual')),'n'=>trim((string)($_POST['notes'] ?? '')),'a'=>$actor]);
            $message = 'Telefone bloqueado para chamadas.';
        } elseif ($action === 'upload_media') {
            if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) throw new RuntimeException('Envie um arquivo MP3 ou WAV.');
            $mime = (string)(mime_content_type($_FILES['audio']['tmp_name']) ?: '');
            $ext = strtolower(pathinfo((string)$_FILES['audio']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp3','wav'], true) || !in_array($mime, ['audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/wave'], true)) throw new RuntimeException('Formato de audio nao aceito.');
            if ((int)$_FILES['audio']['size'] > 20 * 1024 * 1024) throw new RuntimeException('Audio acima de 20 MB.');
            $dir = realpath(__DIR__ . '/../public/uploads') ?: '';
            if ($dir === '') { mkdir(__DIR__ . '/../public/uploads', 0755, true); $dir = realpath(__DIR__ . '/../public/uploads') ?: ''; }
            $voiceDir = $dir . DIRECTORY_SEPARATOR . 'voice_media';
            if (!is_dir($voiceDir)) mkdir($voiceDir, 0755, true);
            $name = bin2hex(random_bytes(14)) . '.' . $ext;
            $dest = $voiceDir . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) throw new RuntimeException('Falha ao salvar audio.');
            $publicUrl = rtrim(BASE_URL, '/') . '/uploads/voice_media/' . rawurlencode($name);
            $pdo->prepare("INSERT INTO voice_media(name,media_type,source,local_path,public_url,mime_type,file_size,checksum,status,metadata_json,created_by) VALUES(:n,'uploaded_audio','local',:path,:url,:mime,:size,:checksum,'active',:meta,:actor)")
                ->execute(['n'=>trim((string)($_POST['name'] ?? 'Audio de voz')) ?: 'Audio de voz','path'=>$dest,'url'=>$publicUrl,'mime'=>$mime,'size'=>(int)$_FILES['audio']['size'],'checksum'=>hash_file('sha256',$dest),'meta'=>voice_json(['description'=>trim((string)($_POST['description'] ?? ''))]),'actor'=>$actor]);
            $message = 'Audio salvo na biblioteca.';
        } elseif ($action === 'update_media') {
            $mediaId = max(0, (int)($_POST['media_id'] ?? 0));
            if ($mediaId < 1) throw new InvalidArgumentException('Audio invalido.');
            $st = $pdo->prepare("SELECT * FROM voice_media WHERE id=:id AND status='active' LIMIT 1");
            $st->execute(['id'=>$mediaId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Audio nao encontrado.');
            $meta = vv_media_meta($row);
            $meta['description'] = trim((string)($_POST['description'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Informe o titulo do audio.');
            $pdo->prepare("UPDATE voice_media SET name=:n,metadata_json=:m WHERE id=:id")
                ->execute(['n'=>$name,'m'=>voice_json($meta),'id'=>$mediaId]);
            voice_audit($pdo, $actor, 'media_updated', 'voice_media', (string)$mediaId, ['name'=>$row['name'],'description'=>vv_media_description($row)], ['name'=>$name,'description'=>$meta['description']]);
            $message = 'Audio atualizado.';
        } elseif ($action === 'delete_media') {
            $mediaId = max(0, (int)($_POST['media_id'] ?? 0));
            if ($mediaId < 1) throw new InvalidArgumentException('Audio invalido.');
            $st = $pdo->prepare("SELECT * FROM voice_media WHERE id=:id AND status='active' LIMIT 1");
            $st->execute(['id'=>$mediaId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Audio nao encontrado.');
            $pdo->prepare("UPDATE voice_media SET status='deleted' WHERE id=:id")->execute(['id'=>$mediaId]);
            voice_audit($pdo, $actor, 'media_deleted', 'voice_media', (string)$mediaId, ['name'=>$row['name']], []);
            $message = 'Audio removido da biblioteca.';
        } elseif ($action === 'save_test_defaults') {
            $cfg = voice_save_test_defaults($pdo, (string)($_POST['test_phone'] ?? ''), (int)($_POST['test_audio_media_id'] ?? 0), $actor);
            $message = 'Configuracao de teste salva.';
        } elseif ($action === 'test_call') {
            if (empty($_POST['confirm_test_call'])) throw new RuntimeException('Confirme que entende que a chamada de teste pode gerar custo.');
            voice_save_test_defaults($pdo, (string)($_POST['test_phone'] ?? ''), (int)($_POST['test_audio_media_id'] ?? 0), $actor);
            $r = voice_send_test_call($pdo, (string)$_POST['test_phone'], (string)($_POST['test_message'] ?? ''), (string)($_POST['test_audio_url'] ?? ''), $actor, (int)($_POST['test_audio_media_id'] ?? 0));
            $message = 'Chamada de teste criada. Attempt #' . (int)$r['attempt_id'];
        } elseif ($action === 'create_campaign') {
            $provider = voice_provider($pdo);
            $pdo->prepare("INSERT INTO voice_campaigns(name,description,provider_id,message_mode,message_template,machine_message_template,status,timezone,concurrency_limit,calls_per_minute,max_attempts,answering_machine_detection,record_calls,transcribe_calls,created_by)
                VALUES(:n,:d,:p,:mode,:msg,:machine,'draft',:tz,:conc,:rate,:attempts,:amd,:record,:transcribe,:actor)")
                ->execute([
                    'n'=>trim((string)($_POST['name'] ?? 'Nova campanha de voz')) ?: 'Nova campanha de voz',
                    'd'=>trim((string)($_POST['description'] ?? '')),
                    'p'=>(int)$provider['id'],
                    'mode'=>(string)($_POST['message_mode'] ?? 'text_to_speech'),
                    'msg'=>(string)($_POST['message_template'] ?? ''),
                    'machine'=>(string)($_POST['machine_message_template'] ?? ''),
                    'tz'=>(string)($provider['public']['timezone'] ?? 'America/Sao_Paulo'),
                    'conc'=>max(1,(int)($_POST['concurrency_limit'] ?? 1)),
                    'rate'=>max(1,(int)($_POST['calls_per_minute'] ?? 1)),
                    'attempts'=>max(1,min(5,(int)($_POST['max_attempts'] ?? 1))),
                    'amd'=>!empty($_POST['answering_machine_detection']) ? 1 : 0,
                    'record'=>!empty($_POST['record_calls']) ? 1 : 0,
                    'transcribe'=>!empty($_POST['transcribe_calls']) ? 1 : 0,
                    'actor'=>$actor,
                ]);
            $message = 'Campanha criada como rascunho.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$tab = (string)($_GET['tab'] ?? 'overview');
$provider = voice_provider($pdo);
$cfg = (array)$provider['public'];
$creds = (array)$provider['credentials'];
$stats = voice_dashboard_stats($pdo);
$queueFrom = vv_date_ymd((string)($_GET['queue_from'] ?? '')) ?: date('Y-m-d', strtotime('-7 days'));
$queueTo = vv_date_ymd((string)($_GET['queue_to'] ?? '')) ?: date('Y-m-d');
$queueFlowId = max(0, (int)($_GET['queue_flow_id'] ?? 0));
$queueStatus = (string)($_GET['queue_status'] ?? 'all');
if (!in_array($queueStatus, ['open','all','completed','failed','scheduled','processing'], true)) $queueStatus = 'all';
$queueFlowOptions = $pdo->query("SELECT id,name,status FROM automation_flows WHERE status<>'deleted' ORDER BY updated_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$campaigns = $pdo->query("SELECT * FROM voice_campaigns ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$numbers = $pdo->query("SELECT * FROM voice_phone_numbers ORDER BY is_default DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$media = $pdo->query("SELECT * FROM voice_media WHERE status='active' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$calls = $pdo->query("SELECT * FROM voice_call_attempts ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$failedCalls = $pdo->query("SELECT * FROM voice_call_attempts WHERE status='failed' OR error_json IS NOT NULL ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$events = $pdo->query("SELECT * FROM voice_events ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$webhooks = $pdo->query("SELECT * FROM voice_webhook_logs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$eventsByAttempt = [];
foreach ($events as $e) $eventsByAttempt[(int)($e['attempt_id'] ?? 0)][] = $e;
$webhooksByEvent = [];
foreach ($webhooks as $w) if (!empty($w['event_id'])) $webhooksByEvent[(string)$w['event_id']][] = $w;
$queueOpenStatuses = ['queued'=>true,'retry'=>true,'scheduled'=>true,'processing'=>true];
$queueRawByJob = [];
$queueOpenWhere = ["j.status IN ('queued','retry','scheduled','processing')"];
$queueOpenParams = [];
if ($queueFlowId > 0) { $queueOpenWhere[] = 'r.flow_id=:q_flow'; $queueOpenParams['q_flow'] = $queueFlowId; }
if (in_array($queueStatus, ['scheduled','processing'], true)) { $queueOpenWhere[] = 'j.status=:q_status'; $queueOpenParams['q_status'] = $queueStatus; }
if (in_array($queueStatus, ['all','open','scheduled','processing'], true)) {
    $queueOpenSql = "SELECT j.id job_id,j.run_id,j.node_id,j.status job_status,j.available_at,j.attempts,j.max_attempts,j.last_error,j.created_at job_created_at,j.updated_at job_updated_at,
        r.flow_id,r.version_id,r.user_id,r.status run_status,r.started_at run_started_at,r.finished_at run_finished_at,
        f.name flow_name,f.status flow_status,v.version_number,u.nome,u.email,u.telefone,
        a.id attempt_id,a.status call_status,a.answered_by,a.created_at call_created_at,a.ended_at call_ended_at,a.duration_seconds,a.error_json
        FROM automation_flow_jobs j
        JOIN automation_flow_runs r ON r.id=j.run_id
        JOIN automation_flows f ON f.id=r.flow_id
        JOIN automation_flow_versions v ON v.id=r.version_id
        LEFT JOIN users u ON u.id=r.user_id
        LEFT JOIN voice_call_attempts a ON a.automation_job_id=j.id
        WHERE " . implode(' AND ', $queueOpenWhere) . "
        ORDER BY FIELD(j.status,'processing','queued','retry','scheduled'), j.available_at ASC, j.id ASC
        LIMIT 500";
    $queueOpenStmt = $pdo->prepare($queueOpenSql);
    $queueOpenStmt->execute($queueOpenParams);
    foreach ($queueOpenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $queueRawByJob[(int)$row['job_id']] = $row;
}
$queueAttemptWhere = ['a.automation_job_id IS NOT NULL'];
$queueAttemptParams = [];
if ($queueFlowId > 0) { $queueAttemptWhere[] = 'r.flow_id=:qa_flow'; $queueAttemptParams['qa_flow'] = $queueFlowId; }
if ($queueFrom !== '') { $queueAttemptWhere[] = 'a.created_at>=:qa_from'; $queueAttemptParams['qa_from'] = $queueFrom . ' 00:00:00'; }
if ($queueTo !== '') { $queueAttemptWhere[] = 'a.created_at<:qa_to'; $queueAttemptParams['qa_to'] = date('Y-m-d 00:00:00', strtotime($queueTo . ' +1 day')); }
if ($queueStatus === 'completed') $queueAttemptWhere[] = "j.status='completed'";
if ($queueStatus === 'failed') $queueAttemptWhere[] = "(j.status='failed' OR a.status='failed' OR a.error_json IS NOT NULL)";
if (in_array($queueStatus, ['all','completed','failed'], true)) {
    $queueAttemptSql = "SELECT j.id job_id,j.run_id,j.node_id,j.status job_status,j.available_at,j.attempts,j.max_attempts,j.last_error,j.created_at job_created_at,j.updated_at job_updated_at,
    r.flow_id,r.version_id,r.user_id,r.status run_status,r.started_at run_started_at,r.finished_at run_finished_at,
    f.name flow_name,f.status flow_status,v.version_number,u.nome,u.email,u.telefone,
    a.id attempt_id,a.status call_status,a.answered_by,a.created_at call_created_at,a.ended_at call_ended_at,a.duration_seconds,a.error_json
    FROM automation_flow_jobs j
    JOIN automation_flow_runs r ON r.id=j.run_id
    JOIN automation_flows f ON f.id=r.flow_id
    JOIN automation_flow_versions v ON v.id=r.version_id
    LEFT JOIN users u ON u.id=r.user_id
    JOIN voice_call_attempts a ON a.automation_job_id=j.id
    WHERE " . implode(' AND ', $queueAttemptWhere) . "
    ORDER BY a.created_at DESC, j.id DESC
    LIMIT 300";
    $queueAttemptStmt = $pdo->prepare($queueAttemptSql);
    $queueAttemptStmt->execute($queueAttemptParams);
    foreach ($queueAttemptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $queueRawByJob[(int)$row['job_id']] = $row;
}
$queueRawRows = array_values($queueRawByJob);
$queueVersionIds = array_values(array_unique(array_filter(array_map(static fn($r)=>(int)($r['version_id'] ?? 0), $queueRawRows))));
$queueGraphsByVersion = [];
if ($queueVersionIds) {
    $in = implode(',', array_fill(0, count($queueVersionIds), '?'));
    $graphStmt = $pdo->prepare("SELECT id,graph_json FROM automation_flow_versions WHERE id IN ($in)");
    $graphStmt->execute($queueVersionIds);
    foreach ($graphStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $graphRow) {
        $decoded = json_decode((string)($graphRow['graph_json'] ?? ''), true);
        $queueGraphsByVersion[(int)$graphRow['id']] = is_array($decoded) ? $decoded : [];
    }
}
$queueRows = [];
$queueByFlow = [];
$queueSpacing = max(1, (int)($cfg['automation_queue_spacing_seconds'] ?? 75));
$queuePerMinute = max(1, (int)($cfg['calls_per_minute'] ?? 1));
$queueSecondsPerCall = max($queueSpacing, (int)ceil(60 / $queuePerMinute));
foreach ($queueRawRows as $row) {
    $graph = $queueGraphsByVersion[(int)($row['version_id'] ?? 0)] ?? [];
    $nodeId = (string)($row['node_id'] ?? '');
    if (!vv_voice_job_is_voice($graph, $nodeId)) continue;
    $row['node_label'] = vv_voice_job_label($graph, $nodeId);
    $flowId = (int)($row['flow_id'] ?? 0);
    if (!isset($queueByFlow[$flowId])) {
        $queueByFlow[$flowId] = ['flow_id'=>$flowId,'flow_name'=>(string)($row['flow_name'] ?? 'Fluxo #' . $flowId),'flow_status'=>(string)($row['flow_status'] ?? ''),'pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'total'=>0,'calls'=>0,'answered'=>0,'last_call'=>'','latest_available'=>''];
    }
    $bucket =& $queueByFlow[$flowId];
    $bucket['total']++;
    $status = (string)($row['job_status'] ?? '');
    if (isset($queueOpenStatuses[$status])) $bucket['pending']++;
    if ($status === 'processing') $bucket['processing']++;
    if ($status === 'completed') $bucket['completed']++;
    if ($status === 'failed') $bucket['failed']++;
    if (!empty($row['attempt_id'])) $bucket['calls']++;
    if (trim((string)($row['answered_by'] ?? '')) !== '') $bucket['answered']++;
    if (trim((string)($row['call_created_at'] ?? '')) !== '' && (string)$row['call_created_at'] > (string)$bucket['last_call']) $bucket['last_call'] = (string)$row['call_created_at'];
    if (isset($queueOpenStatuses[$status]) && (string)$row['available_at'] > (string)$bucket['latest_available']) $bucket['latest_available'] = (string)$row['available_at'];
    unset($bucket);
    $queueRows[] = $row;
}
$queueAggWhere = ['a.automation_job_id IS NOT NULL'];
$queueAggParams = [];
if ($queueFlowId > 0) { $queueAggWhere[] = 'r.flow_id=:qag_flow'; $queueAggParams['qag_flow'] = $queueFlowId; }
if ($queueFrom !== '') { $queueAggWhere[] = 'a.created_at>=:qag_from'; $queueAggParams['qag_from'] = $queueFrom . ' 00:00:00'; }
if ($queueTo !== '') { $queueAggWhere[] = 'a.created_at<:qag_to'; $queueAggParams['qag_to'] = date('Y-m-d 00:00:00', strtotime($queueTo . ' +1 day')); }
if ($queueStatus === 'completed') $queueAggWhere[] = "j.status='completed'";
if ($queueStatus === 'failed') $queueAggWhere[] = "(j.status='failed' OR a.status='failed' OR a.error_json IS NOT NULL)";
if (in_array($queueStatus, ['all','completed','failed'], true)) {
    $queueAggSql = "SELECT r.flow_id,f.name flow_name,f.status flow_status,
        COUNT(*) calls,
        SUM(j.status='completed') completed,
        SUM(j.status='failed' OR a.status='failed' OR a.error_json IS NOT NULL) failed,
        SUM(a.answered_at IS NOT NULL OR a.answered_by IS NOT NULL) answered,
        MAX(a.created_at) last_call
        FROM voice_call_attempts a
        JOIN automation_flow_jobs j ON j.id=a.automation_job_id
        JOIN automation_flow_runs r ON r.id=j.run_id
        JOIN automation_flows f ON f.id=r.flow_id
        WHERE " . implode(' AND ', $queueAggWhere) . "
        GROUP BY r.flow_id,f.name,f.status";
    $queueAggStmt = $pdo->prepare($queueAggSql);
    $queueAggStmt->execute($queueAggParams);
    foreach ($queueAggStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $agg) {
        $flowId = (int)($agg['flow_id'] ?? 0);
        if (!isset($queueByFlow[$flowId])) {
            $queueByFlow[$flowId] = ['flow_id'=>$flowId,'flow_name'=>(string)($agg['flow_name'] ?? 'Fluxo #' . $flowId),'flow_status'=>(string)($agg['flow_status'] ?? ''),'pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'total'=>0,'calls'=>0,'answered'=>0,'last_call'=>'','latest_available'=>''];
        }
        $queueByFlow[$flowId]['calls'] = (int)($agg['calls'] ?? 0);
        $queueByFlow[$flowId]['completed'] = (int)($agg['completed'] ?? 0);
        $queueByFlow[$flowId]['failed'] = (int)($agg['failed'] ?? 0);
        $queueByFlow[$flowId]['answered'] = (int)($agg['answered'] ?? 0);
        $queueByFlow[$flowId]['last_call'] = (string)($agg['last_call'] ?? $queueByFlow[$flowId]['last_call']);
        $queueByFlow[$flowId]['total'] = max((int)$queueByFlow[$flowId]['total'], (int)$queueByFlow[$flowId]['calls'] + (int)$queueByFlow[$flowId]['pending']);
    }
}
$queueOpenTotal = array_sum(array_map(static fn($r)=>(int)$r['pending'], $queueByFlow));
$queueCompletedTotal = array_sum(array_map(static fn($r)=>(int)$r['completed'], $queueByFlow));
$queueFailedTotal = array_sum(array_map(static fn($r)=>(int)$r['failed'], $queueByFlow));
$queueEtaSeconds = $queueOpenTotal > 0 ? $queueOpenTotal * $queueSecondsPerCall : 0;
foreach ($queueByFlow as &$qf) {
    $baseTs = max(time(), strtotime((string)$qf['latest_available']) ?: 0);
    $qf['eta_seconds'] = (int)$qf['pending'] > 0 ? max(0, ($baseTs + ((int)$qf['pending'] * $queueSecondsPerCall)) - time()) : 0;
}
unset($qf);
uasort($queueByFlow, static fn($a,$b)=>[$b['pending'],$b['total']] <=> [$a['pending'],$a['total']]);
$usersById = [];
$voiceUserIds = array_values(array_unique(array_filter(array_map(static fn($c)=>(int)($c['user_id'] ?? 0), $calls))));
if ($voiceUserIds) {
    $in = implode(',', array_fill(0, count($voiceUserIds), '?'));
    $stUsers = $pdo->prepare("SELECT id,nome,email,telefone FROM users WHERE id IN ($in)");
    $stUsers->execute($voiceUserIds);
    foreach ($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $usersById[(int)$u['id']] = $u;
}
$suppression = $pdo->query("SELECT * FROM voice_suppression_list ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$daily = $pdo->query("SELECT DATE(created_at) d,COUNT(*) c,SUM(status='finished') done,SUM(answered_by='human') human FROM voice_call_attempts WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$statusRows = $pdo->query("SELECT status,COUNT(*) c FROM voice_call_attempts GROUP BY status ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalCalls = (int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts")->fetchColumn();
$errorCalls = (int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE status='failed' OR error_json IS NOT NULL")->fetchColumn();
$finishedCalls = (int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE status='finished'")->fetchColumn();
$answeredCalls = (int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE answered_at IS NOT NULL OR answered_by IS NOT NULL")->fetchColumn();
$errorRate = $totalCalls > 0 ? round(($errorCalls / $totalCalls) * 100, 1) : 0;
$successRate = $totalCalls > 0 ? round(($finishedCalls / $totalCalls) * 100, 1) : 0;
$answerRate = $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 100, 1) : 0;
$funnelStages = [
    ['key'=>'created','label'=>'Pedido enviado','count'=>$totalCalls],
    ['key'=>'initiated','label'=>'Operadora iniciou','count'=>(int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE started_at IS NOT NULL OR status IN ('api_accepted','initiated','ringing','answered','playing','finished')")->fetchColumn()],
    ['key'=>'ringing','label'=>'Chamou no telefone','count'=>(int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE ringing_at IS NOT NULL OR answered_at IS NOT NULL OR audio_started_at IS NOT NULL OR audio_ended_at IS NOT NULL")->fetchColumn()],
    ['key'=>'answered','label'=>'Foi atendida','count'=>$answeredCalls],
    ['key'=>'audio_started','label'=>'Audio iniciou','count'=>(int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE audio_started_at IS NOT NULL OR audio_ended_at IS NOT NULL")->fetchColumn()],
    ['key'=>'audio_completed','label'=>'Audio terminou','count'=>(int)$pdo->query("SELECT COUNT(*) FROM voice_call_attempts WHERE audio_ended_at IS NOT NULL")->fetchColumn()],
];
foreach ($funnelStages as $i => $stage) {
    $base = $i === 0 ? max(1, $totalCalls) : max(1, (int)$funnelStages[$i - 1]['count']);
    $funnelStages[$i]['pct_total'] = $totalCalls > 0 ? round(((int)$stage['count'] / $totalCalls) * 100, 1) : 0;
    $funnelStages[$i]['pct_prev'] = $i === 0 ? 100 : round(((int)$stage['count'] / $base) * 100, 1);
}
$funnelPoints = [];
$stageCount = max(1, count($funnelStages));
$segmentWidth = 100 / $stageCount;
$visualPct = [];
$currentVisualPct = 100.0;
foreach ($funnelStages as $i => $stage) {
    $currentVisualPct = min($currentVisualPct, (float)$stage['pct_total']);
    $visualPct[$i] = $currentVisualPct;
}
foreach ($funnelStages as $i => $stage) {
    $left = $i * $segmentWidth;
    $right = ($i + 1) * $segmentWidth;
    $nextPct = (float)($visualPct[$i + 1] ?? $visualPct[$i]);
    $leftHalf = max(3.5, 30 * ((float)$visualPct[$i] / 100));
    $rightHalf = max(3.5, 30 * ($nextPct / 100));
    $funnelPoints[] = [
        'points' => number_format($left,3,'.','') . ',' . number_format(50 - $leftHalf,3,'.','') . ' ' . number_format($right,3,'.','') . ',' . number_format(50 - $rightHalf,3,'.','') . ' ' . number_format($right,3,'.','') . ',' . number_format(50 + $rightHalf,3,'.','') . ' ' . number_format($left,3,'.','') . ',' . number_format(50 + $leftHalf,3,'.',''),
        'opacity' => number_format(max(.32, .92 - ($i * .075)), 2, '.', ''),
    ];
}
$webhookUrl = rtrim(BASE_URL, '/') . '/telnyx_voice_webhook.php';
$failoverUrl = rtrim(BASE_URL, '/') . '/telnyx_voice_webhook_failover.php';

include __DIR__ . '/_header.php';
?>
<style>
.vv{display:grid;gap:14px}.vv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.vv-head h1{font-size:22px}.vv-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.vv-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.vv-nav a.active,.vv-nav a:hover{background:var(--primary-dim);color:var(--primary)}.vv-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow)}.vv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.vv-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.vv-kpi strong{display:block;font-size:25px}.vv-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.vv-field label{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.vv-field input,.vv-field select,.vv-field textarea{width:100%;padding:9px 11px;border:1px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text)}.vv-field-hint{display:block;margin-top:5px;color:var(--muted);font-size:11px;line-height:1.4;text-transform:none}.vv-option-wrap{grid-column:span 2}.vv-option-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:8px}.vv-option{display:grid;grid-template-columns:18px 1fr;gap:8px;align-items:start;min-height:92px;padding:10px;border:1px solid var(--border);border-radius:9px;background:#071020}.vv-option input{width:16px;height:16px;margin-top:2px}.vv-option strong{display:block;font-size:11px;color:var(--text);line-height:1.25}.vv-option span{display:block;margin-top:2px;color:var(--muted);font-size:10px;line-height:1.3}.vv-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.vv-msg{padding:10px 12px;border-radius:9px;background:var(--success-dim);color:#86efac}.vv-error{padding:10px 12px;border-radius:9px;background:var(--danger-dim);color:#fca5a5}.vv-table{overflow:auto}.vv-table table{width:100%;border-collapse:collapse}.vv-table th,.vv-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.vv-table th{font-size:10px;color:var(--muted);text-transform:uppercase}.vv-pill{display:inline-flex;padding:3px 8px;border-radius:999px;background:var(--bg-hover);font-size:10px}.vv-pill.ok{background:var(--success-dim);color:#86efac}.vv-pill.bad{background:var(--danger-dim);color:#fca5a5}.vv-pill.warn{background:var(--warning-dim);color:#facc15}.vv-code{display:block;padding:9px;border:1px solid var(--border);border-radius:8px;background:#071020;color:#bae6fd;word-break:break-all;font-size:12px}.vv-note{font-size:11px;color:var(--muted);line-height:1.45}.vv-split{display:grid;grid-template-columns:minmax(300px,1fr) minmax(300px,1fr);gap:14px}.vv-diag{display:grid;gap:8px;margin-top:12px}.vv-diag-row{display:grid;grid-template-columns:28px minmax(180px,1fr) minmax(180px,2fr);gap:10px;align-items:start;padding:10px;border:1px solid var(--border);border-radius:10px;background:#071020}.vv-diag-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.vv-diag-icon.ok{background:var(--success-dim);color:#86efac}.vv-diag-icon.error{background:var(--danger-dim);color:#fca5a5}.vv-diag-icon.pending{background:var(--bg-hover);color:#94a3b8}.vv-diag-icon.warning{background:var(--warning-dim);color:#facc15}.vv-diag-label{font-weight:700}.vv-diag-detail{color:var(--muted);font-size:11px}.vv-media-list{display:grid;gap:12px}.vv-media-card{display:grid;grid-template-columns:minmax(220px,1.1fr) minmax(260px,1fr) minmax(260px,1.4fr);gap:12px;padding:12px;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.025)}.vv-media-card audio{width:100%;max-width:360px}.vv-media-card form{display:grid;gap:8px}.vv-media-card .vv-field textarea{min-height:70px}.vv-call-list{display:grid;gap:10px}.vv-call{border:1px solid var(--border);border-radius:12px;background:#071020;overflow:hidden}.vv-call summary{display:grid;grid-template-columns:28px minmax(180px,1.2fr) minmax(260px,2fr) minmax(120px,.5fr);gap:12px;align-items:center;padding:12px;cursor:pointer;list-style:none}.vv-call summary::-webkit-details-marker{display:none}.vv-arrow{width:24px;height:24px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--bg-hover);color:var(--primary);font-weight:800}.vv-call[open] .vv-arrow{transform:rotate(90deg)}.vv-call-title strong{display:block}.vv-call-title span,.vv-call-meta,.vv-step span,.vv-event-detail{color:var(--muted);font-size:11px}.vv-stepper{display:grid;grid-template-columns:repeat(7,minmax(75px,1fr));gap:7px}.vv-step{min-height:60px;border:1px solid var(--border);border-radius:8px;padding:8px;background:rgba(255,255,255,.025)}.vv-step.done{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.08)}.vv-step.bad{border-color:rgba(239,68,68,.55);background:rgba(239,68,68,.08)}.vv-step b{display:block;font-size:10px}.vv-step i{display:inline-flex;width:18px;height:18px;border-radius:50%;align-items:center;justify-content:center;margin-bottom:5px;background:var(--bg-hover);font-style:normal}.vv-step.done i{background:var(--success-dim);color:#86efac}.vv-step.bad i{background:var(--danger-dim);color:#fca5a5}.vv-call-body{border-top:1px solid var(--border);padding:12px;display:grid;gap:12px}.vv-event-list{display:grid;gap:8px}.vv-event{display:grid;grid-template-columns:130px minmax(160px,1fr) minmax(220px,2fr) 90px;gap:10px;padding:9px;border:1px solid var(--border);border-radius:9px;background:rgba(255,255,255,.025)}.vv-event-label{font-weight:700}.vv-muted-box{padding:10px;border:1px dashed var(--border);border-radius:9px;color:var(--muted);font-size:12px}@media(max-width:1200px){.vv-option-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}.vv-call summary{grid-template-columns:28px 1fr}.vv-stepper{grid-template-columns:repeat(3,minmax(90px,1fr))}.vv-event{grid-template-columns:1fr}.vv-media-card{grid-template-columns:1fr}}@media(max-width:900px){.vv-head,.vv-split{display:grid}.vv-actions{width:100%}.vv-diag-row{grid-template-columns:28px 1fr}.vv-diag-detail{grid-column:2}.vv-option-wrap{grid-column:auto}.vv-option-grid{grid-template-columns:1fr}.vv-stepper{grid-template-columns:1fr}}
</style>
<style>
.vv-chart-card canvas{height:280px!important;max-height:280px}.vv-status-card #vvStatus{height:190px!important;max-height:190px}.vv-status-card #vvStatusBars{height:180px!important;max-height:180px}.vv-overview-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.vv-rate.good strong{color:#86efac}.vv-rate.bad strong{color:#fca5a5}.vv-rate.warn strong{color:#facc15}.vv-funnel{overflow:auto;padding-bottom:3px}.vv-funnel-inner{min-width:940px}.vv-funnel-graph{height:148px;border-bottom:1px solid var(--border);background:#0b0f13}.vv-funnel-svg{display:block;width:100%;height:148px}.vv-funnel-labels{display:grid;gap:0}.vv-funnel-label{min-height:86px;padding:10px 10px 9px;border-left:1px solid var(--border)}.vv-funnel-label:last-child{border-right:1px solid var(--border)}.vv-funnel-label strong{display:block;font-size:18px;line-height:1.1}.vv-funnel-label small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.vv-funnel-label span{display:block;margin-top:7px;color:#facc15;font-size:11px;font-weight:800}.vv-funnel-label em{display:block;margin-top:3px;color:#60a5fa;font-size:10px;font-style:normal;font-weight:700}.vv-overview-wide{grid-column:1/-1}@media(max-width:700px){.vv-funnel-inner{min-width:760px}.vv-funnel-graph,.vv-funnel-svg{height:120px}.vv-funnel-label{padding:8px;min-height:78px}.vv-funnel-label strong{font-size:16px}}
</style>
<div class="vv">
  <div class="vv-head">
    <div><h1>Torpedo de Voz</h1><p class="text-muted">Central para chamadas de voz, Telnyx, campanhas, audios, webhooks e logs auditaveis.</p></div>
    <div class="vv-actions"><span class="vv-pill <?=!empty($provider['enabled'])?'ok':'bad'?>"><?=!empty($provider['enabled'])?'Telnyx ativo':'Telnyx inativo'?></span><span class="vv-pill"><?=vv_h($provider['connection_status'] ?? 'pending')?></span></div>
  </div>
  <nav class="vv-nav">
    <?php foreach(['overview'=>'Visao geral','queue'=>'Fila','campaigns'=>'Campanhas','new'=>'Nova campanha','contacts'=>'Contatos e listas','media'=>'Audios e vozes','numbers'=>'Numeros','ai'=>'IA','calls'=>'Chamadas','reports'=>'Relatorios','settings'=>'Configuracoes','webhooks'=>'Webhooks e logs','suppression'=>'Bloqueio'] as $k=>$label): ?>
      <a class="<?=$tab===$k?'active':''?>" href="torpedo_voz.php?tab=<?=$k?>"><?=vv_h($label)?></a>
    <?php endforeach; ?>
  </nav>
  <?php if($message): ?><div class="vv-msg"><?=vv_h($message)?></div><?php endif; ?>
  <?php if($error): ?><div class="vv-error"><?=vv_h($error)?></div><?php endif; ?>

<?php if($tab === 'overview'): ?>
  <section class="vv-grid">
    <?php foreach(['campaigns'=>'Campanhas','running'=>'Em execucao','scheduled'=>'Programadas','initiated'=>'Iniciadas','ringing'=>'Chamando','answered'=>'Atendidas','human'=>'Humanos','machine'=>'Caixa postal','failed'=>'Falhas','completed'=>'Concluidas','audio_completed'=>'Audio completo','blocked'=>'Bloqueados'] as $k=>$label): ?>
      <div class="vv-card vv-kpi"><small><?=vv_h($label)?></small><strong><?=(int)$stats[$k]?></strong></div>
    <?php endforeach; ?>
  </section>
  <section class="vv-overview-metrics">
    <div class="vv-card vv-kpi vv-rate good"><small>Taxa de conclusao</small><strong><?=number_format($successRate,1,',','.')?>%</strong><span class="vv-note"><?=$finishedCalls?> de <?=$totalCalls?> chamadas</span></div>
    <div class="vv-card vv-kpi vv-rate warn"><small>Taxa de atendimento</small><strong><?=number_format($answerRate,1,',','.')?>%</strong><span class="vv-note"><?=$answeredCalls?> atendidas</span></div>
    <div class="vv-card vv-kpi vv-rate bad"><small>Taxa de erro</small><strong><?=number_format($errorRate,1,',','.')?>%</strong><span class="vv-note"><?=$errorCalls?> com falha ou erro</span></div>
  </section>
  <section class="vv-card vv-overview-wide">
    <div class="panel-title">Funil geral das ligacoes</div>
    <div class="vv-funnel mt-3">
      <div class="vv-funnel-inner">
        <div class="vv-funnel-graph">
          <svg class="vv-funnel-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <?php foreach($funnelPoints as $part): ?>
              <polygon points="<?=vv_h($part['points'])?>" fill="rgba(250,204,21,<?=vv_h($part['opacity'])?>)" stroke="rgba(2,6,23,.55)" stroke-width=".15"></polygon>
            <?php endforeach; ?>
          </svg>
        </div>
        <div class="vv-funnel-labels" style="grid-template-columns:repeat(<?=count($funnelStages)?>,minmax(120px,1fr))">
          <?php foreach($funnelStages as $s): ?>
            <div class="vv-funnel-label"><small title="<?=vv_h($s['label'])?>"><?=vv_h($s['label'])?></small><strong><?=(int)$s['count']?></strong><span><?=number_format((float)$s['pct_total'],1,',','.')?>% do total</span><em><?=number_format((float)$s['pct_prev'],1,',','.')?>% da etapa anterior</em></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>
  <section class="vv-split">
    <div class="vv-card vv-chart-card"><div class="panel-title">Chamadas por dia</div><canvas id="vvDaily"></canvas></div>
    <div class="vv-card vv-status-card"><div class="panel-title">Distribuicao por status</div><canvas id="vvStatus"></canvas><canvas id="vvStatusBars" class="mt-3"></canvas></div>
  </section>
  <script>
  const vd=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,vs=<?=json_encode($statusRows,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;
  if(window.Chart){const colors=['#22c55e','#38bdf8','#facc15','#ef4444','#a78bfa','#f97316','#14b8a6','#94a3b8'];new Chart(vvDaily,{type:'line',data:{labels:vd.map(x=>x.d),datasets:[{label:'Chamadas',data:vd.map(x=>+x.c),borderColor:'#38bdf8',backgroundColor:'rgba(56,189,248,.16)',fill:true,tension:.35},{label:'Concluidas',data:vd.map(x=>+x.done),borderColor:'#22c55e',tension:.35},{label:'Humanos',data:vd.map(x=>+x.human),borderColor:'#facc15',tension:.35}]},options:{maintainAspectRatio:false,plugins:{legend:{labels:{color:'#cbd5e1'}}},scales:{x:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}},y:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}}}}});new Chart(vvStatus,{type:'doughnut',data:{labels:vs.map(x=>x.status),datasets:[{data:vs.map(x=>+x.c),backgroundColor:colors}]},options:{maintainAspectRatio:false,cutout:'64%',plugins:{legend:{position:'bottom',labels:{color:'#cbd5e1'}}}}});new Chart(vvStatusBars,{type:'bar',data:{labels:vs.map(x=>x.status),datasets:[{label:'Chamadas',data:vs.map(x=>+x.c),backgroundColor:colors}]},options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}},y:{ticks:{color:'#cbd5e1'},grid:{display:false}}}}})}
  </script>
<?php elseif($tab === 'queue'): ?>
  <section class="vv-grid">
    <div class="vv-card vv-kpi"><small>Na fila agora</small><strong><?=$queueOpenTotal?></strong><span class="vv-note">queued, retry, scheduled e processing</span></div>
    <div class="vv-card vv-kpi"><small>Concluidos no filtro</small><strong><?=$queueCompletedTotal?></strong><span class="vv-note">Jobs de bloco de voz completos</span></div>
    <div class="vv-card vv-kpi"><small>Falhas no filtro</small><strong><?=$queueFailedTotal?></strong><span class="vv-note">Jobs de voz com erro final</span></div>
    <div class="vv-card vv-kpi"><small>Previsao geral</small><strong><?=vv_h(vv_duration_label($queueEtaSeconds))?></strong><span class="vv-note">Base: <?=vv_h($queueSecondsPerCall)?>s por chamada</span></div>
  </section>
  <section class="vv-card">
    <div class="card-header-title">Filtros da fila de automacoes</div>
    <form method="get" class="vv-form-grid mt-3">
      <input type="hidden" name="tab" value="queue">
      <label class="vv-field"><label>De</label><input type="date" name="queue_from" value="<?=vv_h($queueFrom)?>"></label>
      <label class="vv-field"><label>Ate</label><input type="date" name="queue_to" value="<?=vv_h($queueTo)?>"></label>
      <label class="vv-field"><label>Fluxo</label><select name="queue_flow_id"><option value="0">Todos os fluxos</option><?php foreach($queueFlowOptions as $f): $fid=(int)$f['id']; ?><option value="<?=$fid?>" <?=$queueFlowId===$fid?'selected':''?>>#<?=$fid?> - <?=vv_h($f['name'])?><?=((string)$f['status']!=='active'?' · '.vv_h($f['status']):'')?></option><?php endforeach; ?></select></label>
      <label class="vv-field"><label>Status</label><select name="queue_status"><?php foreach(['open'=>'Abertos agora','all'=>'Todos','scheduled'=>'Agendados','processing'=>'Processando','completed'=>'Concluidos','failed'=>'Falhas'] as $k=>$label): ?><option value="<?=$k?>" <?=$queueStatus===$k?'selected':''?>><?=vv_h($label)?></option><?php endforeach; ?></select></label>
      <div><button class="btn btn-primary mt-3">Filtrar</button></div>
    </form>
  </section>
  <section class="vv-card">
    <div class="card-header-title">Resumo por fluxo</div>
    <div class="vv-table mt-3"><table><thead><tr><th>Fluxo</th><th>Fila</th><th>Processando</th><th>Concluidos</th><th>Falhas</th><th>Chamadas criadas</th><th>Atendidas</th><th>Previsao de zerar</th><th>Ultima chamada</th></tr></thead><tbody>
      <?php foreach($queueByFlow as $flow): ?>
        <tr>
          <td><strong>#<?=(int)$flow['flow_id']?> - <?=vv_h($flow['flow_name'])?></strong><div class="vv-note"><?=vv_h($flow['flow_status'])?></div></td>
          <td><span class="vv-pill <?=((int)$flow['pending']>0?'warn':'ok')?>"><?=(int)$flow['pending']?></span></td>
          <td><?=(int)$flow['processing']?></td>
          <td><?=(int)$flow['completed']?></td>
          <td><span class="vv-pill <?=((int)$flow['failed']>0?'bad':'')?>"><?=(int)$flow['failed']?></span></td>
          <td><?=(int)$flow['calls']?></td>
          <td><?=(int)$flow['answered']?></td>
          <td><?=vv_h(vv_duration_label((int)($flow['eta_seconds'] ?? 0)))?></td>
          <td><?=vv_h(vv_dt($flow['last_call']) ?: '-')?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$queueByFlow): ?><tr><td colspan="9">Nenhum job de chamada de voz encontrado para os filtros atuais.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="vv-card">
    <div class="card-header-title">Itens da fila</div>
    <p class="vv-note">Mostra os blocos de chamada de voz encontrados nas automacoes. Jobs abertos aparecem mesmo fora do periodo para nao esconder pendencias antigas.</p>
    <div class="vv-table mt-3"><table><thead><tr><th>Quando</th><th>Fluxo e bloco</th><th>Aluno</th><th>Job</th><th>Chamada</th><th>Tentativas</th><th>Erro</th></tr></thead><tbody>
      <?php foreach($queueRows as $row): $open=isset($queueOpenStatuses[(string)$row['job_status']]); ?>
        <tr>
          <td><?=vv_h(vv_dt($row['available_at']))?><div class="vv-note">Criado: <?=vv_h(vv_dt($row['job_created_at']))?></div></td>
          <td><strong>#<?=(int)$row['flow_id']?> - <?=vv_h($row['flow_name'])?></strong><div class="vv-note"><?=vv_h($row['node_label'])?> · v<?=(int)$row['version_number']?></div></td>
          <td><?=vv_h(($row['nome'] ?: 'Aluno #' . (int)$row['user_id']))?><div class="vv-note"><?=vv_h($row['email'] ?: $row['telefone'] ?: '')?></div></td>
          <td><span class="vv-pill <?=$open?'warn':((string)$row['job_status']==='failed'?'bad':'ok')?>"><?=vv_h($row['job_status'])?></span><div class="vv-note">job #<?=(int)$row['job_id']?> · run #<?=(int)$row['run_id']?></div></td>
          <td><?php if(!empty($row['attempt_id'])): ?><span class="vv-pill"><?=vv_h($row['call_status'] ?: 'created')?></span><div class="vv-note">attempt #<?=(int)$row['attempt_id']?> · <?=vv_h(vv_dt($row['call_created_at']))?></div><?php else: ?>-<div class="vv-note">Ainda nao criou chamada</div><?php endif; ?></td>
          <td><?=(int)$row['attempts']?> / <?=(int)$row['max_attempts']?></td>
          <td class="text-muted"><?=vv_h(trim((string)$row['last_error']) !== '' ? mb_substr((string)$row['last_error'],0,180) : '-')?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$queueRows): ?><tr><td colspan="7">Nenhum item na fila para os filtros atuais.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
<?php elseif($tab === 'settings'): ?>
  <section class="vv-card">
    <div class="card-header"><div><div class="card-header-title">Provedor de voz - Telnyx</div><p class="vv-note">A API key e criptografada no banco. A tela nunca exibe a chave completa.</p></div></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="save_provider">
      <div class="vv-form-grid">
        <label class="vv-field"><label>Status</label><select name="enabled"><option value="1" <?=!empty($provider['enabled'])?'selected':''?>>Ativo</option><option value="0" <?=empty($provider['enabled'])?'selected':''?>>Inativo</option></select></label>
        <label class="vv-field"><label>Ambiente</label><select name="environment"><option value="test" <?=$provider['environment']==='test'?'selected':''?>>Teste</option><option value="production" <?=$provider['environment']==='production'?'selected':''?>>Producao</option></select></label>
        <label class="vv-field"><label>API key <?=!empty($creds['api_key'])?'('.vv_h(voice_mask_secret(voice_decrypt_secret((string)$creds['api_key']))).')':''?></label><input type="password" name="api_key" autocomplete="new-password" placeholder="Cole somente se quiser substituir"></label>
        <label class="vv-field"><label>Public key do webhook</label><input name="public_key" value="<?=vv_h($cfg['public_key'] ?? '')?>"></label>
        <label class="vv-field"><label>Connection ID</label><input name="connection_id" value="<?=vv_h($cfg['connection_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Outbound Voice Profile ID</label><input name="outbound_voice_profile_id" value="<?=vv_h($cfg['outbound_voice_profile_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Organization ID</label><input name="organization_id" value="<?=vv_h($cfg['organization_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Numero padrao de origem</label><input name="default_from_number" value="<?=vv_h($cfg['default_from_number'] ?? '')?>" placeholder="+5531999999999"></label>
        <label class="vv-field"><label>URL base publica</label><input name="webhook_base_url" value="<?=vv_h($cfg['webhook_base_url'] ?? rtrim(BASE_URL, '/'))?>"></label>
        <label class="vv-field"><label>Versao API</label><input name="api_version" value="<?=vv_h($cfg['api_version'] ?? 'v2')?>"></label>
        <label class="vv-field"><label>Timeout HTTP</label><input type="number" name="http_timeout" value="<?=vv_h($cfg['http_timeout'] ?? 15)?>"></label>
        <label class="vv-field"><label>Max. retries</label><input type="number" name="max_retries" value="<?=vv_h($cfg['max_retries'] ?? 1)?>"></label>
        <label class="vv-field"><label>Intervalo retry (seg.)</label><input type="number" name="retry_interval_seconds" value="<?=vv_h($cfg['retry_interval_seconds'] ?? 30)?>"></label>
        <label class="vv-field"><label>Concorrencia</label><input type="number" name="concurrency_limit" value="<?=vv_h($cfg['concurrency_limit'] ?? 1)?>"></label>
        <label class="vv-field"><label>Chamadas/minuto</label><input type="number" name="calls_per_minute" value="<?=vv_h($cfg['calls_per_minute'] ?? 1)?>"></label>
        <label class="vv-field"><label>Chamadas/hora</label><input type="number" name="calls_per_hour" value="<?=vv_h($cfg['calls_per_hour'] ?? 300)?>"></label>
        <label class="vv-field"><label>Chamadas/dia</label><input type="number" name="calls_per_day" value="<?=vv_h($cfg['calls_per_day'] ?? 1000)?>"></label>
        <label class="vv-field"><label>Espaco fila automacao (seg.)</label><input type="number" name="automation_queue_spacing_seconds" value="<?=vv_h($cfg['automation_queue_spacing_seconds'] ?? 75)?>"><span class="vv-field-hint">Intervalo minimo entre novas tentativas de voz de automacoes.</span></label>
        <label class="vv-field"><label>Backoff limite simultaneo (seg.)</label><input type="number" name="automation_concurrency_backoff_step_seconds" value="<?=vv_h($cfg['automation_concurrency_backoff_step_seconds'] ?? 90)?>"><span class="vv-field-hint">Quanto acrescentar ao atraso quando a Telnyx/sistema estiver no limite.</span></label>
        <label class="vv-field"><label>Backoff maximo automacao (seg.)</label><input type="number" name="automation_concurrency_backoff_max_seconds" value="<?=vv_h($cfg['automation_concurrency_backoff_max_seconds'] ?? 3600)?>"><span class="vv-field-hint">Teto para refileirar por limite simultaneo antes de tentar novamente.</span></label>
        <label class="vv-field"><label>Limite diario de gasto</label><input type="number" step="0.01" name="daily_cost_limit" value="<?=vv_h($cfg['daily_cost_limit'] ?? 0)?>"></label>
        <label class="vv-field"><label>Duracao maxima padrao</label><input type="number" name="default_call_limit_secs" value="<?=vv_h($cfg['default_call_limit_secs'] ?? 120)?>"></label>
        <label class="vv-field"><label>Timeout atendimento</label><input type="number" name="default_timeout_secs" value="<?=vv_h($cfg['default_timeout_secs'] ?? 30)?>"></label>
        <label class="vv-field"><label>Codigo pais padrao</label><input name="default_country_code" value="<?=vv_h($cfg['default_country_code'] ?? '55')?>"></label>
        <label class="vv-field"><label>Fuso horario</label><input name="timezone" value="<?=vv_h($cfg['timezone'] ?? 'America/Sao_Paulo')?>"></label>
        <label class="vv-field"><label>Destinos permitidos</label><input name="allowed_destinations" value="<?=vv_h($cfg['allowed_destinations'] ?? '+55')?>"></label>
        <label class="vv-field"><label>Voz TTS padrao</label><input name="default_voice" value="<?=vv_h($cfg['default_voice'] ?? '')?>" placeholder="Conforme voz disponivel na Telnyx"></label>
        <label class="vv-field"><label>Idioma padrao</label><input name="default_language" value="<?=vv_h($cfg['default_language'] ?? 'pt-BR')?>"></label>
        <label class="vv-field"><label>Telefone padrao de teste</label><input name="test_default_phone" value="<?=vv_h($cfg['test_default_phone'] ?? '')?>" placeholder="+5531999999999"></label>
        <label class="vv-field"><label>Audio padrao de teste</label><select name="test_default_audio_media_id"><option value="0">Sem audio salvo</option><?php foreach($media as $m): $mid=(int)$m['id']; ?><option value="<?=$mid?>" <?=((int)($cfg['test_default_audio_media_id'] ?? 0)===$mid)?'selected':''?>><?=vv_h($m['name'])?></option><?php endforeach; ?></select></label>
        <label class="vv-field"><label>Numeros autorizados para teste</label><textarea name="test_allowed_numbers" placeholder="+5531999999999, +5511999999999"><?=vv_h($cfg['test_allowed_numbers'] ?? '')?></textarea><span class="vv-field-hint">Allowlist interna: a chamada de teste so pode ligar para estes numeros. A Telnyx nao fornece essa lista; ela evita teste acidental para aluno/lead real.</span></label>
        <div class="vv-field vv-option-wrap"><label>Padroes para novas chamadas</label><div class="vv-option-grid">
          <label class="vv-option"><input type="checkbox" name="amd_default" <?=!empty($cfg['amd_default'])?'checked':''?>><span><strong>Detectar humano/caixa postal</strong><span>Usa AMD quando a chamada/campanha nao sobrescrever essa opcao.</span></span></label>
          <label class="vv-option"><input type="checkbox" name="record_calls_default" <?=!empty($cfg['record_calls_default'])?'checked':''?>><span><strong>Gravar chamadas</strong><span>Prepara o padrao de gravacao; mantenha desligado se nao houver politica/consentimento.</span></span></label>
          <label class="vv-option"><input type="checkbox" name="transcribe_calls_default" <?=!empty($cfg['transcribe_calls_default'])?'checked':''?>><span><strong>Transcrever chamadas</strong><span>Padrao futuro para salvar transcricoes quando a etapa de transcricao estiver ativa.</span></span></label>
          <label class="vv-option"><input type="checkbox" name="debug_mode" <?=!empty($cfg['debug_mode'])?'checked':''?>><span><strong>Debug detalhado</strong><span>Guarda mais detalhes tecnicos nos logs para investigacao de falhas.</span></span></label>
        </div></div>
      </div>
      <div class="vv-actions mt-3"><button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>Salvar configuracao</button></div>
    </form>
  </section>
  <section class="vv-split">
    <div class="vv-card"><div class="card-header-title">Webhooks para cadastrar na Telnyx</div><p class="vv-note">Principal</p><code class="vv-code"><?=vv_h($webhookUrl)?></code><p class="vv-note mt-3">Failover</p><code class="vv-code"><?=vv_h($failoverUrl)?></code></div>
    <div class="vv-card"><div class="card-header-title">Diagnostico e teste controlado</div><form method="post" class="vv-actions"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="test_connection"><button class="btn btn-ghost" <?=$canWrite?'':'disabled'?>>Testar conexao Telnyx</button></form><?php if($diagnostic): ?><div class="vv-diag"><div class="vv-actions"><span class="vv-pill <?=!empty($diagnostic['ready'])?'ok':'bad'?>"><?=!empty($diagnostic['ready'])?'Pronto para chamadas':'Com pendencias'?></span><span class="vv-pill ok">OK: <?=(int)($diagnostic['summary']['ok'] ?? 0)?></span><span class="vv-pill warn">Pendentes: <?=(int)($diagnostic['summary']['pending'] ?? 0)?></span><span class="vv-pill bad">Erros: <?=(int)($diagnostic['summary']['error'] ?? 0)?></span></div><?php foreach(($diagnostic['items'] ?? []) as $it): $st=(string)($it['status'] ?? 'pending'); ?><div class="vv-diag-row"><span class="vv-diag-icon <?=$st?>"><?=$st==='ok'?'✓':($st==='error'?'!':($st==='warning'?'?':'-'))?></span><div class="vv-diag-label"><?=vv_h($it['label'] ?? '')?></div><div class="vv-diag-detail"><?=vv_h($it['detail'] ?? '')?><?php if(!empty($it['meta']['url'])): ?><br><code><?=vv_h($it['meta']['url'])?></code><?php endif; ?></div></div><?php endforeach; ?></div><?php endif; ?><form method="post" class="mt-3" onsubmit="return confirm('Esta acao cria uma chamada real pela Telnyx e pode gerar custo. Continuar?')"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="test_call"><div class="vv-form-grid"><label class="vv-field"><label>Telefone autorizado</label><input name="test_phone" placeholder="+5531999999999"></label><label class="vv-field"><label>Audio URL opcional</label><input name="test_audio_url" placeholder="https://.../audio.mp3"></label><label class="vv-field"><label>Mensagem TTS de referencia</label><textarea name="test_message">Ola, esta e uma chamada de teste do Torpedo de Voz.</textarea></label><label class="vv-field"><label>Confirmacao</label><label><input type="checkbox" name="confirm_test_call" value="1"> Entendo que pode gerar custo</label></label></div><button class="btn btn-danger mt-3" <?=$canWrite?'':'disabled'?>>Fazer chamada de teste</button></form></div>
  </section>
  <script>
  (() => {
    const cfg = <?=json_encode(['phone'=>(string)($cfg['test_default_phone'] ?? ''),'audio'=>(int)($cfg['test_default_audio_media_id'] ?? 0)], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;
    const media = <?=json_encode(array_map(static fn($m)=>['id'=>(int)$m['id'],'name'=>(string)$m['name']], $media), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;
    const phone = document.querySelector('input[name="test_phone"]');
    if (phone && cfg.phone) phone.value = cfg.phone;
    const url = document.querySelector('input[name="test_audio_url"]');
    if (url && !document.querySelector('select[name="test_audio_media_id"]')) {
      const wrap = document.createElement('label');
      wrap.className = 'vv-field';
      const options = ['<option value="0">Sem audio salvo, usar TTS</option>'].concat(media.map(a => `<option value="${a.id}" ${a.id === cfg.audio ? 'selected' : ''}>${String(a.name).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}</option>`));
      wrap.innerHTML = '<label>Audio salvo</label><select name="test_audio_media_id">' + options.join('') + '</select>';
      url.closest('.vv-field')?.before(wrap);
      const hint = document.createElement('span');
      hint.className = 'vv-field-hint';
      hint.textContent = 'Se preencher URL, ela substitui o audio salvo.';
      url.after(hint);
    }
  })();
  </script>
<?php elseif($tab === 'numbers'): ?>
  <section class="vv-card"><div class="card-header d-flex justify-between align-center gap-3"><div><div class="card-header-title">Numeros de telefone</div><p class="vv-note">Sincronize os numeros configurados na Telnyx e use esta area apenas para escolher padrao, regras internas e excecoes.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="sync_numbers"><button class="btn btn-ghost" <?=$canWrite?'':'disabled'?>>Sincronizar Telnyx</button></form></div><?php if($syncResult && !empty($syncResult['errors'])): ?><div class="vv-error"><?=vv_h(implode(' ', $syncResult['errors']))?></div><?php endif; ?><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="save_number"><label class="vv-field"><label>Nome</label><input name="friendly_name"></label><label class="vv-field"><label>Numero E.164</label><input name="phone_e164" placeholder="+5531999999999"></label><label class="vv-field"><label>Pais</label><input name="country" value="BR"></label><label class="vv-field"><label>Regiao</label><input name="region"></label><label class="vv-field"><label>Tipo</label><input name="type" value="voice"></label><label class="vv-field"><label>Origem</label><select name="source_type"><option value="telnyx_owned">Telnyx comprado</option><option value="verified_external">Externo verificado</option><option value="ported">Portado</option><option value="sip">SIP</option><option value="manual">Manual</option></select></label><label class="vv-field"><label>Connection ID</label><input name="connection_id"></label><label class="vv-field"><label>Outbound Profile</label><input name="outbound_profile_id"></label><label class="vv-field"><label>Flags</label><label><input type="checkbox" name="outbound_enabled" checked> Origina</label><label><input type="checkbox" name="inbound_enabled"> Recebe</label><label><input type="checkbox" name="is_default"> Padrao</label></label><label class="vv-field"><label>Observacoes</label><textarea name="notes"></textarea></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Salvar numero manual</button></div></form></section>
  <section class="vv-card vv-table"><table><thead><tr><th>Numero</th><th>Nome</th><th>Origem</th><th>Status</th><th>Padrao</th><th>Outbound</th></tr></thead><tbody><?php foreach($numbers as $n): ?><tr><td><?=vv_h($n['phone_e164'])?></td><td><?=vv_h($n['friendly_name'])?></td><td><?=vv_h($n['source_type'])?></td><td><span class="vv-pill"><?=vv_h($n['status'])?></span></td><td><?=!empty($n['is_default'])?'Sim':'-'?></td><td><?=!empty($n['outbound_enabled'])?'Sim':'-'?></td></tr><?php endforeach; ?><?php if(!$numbers): ?><tr><td colspan="6">Nenhum numero cadastrado.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'media'): ?>
  <section class="vv-card"><div class="card-header-title">Biblioteca de audios</div><form method="post" enctype="multipart/form-data" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="upload_media"><label class="vv-field"><label>Nome</label><input name="name" placeholder="Aviso de live"></label><label class="vv-field"><label>Descricao</label><textarea name="description" placeholder="Resumo interno do uso deste audio"></textarea></label><label class="vv-field"><label>Arquivo MP3/WAV</label><input type="file" name="audio" accept=".mp3,.wav,audio/mpeg,audio/wav"></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Enviar audio</button></div></form></section>
  <section class="vv-card">
    <div class="card-header-title">Audios enviados</div>
    <div class="vv-table"><table><thead><tr><th>Audio</th><th>Reproduzir</th><th>Titulo e descricao</th><th>Arquivo</th><th>Acoes</th></tr></thead><tbody><?php foreach($media as $m): ?><tr><td><strong><?=vv_h($m['name'])?></strong><div class="vv-note"><?=vv_h(vv_media_description($m) ?: 'Sem descricao.')?></div></td><td><?php if(!empty($m['public_url'])): ?><audio controls preload="none" src="<?=vv_h($m['public_url'])?>" style="width:260px;max-width:100%"></audio><?php else: ?>-<?php endif; ?></td><td><form method="post" class="vv-inline-edit" id="vv-media-edit-<?=(int)$m['id']?>"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="update_media"><input type="hidden" name="media_id" value="<?=(int)$m['id']?>"><label class="vv-field"><label>Titulo</label><input name="name" value="<?=vv_h($m['name'])?>" required></label><label class="vv-field"><label>Descricao</label><textarea name="description" placeholder="Resumo interno do uso deste audio"><?=vv_h(vv_media_description($m))?></textarea></label></form></td><td><span class="vv-pill"><?=vv_h($m['mime_type'])?></span> <span class="vv-pill"><?=number_format(((int)$m['file_size'])/1024,1,',','.')?> KB</span><code class="vv-code mt-3"><?=vv_h($m['public_url'])?></code></td><td><div class="vv-actions"><button class="btn btn-primary" form="vv-media-edit-<?=(int)$m['id']?>" <?=$canWrite?'':'disabled'?>>Salvar</button><form method="post" onsubmit="return confirm('Remover este audio da biblioteca?')"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="delete_media"><input type="hidden" name="media_id" value="<?=(int)$m['id']?>"><button class="btn btn-danger" <?=$canWrite?'':'disabled'?>>Excluir</button></form></div></td></tr><?php endforeach; ?><?php if(!$media): ?><tr><td colspan="5">Nenhum audio enviado.</td></tr><?php endif; ?></tbody></table></div>
  </section>
<?php elseif($tab === 'new'): ?>
  <section class="vv-card"><div class="card-header-title">Nova campanha</div><p class="vv-note">Nesta fase a campanha nasce como rascunho. A fila grande fica para o worker, nunca para a requisicao web.</p><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="create_campaign"><label class="vv-field"><label>Nome</label><input name="name" required></label><label class="vv-field"><label>Descricao</label><input name="description"></label><label class="vv-field"><label>Modo</label><select name="message_mode"><option value="text_to_speech">Texto para voz</option><option value="uploaded_audio">Audio enviado</option><option value="ai_assistant">IA assistente</option></select></label><label class="vv-field"><label>Concorrencia</label><input type="number" name="concurrency_limit" value="1"></label><label class="vv-field"><label>Chamadas/minuto</label><input type="number" name="calls_per_minute" value="1"></label><label class="vv-field"><label>Tentativas</label><input type="number" name="max_attempts" value="1"></label><label class="vv-field"><label>Mensagem humano</label><textarea name="message_template" placeholder="Ola, {{primeiro_nome|aluno}}..."></textarea></label><label class="vv-field"><label>Mensagem caixa postal</label><textarea name="machine_message_template"></textarea></label><label class="vv-field"><label>Opcoes</label><label><input type="checkbox" name="answering_machine_detection"> AMD</label><label><input type="checkbox" name="record_calls"> Gravar</label><label><input type="checkbox" name="transcribe_calls"> Transcrever</label></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Criar rascunho</button></div></form></section>
<?php elseif($tab === 'campaigns'): ?>
  <section class="vv-card vv-table"><table><thead><tr><th>Campanha</th><th>Status</th><th>Modo</th><th>Limites</th><th>Criada</th></tr></thead><tbody><?php foreach($campaigns as $c): ?><tr><td><strong><?=vv_h($c['name'])?></strong><div class="text-muted"><?=vv_h($c['description'])?></div></td><td><span class="vv-pill"><?=vv_h($c['status'])?></span></td><td><?=vv_h($c['message_mode'])?></td><td><?=vv_h($c['concurrency_limit'])?> simult. / <?=vv_h($c['calls_per_minute'])?> min</td><td><?=vv_h(vv_dt($c['created_at']))?></td></tr><?php endforeach; ?><?php if(!$campaigns): ?><tr><td colspan="5">Nenhuma campanha criada.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'calls' || $tab === 'reports'): ?>
  <section class="vv-card vv-table"><div class="card-header-title">Logs de chamadas</div><p class="vv-note">Falhas vindas da Telnyx aparecem aqui com codigo e detalhe legivel para diagnostico.</p><table><thead><tr><th>ID</th><th>Destino</th><th>Origem</th><th>Status</th><th>Humano/maquina</th><th>Dura.</th><th>Custo</th><th>Criada</th><th>Erro</th></tr></thead><tbody><?php foreach($calls as $c): $err=voice_error_summary((string)($c['error_json'] ?? ''), (string)($c['provider_response_json'] ?? '')); ?><tr><td>#<?=(int)$c['id']?></td><td><?=vv_h(voice_mask_phone($c['to_number']))?></td><td><?=vv_h($c['from_number'])?></td><td><span class="vv-pill <?=$c['status']==='failed'?'bad':''?>"><?=vv_h($c['status'])?></span></td><td><?=vv_h($c['answered_by'] ?: '-')?></td><td><?=vv_h($c['duration_seconds'] ?: '-')?></td><td><?=vv_h($c['cost'] ?: '-')?></td><td><?=vv_h(vv_dt($c['created_at']))?></td><td class="text-muted"><?=vv_h($err ?: '-')?></td></tr><?php endforeach; ?><?php if(!$calls): ?><tr><td colspan="9">Nenhuma chamada registrada.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'webhooks'): ?>
  <section class="vv-card">
    <div class="card-header-title">Linha do tempo das chamadas</div>
    <p class="vv-note">Cada chamada aparece em uma linha. Abra a seta para ver o passo a passo, os eventos recebidos da Telnyx e os webhooks ligados a esta chamada.</p>
    <div class="vv-call-list mt-3">
      <?php foreach(array_slice($calls,0,80) as $c): $aid=(int)$c['id'];$callEvents=$eventsByAttempt[$aid] ?? [];$user=$usersById[(int)($c['user_id'] ?? 0)] ?? [];$doneSteps=0;$steps=vv_voice_stage_defs();foreach($steps as $s)if(vv_voice_stage_done($c,$callEvents,$s)||vv_voice_stage_inferred($c,$callEvents,$s))$doneSteps++;$err=voice_error_summary((string)($c['error_json'] ?? ''), (string)($c['provider_response_json'] ?? ''));$isBad=(string)$c['status']==='failed'||$err!==''; ?>
        <details class="vv-call">
          <summary>
            <span class="vv-arrow">&rsaquo;</span>
            <span class="vv-call-title"><strong>#<?=$aid?> - <?=vv_h($user['nome'] ?? (string)$c['to_number'])?></strong><span><?=vv_h($c['to_number'])?> · criada em <?=vv_h(vv_dt($c['created_at']))?></span></span>
            <span class="vv-stepper">
              <?php foreach($steps as $s): $done=vv_voice_stage_done($c,$callEvents,$s);$inferred=vv_voice_stage_inferred($c,$callEvents,$s);$bad=$isBad&&$s['key']==='ended'; ?>
                <span class="vv-step <?=($done||$inferred)?'done':($bad?'bad':'')?>"><i><?=($done||$inferred)?'ok':($bad?'!':'-')?></i><b><?=vv_h($s['label'])?></b><span><?=vv_h(vv_voice_stage_text($c,$callEvents,$s))?></span></span>
              <?php endforeach; ?>
            </span>
            <span class="vv-call-meta"><span class="vv-pill <?=$isBad?'bad':((string)$c['status']==='finished'?'ok':'warn')?>"><?=vv_h(vv_voice_status_label((string)$c['status']))?></span><br><?=$doneSteps?>/<?=count($steps)?> etapas</span>
          </summary>
          <div class="vv-call-body">
            <div class="vv-actions">
              <span class="vv-pill">Origem: <?=vv_h(voice_mask_phone((string)$c['from_number']))?></span>
              <span class="vv-pill">Destino: <?=vv_h($c['to_number'])?></span>
              <span class="vv-pill">Duração: <?=vv_h($c['duration_seconds'] ?: '-')?>s</span>
              <span class="vv-pill">Custo: <?=vv_h($c['cost'] ?: '-')?></span>
              <span class="vv-pill">Atendido por: <?=vv_h($c['answered_by'] ?: 'nao detectado')?></span>
            </div>
            <?php if($err): ?><div class="vv-error"><?=vv_h($err)?></div><?php endif; ?>
            <div>
              <div class="card-header-title">Eventos desta chamada</div>
              <div class="vv-event-list mt-2">
                <?php foreach(array_reverse($callEvents) as $e): $eventHooks=$webhooksByEvent[(string)($e['provider_event_id'] ?? '')] ?? []; ?>
                  <div class="vv-event">
                    <div><?=vv_h(vv_dt($e['received_at']))?></div>
                    <div><div class="vv-event-label"><?=vv_h(vv_voice_event_label((string)$e['event_type'], (string)$e['normalized_event']))?></div><span class="vv-note"><?=vv_h($e['event_type'])?></span></div>
                    <div class="vv-event-detail"><?=vv_h(vv_voice_event_detail($e))?><br>Gatilho central: <?=vv_h(['initiated'=>'VOICE_CALL_INITIATED','ringing'=>'VOICE_CALL_RINGING','answered'=>'VOICE_CALL_ANSWERED','answered_human'=>'VOICE_CALL_HUMAN','answered_machine'=>'VOICE_CALL_MACHINE','audio_started'=>'VOICE_CALL_AUDIO_STARTED','audio_completed'=>'VOICE_CALL_AUDIO_COMPLETED','busy'=>'VOICE_CALL_BUSY','no_answer'=>'VOICE_CALL_NOT_ANSWERED','rejected'=>'VOICE_CALL_REJECTED','failed'=>'VOICE_CALL_FAILED','completed'=>'VOICE_CALL_COMPLETED','interacted'=>'VOICE_CALL_DTMF_RECEIVED'][(string)$e['normalized_event']] ?? 'sem gatilho central')?><?php foreach($eventHooks as $hook): ?><br>Webhook: assinatura <?=!empty($hook['signature_valid'])?'OK':'nao validada'?>, HTTP <?=(int)$hook['http_status']?>, status <?=vv_h($hook['processing_status'])?><?=trim((string)$hook['error'])!==''?' - '.vv_h($hook['error']):''?><?php endforeach; ?></div>
                    <div><span class="vv-pill <?=!empty($eventHooks)?'ok':'warn'?>"><?=count($eventHooks)?> webhook</span></div>
                  </div>
                <?php endforeach; ?>
                <?php if(!$callEvents): ?><div class="vv-muted-box">Ainda nao chegou nenhum evento da Telnyx para esta chamada. Ela pode ter sido criada agora ou o webhook pode nao ter retornado.</div><?php endif; ?>
              </div>
            </div>
          </div>
        </details>
      <?php endforeach; ?>
      <?php if(!$calls): ?><div class="vv-muted-box">Nenhuma chamada registrada.</div><?php endif; ?>
    </div>
  </section>
  <section class="vv-card vv-table"><div class="card-header-title">Falhas recentes de chamadas</div><table><thead><tr><th>Attempt</th><th>Destino</th><th>Origem</th><th>Status</th><th>Erro</th><th>Data</th></tr></thead><tbody><?php foreach($failedCalls as $c): $err=voice_error_summary((string)($c['error_json'] ?? ''), (string)($c['provider_response_json'] ?? '')); ?><tr><td>#<?=(int)$c['id']?></td><td><?=vv_h(voice_mask_phone($c['to_number']))?></td><td><?=vv_h(voice_mask_phone($c['from_number']))?></td><td><span class="vv-pill bad"><?=vv_h($c['status'])?></span></td><td class="text-muted"><?=vv_h($err ?: '-')?></td><td><?=vv_h(vv_dt($c['created_at']))?></td></tr><?php endforeach; ?><?php if(!$failedCalls): ?><tr><td colspan="6">Nenhuma falha recente.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'suppression'): ?>
  <section class="vv-card"><div class="card-header-title">Lista de bloqueio</div><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="add_suppression"><label class="vv-field"><label>Telefone</label><input name="phone_e164" placeholder="+5531999999999"></label><label class="vv-field"><label>Motivo</label><input name="reason" value="manual"></label><label class="vv-field"><label>Notas</label><input name="notes"></label><div><button class="btn btn-danger mt-3" <?=$canWrite?'':'disabled'?>>Nao ligar novamente</button></div></form></section>
  <section class="vv-card vv-table"><table><thead><tr><th>Telefone</th><th>Motivo</th><th>Origem</th><th>Criado por</th><th>Data</th></tr></thead><tbody><?php foreach($suppression as $s): ?><tr><td><?=vv_h(voice_mask_phone($s['phone_e164']))?></td><td><?=vv_h($s['reason'])?></td><td><?=vv_h($s['source'])?></td><td><?=vv_h($s['created_by'])?></td><td><?=vv_h(vv_dt($s['created_at']))?></td></tr><?php endforeach; ?><?php if(!$suppression): ?><tr><td colspan="5">Nenhum telefone bloqueado.</td></tr><?php endif; ?></tbody></table></section>
<?php else: ?>
  <section class="vv-card"><div class="card-header-title"><?=vv_h(['contacts'=>'Contatos e listas','ai'=>'Inteligencia Artificial'][$tab] ?? 'Secao')?></div><p class="vv-note">Estrutura reservada para a proxima fase. Ela vai reutilizar as tabelas e logs reais criados agora, sem dados ficticios permanentes.</p></section>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
