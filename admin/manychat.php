<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/manychat_dispatcher.php';

proteger_admin();
$pdo = getPDO();
$menu = 'manychat';
$page_title = 'Manychat';
$page_subtitle = 'Integracao de eventos com Manychat';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post_str(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function post_int(string $key): int { return (int)($_POST[$key] ?? 0); }

mc_ensure_tables($pdo);

$eventOptions = [
    'INSCRITO',
    'REINSCRITO',
    'PRIMEIRO_LOGIN',
    'ASSISTIU_ALGUMA_AULA',
    'CONCLUIU_TRILHA',
    'CERT_EMITIDO',
    'REENVIO_CERTIFICADO',
    'LIVE_TURMA',
    'LIVE_REAGENDADA',
    'LIVE_REAGENDAMENTO_LEMBRETE',
    'LIVE_REAGENDAMENTO_EXPIRADO',
    'WHATSAPP_GRUPO_ENTROU',
    'WHATSAPP_GRUPO_SAIU',
    'WHATSAPP_GRUPO_REMOVIDO_ADMIN',
    'WHATSAPP_BLACKLIST_DETECTADO',
];

$fieldOptions = [
    'user.id' => 'ID do aluno',
    'user.nome' => 'Nome',
    'user.email' => 'Email',
    'user.telefone' => 'Telefone',
    'user.magic_link' => 'Magic link',
    'extra.codigo_turma' => 'Turma',
    'extra.codigo_live' => 'Codigo da live',
    'extra.data_live' => 'Data da live',
    'extra.data_live_iso' => 'Data da live ISO',
    'extra.andamento' => 'Andamento',
    'extra.aulas_concluidas' => 'Aulas concluidas',
    'extra.aulas_totais' => 'Aulas totais',
    'extra.pdf_url' => 'PDF certificado',
    'extra.codigo_certificado' => 'Codigo certificado',
    'literal:valor_fixo' => 'Valor fixo',
];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') $fieldOptions['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $enabled = isset($_POST['is_enabled']) ? 1 : 0;
    $baseUrl = post_str('base_url') ?: 'https://api.manychat.com';
    $token = post_str('token');
    $timeout = max(1, post_int('timeout_seconds'));

    $existing = $pdo->query("SELECT id FROM manychat_config ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($existing) {
        $st = $pdo->prepare("UPDATE manychat_config SET is_enabled=:en, base_url=:bu, token=:tk, timeout_seconds=:to WHERE id=:id LIMIT 1");
        $st->execute([':en'=>$enabled, ':bu'=>$baseUrl, ':tk'=>$token, ':to'=>$timeout, ':id'=>$existing]);
    } else {
        $st = $pdo->prepare("INSERT INTO manychat_config (is_enabled, base_url, token, timeout_seconds) VALUES (:en,:bu,:tk,:to)");
        $st->execute([':en'=>$enabled, ':bu'=>$baseUrl, ':tk'=>$token, ':to'=>$timeout]);
    }
    header('Location: manychat.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    $id = post_int('id');
    $nome = post_str('nome');
    $evento = post_str('evento');
    $active = isset($_POST['is_active']) ? 1 : 0;
    $tagsText = post_str('tags_text');
    $flowsText = post_str('flows_text');

    $sources = $_POST['field_source'] ?? [];
    $dests = $_POST['field_dest'] ?? [];
    $pairs = [];
    if (is_array($sources) && is_array($dests)) {
        $n = min(count($sources), count($dests));
        for ($i = 0; $i < $n; $i++) {
            $src = trim((string)$sources[$i]);
            $dst = trim((string)$dests[$i]);
            if ($src !== '' && $dst !== '') $pairs[] = ['source' => $src, 'dest' => $dst];
        }
    }
    $fieldsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE manychat_rules
               SET nome=:n, evento=:e, is_active=:a, tags_text=:t, flows_text=:f, fields_json=:fj
             WHERE id=:id LIMIT 1
        ");
        $st->execute([':n'=>$nome, ':e'=>$evento, ':a'=>$active, ':t'=>$tagsText, ':f'=>$flowsText, ':fj'=>$fieldsJson, ':id'=>$id]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO manychat_rules (nome, evento, is_active, tags_text, flows_text, fields_json)
            VALUES (:n,:e,:a,:t,:f,:fj)
        ");
        $st->execute([':n'=>$nome, ':e'=>$evento, ':a'=>$active, ':t'=>$tagsText, ':f'=>$flowsText, ':fj'=>$fieldsJson]);
    }
    header('Location: manychat.php?saved=1');
    exit;
}

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE manychat_rules SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id'=>(int)$_GET['toggle']]);
    header('Location: manychat.php');
    exit;
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM manychat_rules WHERE id=:id")->execute([':id'=>(int)$_GET['del']]);
    header('Location: manychat.php');
    exit;
}

$cfg = mc_get_config($pdo);
$rules = $pdo->query("SELECT * FROM manychat_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM manychat_rules WHERE id=:id LIMIT 1");
    $st->execute([':id'=>(int)$_GET['edit']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editPairs = [];
if ($edit && trim((string)($edit['fields_json'] ?? '')) !== '') {
    $tmp = json_decode((string)$edit['fields_json'], true);
    if (is_array($tmp)) $editPairs = $tmp;
}
if (!$editPairs) $editPairs = [['source'=>'user.email', 'dest'=>'email_area_membros']];

$recentLogs = $pdo->query("
    SELECT ml.*, mr.nome AS rule_nome
      FROM manychat_logs ml
      LEFT JOIN manychat_rules mr ON mr.id = ml.rule_id
  ORDER BY ml.created_at DESC, ml.id DESC
     LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/_header.php';
?>

<style>
.mc-grid{display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:16px;align-items:start;}
.mc-field-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;margin-bottom:8px;}
.mc-muted{font-size:12px;color:var(--muted);line-height:1.5;}
.mc-table-small td{font-size:12px;}
@media(max-width:1000px){.mc-grid{grid-template-columns:1fr;}.mc-field-grid{grid-template-columns:1fr;}}
</style>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-ok">Configuracao salva.</div>
<?php endif; ?>

<div class="mc-grid">
    <div>
        <div class="card">
            <div class="card-header">
                <div class="card-header-title">Credenciais Manychat</div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="save_config">
                <div class="form-group checkbox-row">
                    <input type="checkbox" id="mc-enabled" name="is_enabled" <?= (int)$cfg['is_enabled'] === 1 ? 'checked' : '' ?>>
                    <label for="mc-enabled">Ativar disparos Manychat</label>
                </div>
                <div class="form-group">
                    <label class="form-label">Base URL</label>
                    <input type="text" name="base_url" value="<?= h($cfg['base_url']) ?>" placeholder="https://api.manychat.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Token</label>
                    <input type="password" name="token" value="<?= h($cfg['token']) ?>" placeholder="Bearer token do Manychat">
                    <div class="mc-muted">No Manychat, gere o token em Settings > API. Os flows/tags/campos precisam existir no Manychat.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Timeout (segundos)</label>
                    <input type="number" name="timeout_seconds" min="1" max="60" value="<?= (int)$cfg['timeout_seconds'] ?>">
                </div>
                <button class="btn btn-primary" type="submit">Salvar credenciais</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-title"><?= $edit ? 'Editar regra' : 'Nova regra' ?></div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="form-group">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" value="<?= h($edit['nome'] ?? '') ?>" placeholder="Ex.: Tag de inscrito no Manychat">
                </div>
                <div class="form-group">
                    <label class="form-label">Evento</label>
                    <input list="mc-events" name="evento" value="<?= h($edit['evento'] ?? '') ?>" placeholder="INSCRITO ou LIVE_TURMA_300526" required>
                    <datalist id="mc-events">
                        <?php foreach ($eventOptions as $event): ?><option value="<?= h($event) ?>"></option><?php endforeach; ?>
                    </datalist>
                    <div class="mc-muted">Para turma especifica, use o evento completo, como <code>LIVE_TURMA_300526</code>.</div>
                </div>
                <div class="form-group checkbox-row">
                    <input type="checkbox" id="mc-rule-active" name="is_active" <?= !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                    <label for="mc-rule-active">Regra ativa</label>
                </div>
                <div class="form-group">
                    <label class="form-label">Tags por nome (uma por linha)</label>
                    <textarea name="tags_text" placeholder="INSCRITO_AREA_MEMBROS"><?= h($edit['tags_text'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Flows flow_ns</label>
                    <textarea name="flows_text" placeholder="content20240101abc123"><?= h($edit['flows_text'] ?? '') ?></textarea>
                    <div class="mc-muted">Use o identificador <code>flow_ns</code> do Manychat. Pode separar por linha, espaco ou virgula.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Campos personalizados</label>
                    <datalist id="mc-field-options">
                        <?php foreach ($fieldOptions as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                    </datalist>
                    <div id="mc-fields">
                        <?php foreach ($editPairs as $pair): ?>
                            <div class="mc-field-grid">
                                <input list="mc-field-options" name="field_source[]" value="<?= h($pair['source'] ?? '') ?>" placeholder="Origem. Ex.: user.email">
                                <input name="field_dest[]" value="<?= h($pair['dest'] ?? '') ?>" placeholder="Campo no Manychat">
                                <button class="btn btn-ghost btn-sm" type="button" onclick="this.closest('.mc-field-grid').remove()">Remover</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="mcAddField()">Adicionar campo</button>
                </div>
                <button class="btn btn-primary" type="submit"><?= $edit ? 'Salvar regra' : 'Adicionar regra' ?></button>
                <?php if ($edit): ?><a class="btn btn-ghost" href="manychat.php">Cancelar</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <div class="card-header-title">Regras configuradas</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Evento</th><th>Acoes</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rules): ?>
                        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:22px">Nenhuma regra Manychat cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rules as $rule): ?>
                        <?php
                        $tagCount = count(array_filter(array_map('trim', preg_split('/\R+/', (string)($rule['tags_text'] ?? '')) ?: [])));
                        $flowCount = count(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($rule['flows_text'] ?? '')) ?: [])));
                        $fieldCount = 0;
                        $tmp = json_decode((string)($rule['fields_json'] ?? ''), true);
                        if (is_array($tmp)) $fieldCount = count($tmp);
                        ?>
                        <tr>
                            <td class="fw-700"><?= h($rule['nome'] ?: ('Regra #' . (int)$rule['id'])) ?></td>
                            <td><span class="badge badge-neutral"><?= h($rule['evento']) ?></span></td>
                            <td class="text-sm text-muted"><?= $tagCount ?> tag(s), <?= $flowCount ?> flow(s), <?= $fieldCount ?> campo(s)</td>
                            <td><?= (int)$rule['is_active'] === 1 ? '<span class="badge badge-success">Ativa</span>' : '<span class="badge badge-neutral">Pausada</span>' ?></td>
                            <td style="white-space:nowrap;text-align:right">
                                <a class="btn btn-ghost btn-sm" href="manychat.php?edit=<?= (int)$rule['id'] ?>">Editar</a>
                                <a class="btn btn-ghost btn-sm" href="manychat.php?toggle=<?= (int)$rule['id'] ?>"><?= (int)$rule['is_active'] === 1 ? 'Pausar' : 'Ativar' ?></a>
                                <a class="btn btn-danger btn-sm" href="manychat.php?del=<?= (int)$rule['id'] ?>" onclick="return confirm('Excluir esta regra?')">Excluir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-title">Ultimos logs Manychat</div>
                <a class="btn btn-ghost btn-sm" href="logs.php?source=manychat">Ver todos</a>
            </div>
            <div class="table-wrap">
                <table class="mc-table-small">
                    <thead><tr><th>Quando</th><th>Evento</th><th>Acao</th><th>Status</th><th>Resumo</th></tr></thead>
                    <tbody>
                    <?php if (!$recentLogs): ?>
                        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:22px">Sem logs ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= h($log['created_at']) ?></td>
                            <td><?= h($log['evento']) ?></td>
                            <td><?= h($log['action']) ?></td>
                            <td><?= (int)$log['ok'] === 1 ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Erro</span>' ?> <span class="text-muted"><?= h($log['http_status'] ?? '-') ?></span></td>
                            <td><?= h($log['error_text'] ?: substr((string)($log['response_text'] ?? ''), 0, 120)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function mcAddField() {
    var wrap = document.getElementById('mc-fields');
    var div = document.createElement('div');
    div.className = 'mc-field-grid';
    div.innerHTML = '<input list="mc-field-options" name="field_source[]" placeholder="Origem. Ex.: user.email">' +
        '<input name="field_dest[]" placeholder="Campo no Manychat">' +
        '<button class="btn btn-ghost btn-sm" type="button" onclick="this.closest(\\'.mc-field-grid\\').remove()">Remover</button>';
    wrap.appendChild(div);
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
