<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/retorno_agendamentos.php';
proteger_admin();

$pdo = getPDO();
retorno_ensure_tables($pdo);

$menu = 'retorno_agendamentos';
$page_title = 'Agendamentos de Retorno';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_retorno_dt(?string $v): string {
    if (!$v) return '-';
    try { return (new DateTime($v))->format('d/m/Y H:i'); } catch (Throwable $e) { return (string)$v; }
}
function retorno_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function retorno_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function retorno_pct(int $parte, int $total): string {
    if ($total <= 0) return '0,0%';
    return number_format(($parte / $total) * 100, 1, ',', '.') . '%';
}
function retorno_money(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

$msg = '';
$msgTipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'processar_agora_ajax') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $r = retorno_processar_devidos($pdo, 50);
            echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    try {
        if ($acao === 'salvar_agendamento') {
            $id = (int)($_POST['id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $tipo = (string)($_POST['tipo'] ?? 'vendas');
            $scheduledAt = retorno_parse_data_hora((string)($_POST['scheduled_at'] ?? ''));
            $assunto = trim((string)($_POST['assunto'] ?? ''));
            $mensagem = trim((string)($_POST['mensagem'] ?? ''));
            if ($userId <= 0) throw new RuntimeException('Informe o ID do aluno.');
            retorno_buscar_usuario($pdo, $userId);

            if ($id > 0) {
                $pdo->prepare("UPDATE retorno_agendamentos SET user_id=:u,tipo=:t,scheduled_at=:d,assunto=:a,mensagem=:m,status='aguardando',last_error=NULL,sent_at=NULL WHERE id=:id")
                    ->execute([':u'=>$userId, ':t'=>retorno_normalizar_tipo($tipo), ':d'=>$scheduledAt, ':a'=>$assunto !== '' ? $assunto : null, ':m'=>$mensagem !== '' ? $mensagem : null, ':id'=>$id]);
                $msg = 'Agendamento atualizado.';
            } else {
                retorno_criar_agendamento($pdo, $userId, $tipo, $scheduledAt, $mensagem, 'admin_controle', [], $assunto);
                $msg = 'Agendamento criado.';
            }
        } elseif ($acao === 'delete_agendamento') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) $pdo->prepare("DELETE FROM retorno_agendamentos WHERE id=:id")->execute([':id'=>$id]);
            $msg = 'Agendamento deletado.';
        } elseif ($acao === 'cancelar_agendamento') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) $pdo->prepare("UPDATE retorno_agendamentos SET status='cancelado' WHERE id=:id")->execute([':id'=>$id]);
            $msg = 'Agendamento cancelado.';
        } elseif ($acao === 'reenfileirar_agendamento') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) $pdo->prepare("UPDATE retorno_agendamentos SET status='aguardando', last_error=NULL, sent_at=NULL WHERE id=:id")->execute([':id'=>$id]);
            $msg = 'Agendamento voltou para aguardando.';
        } elseif ($acao === 'salvar_modelo') {
            retorno_salvar_modelo($pdo, (string)($_POST['modelo_nome'] ?? ''), (string)($_POST['modelo_tipo'] ?? 'vendas'), (string)($_POST['modelo_mensagem'] ?? ''), (int)($_POST['modelo_id'] ?? 0), (string)($_POST['modelo_assunto'] ?? ''));
            $msg = 'Modelo salvo.';
        } elseif ($acao === 'delete_modelo') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) $pdo->prepare("DELETE FROM retorno_modelos WHERE id=:id")->execute([':id'=>$id]);
            $msg = 'Modelo deletado.';
        } elseif ($acao === 'processar_agora') {
            $r = retorno_processar_devidos($pdo, 50);
            $msg = 'Processamento executado: ' . (int)$r['total'] . ' pendente(s), ' . (int)$r['enviados'] . ' enviado(s), ' . (int)$r['erros'] . ' erro(s).';
        }
    } catch (Throwable $e) {
        $msg = 'Erro: ' . $e->getMessage();
        $msgTipo = 'erro';
    }
}

$fStatus = trim((string)($_GET['status'] ?? ''));
$fTipo = trim((string)($_GET['tipo'] ?? ''));
$fAluno = trim((string)($_GET['aluno'] ?? ($_GET['user_id'] ?? '')));
$fTag = trim((string)($_GET['tag'] ?? ''));
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo = trim((string)($_GET['to'] ?? ''));

$where = [];
$params = [];
if ($fStatus !== '') { $where[] = "ra.status = :status"; $params[':status'] = $fStatus; }
if ($fTipo !== '') { $where[] = "ra.tipo = :tipo"; $params[':tipo'] = $fTipo; }
if ($fAluno !== '') { $where[] = "(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR u.id = :aluno_id)"; $params[':aluno'] = '%' . $fAluno . '%'; $params[':aluno_id'] = ctype_digit($fAluno) ? (int)$fAluno : 0; }
if ($fTag !== '') { $where[] = "EXISTS (SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=u.id AND t.nome LIKE :tag)"; $params[':tag'] = '%' . $fTag . '%'; }
if ($fFrom !== '') { $where[] = "ra.scheduled_at >= :from"; $params[':from'] = $fFrom . ' 00:00:00'; }
if ($fTo !== '') { $where[] = "ra.scheduled_at <= :to"; $params[':to'] = $fTo . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$kpis = ['aguardando'=>0,'enviado'=>0,'erro'=>0,'cancelado'=>0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) qtd FROM retorno_agendamentos GROUP BY status") as $r) {
        $kpis[(string)$r['status']] = (int)$r['qtd'];
    }
} catch (Throwable $e) {}

$kpiAlunosAgendados = 0;
$kpiCompradoresAposDisparo = 0;
$kpiFaturamentoAposDisparo = 0.0;
try {
    $kpiAlunosAgendados = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM retorno_agendamentos")->fetchColumn();
} catch (Throwable $e) {}

$hotmartReady = retorno_table_exists($pdo, 'hotmart_sales')
    && retorno_col_exists($pdo, 'hotmart_sales', 'matched_user_id')
    && retorno_col_exists($pdo, 'hotmart_sales', 'status')
    && retorno_col_exists($pdo, 'hotmart_sales', 'transaction_date');
$revenueCol = null;
foreach (['gross_revenue', 'producer_net', 'net_revenue'] as $col) {
    if (retorno_col_exists($pdo, 'hotmart_sales', $col)) {
        $revenueCol = $col;
        break;
    }
}
if ($hotmartReady) {
    try {
        $st = $pdo->query("
            SELECT COUNT(DISTINCT ra.user_id)
            FROM retorno_agendamentos ra
            WHERE ra.status = 'enviado'
              AND ra.sent_at IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM hotmart_sales s
                  WHERE s.matched_user_id = ra.user_id
                    AND s.status IN ('Aprovado','Completo')
                    AND s.transaction_date IS NOT NULL
                    AND s.transaction_date >= ra.sent_at
                  LIMIT 1
              )
        ");
        $kpiCompradoresAposDisparo = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    if ($revenueCol !== null) {
        try {
            $st = $pdo->query("
                SELECT COALESCE(SUM(s.`$revenueCol`), 0)
                FROM hotmart_sales s
                WHERE s.status IN ('Aprovado','Completo')
                  AND s.transaction_date IS NOT NULL
                  AND EXISTS (
                      SELECT 1
                      FROM retorno_agendamentos ra
                      WHERE ra.user_id = s.matched_user_id
                        AND ra.status = 'enviado'
                        AND ra.sent_at IS NOT NULL
                        AND s.transaction_date >= ra.sent_at
                      LIMIT 1
                  )
            ");
            $kpiFaturamentoAposDisparo = (float)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
}

$st = $pdo->prepare("SELECT ra.*, u.nome, u.email, u.telefone,
    (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR ', ') FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=u.id) AS tags_lista
    FROM retorno_agendamentos ra
    JOIN users u ON u.id = ra.user_id
    $whereSql
    ORDER BY ra.scheduled_at DESC, ra.id DESC
    LIMIT 500");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$modelos = retorno_listar_modelos($pdo);
$tipos = retorno_tipos();

require __DIR__ . '/_header.php';
?>
<style>
.ret-grid { display:grid; grid-template-columns:1.25fr .75fr; gap:16px; align-items:start; }
@media(max-width:1100px){ .ret-grid{grid-template-columns:1fr;} }
.ret-status { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.ret-status.aguardando { background:var(--warning-dim); color:#fcd34d; }
.ret-status.enviado { background:var(--success-dim); color:#86efac; }
.ret-status.erro { background:var(--danger-dim); color:#fca5a5; }
.ret-status.cancelado { background:rgba(100,116,139,.14); color:#94a3b8; }
.ret-msg { max-width:420px; white-space:pre-wrap; color:var(--muted); font-size:12px; }
.ret-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
</style>

<?php if ($msg): ?>
<div class="alert <?= $msgTipo === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="topbar">
    <div>
        <div class="topbar-title">Agendamentos de retorno</div>
        <div class="text-muted text-sm">O cron dispara o gatilho <code>RETORNO_AGENDADO</code> na data e hora marcada. Com esta tela aberta, o sistema tambem verifica pendentes automaticamente.</div>
    </div>
    <form method="post" style="margin:0">
        <input type="hidden" name="acao" value="processar_agora">
        <button class="btn btn-ghost btn-sm" type="submit">Processar pendentes agora</button>
    </form>
</div>

<div class="kpi-grid">
    <div class="kpi kpi-o"><div class="kpi-label">Aguardando</div><div class="kpi-value"><?= number_format($kpis['aguardando']) ?></div></div>
    <div class="kpi kpi-g"><div class="kpi-label">Enviados</div><div class="kpi-value"><?= number_format($kpis['enviado']) ?></div></div>
    <div class="kpi kpi-r"><div class="kpi-label">Erro</div><div class="kpi-value"><?= number_format($kpis['erro']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Cancelados</div><div class="kpi-value"><?= number_format($kpis['cancelado']) ?></div></div>
    <div class="kpi kpi-g"><div class="kpi-label">Compraram apos disparo</div><div class="kpi-value"><?= number_format($kpiCompradoresAposDisparo, 0, ',', '.') ?></div><div class="kpi-sub"><?= h(retorno_pct($kpiCompradoresAposDisparo, $kpiAlunosAgendados)) ?> dos alunos agendados</div></div>
    <div class="kpi kpi-b"><div class="kpi-label">Faturamento apos disparo</div><div class="kpi-value"><?= h(retorno_money($kpiFaturamentoAposDisparo)) ?></div><div class="kpi-sub">Vendas aprovadas apos envio</div></div>
</div>

<form method="get" class="filter-bar">
    <div class="filter-group"><label>Status</label><select name="status"><option value="">Todos</option><?php foreach ($kpis as $s=>$v): ?><option value="<?=h($s)?>" <?=$fStatus===$s?'selected':''?>><?=h(retorno_status_label($s))?></option><?php endforeach; ?></select></div>
    <div class="filter-group"><label>Tipo</label><select name="tipo"><option value="">Todos</option><?php foreach ($tipos as $k=>$l): ?><option value="<?=h($k)?>" <?=$fTipo===$k?'selected':''?>><?=h($l)?></option><?php endforeach; ?></select></div>
    <div class="filter-group" style="min-width:220px"><label>Aluno</label><input name="aluno" value="<?=h($fAluno)?>" placeholder="Nome, email, telefone ou ID"></div>
    <div class="filter-group"><label>Tag</label><input name="tag" value="<?=h($fTag)?>"></div>
    <div class="filter-group"><label>De</label><input type="date" name="from" value="<?=h($fFrom)?>"></div>
    <div class="filter-group"><label>Ate</label><input type="date" name="to" value="<?=h($fTo)?>"></div>
    <div class="filter-actions"><button class="btn btn-primary btn-sm">Filtrar</button><a class="reset-link" href="retorno_agendamentos.php">Limpar</a></div>
</form>

<div class="ret-grid">
    <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Aluno</th><th>Agendamento</th><th>Assunto / Mensagem</th><th>Status</th><th style="text-align:right">Acoes</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="text-muted" style="text-align:center;padding:26px">Nenhum agendamento encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?=h($r['nome'])?></div>
                            <div class="text-muted text-xs">#<?= (int)$r['user_id'] ?> · <?=h($r['email'])?> · <?=h($r['telefone'])?></div>
                            <?php if (!empty($r['tags_lista'])): ?><div class="text-xs text-muted"><?=h($r['tags_lista'])?></div><?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-700"><?=h(fmt_retorno_dt($r['scheduled_at']))?></div>
                            <div class="text-muted text-xs"><?=h($tipos[$r['tipo']] ?? $r['tipo'])?> · <?=h($r['origem'] ?? '')?></div>
                        </td>
                        <td>
                            <?php if (!empty($r['assunto'])): ?><div class="fw-700"><?=h((string)$r['assunto'])?></div><?php endif; ?>
                            <div class="ret-msg"><?=h((string)($r['mensagem'] ?? ''))?></div>
                            <?php if (!empty($r['last_error'])): ?><div class="text-danger text-xs"><?=h($r['last_error'])?></div><?php endif; ?>
                        </td>
                        <td><span class="ret-status <?=h($r['status'])?>"><?=h(retorno_status_label((string)$r['status']))?></span><?php if (!empty($r['sent_at'])): ?><div class="text-xs text-muted"><?=h(fmt_retorno_dt($r['sent_at']))?></div><?php endif; ?></td>
                        <td>
                            <div class="ret-actions">
                                <button class="btn btn-ghost btn-xs" type="button" onclick='editarAg(<?=json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>)'>Editar</button>
                                <?php if ($r['status'] !== 'cancelado'): ?>
                                <form method="post" onsubmit="return confirm('Cancelar este agendamento?')"><input type="hidden" name="acao" value="cancelar_agendamento"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-ghost btn-xs">Cancelar</button></form>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'erro' || $r['status'] === 'cancelado'): ?>
                                <form method="post"><input type="hidden" name="acao" value="reenfileirar_agendamento"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-ghost btn-xs">Reativar</button></form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Deletar definitivamente?')"><input type="hidden" name="acao" value="delete_agendamento"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-danger btn-xs">Excluir</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header-title" id="formTitle">Novo agendamento</div>
            <form method="post" id="agForm">
                <input type="hidden" name="acao" value="salvar_agendamento">
                <input type="hidden" name="id" id="agId" value="0">
                <div class="form-group"><label class="form-label">ID do aluno</label><input type="number" name="user_id" id="agUserId" value="<?=h($_GET['user_id'] ?? '')?>" required></div>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">Data e hora</label><input type="datetime-local" name="scheduled_at" id="agScheduled" required></div>
                    <div class="form-group"><label class="form-label">Tipo</label><select name="tipo" id="agTipo"><?php foreach ($tipos as $k=>$l): ?><option value="<?=h($k)?>"><?=h($l)?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-group"><label class="form-label">Carregar modelo</label><select id="modeloSelect" onchange="carregarModelo(this.value)"><option value="">Selecionar...</option><?php foreach ($modelos as $m): ?><option value="<?=(int)$m['id']?>"><?=h($m['nome'])?> (<?=h($tipos[$m['tipo']] ?? $m['tipo'])?>)</option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Assunto</label><input type="text" name="assunto" id="agAssunto" placeholder="Ex: Retorno sobre sua vaga"></div>
                <div class="form-group"><label class="form-label">Mensagem</label><textarea name="mensagem" id="agMensagem" rows="6" placeholder="Ex: Oi {primeiro_nome}, passando para dar continuidade..."></textarea><div class="text-xs text-muted mt-2">Variaveis: <code>{primeiro_nome}</code>, <code>{nome}</code>, <code>{email}</code>, <code>{telefone}</code>, <code>{assunto}</code>, <code>{tipo}</code>, <code>{data_agendamento}</code>.</div></div>
                <div class="d-flex gap-2"><button class="btn btn-primary">Salvar</button><button type="button" class="btn btn-ghost" onclick="novoAg()">Novo</button></div>
            </form>
        </div>

        <div class="card">
            <div class="card-header-title">Modelos salvos</div>
            <form method="post">
                <input type="hidden" name="acao" value="salvar_modelo">
                <input type="hidden" name="modelo_id" id="modeloId" value="0">
                <div class="form-group"><label class="form-label">Nome</label><input type="text" name="modelo_nome" id="modeloNome" required></div>
                <div class="form-group"><label class="form-label">Tipo</label><select name="modelo_tipo" id="modeloTipo"><?php foreach ($tipos as $k=>$l): ?><option value="<?=h($k)?>"><?=h($l)?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Assunto</label><input type="text" name="modelo_assunto" id="modeloAssunto"></div>
                <div class="form-group"><label class="form-label">Mensagem</label><textarea name="modelo_mensagem" id="modeloMensagem" rows="4" required></textarea></div>
                <button class="btn btn-ghost btn-sm">Salvar modelo</button>
            </form>
            <div class="mt-3">
                <?php foreach ($modelos as $m): ?>
                    <div class="d-flex justify-between align-center gap-2" style="border-top:1px solid var(--border);padding:8px 0">
                        <button type="button" class="btn btn-ghost btn-xs" onclick='editarModelo(<?=json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>)'><?=h($m['nome'])?></button>
                        <form method="post" onsubmit="return confirm('Excluir modelo?')"><input type="hidden" name="acao" value="delete_modelo"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="btn btn-danger btn-xs">Excluir</button></form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
const MODELOS = <?=json_encode($modelos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
function toLocalInput(dt) { return dt ? String(dt).replace(' ', 'T').slice(0, 16) : ''; }
function novoAg() {
    document.getElementById('formTitle').textContent = 'Novo agendamento';
    document.getElementById('agId').value = 0;
    document.getElementById('agForm').reset();
}
function editarAg(r) {
    document.getElementById('formTitle').textContent = 'Editar agendamento #' + r.id;
    document.getElementById('agId').value = r.id || 0;
    document.getElementById('agUserId').value = r.user_id || '';
    document.getElementById('agTipo').value = r.tipo || 'vendas';
    document.getElementById('agScheduled').value = toLocalInput(r.scheduled_at || '');
    document.getElementById('agAssunto').value = r.assunto || '';
    document.getElementById('agMensagem').value = r.mensagem || '';
    window.scrollTo({top:0, behavior:'smooth'});
}
function carregarModelo(id) {
    const m = MODELOS.find(x => String(x.id) === String(id));
    if (!m) return;
    document.getElementById('agTipo').value = m.tipo || 'vendas';
    document.getElementById('agAssunto').value = m.assunto || '';
    document.getElementById('agMensagem').value = m.mensagem || '';
}
function editarModelo(m) {
    document.getElementById('modeloId').value = m.id || 0;
    document.getElementById('modeloNome').value = m.nome || '';
    document.getElementById('modeloTipo').value = m.tipo || 'vendas';
    document.getElementById('modeloAssunto').value = m.assunto || '';
    document.getElementById('modeloMensagem').value = m.mensagem || '';
}
setInterval(function() {
    if (document.hidden) return;
    var fd = new FormData();
    fd.append('acao', 'processar_agora_ajax');
    fetch('retorno_agendamentos.php', {method:'POST', body:fd})
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j && j.ok && parseInt(j.total || 0, 10) > 0) window.location.reload();
        })
        .catch(function(){});
}, 30000);
</script>
<?php require __DIR__ . '/_footer.php'; ?>
