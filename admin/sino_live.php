<?php
declare(strict_types=1);

// Andamento do aviso de live enviado ao Sino (pacotes) de uma turma.
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/sino_live.php';

proteger_admin();
$pdo = getPDO();
sino_live_ensure_schema($pdo);
$menu = 'superfuncionario';

function sl_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$turmaId = (int)($_GET['turma'] ?? $_POST['turma'] ?? 0);
$st = $pdo->prepare('SELECT * FROM turmas WHERE id = :id LIMIT 1');
$st->execute([':id' => $turmaId]);
$turma = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$turma) { header('Location: turmas.php?err=' . urlencode('Turma nao encontrada.')); exit; }
$dispatch = sino_live_dispatch($pdo, (int)($turma['sino_live_dispatch_id'] ?? 0));

// Andamento no Sino (chamado pela pagina a cada 60 s).
if (($_GET['ajax'] ?? '') === 'progress') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$dispatch) { echo json_encode(['ok' => false, 'error' => 'Nenhum envio.']); exit; }
    $res = sino_live_get_config((string)$dispatch['dispatch_code']);
    if ($res['status'] !== 200) { echo json_encode(['ok' => false, 'error' => $res['error'] ?: ('HTTP ' . $res['status'])], JSON_UNESCAPED_UNICODE); exit; }
    $remessa = null;
    foreach ((array)($res['body']['progress']['remessas'] ?? []) as $r) {
        if (is_array($r) && (string)($r['remessa'] ?? '') === (string)$dispatch['remessa']) { $remessa = $r; break; }
    }
    echo json_encode(['ok' => true, 'status' => $res['body']['status'] ?? null, 'remessa' => $remessa, 'steps' => $res['body']['steps'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $msg = '';
    if ($acao === 'cancelar' && ($_POST['confirmar'] ?? '') === 'CANCELAR') {
        $r = sino_live_cancel($pdo, $turmaId);
        $msg = $r['message'];
        $key = $r['ok'] ? 'ok' : 'err';
    } elseif ($acao === 'retomar') {
        $msg = sino_live_resume($pdo, $turmaId); $key = 'ok';
    } elseif ($acao === 'novo_envio') {
        sino_live_reset($pdo, $turmaId);
        $msg = 'Liberado para um novo envio. Ele sai quando chegar o horario de disparo da turma (ou no proximo minuto, se ja passou). Com a mesma remessa, o Sino pula quem ja recebeu.';
        $key = 'ok';
    } else {
        $msg = 'Acao nao confirmada.'; $key = 'err';
    }
    header('Location: sino_live.php?turma=' . $turmaId . '&' . $key . '=' . urlencode($msg));
    exit;
}

$steps = $dispatch ? (json_decode((string)($dispatch['steps_json'] ?? ''), true) ?: []) : [];
$pulados = $dispatch ? (json_decode((string)($dispatch['pulados_json'] ?? ''), true) ?: []) : [];
$erros = [];
if ($dispatch) {
    $stE = $pdo->prepare("SELECT r.user_id, r.telefone, r.motivo, u.nome FROM sino_live_recipients r LEFT JOIN users u ON u.id = r.user_id
                           WHERE r.dispatch_id = :d AND r.status = 'erro' ORDER BY r.user_id LIMIT 500");
    $stE->execute([':d' => (int)$dispatch['id']]);
    $erros = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$statusLabel = [
    'preparando' => 'Preparando o publico', 'enviando' => 'Enviando pacotes', 'concluido' => 'Concluido',
    'erro' => 'Parado por erro', 'cancelado' => 'Cancelado',
];
$motivoLabel = [
    'andamento_zero' => 'sem andamento', 'tag_excluida' => 'tag excluida', 'certificado_emitido' => 'ja gerou certificado',
    'compra_identificada' => 'ja comprou', 'live_reagendada' => 'reagendou a live', 'nao_quer_whatsapp' => 'pediu para nao receber WhatsApp',
    'aluno_bloqueado' => 'aluno bloqueado', 'sem_telefone' => 'sem telefone',
];
$fmt = fn($v) => number_format((int)$v, 0, ',', '.');
$emAndamento = $dispatch && in_array($dispatch['status'], ['preparando', 'enviando'], true);

include __DIR__ . '/_header.php';
?>
<style>
    .sl-wrap { padding: 24px 16px 60px; max-width: 1100px; }
    .sl-card { background: rgba(255,255,255,.02); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
    .sl-card h2 { font-size: 15px; margin: 0 0 12px; }
    .sl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .sl-k { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .sl-v { font-size: 18px; font-weight: 700; color: var(--text); margin-top: 2px; }
    .sl-note { font-size: 12px; color: var(--muted); }
    .sl-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sl-table th, .sl-table td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--border); }
    .sl-table th { font-size: 11px; color: var(--muted); text-transform: uppercase; }
    .sl-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--border); }
    .sl-badge.ok { color: #4ade80; border-color: rgba(74,222,128,.4); }
    .sl-badge.run { color: #38bdf8; border-color: rgba(56,189,248,.4); }
    .sl-badge.bad { color: #f87171; border-color: rgba(248,113,113,.4); }
    .sl-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .sl-btn { padding: 8px 14px; border-radius: 8px; border: 1px solid var(--border); background: rgba(255,255,255,.04); color: var(--text); font-size: 13px; cursor: pointer; }
    .sl-btn.danger { border-color: rgba(248,113,113,.5); color: #f87171; }
    .sl-msg { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
    .sl-msg.ok { background: rgba(74,222,128,.08); color: #4ade80; }
    .sl-msg.err { background: rgba(248,113,113,.08); color: #f87171; }
    .sl-scroll { overflow-x: auto; }
</style>
<div class="sl-wrap">
    <div style="margin-bottom:16px;">
        <a href="turmas.php" class="sl-note">&larr; Turmas</a>
        <h1 style="font-size:22px;margin:6px 0 2px;">Aviso de live no Sino &middot; turma <?= sl_h($turma['codigo']) ?></h1>
        <div class="sl-note">Live: <?= sl_h($turma['data_live'] ? date('d/m/Y H:i', strtotime((string)$turma['data_live'])) : '—') ?>
            &middot; disparo planejado: <?= sl_h($turma['live_disparo_data'] ? date('d/m/Y H:i', strtotime((string)$turma['live_disparo_data'])) : '—') ?>
            &middot; <a href="superfuncionario.php?sf_edit=<?= (int)$turma['id'] ?>">configurar</a></div>
    </div>
    <?php if (!empty($_GET['ok'])): ?><div class="sl-msg ok"><?= sl_h($_GET['ok']) ?></div><?php endif; ?>
    <?php if (!empty($_GET['err'])): ?><div class="sl-msg err"><?= sl_h($_GET['err']) ?></div><?php endif; ?>

    <?php if ((int)($turma['sino_live_enabled'] ?? 0) !== 1): ?>
        <div class="sl-card sl-note">O envio para o Sino esta desligado nesta turma.</div>
    <?php endif; ?>

    <?php if (!$dispatch): ?>
        <div class="sl-card">
            <div class="sl-note">Nenhum envio ao Sino ainda. Disparo escolhido: <strong><?= sl_h($turma['sino_dispatch_name'] ?: ($turma['sino_dispatch_code'] ?: '—')) ?></strong>
                <?php if (!empty($turma['sino_dispatch_code'])): ?><code><?= sl_h($turma['sino_dispatch_code']) ?></code><?php endif; ?>
                &middot; remessa <code><?= sl_h(sino_live_remessa($turma)) ?></code></div>
        </div>
    <?php else:
        $badge = ['concluido' => 'ok', 'erro' => 'bad', 'cancelado' => 'bad'][$dispatch['status']] ?? 'run'; ?>
        <div class="sl-card">
            <h2><span class="sl-badge <?= $badge ?>"><?= sl_h($statusLabel[$dispatch['status']] ?? $dispatch['status']) ?></span>
                <?php if ($dispatch['sino_status'] === 'paused'): ?><span class="sl-badge bad">disparo pausado no Sino</span><?php endif; ?></h2>
            <div class="sl-note" style="margin-bottom:12px;">Disparo <strong><?= sl_h($dispatch['dispatch_name'] ?: $dispatch['dispatch_code']) ?></strong> <code><?= sl_h($dispatch['dispatch_code']) ?></code>
                &middot; remessa <code><?= sl_h($dispatch['remessa']) ?></code> &middot; pacotes de <?= $fmt($dispatch['batch_size']) ?>
                &middot; criado <?= sl_h(date('d/m H:i', strtotime((string)$dispatch['created_at']))) ?><?= $dispatch['finished_at'] ? ' &middot; encerrado ' . sl_h(date('d/m H:i', strtotime((string)$dispatch['finished_at']))) : '' ?></div>
            <div class="sl-grid">
                <div><div class="sl-k">Alunos da remessa</div><div class="sl-v"><?= $fmt($dispatch['total']) ?></div></div>
                <div><div class="sl-k">Pacote atual</div><div class="sl-v"><?= $fmt($dispatch['lotes_enviados']) ?> / <?= $fmt($dispatch['lotes_total']) ?></div></div>
                <div><div class="sl-k">Recebidos pelo Sino</div><div class="sl-v"><?= $fmt($dispatch['received']) ?></div></div>
                <div><div class="sl-k">Aceitos</div><div class="sl-v"><?= $fmt($dispatch['accepted']) ?></div></div>
                <div><div class="sl-k">Recusados</div><div class="sl-v" style="<?= (int)$dispatch['errors_count'] > 0 ? 'color:#f87171' : '' ?>"><?= $fmt($dispatch['errors_count']) ?></div></div>
                <div><div class="sl-k">Fora pelos filtros</div><div class="sl-v"><?= $fmt($dispatch['pulados_local']) ?></div></div>
            </div>
            <?php if ($pulados): ?>
                <div class="sl-note" style="margin-top:10px;">Fora pelos filtros: <?= sl_h(implode(' · ', array_map(fn($k, $v) => ($motivoLabel[$k] ?? $k) . ' ' . $v, array_keys($pulados), $pulados))) ?></div>
            <?php endif; ?>
            <?php if (!empty($dispatch['last_error'])): ?>
                <div class="sl-msg err" style="margin-top:12px;"><?= sl_h($dispatch['last_error']) ?><?= $dispatch['last_http'] ? ' (HTTP ' . (int)$dispatch['last_http'] . ')' : '' ?></div>
            <?php endif; ?>
            <div class="sl-actions">
                <?php if ($dispatch['status'] === 'erro'): ?>
                    <form method="post"><input type="hidden" name="turma" value="<?= $turmaId ?>"><input type="hidden" name="acao" value="retomar">
                        <button class="sl-btn" type="submit">Retomar do pacote <?= (int)$dispatch['lotes_enviados'] + 1 ?></button></form>
                <?php endif; ?>
                <?php if (!$emAndamento): ?>
                    <form method="post" onsubmit="return confirm('Liberar um novo envio desta turma ao Sino? Com a mesma remessa, o Sino pula quem ja recebeu.');">
                        <input type="hidden" name="turma" value="<?= $turmaId ?>"><input type="hidden" name="acao" value="novo_envio">
                        <button class="sl-btn" type="submit">Novo envio</button></form>
                <?php endif; ?>
                <?php if ($dispatch['status'] !== 'cancelado'): ?>
                    <form method="post" onsubmit="var t=prompt('Isto tira da fila do Sino TODOS que ainda nao receberam neste disparo (<?= sl_h($dispatch['dispatch_code']) ?>), em TODAS as turmas/remessas que usam ele. Para confirmar, digite CANCELAR'); if(t!=='CANCELAR')return false; this.confirmar.value=t; return true;">
                        <input type="hidden" name="turma" value="<?= $turmaId ?>"><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="confirmar" value="">
                        <button class="sl-btn danger" type="submit">Cancelar no Sino</button></form>
                <?php endif; ?>
            </div>
        </div>

        <div class="sl-card">
            <h2>Etapas (somatorio das respostas dos pacotes)</h2>
            <?php if (!$steps): ?><div class="sl-note">Ainda nenhum pacote enviado.</div><?php else: ?>
                <div class="sl-scroll"><table class="sl-table">
                    <tr><th>Etapa</th><th>Fluxo</th><th>Agendados</th><th>Pulados</th><th>Motivos</th></tr>
                    <?php foreach ($steps as $s): ?>
                        <tr><td><?= (int)$s['step'] ?></td><td><?= sl_h($s['flow']) ?></td><td><?= $fmt($s['scheduled']) ?></td><td><?= $fmt($s['skipped']) ?></td>
                            <td class="sl-note"><?= sl_h(implode(' · ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys((array)$s['reasons']), (array)$s['reasons']))) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>
        </div>

        <div class="sl-card">
            <h2>No Sino agora <span class="sl-note" id="sl-live-at"></span></h2>
            <div id="sl-live" class="sl-note">Consultando...</div>
        </div>

        <div class="sl-card">
            <h2>Recusados pelo Sino (<?= $fmt($dispatch['errors_count']) ?>)</h2>
            <?php if (!$erros): ?><div class="sl-note">Nenhum.</div><?php else: ?>
                <div class="sl-scroll"><table class="sl-table">
                    <tr><th>Aluno</th><th>Telefone</th><th>Motivo</th></tr>
                    <?php foreach ($erros as $e): ?>
                        <tr><td><?= sl_h($e['nome'] ?: ('#' . $e['user_id'])) ?> <span class="sl-note">#<?= (int)$e['user_id'] ?></span></td><td><?= sl_h($e['telefone']) ?></td><td><?= sl_h($e['motivo']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($dispatch): ?>
<script>
(function () {
    var box = document.getElementById('sl-live'), at = document.getElementById('sl-live-at');
    function fmtUtc(v) { if (!v) return '—'; var d = new Date(v); return isNaN(d) ? v : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function load() {
        fetch('sino_live.php?turma=<?= $turmaId ?>&ajax=progress', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            at.textContent = '· atualizado ' + new Date().toLocaleTimeString('pt-BR');
            if (!j.ok) { box.textContent = 'Nao foi possivel consultar o Sino: ' + (j.error || ''); return; }
            if (!j.remessa) { box.textContent = 'Esta remessa ainda nao aparece no Sino.' + (j.status === 'paused' ? ' O disparo esta pausado no Sino.' : ''); return; }
            var names = {}; (j.steps || []).forEach(function (s) { names[s.order] = s.flowName || s.flow; });
            var h = '<table class="sl-table"><tr><th>Etapa</th><th>Total</th><th>Ja receberam</th><th>Na fila</th><th>Cancelados</th><th>Proximo envio</th><th>Ultimo envio</th></tr>';
            (j.remessa.steps || []).forEach(function (s) {
                h += '<tr><td>' + esc(s.step) + '. ' + esc(names[s.step] || '') + '</td><td>' + esc(s.total) + '</td><td>' + esc(s.started) + '</td><td>' + esc(s.queued) + '</td><td>' + esc(s.cancelled) + '</td><td>' + esc(fmtUtc(s.nextAt)) + '</td><td>' + esc(fmtUtc(s.lastAt)) + '</td></tr>';
            });
            box.innerHTML = (j.status === 'paused' ? '<div class="sl-msg err">Disparo pausado no Sino: a fila so anda quando for retomado la.</div>' : '') + '<div class="sl-scroll">' + h + '</table></div>';
        }).catch(function () { box.textContent = 'Falha ao consultar o Sino.'; });
    }
    load(); setInterval(load, 60000);
    <?php if ($emAndamento): ?>setTimeout(function () { location.reload(); }, 30000);<?php endif; ?>
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
