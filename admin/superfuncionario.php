<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/superfuncionario_dispatcher.php';

proteger_admin();
$pdo = getPDO();

// menu ativo
$menu = 'superfuncionario';
$view=(string)($_GET['view']??(isset($_GET['edit'])?'rules':(isset($_GET['sf_edit'])?'live':'overview')));if(!in_array($view,['overview','rules','reference','live','logs','settings'],true))$view='overview';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function post_int(string $k): int { return (int)($_POST[$k] ?? 0); }

function sf_parse_live_offset_minutes(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') return 0;
    if (!preg_match('/^([+-])?\s*(\d{1,3})(?::([0-5]\d))?$/', $raw, $m)) return null;

    $sign = ($m[1] ?? '') === '-' ? -1 : 1;
    $hours = (int)$m[2];
    $minutes = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
    return $sign * (($hours * 60) + $minutes);
}

function sf_format_live_offset(?string $liveAt, ?string $disparoAt): string {
    if (!$liveAt || !$disparoAt) return '0:00';
    $liveTs = strtotime($liveAt);
    $dispTs = strtotime($disparoAt);
    if (!$liveTs || !$dispTs) return '0:00';

    $diffMinutes = (int)round(($dispTs - $liveTs) / 60);
    $sign = $diffMinutes < 0 ? '-' : '';
    $abs = abs($diffMinutes);
    return $sign . intdiv($abs, 60) . ':' . str_pad((string)($abs % 60), 2, '0', STR_PAD_LEFT);
}

function sf_format_datetime_local(?string $dbValue): string {
    if (!$dbValue) return '';
    $ts = strtotime($dbValue);
    return $ts ? date('d/m/Y H:i:s', $ts) : '';
}

function sf_admin_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function sf_admin_first_table(PDO $pdo, array $tables): ?string {
    foreach ($tables as $table) {
        if (sf_admin_table_exists($pdo, $table)) return $table;
    }
    return null;
}

function sf_admin_live_filter($raw): array {
    $cfg = ['include_any'=>[],'exclude_any'=>[],'exclude_purchase'=>0,'exclude_cert'=>0,'exclude_zero'=>0,'exclude_rescheduled'=>1];
    $json = json_decode(trim((string)$raw), true);
    if (is_array($json)) {
        $cfg['include_any'] = array_values(array_filter(array_map('intval', $json['include_any'] ?? []), fn($v)=>$v>0));
        $cfg['exclude_any'] = array_values(array_filter(array_map('intval', $json['exclude_any'] ?? []), fn($v)=>$v>0));
        foreach (['exclude_purchase','exclude_cert','exclude_zero'] as $k) $cfg[$k] = (int)(!!($json[$k] ?? 0));
        $cfg['exclude_rescheduled'] = array_key_exists('exclude_rescheduled', $json) ? (int)(!!$json['exclude_rescheduled']) : 1;
    }
    return $cfg;
}

function sf_admin_user_has_any_tag(PDO $pdo, ?string $relTable, int $userId, array $tagIds): bool {
    if (!$relTable || $userId <= 0 || !$tagIds) return false;
    try {
        $in = implode(',', array_fill(0, count($tagIds), '?'));
        $st = $pdo->prepare("SELECT 1 FROM `$relTable` WHERE user_id = ? AND tag_id IN ($in) LIMIT 1");
        $st->execute(array_merge([$userId], $tagIds));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function sf_admin_user_tags(PDO $pdo, ?string $relTable, int $userId): string {
    if (!$relTable || $userId <= 0 || !sf_admin_table_exists($pdo, 'tags')) return '';
    try {
        $st = $pdo->prepare("SELECT t.nome FROM `$relTable` ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = :u ORDER BY t.nome ASC LIMIT 12");
        $st->execute([':u' => $userId]);
        return implode(', ', array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } catch (Throwable $e) { return ''; }
}

function sf_admin_user_has_certificate(PDO $pdo, int $userId): bool {
    if ($userId <= 0 || !sf_admin_table_exists($pdo, 'certificates')) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM certificates WHERE user_id = :u LIMIT 1");
        $st->execute([':u'=>$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function sf_admin_user_has_purchase(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        if (sf_admin_table_exists($pdo, 'hotmart_sales')) {
            $st = $pdo->prepare("SELECT 1 FROM hotmart_sales WHERE matched_user_id = :u AND LOWER(COALESCE(status,'')) IN ('aprovado','completo','approved','complete','paid') LIMIT 1");
            $st->execute([':u'=>$userId]);
            if ($st->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function sf_admin_user_has_reschedule(PDO $pdo, int $userId, ?string $turmaLiveAt): bool {
    if ($userId <= 0 || !sf_admin_table_exists($pdo, 'reagendamentos_live')) return false;
    try {
        $st = $pdo->prepare("SELECT new_turma_live_at FROM reagendamentos_live WHERE user_id=:u AND status IN ('reagendado','enviado') AND new_turma_live_at IS NOT NULL AND new_turma_live_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR) ORDER BY created_at DESC, id DESC LIMIT 1");
        $st->execute([':u'=>$userId]);
        $newLive = (string)($st->fetchColumn() ?: '');
        if ($newLive === '') return false;
        $newTs = strtotime($newLive);
        $turmaTs = $turmaLiveAt ? strtotime($turmaLiveAt) : false;
        if (!$newTs || !$turmaTs) return true;
        return abs($newTs - $turmaTs) > 60;
    } catch (Throwable $e) { return false; }
}

function sf_admin_total_required_lessons(PDO $pdo): int {
    if (!sf_admin_table_exists($pdo, 'lessons')) return 0;
    try { return (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1")->fetchColumn(); }
    catch (Throwable $e) {
        try { return (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1")->fetchColumn(); }
        catch (Throwable $e2) { return 0; }
    }
}

function sf_admin_progress(PDO $pdo, int $userId, int $total): int {
    if ($userId <= 0 || $total <= 0 || !sf_admin_table_exists($pdo, 'lesson_progress')) return 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE lp.user_id=:u AND lp.status='completed' AND l.ativo=1 AND l.conta_para_conclusao=1");
        $st->execute([':u'=>$userId]);
        return max(0, min(100, (int)round(((int)$st->fetchColumn() / $total) * 100)));
    } catch (Throwable $e) { return 0; }
}

function sf_admin_audience(PDO $pdo, array $turma, int $limit = 0): array {
    $codigo = (string)($turma['codigo'] ?? '');
    $filter = sf_admin_live_filter($turma['live_filter_tag_ids'] ?? null);
    $relTable = sf_admin_first_table($pdo, ['user_tags','usuarios_tags','aluno_tags','users_tags','tags_users','user_tag_rel','user_tag_relations']);
    $totalLessons = sf_admin_total_required_lessons($pdo);
    $rows = [];
    $total = 0;
    $skipped = ['tags'=>0,'certificado'=>0,'compra'=>0,'progresso'=>0,'reagendado'=>0];
    if ($codigo === '') return ['total'=>0,'rows'=>[],'skipped'=>$skipped];

    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE codigo_turma = :c ORDER BY nome ASC, id ASC");
        $st->execute([':c'=>$codigo]);
        $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $users = []; }

    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($filter['include_any'] && !sf_admin_user_has_any_tag($pdo, $relTable, $uid, $filter['include_any'])) { $skipped['tags']++; continue; }
        if ($filter['exclude_any'] && sf_admin_user_has_any_tag($pdo, $relTable, $uid, $filter['exclude_any'])) { $skipped['tags']++; continue; }
        if ((int)$filter['exclude_cert'] === 1 && sf_admin_user_has_certificate($pdo, $uid)) { $skipped['certificado']++; continue; }
        if ((int)$filter['exclude_purchase'] === 1 && sf_admin_user_has_purchase($pdo, $uid)) { $skipped['compra']++; continue; }
        $progress = sf_admin_progress($pdo, $uid, $totalLessons);
        if ((int)$filter['exclude_zero'] === 1 && $progress <= 0) { $skipped['progresso']++; continue; }
        if ((int)$filter['exclude_rescheduled'] === 1 && sf_admin_user_has_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) { $skipped['reagendado']++; continue; }
        $total++;
        if ($limit <= 0 || count($rows) < $limit) {
            $rows[] = [
                'nome' => (string)($u['nome'] ?? ''),
                'email' => (string)($u['email'] ?? ''),
                'telefone' => (string)($u['telefone'] ?? ''),
                'turma' => (string)($u['codigo_turma'] ?? $codigo),
                'tags' => sf_admin_user_tags($pdo, $relTable, $uid),
                'andamento' => $progress,
            ];
        }
    }
    return ['total'=>$total,'rows'=>$rows,'skipped'=>$skipped];
}

function sf_admin_log_summary(PDO $pdo, string $codigo, ?string $plannedAt): array {
    $out = ['total'=>0,'ok'=>0,'fail'=>0,'api_fail'=>0,'first'=>'','last'=>'','planned'=>$plannedAt ?: '', 'tags'=>[], 'flows'=>[], 'contacts_created'=>0];
    if ($codigo === '') return $out;
    try {
        $st = $pdo->prepare("SELECT ok,http_status,request_json,response_text,created_at FROM superfuncionario_logs WHERE evento = :e ORDER BY id DESC LIMIT 600");
        $st->execute([':e'=>'LIVE_TURMA_' . $codigo]);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return $out; }
    foreach ($logs as $l) {
        $out['total']++;
        if ((int)($l['ok'] ?? 0) === 1) $out['ok']++; else $out['fail']++;
        $created = (string)($l['created_at'] ?? '');
        if ($created !== '') {
            if ($out['last'] === '') $out['last'] = $created;
            $out['first'] = $created;
        }
        $req = json_decode((string)($l['request_json'] ?? ''), true);
        $dbg = is_array($req) ? (array)($req['_debug'] ?? []) : [];
        foreach ((array)($dbg['tags_requested'] ?? []) as $tag) if ($tag !== '') $out['tags'][(string)$tag] = true;
        foreach ((array)($dbg['flows_requested'] ?? []) as $flow) if ($flow !== '') $out['flows'][(string)$flow] = true;
        $resp = json_decode((string)($l['response_text'] ?? ''), true);
        if (is_array($resp) && array_key_exists('success', $resp) && !$resp['success']) $out['api_fail']++;
        if (is_array($resp) && !empty($resp['contact_created'])) $out['contacts_created']++;
    }
    $out['tags'] = array_keys($out['tags']);
    $out['flows'] = array_keys($out['flows']);
    return $out;
}

function sf_admin_ensure_live_dispatch_logs(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS live_turma_dispatch_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                turma_id INT NULL,
                turma_codigo VARCHAR(80) NULL,
                planned_at DATETIME NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                total_alunos INT NOT NULL DEFAULT 0,
                elegiveis INT NOT NULL DEFAULT 0,
                sf_ok INT NOT NULL DEFAULT 0,
                sf_fail INT NOT NULL DEFAULT 0,
                webhook_ok INT NOT NULL DEFAULT 0,
                webhook_fail INT NOT NULL DEFAULT 0,
                skipped_json LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'iniciado',
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_live_dispatch_turma (turma_codigo),
                KEY idx_live_dispatch_started (started_at),
                KEY idx_live_dispatch_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}
}

function sf_admin_skipped_summary(?string $json): string {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '-';
    $labels = [
        'include_tag_table_missing' => 'sem tabela de tags',
        'andamento_zero' => 'andamento zero',
        'tag_excluida' => 'tag excluida',
        'certificado' => 'certificado',
        'compra' => 'compra',
        'live_reagendada' => 'live reagendada',
    ];
    $parts = [];
    foreach ($labels as $key => $label) {
        $n = (int)($data[$key] ?? 0);
        if ($n > 0) $parts[] = $label . ': ' . $n;
    }
    return $parts ? implode(' | ', $parts) : '-';
}

// garante tabelas
sf_ensure_tables($pdo);
sf_admin_ensure_live_dispatch_logs($pdo);

// ===== eventos (mesmos do Webhooks) =====
$eventOptions = [
    'INSCRITO'              => 'Aluno se cadastrou na área de membros (primeira vez)',
    'INSCRICAO_GRATUITA'    => 'Aluno recebeu inscricao gratuita com prazo da turma',
    'INSCRICAO_VITALICIA'   => 'Aluno recebeu acesso vitalicio pago ou concedido',
    'ACESSO_VITALICIO_LIBERADO' => 'Acesso vitalicio foi liberado para o aluno',
    'REINSCRITO'            => 'Aluno se inscreveu novamente (já existente)',
    'PRIMEIRO_LOGIN'        => 'Aluno fez login pela primeira vez na plataforma',
    'ASSISTIU_ALGUMA_AULA'  => 'Aluno assistiu pelo menos 10 segundos de qualquer aula',
    'CONCLUIU_TRILHA'       => 'Concluiu todas as aulas obrigatórias',
    'RETORNO_AGENDADO'      => 'Retorno de contato agendado chegou',
    'APP_INSTALADO'         => 'Aplicativo instalado pelo aluno',
    'APP_NOTIFICACOES_AUTORIZADAS' => 'Aluno autorizou notificações do aplicativo',
    'APP_DESINSTALADO_DETECTADO' => 'Aplicativo desinstalado/inativo detectado',
    'CERT_EMITIDO'          => 'Certificado emitido com sucesso',
    'REENVIO_CERTIFICADO'   => 'Reenvio de certificado',
    'CERT_SENHA_ERRADA'     => 'Tentativa de senha de certificado incorreta',
    'LIVE_TURMA'            => 'Disparo de live por turma (regra global)',
    'LIVE_REAGENDADA'       => 'Live reagendada',
    'LIVE_REAGENDAMENTO_LEMBRETE' => 'Lembrete de live reagendada',
    'LIVE_REAGENDAMENTO_EXPIRADO' => 'Reagendamento expirado',
    'LIVE_ACESSOU'          => 'Live — aluno acessou (via webhook externo)',
    'LIVE_OFERTA'           => 'Live — ficou até a oferta',
    'LIVE_COMPRA'           => 'Live — clicou na compra',
    'LIVE_EVENTO'           => 'Live — evento customizado',
    'WHATSAPP_GRUPO_ENTROU' => 'WhatsApp - aluno entrou no grupo',
    'WHATSAPP_GRUPO_SAIU'   => 'WhatsApp - aluno saiu por conta propria',
    'WHATSAPP_GRUPO_REMOVIDO_ADMIN' => 'WhatsApp - aluno removido por admin',
    'WHATSAPP_BLACKLIST_DETECTADO' => 'WhatsApp - blacklist detectada',
];

// dinâmico por aula
try {
    $stLessons = $pdo->query("SELECT id, titulo FROM lessons ORDER BY ordem ASC, id ASC");
    while ($ls = $stLessons->fetch(PDO::FETCH_ASSOC)) {
        $lessonId   = (int)($ls['id'] ?? 0);
        $lessonName = trim((string)($ls['titulo'] ?? 'Aula sem título'));
        if ($lessonId > 0) {
            $code = 'VIU_AULA_' . $lessonId;
            $eventOptions[$code] = $code . ' – ' . $lessonName;
        }
    }
} catch (Throwable $e) { /* ignora */ }

// ===== opções de campos (origem) =====
$fieldOptions = [
    'Payload (fixo)' => [
        'evento'          => 'Evento (código)',
        'timestamp'       => 'Timestamp (ISO)',
        'user.id'         => 'User ID',
        'user.nome'       => 'Nome',
        'user.email'      => 'Email',
        'user.telefone'   => 'Telefone',
        'user.data_live'  => 'Live atual do aluno (banco: Y-m-d H:i:s)',
        'user.turma_live_at' => 'Live atual do aluno (banco: Y-m-d H:i:s)',
        'user.magic_link' => 'Magic link (URL de auto-login, 30 dias)',
    ],
    'Extra — INSCRITO / REINSCRITO / LIVE' => [
        'extra.codigo_turma'              => 'Código da turma atual do aluno',
        'extra.codigo_live'               => 'Código/slug da live da turma',
        'extra.data_live'                 => 'Data da live atual em BR (dd/mm/aaaa hh:mm)',
        'extra.data.live'                 => 'Data da live atual em BR (alias de extra.data_live)',
        'extra.data_live_iso'             => 'Data da live atual em banco (Y-m-d H:i:s)',
        'extra.data.live_iso'             => 'Data da live atual em banco (alias de extra.data_live_iso)',
        'extra.tipo_inscricao'            => 'Tipo da inscricao: gratuita ou vitalicia',
        'extra.tipo_inscricao_solicitada' => 'Tipo solicitado no canal de entrada, antes de preservar direitos existentes',
        'extra.acesso_vitalicio'          => '1 quando o aluno possui acesso vitalicio',
        'extra.acesso_pago'               => '1 somente quando o vitalicio veio de pagamento real',
        'extra.acesso.dias_restantes'     => 'Dias restantes do acesso temporario',
        'extra.reinscricao_renovou_prazo' => '1 quando a reinscricao realmente iniciou um novo prazo',
        'extra.live_url'                  => 'Link da sala/live quando configurado',
        'extra.reagendamento_id'          => 'ID do histórico de reagendamento',
        'extra.qtd_inscricoes'            => 'Total de inscrições do aluno',
        'extra.primeira_inscricao'        => 'Data da 1ª inscrição',
        'extra.data_inscricao_anterior'   => 'Data da penúltima inscrição',
        'extra.turma_anterior'            => 'Turma da inscrição anterior',
        'extra.eh_reinscrito'             => '0 = novo, 1 = reinscrito',
        'extra.andamento'                 => '% de conclusão no LIVE_TURMA',
        'extra.aulas_concluidas'          => 'Aulas obrigatórias concluídas',
        'extra.aulas_totais'              => 'Total de aulas obrigatórias',
    ],
    'Extra - WHATSAPP_GRUPOS' => [
        'extra.telefone' => 'telefone limpo',
        'extra.group_id' => 'ID do grupo',
        'extra.participant_id' => 'ID participante/LID',
        'extra.author_id' => 'ID autor da acao',
        'extra.action_original' => 'action original da Evolution',
        'extra.tipo_interpretado' => 'evento interpretado',
        'extra.payload_log_id' => 'ID do payload no monitor',
        'extra.blacklist.id' => 'ID da blacklist',
        'extra.blacklist.reason' => 'motivo da blacklist',
        'extra.blacklist.origem' => 'origem da blacklist',
    ],
    'Extra — CERT_EMITIDO' => [
        'extra.pdf_url'           => 'pdf_url (link do certificado)',
        'extra.codigo_certificado' => 'codigo_certificado',
        'extra.curso'             => 'curso',
        'extra.emitido_em'        => 'emitido_em',
    ],
    'Extra - REENVIO_CERTIFICADO' => [
        'extra.pdf_url'           => 'pdf_url (link do certificado)',
        'extra.codigo_certificado' => 'codigo_certificado',
        'extra.curso'             => 'curso',
        'extra.emitido_em'        => 'emitido_em',
        'extra.certificado_id'    => 'certificado_id',
        'extra.origem'            => 'origem do reenvio',
    ],
    'Extra - RETORNO_AGENDADO' => [
        'extra.agendamento_id' => 'agendamento_id',
        'extra.tipo' => 'tipo',
        'extra.scheduled_at' => 'scheduled_at',
        'extra.assunto' => 'assunto',
        'extra.mensagem' => 'mensagem original',
        'extra.mensagem_renderizada' => 'mensagem com variaveis',
        'extra.origem' => 'origem',
    ],
    'Extra — CERT_SENHA_ERRADA' => [
        'extra.motivo' => 'motivo',
    ],
    'Users (tabela users)' => [],
];

// hints por evento — exibidos dinamicamente no formulário
$eventHints = [
    'RETORNO_AGENDADO'   => 'Extras disponiveis: <code>extra.tipo</code>, <code>extra.scheduled_at</code>, <code>extra.assunto</code>, <code>extra.mensagem</code>, <code>extra.mensagem_renderizada</code>, <code>extra.agendamento_id</code>',
    'INSCRITO'           => 'Disponíveis: <code>user.magic_link</code> (auto-login), <code>extra.codigo_turma</code>, <code>extra.codigo_live</code>, <code>extra.data_live</code>, <code>extra.qtd_inscricoes</code>, <code>extra.primeira_inscricao</code>, <code>extra.eh_reinscrito</code> (=0)',
    'INSCRICAO_GRATUITA' => 'Disponiveis: <code>extra.tipo_inscricao</code>, <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.acesso_vitalicio</code> (=0), <code>extra.acesso_pago</code> (=0)',
    'INSCRICAO_VITALICIA' => 'Disponiveis: <code>extra.tipo_inscricao</code>, <code>extra.codigo_turma</code>, <code>extra.acesso_vitalicio</code> (=1), <code>extra.acesso_pago</code>',
    'REINSCRITO'         => 'Disponíveis: <code>user.magic_link</code>, <code>extra.codigo_turma</code>, <code>extra.qtd_inscricoes</code>, <code>extra.tipo_inscricao</code> (acesso efetivo), <code>extra.tipo_inscricao_solicitada</code>, <code>extra.acesso_vitalicio</code>, <code>extra.acesso_pago</code>, <code>extra.reinscricao_renovou_prazo</code>',
    'PRIMEIRO_LOGIN'     => 'Disparado UMA ÚNICA VEZ — na primeira vez que o aluno acessa a plataforma. Tag PRIMEIRO_LOGIN aplicada automaticamente. Disponíveis: <code>user.id</code>, <code>user.nome</code>, <code>user.email</code>, <code>user.magic_link</code>',
    'CONCLUIU_TRILHA'    => 'Extras disponíveis: <code>extra.andamento</code>, <code>extra.aulas_concluidas</code>, <code>extra.aulas_totais</code>',
    'CERT_EMITIDO'       => 'Extras disponíveis: <code>extra.pdf_url</code> (link do PDF), <code>extra.codigo_certificado</code>, <code>extra.curso</code>, <code>extra.emitido_em</code>',
    'CERT_SENHA_ERRADA'  => 'Extras disponíveis: <code>extra.motivo</code> (valor: <code>senha_incorreta</code>)',
    'LIVE_TURMA'         => 'Extras disponíveis: <code>extra.codigo_turma</code>, <code>extra.codigo_live</code>, <code>extra.data_live</code>, <code>extra.andamento</code>, <code>extra.aulas_concluidas</code>, <code>extra.aulas_totais</code>',
    'LIVE_REAGENDADA'    => 'Extras disponiveis: <code>extra.reagendamento_id</code>, <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.data_live_iso</code>, <code>extra.live_url</code>, <code>extra.reagendamento</code>',
    'LIVE_REAGENDAMENTO_LEMBRETE' => 'Extras disponiveis: <code>extra.reagendamento_id</code>, <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.data_live_iso</code>, <code>extra.live_url</code>',
    'LIVE_REAGENDAMENTO_EXPIRADO' => 'Extras disponiveis: <code>extra.reagendamento_id</code>, <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.data_live_iso</code>, <code>extra.live_url</code>',
    'WHATSAPP_GRUPO_ENTROU' => 'Extras disponiveis: <code>extra.telefone</code>, <code>extra.group_id</code>, <code>extra.participant_id</code>, <code>extra.author_id</code>, <code>extra.action_original</code>, <code>extra.payload_log_id</code>. A tag WHATSAPP_GRUPO_ENTROU e aplicada no aluno.',
    'WHATSAPP_GRUPO_SAIU' => 'Extras disponiveis: <code>extra.telefone</code>, <code>extra.group_id</code>, <code>extra.participant_id</code>, <code>extra.author_id</code>, <code>extra.action_original</code>, <code>extra.payload_log_id</code>. A tag WHATSAPP_GRUPO_SAIU e aplicada no aluno.',
    'WHATSAPP_GRUPO_REMOVIDO_ADMIN' => 'Extras disponiveis: <code>extra.telefone</code>, <code>extra.group_id</code>, <code>extra.participant_id</code>, <code>extra.author_id</code>, <code>extra.action_original</code>, <code>extra.payload_log_id</code>. A tag WHATSAPP_GRUPO_REMOVIDO_ADMIN e aplicada no aluno.',
    'WHATSAPP_BLACKLIST_DETECTADO' => 'Extras disponiveis: <code>extra.telefone</code>, <code>extra.group_id</code>, <code>extra.participant_id</code>, <code>extra.blacklist.id</code>, <code>extra.blacklist.reason</code>. A tag WHATSAPP_BLACKLIST_DETECTADO e aplicada no aluno. A remocao e as notificacoes seguem a configuracao da tela IA WhatsApp.',
];

// pega colunas reais da tabela users (para você mapear qualquer dado salvo)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cols as $c) {
        $name = (string)($c['Field'] ?? '');
        if ($name === '') continue;
        $fieldOptions['Users (tabela users)']['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {
    // se não conseguir, segue sem a lista
}

// ===== salvar config =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
    $baseUrl = post_str('base_url');
    $token = post_str('token');
    $defaultEndpoint = post_str('default_endpoint');
    $headerMode = post_str('header_mode');
    $timeoutSeconds = max(1, post_int('timeout_seconds'));

    if (!in_array($headerMode, ['x-access-token','bearer'], true)) $headerMode = 'x-access-token';
    if ($defaultEndpoint === '') $defaultEndpoint = '/api/contacts';

    // upsert: atualiza linha existente ou cria se não houver
    $existing = $pdo->query("SELECT id FROM superfuncionario_config ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($existing) {
        $st = $pdo->prepare("
            UPDATE superfuncionario_config
               SET is_enabled=:en, base_url=:bu, token=:tk,
                   default_endpoint=:ep, header_mode=:hm, timeout_seconds=:to
             WHERE id=:id LIMIT 1
        ");
        $st->execute([':en'=>$isEnabled,':bu'=>$baseUrl,':tk'=>$token,':ep'=>$defaultEndpoint,':hm'=>$headerMode,':to'=>$timeoutSeconds,':id'=>$existing]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO superfuncionario_config (is_enabled, base_url, token, default_endpoint, header_mode, timeout_seconds)
            VALUES (:en,:bu,:tk,:ep,:hm,:to)
        ");
        $st->execute([':en'=>$isEnabled,':bu'=>$baseUrl,':tk'=>$token,':ep'=>$defaultEndpoint,':hm'=>$headerMode,':to'=>$timeoutSeconds]);
    }

    header('Location: superfuncionario.php');
    exit;
}

// ===== CRUD regras =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    $id = post_int('id');
    $nome = post_str('nome');
    $evento = post_str('evento');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $tagsText = post_str('tags_text');
    $flowsText = post_str('flows_text');
    $endpointOverride = post_str('endpoint_override');

    // mapping (arrays paralelos)
    $sources = $_POST['field_source'] ?? [];
    $dests   = $_POST['field_dest'] ?? [];

    $pairs = [];
    if (is_array($sources) && is_array($dests)) {
        $n = min(count($sources), count($dests));
        for ($i=0; $i<$n; $i++) {
            $src = trim((string)$sources[$i]);
            $dst = trim((string)$dests[$i]);
            if ($src === '' || $dst === '') continue;
            $pairs[] = ['source' => $src, 'dest' => $dst];
        }
    }

    $fieldsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE superfuncionario_rules
               SET nome=:n, evento=:e, is_active=:a,
                   tags_text=:t, flows_text=:f, endpoint_override=:ep, fields_json=:fj
             WHERE id=:id
             LIMIT 1
        ");
        $st->execute([
            ':n' => $nome,
            ':e' => $evento,
            ':a' => $isActive,
            ':t' => $tagsText,
            ':f' => $flowsText,
            ':ep'=> $endpointOverride !== '' ? $endpointOverride : null,
            ':fj'=> $fieldsJson,
            ':id'=> $id,
        ]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO superfuncionario_rules (nome, evento, is_active, tags_text, flows_text, endpoint_override, fields_json)
            VALUES (:n,:e,:a,:t,:f,:ep,:fj)
        ");
        $st->execute([
            ':n' => $nome,
            ':e' => $evento,
            ':a' => $isActive,
            ':t' => $tagsText,
            ':f' => $flowsText,
            ':ep'=> $endpointOverride !== '' ? $endpointOverride : null,
            ':fj'=> $fieldsJson,
        ]);
    }

    header('Location: superfuncionario.php');
    exit;
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $pdo->prepare("DELETE FROM superfuncionario_rules WHERE id=:id")->execute([':id'=>$id]);
    header('Location: superfuncionario.php');
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE superfuncionario_rules SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id'=>$id]);
    header('Location: superfuncionario.php');
    exit;
}

// === AJAX: preview do publico da turma com os filtros atuais do formulario ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sf_turma_audience_preview') {
    header('Content-Type: application/json; charset=utf-8');
    $tid = (int)($_POST['turma_id'] ?? 0);
    if ($tid <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'Turma invalida']);
        exit;
    }
    try {
        $stTurma = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
        $stTurma->execute([':id'=>$tid]);
        $turma = $stTurma->fetch(PDO::FETCH_ASSOC);
        if (!$turma) {
            echo json_encode(['ok'=>false,'msg'=>'Turma nao encontrada']);
            exit;
        }

        $includeSel = is_array($_POST['live_include_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_include_tag_ids']), fn($v)=>$v>0)) : [];
        $excludeSel = is_array($_POST['live_exclude_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_exclude_tag_ids']), fn($v)=>$v>0)) : [];
        $includeRescheduled = isset($_POST['live_include_rescheduled']) ? 1 : 0;
        $turma['live_filter_tag_ids'] = json_encode([
            'include_any' => $includeSel,
            'exclude_any' => $excludeSel,
            'exclude_purchase' => isset($_POST['live_exclude_purchase']) ? 1 : 0,
            'exclude_cert' => isset($_POST['live_exclude_cert']) ? 1 : 0,
            'exclude_rescheduled' => $includeRescheduled ? 0 : 1,
        ], JSON_UNESCAPED_UNICODE);

        $audience = sf_admin_audience($pdo, $turma);
        echo json_encode(['ok'=>true] + $audience, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// === POST: salvar config SF por turma ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sf_turma_save') {
    $tid        = (int)($_POST['turma_id'] ?? 0);
    $sfEnabled  = isset($_POST['sf_enabled']) ? 1 : 0;
    $sfOffsetRaw = trim((string)($_POST['sf_live_offset'] ?? '0:00'));
    $delayMs    = max(0, min(30000, (int)($_POST['delay_ms'] ?? 500)));
    $excludePurchase = isset($_POST['live_exclude_purchase']) ? 1 : 0;
    $excludeCert     = isset($_POST['live_exclude_cert']) ? 1 : 0;
    $includeRescheduled = isset($_POST['live_include_rescheduled']) ? 1 : 0;
    $excludeRescheduled = $includeRescheduled ? 0 : 1;
    $includeSel      = is_array($_POST['live_include_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_include_tag_ids']), fn($v)=>$v>0)) : [];
    $excludeSel      = is_array($_POST['live_exclude_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_exclude_tag_ids']), fn($v)=>$v>0)) : [];
    $filterCfg       = null;
    if ($includeSel || $excludeSel || $excludePurchase || $excludeCert || $includeRescheduled) {
        $filterCfg = json_encode([
            'include_any'      => $includeSel,
            'exclude_any'      => $excludeSel,
            'exclude_purchase' => $excludePurchase,
            'exclude_cert'     => $excludeCert,
            'exclude_rescheduled' => $excludeRescheduled,
        ], JSON_UNESCAPED_UNICODE);
    }
    $offsetMinutes = sf_parse_live_offset_minutes($sfOffsetRaw);
    if ($offsetMinutes === null) {
        header('Location: superfuncionario.php?sf_edit=' . $tid . '&err=' . urlencode('Deslocamento de disparo invalido. Use formatos como -2:30, 0:00 ou 1:15.'));
        exit;
    }

    if ($tid > 0) {
        try {
            $stTurma = $pdo->prepare("SELECT data_live FROM turmas WHERE id = :id LIMIT 1");
            $stTurma->execute([':id' => $tid]);
            $dataLive = (string)($stTurma->fetchColumn() ?: '');
            $liveDisparoData = null;
            if ($dataLive !== '') {
                $live = new DateTime($dataLive);
                if ($offsetMinutes !== 0) {
                    $live->modify(($offsetMinutes > 0 ? '+' : '') . $offsetMinutes . ' minutes');
                }
                $liveDisparoData = $live->format('Y-m-d H:i:s');
            }

            $pdo->prepare("UPDATE turmas SET sf_enabled=:sfen,sf_tags_text=NULL,sf_flows_text=NULL,sf_fields_json=NULL,delay_ms=:delay,live_filter_tag_ids=:filters,live_disparo_data=:ldd,live_disparada=0 WHERE id=:id")
                ->execute([':sfen'=>$sfEnabled,':delay'=>$delayMs,':filters'=>$filterCfg,':ldd'=>$liveDisparoData,':id'=>$tid]);
        } catch (Throwable $e) {
            header('Location: superfuncionario.php?sf_edit=' . $tid . '&err=' . urlencode('Erro ao salvar configuracao da turma: ' . $e->getMessage()));
            exit;
        }
    }
    header('Location: superfuncionario.php?sf_edit=' . $tid . '&saved=1');
    exit;
}

// ===== carregar config e regras =====
$cfg = sf_get_config($pdo);

$rules = $pdo->query("SELECT * FROM superfuncionario_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sfLogStats=['ok'=>0,'failed'=>0];try{$sfLogStats=$pdo->query("SELECT SUM(ok=1) ok,SUM(ok=0) failed FROM (SELECT ok FROM superfuncionario_logs ORDER BY id DESC LIMIT 100) x")->fetch(PDO::FETCH_ASSOC)?:$sfLogStats;}catch(Throwable $e){}

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $st = $pdo->prepare("SELECT * FROM superfuncionario_rules WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$editPairs = [];
if ($edit) {
    // Prefere custom_fields_json (novo), cai em fields_json (legado)
    $cfRaw = trim((string)($edit['custom_fields_json'] ?? ''));
    $fjRaw = trim((string)($edit['fields_json'] ?? ''));
    $rawJson = $cfRaw !== '' ? $cfRaw : $fjRaw;
    if ($rawJson !== '') {
        $tmp = json_decode($rawJson, true);
        if (is_array($tmp)) $editPairs = $tmp;
    }
}

// Datalist plano para o input de origem
$fieldDatalist = [];
foreach ($fieldOptions as $opts) {
    foreach ($opts as $val => $lab) {
        $fieldDatalist[$val] = $lab;
    }
}

$sfEditTurma = null;
if (isset($_GET['sf_edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
    $st->execute([':id' => (int)$_GET['sf_edit']]);
    $sfEditTurma = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$sfTurmasList = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allTagsSfTurma = [];
try {
    $allTagsSfTurma = $pdo->query("SELECT id, nome FROM tags WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try { $allTagsSfTurma = $pdo->query("SELECT id, nome FROM tags ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e2) { $allTagsSfTurma = []; }
}

include __DIR__ . '/_header.php';
?>

<style>
.int-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:16px}.int-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.int-nav a.active,.int-nav a:hover{background:var(--primary-dim);color:var(--primary)}.int-overview{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}.int-kpi{padding:16px;border:1px solid var(--border);border-radius:14px;background:var(--bg-card)}.int-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.int-kpi strong{display:block;font-size:24px;margin-top:5px}@media(max-width:750px){.int-overview{grid-template-columns:repeat(2,1fr)}}
    .int-nav{position:sticky;top:60px;z-index:30;background:var(--bg);padding-top:8px}
    :root {
        --bg:      #020617;
        --bg-card: #0b1120;
        --border:  #1e2d45;
        --text:    #e2e8f0;
        --muted:   #64748b;
        --primary: #facc15;
        --green:   #22c55e;
        --red:     #ef4444;
        --blue:    #3b82f6;
        --purple:  #a855f7;
    }
    *, *::before, *::after { box-sizing: border-box; }
    .sf-wrap { width: 100%; max-width: none; margin: 24px 0 0; padding: 0 16px 60px; overflow-x: hidden; }

    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
    .page-header p  { font-size: 13px; color: var(--muted); margin: 0; }

    .card {
        background: var(--bg-card);
        border-radius: 16px;
        border: 1px solid var(--border);
        box-shadow: 0 8px 32px rgba(0,0,0,.4);
        padding: 22px 26px;
        margin-bottom: 22px;
        min-width: 0;
        overflow: hidden;
    }
    .card-header {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 18px; padding-bottom: 14px;
        border-bottom: 1px solid var(--border);
    }
    .card-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; flex-shrink: 0;
    }
    .card-icon.yellow { background: rgba(250,204,21,.12); }
    .card-icon.purple { background: rgba(168,85,247,.12); }
    .card-icon.blue   { background: rgba(59,130,246,.12); }
    .card-icon.green  { background: rgba(34,197,94,.12); }
    .card-icon.orange { background: rgba(249,115,22,.12); }
    .card-header-text h2 { font-size: 16px; font-weight: 600; margin: 0 0 2px; }
    .card-header-text p  { font-size: 12px; color: var(--muted); margin: 0; }

    .grid-2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
    .grid-2 > * { min-width: 0; }
    @media(max-width: 1100px) { .grid-2 { grid-template-columns: 1fr; } }
    .live-config-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }
    .live-config-grid > * { min-width: 0; }
    @media(max-width: 1180px) {
        .live-config-grid { grid-template-columns: 1fr; }
    }

    label.lbl { font-size: 12px; font-weight: 500; color: var(--muted); display: block; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    input[type="text"], input[type="number"], textarea, select {
        width: 100%; padding: 9px 12px; border-radius: 10px;
        border: 1px solid var(--border); background: #07101f;
        color: var(--text); font-size: 13px; outline: none; transition: border-color .15s;
    }
    input:focus, textarea:focus, select:focus { border-color: var(--blue); }
    textarea { min-height: 70px; resize: vertical; }
    .tag-check-list {
        width: 100%;
        max-height: 170px;
        overflow-y: auto;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #07101f;
        padding: 6px;
    }
    .tag-check-item {
        display: grid;
        grid-template-columns: 16px minmax(0, 1fr);
        align-items: center;
        gap: 8px;
        min-height: 28px;
        padding: 4px 6px;
        border-radius: 7px;
        font-size: 12px;
        color: var(--text);
        cursor: pointer;
    }
    .tag-check-item:hover { background: rgba(255,255,255,.06); }
    .tag-check-item input { width: 14px; height: 14px; accent-color: var(--primary); }
    .tag-check-item span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    select { cursor: pointer; }

    .checkbox-row { display: flex; align-items: center; gap: 8px; }
    .checkbox-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; }
    .checkbox-row label, .checkbox-row span { font-size: 13px; margin: 0; }

    .btn {
        display: inline-flex; align-items: center; gap: 6px;
        border: none; background: var(--primary); color: #111;
        font-weight: 700; font-size: 13px; padding: 10px 20px;
        border-radius: 999px; cursor: pointer; text-decoration: none;
    }
    .btn:hover { filter: brightness(1.06); }
    .btn.blue   { background: var(--blue);   color: #fff; }
    .btn.green  { background: var(--green);  color: #fff; }
    .btn.ghost  { background: rgba(255,255,255,.06); color: var(--text); border: 1px solid var(--border); }
    .btn.sm     { padding: 6px 14px; font-size: 12px; }
    .btn.danger { background: rgba(239,68,68,.12); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
    .btn.danger:hover { background: rgba(239,68,68,.2); }

    .spacer { height: 14px; }
    .note { font-size: 11.5px; color: var(--muted); margin-top: 5px; line-height: 1.5; }
    .note code, .sf-hint code { background: rgba(255,255,255,.06); border-radius: 4px; padding: 1px 5px; font-size: 11px; }

    .sf-hint {
        font-size: 11.5px; color: var(--muted); background: rgba(255,255,255,.03);
        border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; line-height: 1.7;
        border: 1px solid var(--border);
    }
    .sf-hint b { color: var(--text); }
    .sf-hint-warning { border-left: 3px solid rgba(250,204,21,.5); }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .badge-on     { background: rgba(34,197,94,.1);   color: #86efac; border: 1px solid rgba(34,197,94,.3); }
    .badge-off    { background: rgba(100,116,139,.1); color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
    .badge-evt    { background: rgba(250,204,21,.08); border: 1px solid rgba(250,204,21,.2); color: #fef3c7; border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 600; }
    .badge-live   { background: rgba(249,115,22,.1);  color: #fdba74; border: 1px solid rgba(249,115,22,.3); }

    /* rule cards */
    .sf-list { display: flex; flex-direction: column; gap: 12px; }
    .sf-card {
        background: rgba(255,255,255,.03); border: 1px solid var(--border);
        border-radius: 12px; padding: 14px 16px;
    }
    .sf-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
    .sf-card-name { font-size: 14px; font-weight: 600; }
    .sf-card-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .sf-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }

    /* event dropdown */
    .evento-wrapper { position: relative; }
    .evento-input-row { display: flex; gap: 4px; }
    .evento-input-row input { flex: 1; cursor: pointer; caret-color: transparent; }
    .evento-toggle-btn {
        border-radius: 10px; border: 1px solid var(--border); background: #07101f;
        color: var(--text); padding: 0 14px; font-size: 12px; cursor: pointer;
        display: inline-flex; align-items: center; white-space: nowrap; flex-shrink: 0;
    }
    .evento-toggle-btn:hover { background: #0f1f3d; }
    .evento-dropdown {
        position: absolute; left: 0; right: 0; margin-top: 4px;
        background: #0b1120; border-radius: 12px; border: 1px solid var(--border);
        max-height: 290px; overflow-y: auto; padding: 8px;
        box-shadow: 0 20px 48px rgba(0,0,0,.75); display: none; z-index: 50;
    }
    .evento-dropdown.aberto { display: block; }
    .ev-group-label {
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); padding: 6px 8px 4px; margin-top: 4px;
    }
    .ev-group-label:first-child { margin-top: 0; }
    .evento-opcao {
        padding: 7px 10px; border-radius: 8px; cursor: pointer;
        display: flex; flex-direction: column; gap: 2px; transition: background .1s;
        border: 1px solid transparent;
    }
    .evento-opcao:hover { background: rgba(255,255,255,.06); }
    .evento-opcao.selecionado { background: rgba(250,204,21,.06); border-color: rgba(250,204,21,.2); }
    .evento-opcao strong { font-size: 11px; color: var(--text); }
    .evento-opcao em    { font-size: 10px; color: var(--muted); font-style: normal; }
    .ev-pill {
        display: inline-block; padding: 1px 6px; border-radius: 999px;
        font-size: 9px; font-weight: 700; text-transform: uppercase;
        margin-left: 4px; vertical-align: middle;
    }
    .ev-pill.cert  { background: rgba(168,85,247,.15); color: #d8b4fe; border: 1px solid rgba(168,85,247,.3); }
    .ev-pill.aluno { background: rgba(59,130,246,.15);  color: #93c5fd; border: 1px solid rgba(59,130,246,.3); }
    .ev-pill.live  { background: rgba(249,115,22,.15);  color: #fdba74; border: 1px solid rgba(249,115,22,.3); }
    .ev-pill.aula  { background: rgba(34,197,94,.15);   color: #86efac; border: 1px solid rgba(34,197,94,.3); }

    /* field rows */
    .field-row { display: grid; grid-template-columns: 1fr 1fr 34px; gap: 8px; align-items: center; margin-bottom: 8px; }
    .btnx { width: 34px; height: 34px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg-card); color: var(--muted); cursor: pointer; font-size: 16px; }
    .btnx:hover { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.3); }

    /* logs */
    .log-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .log-table th, .log-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); font-size: 12px; text-align: left; vertical-align: top; color: var(--text); overflow: hidden; text-overflow: ellipsis; }
    .log-table th { color: var(--muted); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .log-table td:last-child { overflow: visible; white-space: normal; }
    .live-sf-table col:nth-child(1) { width: 12%; }
    .live-sf-table col:nth-child(2) { width: 19%; }
    .live-sf-table col:nth-child(3) { width: 19%; }
    .live-sf-table col:nth-child(4) { width: 9%; }
    .live-sf-table col:nth-child(5) { width: 10%; }
    .live-sf-table col:nth-child(6) { width: 8%; }
    .live-sf-table col:nth-child(7) { width: 12%; }
    .live-sf-table col:nth-child(8) { width: 11%; }
    .live-sf-table th:nth-child(2),
    .live-sf-table th:nth-child(3),
    .live-sf-table td:nth-child(2),
    .live-sf-table td:nth-child(3) { white-space: nowrap; }
    .live-actions { display:flex; gap:6px; flex-wrap:nowrap; justify-content:flex-start; }
    .live-actions .btn.sm { width:36px; height:32px; padding:0; justify-content:center; flex:0 0 auto; }
    .log-ok   { color: #4ade80; }
    .log-fail { color: #f87171; }
    .sf-metric-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin:10px 0 12px; }
    .sf-metric-card { border:1px solid var(--border); border-radius:10px; background:rgba(255,255,255,.025); padding:10px 12px; }
    .sf-metric-label { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:5px; }
    .sf-metric-value { font-size:18px; font-weight:800; color:var(--text); }
    .sf-metric-sub { font-size:11px; color:var(--muted); margin-top:4px; line-height:1.4; }
    .sf-details-row { display:none; background:rgba(255,255,255,.018); }
    .sf-details-row.open { display:table-row; }
    .sf-audience-card { border:1px solid rgba(59,130,246,.28); border-radius:12px; background:rgba(59,130,246,.055); padding:14px; margin:0 0 16px; }
    .sf-audience-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
    .sf-audience-count { font-size:28px; line-height:1; font-weight:800; color:#bfdbfe; }
    .sf-audience-list { margin-top:12px; border-top:1px solid var(--border); padding-top:10px; }
    .sf-audience-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .sf-audience-table th,.sf-audience-table td { border-bottom:1px solid var(--border); padding:7px 6px; font-size:11px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sf-audience-toggle { cursor:pointer; color:#bfdbfe; font-size:12px; font-weight:700; margin-top:10px; }
    @media(max-width: 900px) { .sf-metric-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }

    .empty-state {
        text-align: center; padding: 32px; color: var(--muted);
        border: 1px dashed var(--border); border-radius: 12px; font-size: 13px;
    }
</style>

<datalist id="sf-source-list">
<?php foreach ($fieldDatalist as $val => $lab): ?>
    <option value="<?= h($val) ?>"><?= h($lab) ?></option>
<?php endforeach; ?>
</datalist>
<datalist id="sf-turma-source-list">
<?php foreach ($fieldDatalist as $val => $lab): ?>
    <option value="<?= h($val) ?>"><?= h($lab) ?></option>
<?php endforeach; ?>
</datalist>

<div class="sf-wrap int-view-<?=h($view)?>">
    <div class="page-header">
        <h1>SuperFuncionário</h1>
        <p>Configure as credenciais globais e crie regras de disparo por evento — tags, fluxos e campos personalizados.</p>
    </div>
    <nav class="int-nav"><?php foreach(['overview'=>'Visão geral','rules'=>'Integrações','reference'=>'Referências','live'=>'Live por turma','logs'=>'Logs','settings'=>'Configurações'] as $k=>$label):?><a class="<?=$view===$k?'active':''?>" href="superfuncionario.php?view=<?=$k?>"><?=h($label)?></a><?php endforeach;?></nav>
    <?php if($view==='overview'):?><div class="int-overview"><div class="int-kpi"><small>Status da integração</small><strong><?=!empty($cfg['is_enabled'])?'Ativa':'Pausada'?></strong></div><div class="int-kpi"><small>Integrações ativas</small><strong><?=count(array_filter($rules,fn($r)=>(int)$r['is_active']===1))?></strong></div><div class="int-kpi"><small>Sucessos recentes</small><strong class="log-ok"><?=(int)$sfLogStats['ok']?></strong></div><div class="int-kpi"><small>Falhas recentes</small><strong class="<?=(int)$sfLogStats['failed']?'log-fail':''?>"><?=(int)$sfLogStats['failed']?></strong></div></div><?php endif;?>

    <div class="grid-2">

        <!-- ===== ESQUERDA: CREDENCIAIS + REFERÊNCIA ===== -->
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon yellow">🔑</div>
                    <div class="card-header-text">
                        <h2>Credenciais globais</h2>
                        <p>Token e endpoint padrão para todos os disparos.</p>
                    </div>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="save_config">

                    <div class="checkbox-row" style="margin-bottom:14px;">
                        <input type="checkbox" id="sf-enabled" name="is_enabled" <?= ((int)$cfg['is_enabled']===1 ? 'checked' : '') ?>>
                        <label for="sf-enabled">Ativar integração SuperFuncionário</label>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label class="lbl">Base URL</label>
                        <input type="text" name="base_url" placeholder="https://app.superfuncionario.com.br" value="<?= h((string)$cfg['base_url']) ?>">
                        <div class="note">Opcional se usar o default.</div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label class="lbl">Token</label>
                        <input type="text" name="token" value="<?= h((string)$cfg['token']) ?>" placeholder="Cole o token aqui">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label class="lbl">Modo do Header</label>
                            <select name="header_mode">
                                <option value="x-access-token" <?= $cfg['header_mode']==='x-access-token'?'selected':'' ?>>X-ACCESS-TOKEN</option>
                                <option value="bearer" <?= $cfg['header_mode']==='bearer'?'selected':'' ?>>Bearer</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl">Timeout (s)</label>
                            <input type="number" name="timeout_seconds" value="<?= (int)$cfg['timeout_seconds'] ?>" min="1" max="60">
                        </div>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label class="lbl">Default Endpoint</label>
                        <input type="text" name="default_endpoint" value="<?= h((string)$cfg['default_endpoint']) ?>" placeholder="/api/contacts">
                    </div>

                    <button class="btn" type="submit">💾 Salvar credenciais</button>
                </form>
                <div class="note" style="margin-top:12px;">
                    Credenciais globais. As regras abaixo definem <b>quando</b> e <b>o que</b> enviar.
                </div>
            </div>

            <!-- Referência de extras -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon blue">📦</div>
                    <div class="card-header-text">
                        <h2>Extras por evento</h2>
                        <p>Campos disponíveis para mapear no source.</p>
                    </div>
                </div>
                <?php
                $payloadRef = [
                    'INSCRITO'          => ['extra.codigo_live', 'extra.data_live'],
                    'CONCLUIU_TRILHA'   => ['extra.andamento', 'extra.aulas_concluidas', 'extra.aulas_totais'],
                    'RETORNO_AGENDADO'  => ['extra.agendamento_id', 'extra.tipo', 'extra.scheduled_at', 'extra.assunto', 'extra.mensagem', 'extra.mensagem_renderizada', 'extra.origem'],
                    'CERT_EMITIDO'      => ['extra.pdf_url', 'extra.codigo_certificado', 'extra.curso', 'extra.emitido_em'],
                    'REENVIO_CERTIFICADO' => ['extra.pdf_url', 'extra.codigo_certificado', 'extra.curso', 'extra.emitido_em', 'extra.certificado_id', 'extra.origem'],
                    'CERT_SENHA_ERRADA' => ['extra.motivo'],
                    'LIVE_TURMA'        => ['extra.codigo_turma', 'extra.codigo_live', 'extra.data_live', 'extra.andamento', 'extra.aulas_concluidas', 'extra.aulas_totais'],
                    'LIVE_REAGENDADA'   => ['extra.reagendamento_id', 'extra.codigo_turma', 'extra.data_live', 'extra.data_live_iso', 'extra.live_url', 'extra.reagendamento'],
                    'LIVE_REAGENDAMENTO_LEMBRETE' => ['extra.reagendamento_id', 'extra.codigo_turma', 'extra.data_live', 'extra.data_live_iso', 'extra.live_url'],
                    'LIVE_REAGENDAMENTO_EXPIRADO' => ['extra.reagendamento_id', 'extra.codigo_turma', 'extra.data_live', 'extra.data_live_iso', 'extra.live_url'],
                    'WHATSAPP_GRUPO_ENTROU' => ['extra.telefone', 'extra.group_id', 'extra.participant_id', 'extra.author_id', 'extra.action_original', 'extra.payload_log_id'],
                    'WHATSAPP_GRUPO_SAIU' => ['extra.telefone', 'extra.group_id', 'extra.participant_id', 'extra.author_id', 'extra.action_original', 'extra.payload_log_id'],
                    'WHATSAPP_GRUPO_REMOVIDO_ADMIN' => ['extra.telefone', 'extra.group_id', 'extra.participant_id', 'extra.author_id', 'extra.action_original', 'extra.payload_log_id'],
                    'WHATSAPP_BLACKLIST_DETECTADO' => ['extra.telefone', 'extra.group_id', 'extra.participant_id', 'extra.blacklist.id', 'extra.blacklist.reason', 'extra.payload_log_id'],
                ];
                foreach ($payloadRef as $ev => $fields): ?>
                    <div style="margin-bottom:12px;padding:10px 12px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid var(--border);">
                        <div style="font-size:12px;font-weight:700;margin-bottom:6px;">
                            <span class="badge-evt"><?= h($ev) ?></span>
                            <?php if ($ev === 'LIVE_TURMA'): ?>
                                <span class="badge badge-live" style="margin-left:4px;">Live</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#93c5fd;font-family:monospace;line-height:1.8;">
                            <?= implode('<br>', array_map('htmlspecialchars', $fields)) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="note">Todos os eventos incluem: <code>user.id</code>, <code>user.nome</code>, <code>user.email</code>, <code>user.telefone</code>.</div>
            </div>
        </div>

        <!-- ===== DIREITA: FORM + LISTA ===== -->
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon purple">⚡</div>
                    <div class="card-header-text">
                        <h2><?= $edit ? 'Editar integração' : 'Nova integração' ?></h2>
                        <p><?= $edit ? 'Atualize os dados da regra de disparo.' : 'Preencha para criar uma nova regra de disparo.' ?></p>
                    </div>
                </div>

                <form method="post" id="form-rule">
                    <input type="hidden" name="action" value="save_rule">
                    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label class="lbl">Nome</label>
                            <input type="text" name="nome" value="<?= h((string)($edit['nome'] ?? '')) ?>" placeholder="Ex.: SF - CTA Click">
                        </div>
                        <div>
                            <label class="lbl">Gatilho (evento)</label>
                            <?php $sfEvAtual = (string)($edit['evento'] ?? 'INSCRITO'); ?>
                            <div class="evento-wrapper">
                                <div class="evento-input-row">
                                    <input type="text" name="evento" id="sf-evento-input"
                                           value="<?= h($sfEvAtual) ?>"
                                           placeholder="Selecione o evento gatilho"
                                           readonly>
                                    <button type="button" class="evento-toggle-btn" id="sf-btn-ev-toggle">▼ Ver eventos</button>
                                </div>
                                <div class="evento-dropdown" id="sf-ev-dropdown">
                                    <div class="ev-group-label">Aluno</div>
                                    <div class="evento-opcao" data-value="INSCRITO">
                                        <strong>INSCRITO <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado quando um novo aluno se cadastra na área de membros pela primeira vez.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="INSCRICAO_GRATUITA">
                                        <strong>INSCRICAO_GRATUITA <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Aluno recebeu acesso temporario conforme o prazo configurado na turma.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="INSCRICAO_VITALICIA">
                                        <strong>INSCRICAO_VITALICIA <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Aluno recebeu acesso vitalicio por pagamento ou concessao.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="ACESSO_VITALICIO_LIBERADO">
                                        <strong>ACESSO_VITALICIO_LIBERADO <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>O acesso vitalicio foi efetivamente liberado.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="REINSCRITO">
                                        <strong>REINSCRITO <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado quando um aluno já existente se inscreve novamente.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="PRIMEIRO_LOGIN">
                                        <strong>PRIMEIRO_LOGIN <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado UMA ÚNICA VEZ na primeira vez que o aluno acessa a plataforma.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="ASSISTIU_ALGUMA_AULA">
                                        <strong>ASSISTIU_ALGUMA_AULA <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado quando o aluno assiste pelo menos 10 segundos de qualquer aula.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="CONCLUIU_TRILHA">
                                        <strong>CONCLUIU_TRILHA <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado quando o aluno finaliza todas as aulas obrigatórias.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="RETORNO_AGENDADO">
                                        <strong>RETORNO_AGENDADO <span class="ev-pill aluno">Aluno</span></strong>
                                        <em>Disparado pelo cron quando um retorno de contato chega na data e hora marcada.</em>
                                    </div>
                                    <div class="ev-group-label">WhatsApp Grupos</div>
                                    <div class="evento-opcao" data-value="WHATSAPP_GRUPO_ENTROU">
                                        <strong>WHATSAPP_GRUPO_ENTROU <span class="ev-pill aluno">WhatsApp</span></strong>
                                        <em>Aluno identificado entrou em grupo monitorado.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="WHATSAPP_GRUPO_SAIU">
                                        <strong>WHATSAPP_GRUPO_SAIU <span class="ev-pill aluno">WhatsApp</span></strong>
                                        <em>Aluno identificado saiu por conta propria de grupo monitorado.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="WHATSAPP_GRUPO_REMOVIDO_ADMIN">
                                        <strong>WHATSAPP_GRUPO_REMOVIDO_ADMIN <span class="ev-pill aluno">WhatsApp</span></strong>
                                        <em>Aluno identificado foi removido por admin de grupo monitorado.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="WHATSAPP_BLACKLIST_DETECTADO">
                                        <strong>WHATSAPP_BLACKLIST_DETECTADO <span class="ev-pill aluno">WhatsApp</span></strong>
                                        <em>Numero em blacklist entrou no grupo. A automacao de remocao e alerta e configurada na tela IA WhatsApp.</em>
                                    </div>
                                    <div class="ev-group-label">Certificado</div>
                                    <div class="evento-opcao" data-value="CERT_EMITIDO">
                                        <strong>CERT_EMITIDO <span class="ev-pill cert">Certificado</span></strong>
                                        <em>Disparado quando o aluno acerta a senha e o certificado é gerado.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="REENVIO_CERTIFICADO">
                                        <strong>REENVIO_CERTIFICADO <span class="ev-pill cert">Certificado</span></strong>
                                        <em>Disparado quando o certificado Ã© reenviado pelo admin ou por webhook de entrada.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="CERT_SENHA_ERRADA">
                                        <strong>CERT_SENHA_ERRADA <span class="ev-pill cert">Certificado</span></strong>
                                        <em>Disparado quando o aluno tenta uma senha inválida.</em>
                                    </div>
                                    <div class="ev-group-label">⚡ Live</div>
                                    <div class="evento-opcao" data-value="LIVE_TURMA">
                                        <strong>LIVE_TURMA <span class="ev-pill live">Live</span></strong>
                                        <em>Regra global: disparada para cada aluno da turma quando a data/hora de disparo chega.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_REAGENDADA">
                                        <strong>LIVE_REAGENDADA <span class="ev-pill live">Live</span></strong>
                                        <em>Disparado quando o aluno ou suporte confirma uma nova data de live de repescagem.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_REAGENDAMENTO_LEMBRETE">
                                        <strong>LIVE_REAGENDAMENTO_LEMBRETE <span class="ev-pill live">Live</span></strong>
                                        <em>Disparado pelo cron no horario configurado antes/depois da live reagendada.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_REAGENDAMENTO_EXPIRADO">
                                        <strong>LIVE_REAGENDAMENTO_EXPIRADO <span class="ev-pill live">Live</span></strong>
                                        <em>Disparado quando a live reagendada passou, terminou o prazo de tolerancia configurado e o aluno nao entrou.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_ACESSOU">
                                        <strong>LIVE_ACESSOU <span class="ev-pill live">Live</span></strong>
                                        <em>Aluno acessou a sala da live (via webhook externo configurado em "Eventos Live").</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_OFERTA">
                                        <strong>LIVE_OFERTA <span class="ev-pill live">Live</span></strong>
                                        <em>Aluno ficou até o momento da oferta.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_COMPRA">
                                        <strong>LIVE_COMPRA <span class="ev-pill live">Live</span></strong>
                                        <em>Aluno clicou no botão de compra durante a live.</em>
                                    </div>
                                    <div class="evento-opcao" data-value="LIVE_EVENTO">
                                        <strong>LIVE_EVENTO <span class="ev-pill live">Live</span></strong>
                                        <em>Evento customizado vindo de Eventos Live.</em>
                                    </div>
                                    <?php
                                    $hasLessons = false;
                                    foreach ($eventOptions as $code => $label) {
                                        if (strpos($code, 'VIU_AULA_') !== 0) continue;
                                        if (!$hasLessons) {
                                            echo '<div class="ev-group-label">Aulas</div>';
                                            $hasLessons = true;
                                        }
                                        echo '<div class="evento-opcao" data-value="' . h($code) . '">';
                                        echo '<strong>' . h($code) . ' <span class="ev-pill aula">Aula</span></strong>';
                                        echo '<em>' . h($label) . '</em>';
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="note" style="margin-top:5px;">Clique para selecionar o evento. Apenas um evento por regra.</div>
                        </div>
                    </div>

                    <!-- Hint dinâmico -->
                    <div class="sf-hint sf-hint-warning" id="sf-event-hint" style="display:none;margin-bottom:12px;"></div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label class="lbl">Tag(s) — uma por linha</label>
                            <textarea name="tags_text" placeholder="Ex.: TAG_CTA"><?= h((string)($edit['tags_text'] ?? '')) ?></textarea>
                        </div>
                        <div>
                            <label class="lbl">Flow ID(s) — separados por vírgula</label>
                            <input type="text" name="flows_text" value="<?= h((string)($edit['flows_text'] ?? '')) ?>" placeholder="Ex.: 123,456">
                            <div class="note">O SF permite disparar fluxo dentro do POST /contacts.</div>
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label class="lbl">Endpoint — sobrescreve o default</label>
                        <input type="text" name="endpoint_override" value="<?= h((string)($edit['endpoint_override'] ?? '')) ?>" placeholder="/api/contacts">
                    </div>

                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border);">Campos personalizados</div>

                    <div class="sf-hint" style="margin-bottom:10px;">
                        <b>Origem</b> — selecione da lista ou digite livremente:<br>
                        • Simples: <code>user.email</code>, <code>extra.codigo_live</code> &nbsp;•&nbsp; Profundo: <code>extra.data.purchase.id</code><br>
                        • Fixo: <code>literal:texto aqui</code> &nbsp;•&nbsp; Template: <code>{{user.nome}} - {{evento}}</code><br>
                        • Fallback: <code>user.telefone|extra.phone|literal:sem_telefone</code>
                    </div>

                    <div id="fields">
                        <?php
                        $initialPairs = $editPairs ?: [['source' => '', 'dest' => '']];
                        foreach ($initialPairs as $p):
                        ?>
                            <div class="field-row">
                                <input type="text" name="field_source[]" list="sf-source-list"
                                       value="<?= h((string)($p['source'] ?? '')) ?>"
                                       placeholder="ex.: user.email ou extra.data.id">
                                <input type="text" name="field_dest[]" value="<?= h((string)($p['dest'] ?? '')) ?>"
                                       placeholder="Campo destino no SF">
                                <button class="btnx" type="button" onclick="removeRow(this)">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="btn ghost sm" type="button" onclick="addRow()" style="margin-bottom:14px;">+ Adicionar campo</button>

                    <div class="checkbox-row" style="margin-bottom:16px;">
                        <input type="checkbox" id="sf-is-active" name="is_active" <?= (!$edit || (int)($edit['is_active'] ?? 1)===1) ? 'checked' : '' ?>>
                        <label for="sf-is-active">Regra ativa</label>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="btn">
                            <?= $edit ? '💾 Salvar regra' : '➕ Criar integração' ?>
                        </button>
                        <?php if ($edit): ?>
                            <a href="superfuncionario.php" class="btn ghost">✕ Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Lista de regras -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon green">📋</div>
                    <div class="card-header-text">
                        <h2>Integrações cadastradas</h2>
                        <p><?= count($rules) ?> regra<?= count($rules) !== 1 ? 's' : '' ?> no total.</p>
                    </div>
                </div>

                <?php if (empty($rules)): ?>
                    <div class="empty-state">Nenhuma regra cadastrada ainda.<br>Crie a primeira pelo formulário ao lado.</div>
                <?php else: ?>
                    <div class="sf-list">
                        <?php foreach ($rules as $r):
                            $rCfRaw = trim((string)($r['custom_fields_json'] ?? ''));
                            $rFjRaw = trim((string)($r['fields_json'] ?? ''));
                            $rFieldsRaw = $rCfRaw !== '' ? $rCfRaw : $rFjRaw;
                            $rFields = [];
                            if ($rFieldsRaw !== '') {
                                $tmp = json_decode($rFieldsRaw, true);
                                if (is_array($tmp)) {
                                    foreach ($tmp as $fp) {
                                        if (trim((string)($fp['source'] ?? '')) !== '' && trim((string)($fp['dest'] ?? '')) !== '') $rFields[] = $fp;
                                    }
                                }
                            }
                            $rIsLive = (string)($r['evento'] ?? '') === 'LIVE_TURMA';
                        ?>
                            <div class="sf-card">
                                <div class="sf-card-top">
                                    <div>
                                        <div class="sf-card-name"><?= h((string)$r['nome']) ?></div>
                                        <div style="margin-top:6px;">
                                            <span class="badge-evt"><?= h((string)$r['evento']) ?></span>
                                            <?php if ($rIsLive): ?>
                                                <span class="badge badge-live" style="margin-left:4px;">Live</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="sf-actions">
                                        <a href="?edit=<?= (int)$r['id'] ?>" class="btn ghost sm">✏️ Editar</a>
                                        <a href="?toggle=<?= (int)$r['id'] ?>" class="btn ghost sm">
                                            <?= (int)$r['is_active']===1 ? '⏸ Pausar' : '▶ Ativar' ?>
                                        </a>
                                        <a href="?del=<?= (int)$r['id'] ?>" class="btn danger sm"
                                           onclick="return confirm('Remover esta regra?')">🗑</a>
                                    </div>
                                </div>
                                <div class="sf-card-meta">
                                    <span class="badge <?= (int)$r['is_active']===1 ? 'badge-on' : 'badge-off' ?>">
                                        <?= (int)$r['is_active']===1 ? '● Ativa' : '○ Inativa' ?>
                                    </span>
                                    <?php if ($rFields): ?>
                                        <span class="badge badge-off" title="<?= h(implode(', ', array_column($rFields, 'dest'))) ?>">
                                            <?= count($rFields) ?> campo<?= count($rFields)!==1?'s':'' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (trim((string)($r['tags_text'] ?? '')) !== ''): ?>
                                        <span class="badge badge-off">Tags</span>
                                    <?php endif; ?>
                                    <?php if (trim((string)($r['flows_text'] ?? '')) !== ''): ?>
                                        <span class="badge badge-off">Flows</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="note" style="margin-top:12px;">
                        Dica: para validar, ative a regra e provoque o evento (ex.: assistir aula, concluir trilha).
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ===== DISPARO DE LIVE POR TURMA ===== -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon orange">🚀</div>
            <div class="card-header-text">
                <h2>Disparo de Live por Turma</h2>
                <p>Configure horario, intervalo e filtros do publico. As acoes do SF usam o gatilho global LIVE_TURMA.</p>
            </div>
        </div>

        <div class="sf-hint" style="margin-bottom:16px;">
            <b>Como funciona:</b> quando a data/hora de disparo da turma chega, o sistema enfileira todos os alunos filtrados e dispara o gatilho global <b>LIVE_TURMA</b>, respeitando o delay configurado entre alunos.
        </div>

        <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
            <div style="margin-bottom:16px;padding:10px 14px;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:#4ade80;font-size:13px;">
                ✓ Configuracao de disparo da turma salva com sucesso!
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['err'])): ?>
            <div style="margin-bottom:16px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:13px;">
                <?= h((string)$_GET['err']) ?>
            </div>
        <?php endif; ?>

        <div class="live-config-grid">
            <!-- FORM -->
            <div>
                <?php if ($sfEditTurma):
                    $sfLiveOffset = sf_format_live_offset((string)($sfEditTurma['data_live'] ?? ''), (string)($sfEditTurma['live_disparo_data'] ?? ''));
                    $sfLiveIso = !empty($sfEditTurma['data_live']) ? date('c', strtotime((string)$sfEditTurma['data_live'])) : '';
                    $sfDisparoPreview = sf_format_datetime_local((string)($sfEditTurma['live_disparo_data'] ?? ''));
                    $sfFilterRaw = $sfEditTurma['live_filter_tag_ids'] ?? '';
                    $sfFilter = ['include_any'=>[],'exclude_any'=>[],'exclude_purchase'=>0,'exclude_cert'=>0,'exclude_rescheduled'=>1];
                    if ($sfFilterRaw) {
                        $sfj = json_decode((string)$sfFilterRaw, true);
                        if (is_array($sfj)) {
                            $sfFilter['include_any'] = array_values(array_filter(array_map('intval', $sfj['include_any'] ?? []), fn($v)=>$v>0));
                            $sfFilter['exclude_any'] = array_values(array_filter(array_map('intval', $sfj['exclude_any'] ?? []), fn($v)=>$v>0));
                            $sfFilter['exclude_purchase'] = (int)(!!($sfj['exclude_purchase'] ?? 0));
                            $sfFilter['exclude_cert'] = (int)(!!($sfj['exclude_cert'] ?? 0));
                            $sfFilter['exclude_rescheduled'] = array_key_exists('exclude_rescheduled', $sfj) ? (int)(!!$sfj['exclude_rescheduled']) : 1;
                        }
                    }
                    $sfSelInc = []; foreach ($sfFilter['include_any'] as $tid) $sfSelInc[(int)$tid] = true;
                    $sfSelExc = []; foreach ($sfFilter['exclude_any'] as $tid) $sfSelExc[(int)$tid] = true;
                    $sfExcPurchase = (int)$sfFilter['exclude_purchase'] === 1;
                    $sfExcCert = (int)$sfFilter['exclude_cert'] === 1;
                    $sfIncludeRescheduled = (int)$sfFilter['exclude_rescheduled'] !== 1;
                    $sfAudience = sf_admin_audience($pdo, $sfEditTurma);
                ?>
                    <form method="post" id="form-sf-turma" style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;padding:20px;">
                        <input type="hidden" name="action" value="sf_turma_save">
                        <input type="hidden" name="turma_id" value="<?= (int)$sfEditTurma['id'] ?>">

                        <div style="margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);">
                            <div class="lbl" style="margin-bottom:4px;">Turma selecionada</div>
                            <div style="font-size:15px;font-weight:700;color:var(--text);"><?= h((string)$sfEditTurma['codigo']) ?></div>
                            <?php if (!empty($sfEditTurma['data_live'])): ?>
                                <div class="note">Live: <?= h(sf_format_datetime_local((string)$sfEditTurma['data_live'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="checkbox-row" style="margin-bottom:14px;">
                            <input type="checkbox" id="sf-t-enabled" name="sf_enabled" <?= (int)($sfEditTurma['sf_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label for="sf-t-enabled">Disparar alunos no SF ao chegar na data da live</label>
                        </div>

                        <div style="margin-bottom:14px;">
                            <label class="lbl">Deslocamento do disparo em relacao a live</label>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div>
                                    <input type="text" id="sf-live-offset" name="sf_live_offset"
                                           value="<?= h($sfLiveOffset) ?>"
                                           data-live-at="<?= h($sfLiveIso) ?>"
                                           placeholder="ex: -2:30"
                                           oninput="updateSfLivePreview()">
                                    <div class="note">Use <code>-2:30</code> para disparar 2h30 antes, <code>0:00</code> no horario da live ou <code>1:15</code> depois.</div>
                                </div>
                                <div>
                                    <input type="text" id="sf-live-preview" value="<?= h($sfDisparoPreview) ?>" readonly>
                                    <div class="note">Horario calculado para o disparo.</div>
                                </div>
                            </div>
                        </div>

                        <div style="margin-bottom:14px;">
                            <label class="lbl">Intervalo entre disparos (ms)</label>
                            <input type="number" name="delay_ms" value="<?= (int)($sfEditTurma['delay_ms'] ?? 500) ?>" min="0" max="30000">
                            <div class="note">Tempo de espera entre um aluno e outro. Ex.: <code>2000</code> = 2 segundos.</div>
                        </div>

                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border);">Filtros de exclusao do publico</div>

                        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px;">
                            <label class="checkbox-row">
                                <input type="checkbox" name="live_exclude_purchase" value="1" <?= $sfExcPurchase ? 'checked' : '' ?>>
                                <span style="font-size:13px;">Excluir quem comprou</span>
                            </label>
                            <label class="checkbox-row">
                                <input type="checkbox" name="live_exclude_cert" value="1" <?= $sfExcCert ? 'checked' : '' ?>>
                                <span style="font-size:13px;">Excluir quem ja gerou certificado</span>
                            </label>
                            <label class="checkbox-row">
                                <input type="checkbox" name="live_include_rescheduled" value="1" <?= $sfIncludeRescheduled ? 'checked' : '' ?>>
                                <span style="font-size:13px;">Incluir alunos que reagendaram a live</span>
                            </label>
                        </div>
                        <div class="note" style="margin-top:-6px;margin-bottom:12px;">Por padrao, alunos com live individual reagendada ficam fora do disparo coletivo da turma.</div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                            <div>
                                <label class="lbl">Excluir quem tem qualquer uma destas tags</label>
                                <div class="tag-check-list">
                                    <?php foreach ($allTagsSfTurma as $tg): $tid2=(int)$tg['id']; ?>
                                        <label class="tag-check-item">
                                            <input type="checkbox" name="live_exclude_tag_ids[]" value="<?= $tid2 ?>" <?= isset($sfSelExc[$tid2]) ? 'checked' : '' ?>>
                                            <span title="<?= h((string)$tg['nome']) ?>"><?= h((string)$tg['nome']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label class="lbl">Excluir quem NAO tem pelo menos uma destas tags</label>
                                <div class="tag-check-list">
                                    <?php foreach ($allTagsSfTurma as $tg): $tid2=(int)$tg['id']; ?>
                                        <label class="tag-check-item">
                                            <input type="checkbox" name="live_include_tag_ids[]" value="<?= $tid2 ?>" <?= isset($sfSelInc[$tid2]) ? 'checked' : '' ?>>
                                            <span title="<?= h((string)$tg['nome']) ?>"><?= h((string)$tg['nome']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="note">Vazio = nao exige tag.</div>
                            </div>
                        </div>

                        <div class="sf-audience-card">
                            <div class="sf-audience-top">
                                <div>
                                    <div class="sf-metric-label">Publico previsto deste disparo</div>
                                    <div class="sf-metric-sub" id="sf-audience-status">Calculado com os filtros selecionados nesta tela.</div>
                                </div>
                                <div class="sf-audience-count" id="sf-audience-count"><?= (int)$sfAudience['total'] ?></div>
                            </div>
                            <div class="sf-metric-sub" id="sf-audience-skipped">
                                Fora do envio: compra <?= (int)$sfAudience['skipped']['compra'] ?>,
                                certificado <?= (int)$sfAudience['skipped']['certificado'] ?>,
                                tags <?= (int)$sfAudience['skipped']['tags'] ?>,
                                progresso <?= (int)$sfAudience['skipped']['progresso'] ?>,
                                reagendados <?= (int)$sfAudience['skipped']['reagendado'] ?>.
                            </div>
                            <details class="sf-audience-list">
                                <summary class="sf-audience-toggle">Mostrar pessoas atingidas</summary>
                                <div id="sf-audience-body">
                                    <?php if (empty($sfAudience['rows'])): ?>
                                        <div class="note">Nenhum aluno elegivel com os filtros atuais.</div>
                                    <?php else: ?>
                                        <div style="overflow-x:auto;margin-top:8px;">
                                        <table class="sf-audience-table">
                                            <thead><tr><th>Nome</th><th>Email</th><th>Telefone</th><th>Turma</th><th>Tags</th><th>%</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($sfAudience['rows'] as $ar): ?>
                                                <tr>
                                                    <td title="<?= h($ar['nome']) ?>"><?= h($ar['nome']) ?></td>
                                                    <td title="<?= h($ar['email']) ?>"><?= h($ar['email']) ?></td>
                                                    <td><?= h($ar['telefone']) ?></td>
                                                    <td><?= h($ar['turma']) ?></td>
                                                    <td title="<?= h($ar['tags']) ?>"><?= h($ar['tags'] ?: '-') ?></td>
                                                    <td><?= (int)$ar['andamento'] ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <button class="btn" type="submit">💾 Salvar configuração</button>
                            <a class="btn ghost" href="superfuncionario.php">✕ Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">Selecione uma turma na tabela ao lado para configurar o SF.</div>
                <?php endif; ?>
            </div>

            <!-- TABLE -->
            <div>
                <?php if (empty($sfTurmasList)): ?>
                    <div class="empty-state">Nenhuma turma cadastrada. <a href="turmas.php">Cadastrar turma</a>.</div>
                <?php else: ?>
                <div>
                <table class="log-table live-sf-table">
                    <colgroup>
                        <col><col><col><col><col><col>
                    </colgroup>
                    <thead>
                    <tr>
                        <th>Turma</th>
                        <th>Data Live</th>
                        <th>Disparo</th>
                        <th>SF</th>
                        <th>Disparado</th>
                        <th>Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sfTurmasList as $stl):
                        $stlSfOn  = (int)($stl['sf_enabled'] ?? 0) === 1;
                        $stlDisp  = (int)($stl['live_disparada'] ?? 0) === 1;
                        $stlSummary = sf_admin_log_summary($pdo, (string)($stl['codigo'] ?? ''), (string)($stl['live_disparo_data'] ?? ''));
                        $stlRate = $stlSummary['total'] > 0 ? round(($stlSummary['ok'] / $stlSummary['total']) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?= h((string)$stl['codigo']) ?></td>
                            <td style="white-space:nowrap;color:var(--muted);"><?= h(sf_format_datetime_local((string)($stl['data_live'] ?? ''))) ?></td>
                            <td style="white-space:nowrap;color:var(--muted);font-size:11px;"><?= h(sf_format_datetime_local((string)($stl['live_disparo_data'] ?? ''))) ?></td>
                            <td>
                                <span class="badge <?= $stlSfOn ? 'badge-on' : 'badge-off' ?>">
                                    <?= $stlSfOn ? '● ON' : '○ OFF' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $stlDisp ? 'badge-on' : 'badge-off' ?>">
                                    <?= $stlDisp ? '● Sim' : '○ Não' ?>
                                </span>
                            </td>
                            <td>
                                <div class="live-actions">
                                <a href="?sf_edit=<?= (int)$stl['id'] ?>" class="btn ghost sm">⚙️</a>
                                <button type="button" class="btn ghost sm" onclick="toggleSfMetrics(<?= (int)$stl['id'] ?>)" title="Metricas">&#128200;</button>
                                <a href="turmas.php?reset_disparo=<?= (int)$stl['id'] ?>" class="btn ghost sm" onclick="return confirm('Resetar disparo desta turma?')" title="Resetar">↺</a>
                                </div>
                            </td>
                        </tr>
                        <tr id="sf-metrics-<?= (int)$stl['id'] ?>" class="sf-details-row">
                            <td colspan="6">
                                <div class="sf-metric-grid">
                                    <div class="sf-metric-card"><div class="sf-metric-label">Disparos</div><div class="sf-metric-value"><?= (int)$stlSummary['total'] ?></div><div class="sf-metric-sub">Registros no SF</div></div>
                                    <div class="sf-metric-card"><div class="sf-metric-label">Acertos</div><div class="sf-metric-value"><?= (int)$stlSummary['ok'] ?></div><div class="sf-metric-sub"><?= h((string)$stlRate) ?>% de sucesso HTTP</div></div>
                                    <div class="sf-metric-card"><div class="sf-metric-label">Falhas</div><div class="sf-metric-value"><?= (int)$stlSummary['fail'] ?></div><div class="sf-metric-sub">API success=false: <?= (int)$stlSummary['api_fail'] ?></div></div>
                                    <div class="sf-metric-card"><div class="sf-metric-label">Contatos criados</div><div class="sf-metric-value"><?= (int)$stlSummary['contacts_created'] ?></div><div class="sf-metric-sub">Demais ja existiam</div></div>
                                </div>
                                <div class="sf-metric-sub">
                                    Planejado: <?= h(sf_format_datetime_local((string)$stlSummary['planned'])) ?: '-' ?> |
                                    Primeiro envio real: <?= h(sf_format_datetime_local((string)$stlSummary['first'])) ?: '-' ?> |
                                    Ultimo envio real: <?= h(sf_format_datetime_local((string)$stlSummary['last'])) ?: '-' ?>
                                </div>
                                <div class="note">O SuperFuncionario retorna sucesso por contato. Erro individual de tag/flow so aparece aqui se a API retornar essa falha no response.</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    $liveDispatchLogs = [];
    try {
        $liveDispatchLogs = $pdo->query("
            SELECT *
              FROM live_turma_dispatch_logs
          ORDER BY id DESC
             LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    ?>
    <div class="card">
        <div class="card-header">
            <div class="card-icon blue">&#128202;</div>
            <div class="card-header-text">
                <h2>Execucoes do cron de live</h2>
                <p>Resumo por turma: quantos alunos foram encontrados, filtrados, enviados e quais motivos impediram disparos.</p>
            </div>
        </div>
        <?php if (!$liveDispatchLogs): ?>
            <div class="empty-state">Ainda nao ha resumo de execucao. Ele sera gravado automaticamente nos proximos disparos de turma.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Turma</th>
                        <th>Planejado</th>
                        <th>Executado</th>
                        <th>Alunos</th>
                        <th>SF</th>
                        <th>Webhook</th>
                        <th>Status</th>
                        <th>Excluidos</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($liveDispatchLogs as $dl): ?>
                    <tr>
                        <td style="color:var(--muted)"><?= (int)$dl['id'] ?></td>
                        <td style="font-weight:700"><?= h((string)($dl['turma_codigo'] ?? '')) ?></td>
                        <td style="white-space:nowrap;font-size:11px;"><?= h(sf_format_datetime_local((string)($dl['planned_at'] ?? ''))) ?></td>
                        <td style="white-space:nowrap;font-size:11px;">
                            <?= h(sf_format_datetime_local((string)($dl['started_at'] ?? ''))) ?>
                            <?php if (!empty($dl['finished_at'])): ?><div class="note"><?= h(sf_format_datetime_local((string)$dl['finished_at'])) ?></div><?php endif; ?>
                        </td>
                        <td style="font-size:11px;">
                            Total: <?= (int)($dl['total_alunos'] ?? 0) ?><br>
                            Elegiveis: <?= (int)($dl['elegiveis'] ?? 0) ?>
                        </td>
                        <td style="font-size:11px;">
                            OK: <?= (int)($dl['sf_ok'] ?? 0) ?><br>
                            Falha: <?= (int)($dl['sf_fail'] ?? 0) ?>
                        </td>
                        <td style="font-size:11px;">
                            OK: <?= (int)($dl['webhook_ok'] ?? 0) ?><br>
                            Falha: <?= (int)($dl['webhook_fail'] ?? 0) ?>
                        </td>
                        <td>
                            <span class="badge <?= (string)($dl['status'] ?? '') === 'concluido' ? 'badge-on' : 'badge-off' ?>"><?= h((string)($dl['status'] ?? '')) ?></span>
                            <div class="note"><?= h((string)($dl['message'] ?? '')) ?></div>
                        </td>
                        <td style="font-size:11px;white-space:normal;"><?= h(sf_admin_skipped_summary($dl['skipped_json'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== LOGS ===== -->
    <?php
    $logs = [];
    try {
        $logs = $pdo->query("
            SELECT sl.id, sl.evento, sl.rule_id, sl.ok, sl.http_status,
                   sl.error_text, sl.response_text, sl.created_at,
                   sr.nome AS rule_nome
            FROM superfuncionario_logs sl
            LEFT JOIN superfuncionario_rules sr ON sr.id = sl.rule_id
            ORDER BY sl.id DESC LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    ?>
    <div class="card">
        <div class="card-header">
            <div class="card-icon blue">📊</div>
            <div class="card-header-text">
                <h2>Logs recentes</h2>
                <p>Últimos 30 disparos registrados.</p>
            </div>
        </div>
        <?php if (!$logs): ?>
            <div class="empty-state">Nenhum log registrado ainda. Os disparos aparecem aqui automaticamente.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Evento</th>
                        <th>Regra</th>
                        <th>Status</th>
                        <th>HTTP</th>
                        <th>Campos</th>
                        <th>Detalhe</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $l):
                    $logDebug = null;
                    $reqRaw = trim((string)($l['request_json'] ?? ''));
                    if ($reqRaw !== '') {
                        $reqArr = json_decode($reqRaw, true);
                        if (is_array($reqArr) && isset($reqArr['_debug'])) {
                            $logDebug = $reqArr['_debug'];
                        }
                    }
                ?>
                    <tr>
                        <td style="color:var(--muted)"><?= (int)$l['id'] ?></td>
                        <td style="white-space:nowrap;font-size:11px;"><?= h(sf_format_datetime_local((string)$l['created_at'])) ?></td>
                        <td><span class="badge-evt" style="font-size:10px;"><?= h((string)$l['evento']) ?></span></td>
                        <td style="font-size:11px;"><?= h((string)($l['rule_nome'] ?? '—')) ?></td>
                        <td>
                            <?php if ((int)$l['ok']): ?>
                                <span class="log-ok">✓ OK</span>
                            <?php else: ?>
                                <span class="log-fail">✗ Falha</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:var(--muted);"><?= $l['http_status'] ? (int)$l['http_status'] : '—' ?></td>
                        <td style="white-space:nowrap;font-size:11px;">
                            <?php if ($logDebug !== null):
                                $cnt    = (int)($logDebug['custom_fields_count'] ?? 0);
                                $skiped = (array)($logDebug['skipped_keys'] ?? []);
                                $tooltip = '';
                                if ($cnt > 0) $tooltip .= 'OK: ' . implode(', ', (array)($logDebug['custom_fields_keys'] ?? []));
                                if ($skiped) $tooltip .= ($tooltip ? ' | ' : '') . 'Skip: ' . implode(', ', $skiped);
                            ?>
                                <span title="<?= h($tooltip) ?>" style="<?= $skiped ? 'color:#f59e0b' : 'color:var(--muted)' ?>">
                                    <?= $cnt ?>✓<?= count($skiped) ? ' '.count($skiped).'✗' : '' ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="max-width:220px;word-break:break-all;font-size:11px;">
                            <?php
                            $detail = trim((string)($l['error_text'] ?? ''));
                            if ($detail === '') $detail = trim(substr((string)($l['response_text'] ?? ''), 0, 100));
                            echo h($detail !== '' ? $detail : '—');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.sf-wrap -->

<script>
const sfView=<?=json_encode($view)?>;document.querySelectorAll('.card h2').forEach(h=>{const t=h.textContent.trim(),card=h.closest('.card'),show=sfView==='overview'?false:(sfView==='settings'?t==='Credenciais globais':sfView==='reference'?t==='Extras por evento':sfView==='rules'?(t==='Nova integração'||t==='Editar integração'||t==='Integrações cadastradas'):sfView==='live'?(t==='Disparo de Live por Turma'||t==='Execucoes do cron de live'):sfView==='logs'?t==='Logs recentes':true);if(!show)card.style.display='none';});document.querySelectorAll('.grid-2').forEach(g=>{g.style.gridTemplateColumns='minmax(0,1fr)';Array.from(g.children).forEach(col=>{const cards=Array.from(col.querySelectorAll(':scope > .card'));if(cards.length&&!cards.some(c=>c.style.display!=='none'))col.style.display='none';});});
function removeRow(btn) { btn.closest('.field-row').remove(); }
function addRow() {
    var c = document.getElementById('fields');
    var d = document.createElement('div');
    d.className = 'field-row';
    d.innerHTML =
        '<input type="text" name="field_source[]" list="sf-source-list" placeholder="ex.: user.email ou extra.data.id">' +
        '<input type="text" name="field_dest[]" placeholder="Campo destino no SF (ex.: idade)">' +
        '<button class="btnx" type="button" onclick="removeRow(this)">×</button>';
    c.appendChild(d);
    d.querySelector('input').focus();
}

var eventHints = <?= json_encode($eventHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function updateEventHint() {
    var input  = document.getElementById('sf-evento-input');
    var hintDiv = document.getElementById('sf-event-hint');
    if (!input || !hintDiv) return;
    var hint = eventHints[input.value];
    if (hint) { hintDiv.innerHTML = hint; hintDiv.style.display = 'block'; }
    else { hintDiv.style.display = 'none'; }
}

// ===== Dropdown de eventos =====
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('sf-evento-input');
    var btn   = document.getElementById('sf-btn-ev-toggle');
    var drop  = document.getElementById('sf-ev-dropdown');
    if (!input || !btn || !drop) return;

    function markSelected() {
        drop.querySelectorAll('.evento-opcao').forEach(function(o) {
            o.classList.toggle('selecionado', o.dataset.value === input.value);
        });
    }

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        drop.classList.toggle('aberto');
        if (drop.classList.contains('aberto')) markSelected();
    });

    drop.querySelectorAll('.evento-opcao').forEach(function(opcao) {
        opcao.addEventListener('click', function(e) {
            e.stopPropagation();
            input.value = this.dataset.value;
            drop.classList.remove('aberto');
            markSelected();
            updateEventHint();
        });
    });

    document.addEventListener('click', function(e) {
        if (!drop.contains(e.target) && e.target !== btn) drop.classList.remove('aberto');
    });

    markSelected();
    updateEventHint();
})();

document.getElementById('form-rule').addEventListener('submit', function(e) {
    var rows = document.querySelectorAll('#fields .field-row');
    var errors = [];
    rows.forEach(function(row, i) {
        var src = row.querySelector('input[name="field_source[]"]').value.trim();
        var dst = row.querySelector('input[name="field_dest[]"]').value.trim();
        if (src !== '' && dst === '') errors.push('Linha ' + (i+1) + ': destino vazio');
        if (src === '' && dst !== '') errors.push('Linha ' + (i+1) + ': origem vazia');
    });
    if (errors.length > 0) {
        if (!confirm('Atenção nos campos:\n\n' + errors.join('\n') + '\n\nSalvar mesmo assim?')) {
            e.preventDefault();
        }
    }
});

function parseSfLiveOffset(raw) {
    raw = String(raw || '').trim();
    if (raw === '') return 0;
    var m = raw.match(/^([+-])?\s*(\d{1,3})(?::([0-5]\d))?$/);
    if (!m) return null;
    var sign = m[1] === '-' ? -1 : 1;
    var hours = parseInt(m[2], 10);
    var minutes = m[3] ? parseInt(m[3], 10) : 0;
    return sign * ((hours * 60) + minutes);
}

function formatSfLiveDate(d) {
    var pad = function(n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

function updateSfLivePreview() {
    var offsetEl = document.getElementById('sf-live-offset');
    var previewEl = document.getElementById('sf-live-preview');
    if (!offsetEl || !previewEl) return true;

    var liveAt = offsetEl.getAttribute('data-live-at') || '';
    if (!liveAt) {
        previewEl.value = 'Data da live nao configurada';
        return false;
    }

    var offset = parseSfLiveOffset(offsetEl.value);
    if (offset === null) {
        previewEl.value = 'Formato invalido';
        return false;
    }

    var d = new Date(liveAt);
    if (isNaN(d.getTime())) {
        previewEl.value = 'Data da live invalida';
        return false;
    }

    d.setMinutes(d.getMinutes() + offset);
    previewEl.value = formatSfLiveDate(d);
    return true;
}

var sfTurmaForm = document.getElementById('form-sf-turma');
if (sfTurmaForm) {
    updateSfLivePreview();
    sfTurmaForm.addEventListener('submit', function(e) {
        if (!updateSfLivePreview()) {
            e.preventDefault();
            alert('Informe o deslocamento no formato -2:30, 0:00 ou 1:15.');
        }
    });

    var sfAudienceTimer = null;
    function sfAudienceEsc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }
    function sfAudienceRowsHtml(rows, total) {
        rows = Array.isArray(rows) ? rows : [];
        total = parseInt(total || 0, 10);
        if (!rows.length) return '<div class="note">Nenhum aluno elegivel com os filtros atuais.</div>';
        var html = '<div style="overflow-x:auto;margin-top:8px;"><table class="sf-audience-table">'
            + '<thead><tr><th>Nome</th><th>Email</th><th>Telefone</th><th>Turma</th><th>Tags</th><th>%</th></tr></thead><tbody>';
        rows.forEach(function(u) {
            html += '<tr>'
                + '<td title="' + sfAudienceEsc(u.nome) + '">' + sfAudienceEsc(u.nome) + '</td>'
                + '<td title="' + sfAudienceEsc(u.email) + '">' + sfAudienceEsc(u.email) + '</td>'
                + '<td>' + sfAudienceEsc(u.telefone) + '</td>'
                + '<td>' + sfAudienceEsc(u.turma) + '</td>'
                + '<td title="' + sfAudienceEsc(u.tags) + '">' + sfAudienceEsc(u.tags || '-') + '</td>'
                + '<td>' + parseInt(u.andamento || 0, 10) + '%</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        return html + '</div>';
    }
    async function updateSfAudiencePreview() {
        var countEl = document.getElementById('sf-audience-count');
        var skippedEl = document.getElementById('sf-audience-skipped');
        var bodyEl = document.getElementById('sf-audience-body');
        var statusEl = document.getElementById('sf-audience-status');
        if (!countEl || !skippedEl || !bodyEl) return;

        countEl.textContent = '...';
        if (statusEl) statusEl.textContent = 'Recalculando com os filtros selecionados...';
        var fd = new FormData(sfTurmaForm);
        fd.set('action', 'sf_turma_audience_preview');
        try {
            var res = await fetch('superfuncionario.php', {method:'POST', body:fd});
            var j = await res.json();
            if (!j.ok) throw new Error(j.msg || 'Erro ao calcular publico');
            var skipped = j.skipped || {};
            countEl.textContent = parseInt(j.total || 0, 10);
            skippedEl.textContent = 'Fora do envio: compra ' + parseInt(skipped.compra || 0, 10)
                + ', certificado ' + parseInt(skipped.certificado || 0, 10)
                + ', tags ' + parseInt(skipped.tags || 0, 10)
                + ', progresso ' + parseInt(skipped.progresso || 0, 10)
                + ', reagendados ' + parseInt(skipped.reagendado || 0, 10) + '.';
            bodyEl.innerHTML = sfAudienceRowsHtml(j.rows || [], j.total || 0);
            if (statusEl) statusEl.textContent = 'Calculado com os filtros selecionados nesta tela.';
        } catch (e) {
            countEl.textContent = '?';
            if (statusEl) statusEl.textContent = 'Nao foi possivel recalcular agora.';
            bodyEl.innerHTML = '<div class="note">Erro ao recalcular: ' + sfAudienceEsc(e.message) + '</div>';
        }
    }
    function scheduleSfAudiencePreview() {
        clearTimeout(sfAudienceTimer);
        sfAudienceTimer = setTimeout(updateSfAudiencePreview, 250);
    }
    sfTurmaForm.querySelectorAll('input[name="live_exclude_purchase"],input[name="live_exclude_cert"],input[name="live_include_rescheduled"],input[name="live_include_tag_ids[]"],input[name="live_exclude_tag_ids[]"]').forEach(function(el) {
        el.addEventListener('change', scheduleSfAudiencePreview);
        el.addEventListener('input', scheduleSfAudiencePreview);
    });
}

function toggleSfMetrics(id) {
    var row = document.getElementById('sf-metrics-' + id);
    if (!row) return;
    row.classList.toggle('open');
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
