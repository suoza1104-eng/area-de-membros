<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/webhook_dispatcher.php';

proteger_admin();
$pdo = getPDO();

$menu = 'webhooks';

function h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// === POST: salvar config webhook de live por turma ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'live_turma_save') {
    $tid           = (int)($_POST['turma_id'] ?? 0);
    $lurl          = trim($_POST['webhook_live_url'] ?? '');
    $delay         = max(0, (int)($_POST['delay_ms'] ?? 500));
    $enabled       = isset($_POST['live_webhook_enabled']) ? 1 : 0;
    $disparo       = trim($_POST['live_disparo_data'] ?? '');
    $disparoDB     = $disparo ? date('Y-m-d H:i:s', strtotime($disparo)) : null;
    $excludeCert   = isset($_POST['live_exclude_cert']) ? 1 : 0;
    $excludeZero   = isset($_POST['live_exclude_zero']) ? 1 : 0;
    $includeSel    = is_array($_POST['live_include_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_include_tag_ids']), fn($v)=>$v>0)) : [];
    $excludeSel2   = is_array($_POST['live_exclude_tag_ids'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['live_exclude_tag_ids']), fn($v)=>$v>0)) : [];
    $filterCfg     = null;
    if ($includeSel || $excludeSel2 || $excludeCert || $excludeZero) {
        $filterCfg = json_encode(['include_any'=>$includeSel,'exclude_any'=>$excludeSel2,'exclude_cert'=>$excludeCert,'exclude_zero'=>$excludeZero], JSON_UNESCAPED_UNICODE);
    }
    if ($tid > 0) {
        try {
            $pdo->prepare("UPDATE turmas SET webhook_live_url=:u,delay_ms=:d,live_webhook_enabled=:en,live_disparo_data=:disp,live_filter_tag_ids=:tags,live_disparada=0 WHERE id=:id")
                ->execute([':u'=>$lurl!==''?$lurl:null,':d'=>$delay,':en'=>$enabled,':disp'=>$disparoDB,':tags'=>$filterCfg,':id'=>$tid]);
        } catch (Throwable $e) {}
    }
    header('Location: webhooks.php?live_edit=' . $tid . '&saved=1');
    exit;
}

// Salvar / atualizar webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'live_turma_save') {
    $id            = (int)($_POST['id'] ?? 0);
    $nome          = trim($_POST['nome'] ?? '');
    $evento        = trim($_POST['evento'] ?? '');
    $url           = trim($_POST['url'] ?? '');
    $metodo        = $_POST['metodo'] ?? 'POST';
    $headers       = trim($_POST['headers_json'] ?? '');
    $ativo         = isset($_POST['ativo']) ? 1 : 0;
    $payloadFormat = strtolower(trim($_POST['payload_format'] ?? 'json'));
    if (!in_array($payloadFormat, ['json', 'form'], true)) $payloadFormat = 'json';

    if ($id > 0) {
        $pdo->prepare("UPDATE webhooks SET nome=:n,evento=:e,url=:u,metodo=:m,headers_json=:h,payload_format=:pf,ativo=:a WHERE id=:id")
            ->execute([':n'=>$nome,':e'=>$evento,':u'=>$url,':m'=>$metodo,':h'=>$headers,':pf'=>$payloadFormat,':a'=>$ativo,':id'=>$id]);
    } else {
        $pdo->prepare("INSERT INTO webhooks (nome,evento,url,metodo,headers_json,payload_format,ativo) VALUES (:n,:e,:u,:m,:h,:pf,:a)")
            ->execute([':n'=>$nome,':e'=>$evento,':u'=>$url,':m'=>$metodo,':h'=>$headers,':pf'=>$payloadFormat,':a'=>$ativo]);
    }
    header('Location: webhooks.php');
    exit;
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM webhooks WHERE id=:id")->execute([':id' => (int)$_GET['del']]);
    header('Location: webhooks.php'); exit;
}

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE webhooks SET ativo=IF(ativo=1,0,1) WHERE id=:id")->execute([':id' => (int)$_GET['toggle']]);
    header('Location: webhooks.php'); exit;
}

if (isset($_GET['test'])) {
    try { disparar_webhook_teste($pdo, (int)$_GET['test']); } catch (Throwable $e) {}
    header('Location: webhooks.php'); exit;
}

$editWebhook = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM webhooks WHERE id=:id LIMIT 1");
    $st->execute([':id' => (int)$_GET['edit']]);
    $editWebhook = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$liveEditTurma = null;
if (isset($_GET['live_edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
    $st->execute([':id' => (int)$_GET['live_edit']]);
    $liveEditTurma = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$turmasList = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// allTags for filter
$allTagsWh = [];
try {
    $stTags = $pdo->query("SELECT id, nome FROM tags WHERE ativo = 1 ORDER BY nome ASC");
    $allTagsWh = $stTags->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$hooks = $pdo->query("SELECT * FROM webhooks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Grupos de eventos
$eventGroups = [
    'Aluno' => [
        'INSCRITO'             => ['label' => 'Aluno inscrito (novo)',         'desc' => 'Disparado quando um novo aluno se cadastra na área de membros pela primeira vez.', 'extra' => 'user.magic_link (auto-login), extra.codigo_turma, extra.data_live, extra.qtd_inscricoes (=1), extra.primeira_inscricao, extra.eh_reinscrito (=0)'],
        'REINSCRITO'           => ['label' => 'Aluno re-inscreveu',            'desc' => 'Disparado quando um aluno já existente se inscreve novamente. Útil para tratar pessoas que já passaram pelo funil.', 'extra' => 'user.magic_link (auto-login), extra.codigo_turma, extra.qtd_inscricoes, extra.primeira_inscricao, extra.data_inscricao_anterior, extra.turma_anterior, extra.eh_reinscrito (=1)'],
        'PRIMEIRO_LOGIN'       => ['label' => 'Primeiro login na plataforma',  'desc' => 'Disparado UMA ÚNICA VEZ — na primeira vez que o aluno acessa a área de membros (qualquer método: senha, magic link ou cookie). Aplica também a tag PRIMEIRO_LOGIN.', 'extra' => 'user.id, user.nome, user.email, user.magic_link'],
        'ASSISTIU_ALGUMA_AULA' => ['label' => 'Assistiu alguma aula',          'desc' => 'Disparado quando o aluno assiste pelo menos 10 segundos de qualquer aula.', 'extra' => 'user.id, user.nome, extra.lesson_id'],
        'CONCLUIU_TRILHA'      => ['label' => 'Concluiu a trilha',             'desc' => 'Disparado quando o aluno finaliza todas as aulas obrigatórias.', 'extra' => 'user.id, user.nome'],
    ],
    'Certificado' => [
        'CERT_EMITIDO'         => ['label' => 'Certificado emitido',           'desc' => 'Disparado quando o aluno acerta a senha e o certificado é gerado.', 'extra' => 'extra.codigo_certificado, extra.curso, extra.emitido_em, extra.pdf_url'],
        'REENVIO_CERTIFICADO'  => ['label' => 'Reenvio de certificado',        'desc' => 'Disparado quando o admin clica em reenviar certificado ou quando um webhook de entrada configurado para reenvio é recebido.', 'extra' => 'extra.codigo_certificado, extra.curso, extra.emitido_em, extra.pdf_url, extra.certificado_id, extra.origem'],
        'CERT_SENHA_ERRADA'    => ['label' => 'Senha de certificado errada',   'desc' => 'Disparado quando o aluno tenta uma senha inválida.', 'extra' => 'extra.motivo'],
    ],
    'Live' => [
        'LIVE_TURMA' => [
            'label' => 'Disparo de live por turma',
            'desc'  => 'Disparado para cada aluno da turma quando a data/hora de disparo configurada chega. Use para regras globais que valem para todas as turmas.',
            'extra' => 'extra.codigo_turma, extra.codigo_live, extra.data_live, extra.andamento, extra.aulas_concluidas, extra.aulas_totais',
        ],
        'LIVE_ACESSOU' => [
            'label' => 'Live — aluno acessou',
            'desc'  => 'Disparado quando o sistema externo (Eventos Live) notifica que um aluno acessou a live. A tag configurada no evento é aplicada automaticamente.',
            'extra' => 'extra.live_event_id, extra.live_event_nome, extra.live_event_tag, extra.payload_raw',
        ],
        'LIVE_OFERTA' => [
            'label' => 'Live — ficou até a oferta',
            'desc'  => 'Disparado quando o aluno permaneceu na live até o momento da oferta.',
            'extra' => 'extra.live_event_id, extra.live_event_nome, extra.live_event_tag, extra.payload_raw',
        ],
        'LIVE_COMPRA' => [
            'label' => 'Live — clicou na compra',
            'desc'  => 'Disparado quando o aluno clicou no botão de compra durante a live.',
            'extra' => 'extra.live_event_id, extra.live_event_nome, extra.live_event_tag, extra.payload_raw',
        ],
        'LIVE_EVENTO' => [
            'label' => 'Live — evento customizado',
            'desc'  => 'Disparado por eventos de live do tipo "Customizado".',
            'extra' => 'extra.live_event_id, extra.live_event_nome, extra.live_event_tag, extra.payload_raw',
        ],
    ],
];

// Aulas dinâmicas
$lessonEvents = [];
try {
    $stLs = $pdo->query("SELECT id, titulo FROM lessons ORDER BY ordem ASC, id ASC");
    while ($ls = $stLs->fetch(PDO::FETCH_ASSOC)) {
        $lid = (int)$ls['id'];
        if ($lid > 0) $lessonEvents['VIU_AULA_' . $lid] = ['label' => 'Viu aula: ' . $ls['titulo'], 'desc' => 'Aluno assistiu pelo menos 10 segundos da aula "' . $ls['titulo'] . '"', 'extra' => 'user.id, extra.lesson_id'];
    }
} catch (Throwable $e) {}

include __DIR__ . '/_header.php';
?>
<style>
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
    .wh-wrap { max-width: 1100px; margin: 36px auto; padding: 0 24px 60px; }

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
    .card-header-text h2 { font-size: 16px; font-weight: 600; margin: 0 0 2px; }
    .card-header-text p  { font-size: 12px; color: var(--muted); margin: 0; }

    .grid-2 { display: grid; grid-template-columns: 420px 1fr; gap: 24px; align-items: start; }
    @media(max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

    label.lbl { font-size: 12px; font-weight: 500; color: var(--muted); display: block; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    input[type="text"], input[type="url"], textarea, select {
        width: 100%; padding: 9px 12px; border-radius: 10px;
        border: 1px solid var(--border); background: #07101f;
        color: var(--text); font-size: 13px; outline: none; transition: border-color .15s;
    }
    input:focus, textarea:focus, select:focus { border-color: var(--blue); }
    textarea { min-height: 70px; resize: vertical; font-family: monospace; font-size: 12px; }

    .checkbox-row { display: flex; align-items: center; gap: 8px; }
    .checkbox-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary); }
    .checkbox-row label { font-size: 13px; margin: 0; }

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
    .spacer-sm { height: 8px; }

    .note { font-size: 11.5px; color: var(--muted); margin-top: 5px; line-height: 1.5; }
    .note code { background: rgba(255,255,255,.06); border-radius: 4px; padding: 1px 5px; font-size: 11px; }

    /* ===== EVENT DROPDOWN ===== */
    .evento-wrapper { position: relative; }
    .evento-input-row { display: flex; gap: 4px; }
    .evento-input-row input { flex: 1; }
    .evento-toggle-btn {
        border-radius: 10px; border: 1px solid var(--border); background: #07101f;
        color: var(--text); padding: 0 12px; font-size: 12px; cursor: pointer;
        display: inline-flex; align-items: center; white-space: nowrap;
    }
    .evento-toggle-btn:hover { background: #0f1f3d; }
    .evento-dropdown {
        position: absolute; left: 0; right: 0; margin-top: 4px;
        background: #0b1120; border-radius: 12px; border: 1px solid var(--border);
        max-height: 280px; overflow-y: auto; padding: 8px;
        box-shadow: 0 20px 48px rgba(0,0,0,.7); display: none; z-index: 30;
    }
    .evento-dropdown.aberto { display: block; }
    .ev-group-label {
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
        color: var(--muted); padding: 6px 8px 4px; margin-top: 4px;
    }
    .ev-group-label:first-child { margin-top: 0; }
    .evento-opcao {
        padding: 7px 10px; border-radius: 8px; cursor: pointer;
        display: flex; flex-direction: column; gap: 2px;
        transition: background .1s;
    }
    .evento-opcao:hover { background: rgba(255,255,255,.06); }
    .evento-opcao strong { font-size: 11px; color: var(--text); }
    .evento-opcao span   { font-size: 10px; color: var(--muted); }
    .ev-pill {
        display: inline-block; padding: 1px 6px; border-radius: 999px;
        font-size: 9px; font-weight: 700; text-transform: uppercase;
        margin-left: 4px; vertical-align: middle;
    }
    .ev-pill.cert   { background: rgba(168,85,247,.15); color: #d8b4fe; border: 1px solid rgba(168,85,247,.3); }
    .ev-pill.aluno  { background: rgba(59,130,246,.15);  color: #93c5fd; border: 1px solid rgba(59,130,246,.3); }
    .ev-pill.aula   { background: rgba(34,197,94,.15);   color: #86efac; border: 1px solid rgba(34,197,94,.3); }
    .ev-pill.live   { background: rgba(250,204,21,.15);  color: #fef3c7; border: 1px solid rgba(250,204,21,.3); }

    /* ===== WEBHOOK CARDS ===== */
    .wh-list { display: flex; flex-direction: column; gap: 12px; }
    .wh-card {
        background: rgba(255,255,255,.03); border: 1px solid var(--border);
        border-radius: 12px; padding: 14px 16px;
    }
    .wh-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
    .wh-card-name { font-size: 14px; font-weight: 600; }
    .wh-card-evento {
        display: inline-flex; align-items: center; gap: 4px;
        background: rgba(250,204,21,.08); border: 1px solid rgba(250,204,21,.2);
        color: #fef3c7; border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 600;
    }
    .wh-card-url { font-size: 12px; color: var(--muted); word-break: break-all; margin-bottom: 8px; }
    .wh-card-url code { color: #93c5fd; font-size: 11px; }
    .wh-card-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .badge-on  { background: rgba(34,197,94,.1);  color: #86efac;  border: 1px solid rgba(34,197,94,.3); }
    .badge-off { background: rgba(100,116,139,.1); color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
    .badge-method { background: rgba(59,130,246,.1); color: #93c5fd; border: 1px solid rgba(59,130,246,.3); }
    .wh-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }

    .empty-state {
        text-align: center; padding: 32px; color: var(--muted);
        border: 1px dashed var(--border); border-radius: 12px; font-size: 13px;
    }
</style>

<div class="wh-wrap">
    <div class="page-header">
        <h1>Webhooks</h1>
        <p>Configure URLs que serão chamadas automaticamente quando eventos ocorrerem na plataforma.</p>
    </div>

    <div class="grid-2">

        <!-- FORM -->
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon yellow">🔗</div>
                    <div class="card-header-text">
                        <h2><?= $editWebhook ? 'Editar webhook' : 'Novo webhook' ?></h2>
                        <p><?= $editWebhook ? 'Atualize os dados do webhook.' : 'Preencha para adicionar um novo disparo automático.' ?></p>
                    </div>
                </div>

                <form method="post" id="wh-form">
                    <input type="hidden" name="id" value="<?= $editWebhook ? (int)$editWebhook['id'] : '' ?>">

                    <div style="margin-bottom:14px;">
                        <label class="lbl">Nome</label>
                        <input type="text" name="nome" placeholder="Ex.: ActiveCampaign — Inscrito"
                            value="<?= h($editWebhook['nome'] ?? '') ?>" required>
                    </div>

                    <div style="margin-bottom:6px;">
                        <label class="lbl">Evento(s)</label>
                        <div class="evento-wrapper">
                            <div class="evento-input-row">
                                <input type="text" name="evento" id="wh-evento"
                                    placeholder="Ex.: INSCRITO, CERT_EMITIDO"
                                    value="<?= h($editWebhook['evento'] ?? '') ?>">
                                <button type="button" class="evento-toggle-btn" id="btn-ev-toggle">▼ Ver eventos</button>
                            </div>
                            <div class="evento-dropdown" id="ev-dropdown">
                                <?php foreach ($eventGroups as $groupName => $events): ?>
                                    <div class="ev-group-label"><?= h($groupName) ?></div>
                                    <?php foreach ($events as $code => $ev): ?>
                                        <div class="evento-opcao" data-value="<?= h($code) ?>">
                                            <strong><?= h($code) ?>
                                                <?php $gpill = match(strtolower($groupName)) { 'certificado' => 'cert', 'live' => 'live', default => 'aluno' }; ?>
                                                <span class="ev-pill <?= $gpill ?>">
                                                    <?= h($groupName) ?>
                                                </span>
                                            </strong>
                                            <span><?= h($ev['label']) ?> — <?= h($ev['desc']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if ($lessonEvents): ?>
                                    <div class="ev-group-label">Aulas</div>
                                    <?php foreach ($lessonEvents as $code => $ev): ?>
                                        <div class="evento-opcao" data-value="<?= h($code) ?>">
                                            <strong><?= h($code) ?> <span class="ev-pill aula">Aula</span></strong>
                                            <span><?= h($ev['desc']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="note">Clique nas opções da lista para adicionar. Separe múltiplos por vírgula.</div>
                    </div>

                    <div class="spacer"></div>

                    <div style="margin-bottom:14px;">
                        <label class="lbl">URL</label>
                        <input type="text" name="url" placeholder="https://..." value="<?= h($editWebhook['url'] ?? '') ?>" required>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label class="lbl">Método</label>
                            <?php $m = $editWebhook['metodo'] ?? 'POST'; ?>
                            <select name="metodo">
                                <option value="POST" <?= $m === 'POST' ? 'selected' : '' ?>>POST</option>
                                <option value="GET"  <?= $m === 'GET'  ? 'selected' : '' ?>>GET</option>
                            </select>
                        </div>
                        <div>
                            <label class="lbl">Formato</label>
                            <?php $pf = $editWebhook['payload_format'] ?? 'json'; ?>
                            <select name="payload_format">
                                <option value="json" <?= $pf === 'json' ? 'selected' : '' ?>>JSON</option>
                                <option value="form" <?= $pf === 'form' ? 'selected' : '' ?>>FORM</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label class="lbl">Headers JSON — opcional</label>
                        <textarea name="headers_json" placeholder='{"Authorization": "Bearer TOKEN"}'><?= h($editWebhook['headers_json'] ?? '') ?></textarea>
                    </div>

                    <div class="checkbox-row" style="margin-bottom:18px;">
                        <?php $ativoAtual = isset($editWebhook['ativo']) ? (int)$editWebhook['ativo'] : 1; ?>
                        <input type="checkbox" id="wh-ativo" name="ativo" <?= $ativoAtual ? 'checked' : '' ?>>
                        <label for="wh-ativo">Webhook ativo</label>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="btn">
                            <?= $editWebhook ? '💾 Salvar alterações' : '➕ Adicionar webhook' ?>
                        </button>
                        <?php if ($editWebhook): ?>
                            <a href="webhooks.php" class="btn ghost">✕ Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Referência de extras -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon blue">📦</div>
                    <div class="card-header-text">
                        <h2>Payload enviado</h2>
                        <p>Campos disponíveis no body JSON de cada evento.</p>
                    </div>
                </div>
                <?php foreach ($eventGroups as $groupName => $events): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:8px;"><?= h($groupName) ?></div>
                        <?php foreach ($events as $code => $ev): ?>
                            <div style="margin-bottom:8px;padding:8px 12px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid var(--border);">
                                <div style="font-size:12px;font-weight:700;margin-bottom:3px;"><?= h($code) ?></div>
                                <div style="font-size:11px;color:var(--muted);margin-bottom:4px;"><?= h($ev['desc']) ?></div>
                                <div style="font-size:11px;color:#93c5fd;font-family:monospace;"><?= h($ev['extra']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="note">Todos os eventos incluem: <code>evento</code>, <code>timestamp</code>, <code>user.id</code>, <code>user.nome</code>, <code>user.email</code>, <code>user.telefone</code>.</div>
            </div>
        </div>

        <!-- LIST -->
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-icon purple">⚡</div>
                    <div class="card-header-text">
                        <h2>Webhooks cadastrados</h2>
                        <p><?= count($hooks) ?> webhook<?= count($hooks) !== 1 ? 's' : '' ?> no total.</p>
                    </div>
                </div>

                <?php if (empty($hooks)): ?>
                    <div class="empty-state">Nenhum webhook cadastrado ainda.<br>Adicione o primeiro pelo formulário ao lado.</div>
                <?php else: ?>
                    <div class="wh-list">
                        <?php foreach ($hooks as $wh): ?>
                            <div class="wh-card">
                                <div class="wh-card-top">
                                    <div>
                                        <div class="wh-card-name"><?= h($wh['nome'] ?? '') ?></div>
                                        <div style="margin-top:4px;">
                                            <?php foreach (array_filter(array_map('trim', explode(',', $wh['evento'] ?? ''))) as $ev): ?>
                                                <span class="wh-card-evento"><?= h($ev) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="wh-actions">
                                        <a href="?edit=<?= (int)$wh['id'] ?>" class="btn ghost sm">✏️ Editar</a>
                                        <a href="?toggle=<?= (int)$wh['id'] ?>" class="btn ghost sm">
                                            <?= !empty($wh['ativo']) ? '⏸ Pausar' : '▶ Ativar' ?>
                                        </a>
                                        <a href="?test=<?= (int)$wh['id'] ?>" class="btn blue sm"
                                            onclick="return confirm('Disparar teste para este webhook?')">⚡ Testar</a>
                                        <a href="?del=<?= (int)$wh['id'] ?>" class="btn danger sm"
                                            onclick="return confirm('Remover este webhook permanentemente?')">🗑</a>
                                    </div>
                                </div>
                                <div class="wh-card-url"><code><?= h($wh['url'] ?? '') ?></code></div>
                                <div class="wh-card-meta">
                                    <span class="badge <?= !empty($wh['ativo']) ? 'badge-on' : 'badge-off' ?>">
                                        <?= !empty($wh['ativo']) ? '● Ativo' : '○ Pausado' ?>
                                    </span>
                                    <span class="badge badge-method"><?= h($wh['metodo'] ?? 'POST') ?></span>
                                    <span class="badge badge-method"><?= h(strtoupper($wh['payload_format'] ?? 'json')) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('wh-evento');
    const btn   = document.getElementById('btn-ev-toggle');
    const drop  = document.getElementById('ev-dropdown');
    if (!input || !btn || !drop) return;

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        drop.classList.toggle('aberto');
    });

    drop.querySelectorAll('.evento-opcao').forEach(function(opcao) {
        opcao.addEventListener('click', function(e) {
            e.stopPropagation();
            const valor = this.dataset.value;
            if (!valor) return;
            let atual = input.value.split(',').map(v => v.trim()).filter(v => v.length > 0);
            if (atual.indexOf(valor) === -1) {
                atual.push(valor);
                input.value = atual.join(', ');
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!drop.contains(e.target) && e.target !== btn) {
            drop.classList.remove('aberto');
        }
    });
});
</script>

<!-- ===== DISPARO DE LIVE POR TURMA ===== -->
<div class="wh-wrap" style="margin-top:0;">
    <div class="card">
        <div class="card-header">
            <div class="card-icon yellow">⚡</div>
            <div class="card-header-text">
                <h2>Disparo de Live por Turma</h2>
                <p>Configure o webhook que é disparado para cada aluno quando a data da live da turma chega.</p>
            </div>
        </div>

        <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
            <div style="margin-bottom:14px;padding:10px 14px;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:#4ade80;font-size:13px;">
                Configuração salva com sucesso!
            </div>
        <?php endif; ?>

        <div class="grid-2" style="align-items:start;">
            <!-- FORM (left) -->
            <div>
                <?php if ($liveEditTurma): ?>
                    <?php
                    $ltFilterRaw = $liveEditTurma['live_filter_tag_ids'] ?? '';
                    $ltFilter = ['include_any'=>[],'exclude_any'=>[],'exclude_cert'=>0,'exclude_zero'=>0];
                    if ($ltFilterRaw) {
                        $ltj = json_decode((string)$ltFilterRaw, true);
                        if (is_array($ltj)) {
                            $ltFilter['include_any'] = array_values(array_filter(array_map('intval', $ltj['include_any'] ?? []), fn($v)=>$v>0));
                            $ltFilter['exclude_any'] = array_values(array_filter(array_map('intval', $ltj['exclude_any'] ?? []), fn($v)=>$v>0));
                            $ltFilter['exclude_cert'] = (int)(!!($ltj['exclude_cert'] ?? 0));
                            $ltFilter['exclude_zero'] = (int)(!!($ltj['exclude_zero'] ?? 0));
                        }
                    }
                    $ltSelInc = []; foreach ($ltFilter['include_any'] as $tid) $ltSelInc[(int)$tid] = true;
                    $ltSelExc = []; foreach ($ltFilter['exclude_any'] as $tid) $ltSelExc[(int)$tid] = true;
                    $ltExcCert = (int)($ltFilter['exclude_cert']) === 1;
                    $ltExcZero = (int)($ltFilter['exclude_zero']) === 1;
                    ?>
                    <form method="post" style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;padding:18px;">
                        <input type="hidden" name="action" value="live_turma_save">
                        <input type="hidden" name="turma_id" value="<?= (int)$liveEditTurma['id'] ?>">

                        <div style="margin-bottom:14px;">
                            <span class="lbl">Turma</span>
                            <div style="font-size:14px;font-weight:600;color:var(--text);"><?= htmlspecialchars((string)$liveEditTurma['codigo'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($liveEditTurma['data_live'])): ?>
                                <div style="font-size:11px;color:var(--muted);margin-top:2px;">Live: <?= htmlspecialchars(substr((string)$liveEditTurma['data_live'],0,16), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="lbl">URL do Webhook</label>
                            <input type="text" name="webhook_live_url" value="<?= htmlspecialchars((string)($liveEditTurma['webhook_live_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://...">
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                            <div>
                                <label class="lbl">Delay entre envios (ms)</label>
                                <input type="number" name="delay_ms" value="<?= (int)($liveEditTurma['delay_ms'] ?? 500) ?>" min="0" max="30000">
                            </div>
                            <div>
                                <label class="lbl">Disparar em</label>
                                <input type="datetime-local" name="live_disparo_data" value="<?= htmlspecialchars(($liveEditTurma['live_disparo_data'] ? date('Y-m-d\TH:i', strtotime((string)$liveEditTurma['live_disparo_data'])) : ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="note">Se vazio, não dispara automaticamente.</div>
                            </div>
                        </div>

                        <div class="checkbox-row" style="margin-bottom:14px;">
                            <input type="checkbox" id="lt-wh-enabled" name="live_webhook_enabled" <?= (int)($liveEditTurma['live_webhook_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label for="lt-wh-enabled">Habilitar webhook da live</label>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="lbl">Tags: ENVIAR se tiver pelo menos 1 dessas</label>
                            <select name="live_include_tag_ids[]" multiple size="6" style="width:100%;">
                                <?php foreach ($allTagsWh as $tg): $tid2=(int)$tg['id']; ?>
                                    <option value="<?= $tid2 ?>" <?= isset($ltSelInc[$tid2])?'selected':'' ?>><?= htmlspecialchars((string)$tg['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="note">Vazio = não filtra por inclusão.</div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="lbl">Tags: NÃO ENVIAR se tiver qualquer 1 dessas</label>
                            <select name="live_exclude_tag_ids[]" multiple size="6" style="width:100%;">
                                <?php foreach ($allTagsWh as $tg): $tid2=(int)$tg['id']; ?>
                                    <option value="<?= $tid2 ?>" <?= isset($ltSelExc[$tid2])?'selected':'' ?>><?= htmlspecialchars((string)$tg['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:16px;">
                            <label class="checkbox-row">
                                <input type="checkbox" name="live_exclude_cert" value="1" <?= $ltExcCert?'checked':'' ?>>
                                <span style="font-size:13px;">Excluir alunos com certificado emitido</span>
                            </label>
                            <label class="checkbox-row">
                                <input type="checkbox" name="live_exclude_zero" value="1" <?= $ltExcZero?'checked':'' ?>>
                                <span style="font-size:13px;">Excluir alunos com 0% de progresso</span>
                            </label>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <button type="submit" class="btn">💾 Salvar configuração</button>
                            <a href="webhooks.php" class="btn ghost">✕ Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">
                        Selecione uma turma na tabela ao lado para configurar o webhook de live.
                    </div>
                <?php endif; ?>
            </div>

            <!-- TABLE (right) -->
            <div>
                <?php if (empty($turmasList)): ?>
                    <div class="empty-state">Nenhuma turma cadastrada ainda. <a href="turmas.php">Cadastrar turma</a>.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                    <tr>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Turma</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Data Live</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">URL</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Delay</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Status</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Disparo em</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Disparado</th>
                        <th style="padding:8px 6px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.05em;font-size:10.5px;">Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($turmasList as $tl): ?>
                        <?php
                        $tlWhOn = (int)($tl['live_webhook_enabled'] ?? 0) === 1 && !empty($tl['webhook_live_url']);
                        $tlDisp = (int)($tl['live_disparada'] ?? 0) === 1;
                        $tlUrl  = (string)($tl['webhook_live_url'] ?? '');
                        $tlUrlShort = strlen($tlUrl) > 40 ? substr($tlUrl, 0, 40) . '…' : ($tlUrl ?: '—');
                        ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:8px 6px;font-weight:600;"><?= htmlspecialchars((string)$tl['codigo'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px 6px;white-space:nowrap;color:var(--muted);"><?= htmlspecialchars(substr((string)($tl['data_live']??'—'),0,16), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px 6px;font-size:11px;color:#93c5fd;word-break:break-all;" title="<?= htmlspecialchars($tlUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tlUrlShort, ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px 6px;color:var(--muted);"><?= (int)($tl['delay_ms'] ?? 500) ?>ms</td>
                            <td style="padding:8px 6px;">
                                <?php if ($tlWhOn): ?>
                                    <span class="badge badge-on">ON</span>
                                <?php else: ?>
                                    <span class="badge badge-off">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px 6px;white-space:nowrap;font-size:11px;color:var(--muted);"><?= htmlspecialchars(substr((string)($tl['live_disparo_data']??'—'),0,16), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding:8px 6px;">
                                <?php if ($tlDisp): ?>
                                    <span class="badge badge-on">Sim</span>
                                <?php else: ?>
                                    <span class="badge badge-off">Não</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px 6px;white-space:nowrap;">
                                <a href="?live_edit=<?= (int)$tl['id'] ?>" class="btn ghost sm">⚙️ Configurar</a>
                                <a href="turmas.php?reset_disparo=<?= (int)$tl['id'] ?>" class="btn ghost sm" onclick="return confirm('Resetar disparo desta turma?')">↺ Resetar</a>
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
</div>

<?php include __DIR__ . '/_footer.php'; ?>
