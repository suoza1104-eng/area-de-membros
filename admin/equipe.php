<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();
$menu       = 'equipe';
$page_title = 'Equipe';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Auto-cria tabela (TEXT não aceita DEFAULT em MySQL strict mode — sem default)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_equipe (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(255) NOT NULL,
        email       VARCHAR(255) NOT NULL,
        whatsapp_number VARCHAR(30) NULL,
        whatsapp_blacklist_exempt TINYINT(1) NOT NULL DEFAULT 1,
        senha_hash  VARCHAR(255) NOT NULL DEFAULT '',
        permissoes  TEXT NULL,
        ativo       TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_equipe_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}
foreach ([
    'whatsapp_number' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email",
    'whatsapp_blacklist_exempt' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_blacklist_exempt TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_number",
] as $column => $sql) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM admin_equipe LIKE :c");
        $st->execute([':c' => $column]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
    } catch (Throwable $e) {}
}

// Páginas controladas e suas labels
$PAGINAS = [
    'dashboard'        => 'Dashboard',
    'vendas_analytics' => 'Analise de Vendas',
    'alunos'           => 'Alunos',
    'retorno_agendamentos' => 'Agendamentos de Retorno',
    'reagendamentos_live' => 'Reagendamentos de Live',
    'aulas'            => 'Aulas',
    'turmas'           => 'Turmas',
    'cursos'           => 'Cursos Recomendados',
    'certificado'      => 'Certificado',
    'webhooks'         => 'Webhooks',
    'integration_hub'  => 'Hub de Integrações',
    'meta_leads'       => 'Meta Leads Qualificados',
    'manychat'         => 'Manychat',
    'superfuncionario' => 'SuperFuncionário',
    'whatsapp_config'   => 'Configurações WhatsApp',
    'whatsapp_monitor'  => 'WhatsApp Monitor',
    'whatsapp_ai'       => 'IA WhatsApp',
    'notificacoes'      => 'Notificações do App',
    'suporte_chat'      => 'Central de Suporte',
    'automacoes'        => 'Automações',
    'email_marketing'   => 'E-mail Marketing',
    'monitor'          => 'Rastreamento',
    'cron_monitor'     => 'Monitor de Cron',
    'logs'             => 'Logs',
    'aparencia'        => 'Aparência',
    'config_app'       => 'Configurações',
    'equipe'           => 'Equipe',
];

$msg     = '';
$msgTipo = 'ok';
$modo    = (string)($_GET['modo'] ?? 'lista');
$editId  = (int)($_GET['id'] ?? 0);
$editRow = null;

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $nome  = trim((string)($_POST['nome']  ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $whatsapp = preg_replace('/\D+/', '', (string)($_POST['whatsapp_number'] ?? '')) ?: '';
        $senha = trim((string)($_POST['senha'] ?? ''));
        $pid   = (int)($_POST['edit_id'] ?? 0);

        $perms = [];
        foreach (array_keys($PAGINAS) as $pag) {
            $perms[$pag] = [
                'acesso'   => isset($_POST['pa_' . $pag]),
                'escrever' => isset($_POST['pe_' . $pag]),
            ];
        }
        $permsJson = json_encode($perms, JSON_UNESCAPED_UNICODE);

        if ($nome === '' || $email === '') {
            $msg = 'Nome e e-mail são obrigatórios.'; $msgTipo = 'erro';
        } elseif ($pid === 0 && $senha === '') {
            $msg = 'Defina uma senha para o novo membro.'; $msgTipo = 'erro';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'E-mail inválido.'; $msgTipo = 'erro';
        } else {
            try {
                if ($pid > 0) {
                    if ($senha !== '') {
                        $hash = password_hash($senha, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE admin_equipe SET nome=:n, email=:e, whatsapp_number=:w, senha_hash=:s, permissoes=:p WHERE id=:id")
                            ->execute([':n'=>$nome,':e'=>$email,':w'=>$whatsapp ?: null,':s'=>$hash,':p'=>$permsJson,':id'=>$pid]);
                    } else {
                        $pdo->prepare("UPDATE admin_equipe SET nome=:n, email=:e, whatsapp_number=:w, permissoes=:p WHERE id=:id")
                            ->execute([':n'=>$nome,':e'=>$email,':w'=>$whatsapp ?: null,':p'=>$permsJson,':id'=>$pid]);
                    }
                    $msg = 'Membro atualizado com sucesso.';
                } else {
                    $hash = password_hash($senha, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO admin_equipe (nome,email,whatsapp_number,senha_hash,permissoes) VALUES (:n,:e,:w,:s,:p)")
                        ->execute([':n'=>$nome,':e'=>$email,':w'=>$whatsapp ?: null,':s'=>$hash,':p'=>$permsJson]);
                    $msg = 'Membro adicionado com sucesso.';
                }
                header('Location: equipe.php?ok=' . urlencode($msg)); exit;
            } catch (Throwable $e) {
                $msg = 'Erro: ' . $e->getMessage(); $msgTipo = 'erro';
            }
        }
    } elseif ($acao === 'toggle') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $pdo->prepare("UPDATE admin_equipe SET ativo = 1 - ativo WHERE id=:id")->execute([':id'=>$pid]);
        }
        header('Location: equipe.php'); exit;
    } elseif ($acao === 'excluir') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $pdo->prepare("DELETE FROM admin_equipe WHERE id=:id")->execute([':id'=>$pid]);
        }
        header('Location: equipe.php?ok=Membro+removido.'); exit;
    }
}

if (isset($_GET['ok'])) { $msg = (string)$_GET['ok']; $msgTipo = 'ok'; }

if (($modo === 'editar') && $editId > 0) {
    $st = $pdo->prepare("SELECT * FROM admin_equipe WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editRow) { header('Location: equipe.php'); exit; }
}

try {
    $membros = $pdo->query("SELECT * FROM admin_equipe ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $membros = [];
    if ($msgTipo !== 'erro') { $msg = 'Erro ao carregar membros: ' . $e->getMessage(); $msgTipo = 'erro'; }
}

require __DIR__ . '/_header.php';
?>
<style>
.eq-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-xl); padding:20px 22px; margin-bottom:18px; box-shadow:var(--shadow); }
.eq-title { font-size:15px; font-weight:700; color:var(--text); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.eq-title svg { width:17px; height:17px; color:var(--primary); }
.perm-table { width:100%; border-collapse:collapse; font-size:12px; }
.perm-table th { padding:6px 10px; text-align:left; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); border-bottom:1px solid var(--border); white-space:nowrap; }
.perm-table td { padding:8px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
.perm-table tr:last-child td { border-bottom:none; }
.perm-table tr:hover td { background:var(--bg-hover); }
.perm-check { display:flex; align-items:center; justify-content:center; }
.perm-check input[type=checkbox] { width:16px; height:16px; accent-color:var(--primary); cursor:pointer; }
.toggle-all-btn { font-size:11px; color:var(--muted); background:none; border:none; cursor:pointer; text-decoration:underline; padding:0; font-family:var(--font); }
.toggle-all-btn:hover { color:var(--text); }
.member-card {
    display:flex; align-items:center; gap:14px;
    padding:12px 14px; border-radius:var(--r-lg);
    border:1px solid var(--border); background:var(--bg);
    margin-bottom:10px; transition:border-color var(--t);
}
.member-card:hover { border-color:var(--border-light); }
.member-avatar {
    width:42px; height:42px; border-radius:var(--r-full);
    background:var(--primary-dim); color:var(--primary);
    display:flex; align-items:center; justify-content:center;
    font-size:16px; font-weight:700; flex-shrink:0;
}
.member-avatar.inactive { background:rgba(100,116,139,.12); color:var(--muted); }
.member-info { flex:1; min-width:0; }
.member-name { font-size:13px; font-weight:600; color:var(--text); }
.member-email { font-size:11px; color:var(--muted); }
.member-perms { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
.perm-pill {
    font-size:10px; padding:1px 7px; border-radius:var(--r-full);
    background:var(--primary-dim); color:var(--primary);
    border:1px solid rgba(250,204,21,.2);
}
.perm-pill.ro { background:rgba(56,189,248,.08); color:var(--info); border-color:rgba(56,189,248,.2); }
.member-actions { display:flex; gap:6px; flex-shrink:0; }
.status-badge { padding:2px 8px; border-radius:var(--r-full); font-size:10px; font-weight:600; }
.status-badge.on  { background:rgba(34,197,94,.12); color:#86efac; }
.status-badge.off { background:rgba(100,116,139,.1); color:var(--muted); }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:640px) { .field-row { grid-template-columns:1fr; } }
.pw-wrap { position:relative; }
.pw-wrap input { padding-right:38px; }
.pw-eye {
    position:absolute; right:10px; top:50%; transform:translateY(-50%);
    background:none; border:none; color:var(--muted); cursor:pointer; padding:4px;
    display:flex; align-items:center; justify-content:center;
    transition:color var(--t);
}
.pw-eye:hover { color:var(--text); }
.pw-eye svg { width:16px; height:16px; }
</style>

<?php if ($msg): ?>
<div class="alert <?= $msgTipo === 'ok' ? 'alert-ok' : 'alert-error' ?>" style="margin-bottom:16px">
    <?= h($msg) ?>
</div>
<?php endif; ?>

<?php if ($modo === 'novo' || $modo === 'editar'): ?>
<!-- ─── FORMULÁRIO ─────────────────────────────────────────────────────── -->
<div class="eq-card">
    <div class="eq-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
        <?= $modo === 'editar' ? 'Editar membro: ' . h((string)($editRow['nome'] ?? '')) : 'Novo membro da equipe' ?>
    </div>

    <form method="post" action="equipe.php<?= $modo === 'editar' ? '?modo=editar&id=' . $editId : '?modo=novo' ?>">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="edit_id" value="<?= $editId ?>">

        <div class="field-row" style="margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label class="form-label">Nome completo *</label>
                <input type="text" name="nome" required value="<?= h((string)($editRow['nome'] ?? '')) ?>" placeholder="Ex: Maria Souza">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">E-mail de login *</label>
                <input type="email" name="email" required value="<?= h((string)($editRow['email'] ?? '')) ?>" placeholder="maria@email.com">
            </div>
        </div>

        <div class="form-group" style="margin-bottom:18px">
            <label class="form-label">WhatsApp da equipe</label>
            <input type="text" name="whatsapp_number" value="<?= h((string)($editRow['whatsapp_number'] ?? '')) ?>" placeholder="Ex: 5522999999999" inputmode="tel">
            <div style="font-size:11px;color:var(--muted);margin-top:6px">
                Use DDI + DDD + número. Este telefone poderá receber alertas administrativos do WhatsApp.
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px">A proteção contra banimento é cadastrada em Configurações WhatsApp → Lista de fraude e números confiáveis.</div>
        </div>

        <div class="form-group" style="margin-bottom:18px">
            <?php if ($modo === 'editar'): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:9px;font-size:12px;color:var(--text)">
                    <input type="checkbox" id="alterar-senha" onchange="toggleAlterarSenha(this.checked)" style="width:16px;height:16px;accent-color:var(--primary)">
                    Alterar senha deste membro
                </label>
            <?php endif; ?>
            <label class="form-label">Senha <?= $modo === 'editar' ? '(opcional)' : '*' ?></label>
            <div class="pw-wrap">
                <input type="password" id="campo-senha" name="senha"
                    <?= $modo === 'novo' ? 'required' : 'disabled' ?>
                    placeholder="<?= $modo === 'editar' ? 'Marque Alterar senha para definir uma nova' : 'Mínimo 6 caracteres' ?>"
                    autocomplete="new-password">
                <button type="button" class="pw-eye" onclick="toggleSenha()" title="Mostrar/ocultar senha">
                    <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Tabela de permissões -->
        <div style="margin-bottom:18px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <label class="form-label" style="margin:0">Permissões de acesso</label>
                <div style="display:flex;gap:10px">
                    <button type="button" class="toggle-all-btn" onclick="toggleAll('pa_', true)">Marcar acesso a tudo</button>
                    <button type="button" class="toggle-all-btn" onclick="toggleAll('pa_', false)">Desmarcar tudo</button>
                </div>
            </div>
            <div style="border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden">
                <table class="perm-table">
                    <thead>
                        <tr>
                            <th>Tela</th>
                            <th style="width:200px">
                                <div style="display:flex;align-items:center;gap:18px;justify-content:flex-start;padding-left:4px">
                                    <span style="width:70px;text-align:center">
                                        Visualizar
                                        <div><button type="button" class="toggle-all-btn" onclick="toggleCol('pa_', true)">todos</button> / <button type="button" class="toggle-all-btn" onclick="toggleCol('pa_', false)">nenhum</button></div>
                                    </span>
                                    <span style="width:70px;text-align:center">
                                        Editar
                                        <div><button type="button" class="toggle-all-btn" onclick="toggleCol('pe_', true)">todos</button> / <button type="button" class="toggle-all-btn" onclick="toggleCol('pe_', false)">nenhum</button></div>
                                    </span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $editPerms = [];
                        if ($editRow && !empty($editRow['permissoes'])) {
                            $editPerms = json_decode((string)$editRow['permissoes'], true) ?: [];
                        }
                        foreach ($PAGINAS as $pag => $label):
                            $temAcesso   = (bool)($editPerms[$pag]['acesso']   ?? false);
                            $temEscrever = (bool)($editPerms[$pag]['escrever'] ?? false);
                        ?>
                        <tr>
                            <td style="font-weight:500"><?= h($label) ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:18px;padding-left:4px">
                                    <div style="width:70px;display:flex;align-items:center;justify-content:center">
                                        <input type="checkbox" name="pa_<?= h($pag) ?>" id="pa_<?= h($pag) ?>" <?= $temAcesso ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary);cursor:pointer">
                                    </div>
                                    <div style="width:70px;display:flex;align-items:center;justify-content:center">
                                        <input type="checkbox" name="pe_<?= h($pag) ?>" id="pe_<?= h($pag) ?>" <?= $temEscrever ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary);cursor:pointer">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px">
                "Pode acessar" = consegue visualizar a tela. "Pode alterar" = pode salvar, editar, excluir dados naquela tela.
            </div>
        </div>

        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Salvar membro
            </button>
            <a href="equipe.php" class="btn btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- ─── LISTA ──────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div>
        <div style="font-size:16px;font-weight:700;color:var(--text)">Equipe</div>
        <div style="font-size:12px;color:var(--muted)">Gerencie quem tem acesso ao painel e quais permissões cada pessoa tem.</div>
    </div>
    <a href="equipe.php?modo=novo" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo membro
    </a>
</div>

<div class="eq-card">
    <?php if (!$membros): ?>
    <div style="text-align:center;padding:32px;color:var(--muted)">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.4;margin:0 auto 10px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <div style="font-size:14px;font-weight:500">Nenhum membro cadastrado</div>
        <div style="font-size:12px;margin-top:4px"><a href="equipe.php?modo=novo" class="btn btn-primary btn-sm" style="margin-top:10px">Adicionar primeiro membro</a></div>
    </div>
    <?php else: ?>
    <?php foreach ($membros as $m):
        $perms = json_decode((string)($m['permissoes'] ?? '{}'), true) ?: [];
        $nomeInicial = strtoupper(substr((string)($m['nome'] ?? '?'), 0, 1));
        $ativo = (int)($m['ativo'] ?? 1);
        // Contar páginas com acesso
        $pagesOn = array_filter($perms, fn($p) => !empty($p['acesso']));
        $pagesWrite = array_filter($perms, fn($p) => !empty($p['escrever']));
    ?>
    <div class="member-card">
        <div class="member-avatar <?= $ativo ? '' : 'inactive' ?>"><?= h($nomeInicial) ?></div>
        <div class="member-info">
            <div class="member-name">
                <?= h((string)($m['nome'] ?? '')) ?>
                <span class="status-badge <?= $ativo ? 'on' : 'off' ?>"><?= $ativo ? 'Ativo' : 'Inativo' ?></span>
            </div>
            <div class="member-email"><?= h((string)($m['email'] ?? '')) ?></div>
            <?php if (!empty($m['whatsapp_number'])): ?>
                <div class="member-email">
                    WhatsApp: <?= h((string)$m['whatsapp_number']) ?>
                </div>
            <?php endif; ?>
            <div class="member-perms">
                <?php foreach ($pagesOn as $pg => $v): ?>
                    <span class="perm-pill <?= empty($v['escrever']) ? 'ro' : '' ?>">
                        <?= h($PAGINAS[$pg] ?? $pg) ?><?= empty($v['escrever']) ? ' (ver)' : '' ?>
                    </span>
                <?php endforeach; ?>
                <?php if (!$pagesOn): ?>
                    <span style="font-size:11px;color:var(--muted)">Sem páginas liberadas</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="member-actions">
            <a href="equipe.php?modo=editar&id=<?= (int)$m['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
            <form method="post" style="margin:0">
                <input type="hidden" name="acao" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="<?= $ativo ? 'color:var(--warning)' : 'color:var(--success)' ?>">
                    <?= $ativo ? 'Desativar' : 'Ativar' ?>
                </button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Remover este membro?')">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">Remover</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="eq-card" style="background:rgba(250,204,21,.04);border-color:rgba(250,204,21,.15)">
    <div style="font-size:12px;color:var(--muted);line-height:1.7">
        <strong style="color:var(--primary)">ℹ️ Como funciona:</strong>
        Cada membro cadastrado aqui tem seu próprio e-mail e senha para entrar no painel.
        As permissões definem quais telas ele pode ver e se pode fazer alterações.
        O <strong>administrador principal</strong> (definido em <code class="code">config.php</code>) sempre tem acesso total, independente dessas configurações.
    </div>
</div>
<?php endif; ?>

<script>
function toggleAll(prefix, val) {
    document.querySelectorAll('input[type=checkbox][name^="' + prefix + '"]').forEach(function(cb) { cb.checked = val; });
}
function toggleCol(prefix, val) {
    document.querySelectorAll('input[type=checkbox][name^="' + prefix + '"]').forEach(function(cb) { cb.checked = val; });
}
// Quando marca "pode alterar", marca "pode acessar" também
document.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        if (this.name && this.name.startsWith('pe_') && this.checked) {
            var pag = this.name.replace('pe_', '');
            var acessoCb = document.querySelector('input[name="pa_' + pag + '"]');
            if (acessoCb) acessoCb.checked = true;
        }
    });
});

function toggleSenha() {
    var inp = document.getElementById('campo-senha');
    var ico = document.getElementById('eye-icon');
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        inp.type = 'password';
        ico.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}
function toggleAlterarSenha(ativo) {
    var inp = document.getElementById('campo-senha');
    if (!inp) return;
    inp.disabled = !ativo;
    inp.required = false;
    if (!ativo) {
        inp.value = '';
        inp.type = 'password';
    } else {
        inp.focus();
    }
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
