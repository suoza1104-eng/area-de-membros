<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$pdo = getPDO();
$menu = 'reagendamentos_live';
$page_title = 'Reagendamentos de Live';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rl_admin_dt(?string $v): string {
    if (!$v) return '-';
    try { return (new DateTime((string)$v))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return (string)$v; }
}
function rl_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function rl_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function rl_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
function rl_make_token_link(string $token): string {
    $publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
    return $publicBase . '/public/reagendar_live.php?t=' . urlencode($token);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS live_reschedule_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by_admin_id INT NULL,
        UNIQUE KEY uk_live_reschedule_token (token),
        KEY idx_live_reschedule_user (user_id),
        KEY idx_live_reschedule_expires (expires_at),
        KEY idx_live_reschedule_used (used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reagendamentos_live (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        old_codigo_turma VARCHAR(80) NULL,
        new_codigo_turma VARCHAR(80) NULL,
        old_turma_live_at DATETIME NULL,
        new_turma_live_at DATETIME NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(250) NULL,
        webhook_url TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_reag_live_user (user_id),
        KEY idx_reag_live_created (created_at),
        KEY idx_reag_live_new_live (new_turma_live_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // A tela continua carregando para exibir o erro nas acoes dependentes.
}

$msg = '';
$msgTipo = 'ok';
$generatedLink = '';

$opcoesN = (int)get_setting('reagendar_opcoes_qtd', (string)get_setting('reagendar_next_lives_count', '3'));
if ($opcoesN < 1) $opcoesN = 3;
$ttlHours = (int)get_setting('reagendar_token_ttl_hours', '72');
if ($ttlHours < 1) $ttlHours = 72;
$windowDays = (int)get_setting('reagendar_window_days', '15');
if ($windowDays < 1) $windowDays = 15;
$webhookUrl = (string)get_setting('reagendar_webhook_url', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'salvar_config') {
            $opcoesN = (int)($_POST['reagendar_opcoes_qtd'] ?? $opcoesN);
            if ($opcoesN < 1) $opcoesN = 1;
            if ($opcoesN > 10) $opcoesN = 10;

            $ttlHours = (int)($_POST['reagendar_token_ttl_hours'] ?? $ttlHours);
            if ($ttlHours < 1) $ttlHours = 1;
            if ($ttlHours > 720) $ttlHours = 720;

            $windowDays = (int)($_POST['reagendar_window_days'] ?? $windowDays);
            if ($windowDays < 1) $windowDays = 1;
            if ($windowDays > 365) $windowDays = 365;

            $webhookUrl = trim((string)($_POST['reagendar_webhook_url'] ?? ''));

            set_setting('reagendar_opcoes_qtd', (string)$opcoesN);
            set_setting('reagendar_next_lives_count', (string)$opcoesN);
            set_setting('reagendar_token_ttl_hours', (string)$ttlHours);
            set_setting('reagendar_window_days', (string)$windowDays);
            set_setting('reagendar_webhook_url', $webhookUrl);
            $msg = 'Configuracoes de reagendamento salvas.';
        } elseif ($acao === 'gerar_link') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) throw new RuntimeException('Informe o ID do aluno.');
            $st = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $userId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Aluno nao encontrado.');
            if (!rl_table_exists($pdo, 'live_reschedule_tokens')) throw new RuntimeException('Tabela de tokens indisponivel.');

            $token = bin2hex(random_bytes(16));
            $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttlHours . ' hours')->format('Y-m-d H:i:s');
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            $pdo->prepare("INSERT INTO live_reschedule_tokens (user_id, token, expires_at, used_at, created_at, created_by_admin_id)
                VALUES (:u, :t, :e, NULL, NOW(), :a)")
                ->execute([
                    ':u' => $userId,
                    ':t' => $token,
                    ':e' => $expiresAt,
                    ':a' => $adminId > 0 ? $adminId : null,
                ]);
            $generatedLink = rl_make_token_link($token);
            $msg = 'Link de reagendamento gerado.';
        } elseif ($acao === 'revogar_token') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE live_reschedule_tokens SET used_at = COALESCE(used_at, NOW()) WHERE id = :id")
                    ->execute([':id' => $id]);
            }
            $msg = 'Link revogado.';
        }
    } catch (Throwable $e) {
        $msg = 'Erro: ' . $e->getMessage();
        $msgTipo = 'erro';
    }
}

$publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
$publicTokenExample = $publicBase . '/public/reagendar_live.php?t=SEU_TOKEN';
$publicAutoExample = $publicBase . '/public/reagendar_live.php?email=EMAIL_DO_ALUNO&telefone=TELEFONE_DO_ALUNO';
$generatorExample = BASE_URL_ADMIN . '/reagendar_link.php?user_id=ID_DO_ALUNO';

$nowSql = date('Y-m-d H:i:s');
$endSql = (new DateTimeImmutable('now'))->modify('+' . $windowDays . ' days')->format('Y-m-d H:i:s');

$kpiTokensAtivos = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NULL AND expires_at >= NOW()");
$kpiTokensUsados = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NOT NULL");
$kpiTokensExpirados = rl_count($pdo, "SELECT COUNT(*) FROM live_reschedule_tokens WHERE used_at IS NULL AND expires_at < NOW()");
$kpiReagTotal = rl_count($pdo, "SELECT COUNT(*) FROM reagendamentos_live");
$kpiReag7 = rl_count($pdo, "SELECT COUNT(*) FROM reagendamentos_live WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$kpiLivesDisponiveis = rl_count($pdo, "SELECT COUNT(*) FROM turmas WHERE data_live >= :now AND data_live <= :end", [':now' => $nowSql, ':end' => $endSql]);

$fAluno = trim((string)($_GET['aluno'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$whereHist = [];
$paramsHist = [];
if ($fAluno !== '') {
    $whereHist[] = "(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR u.id = :aluno_id)";
    $paramsHist[':aluno'] = '%' . $fAluno . '%';
    $paramsHist[':aluno_id'] = ctype_digit($fAluno) ? (int)$fAluno : 0;
}
$whereHistSql = $whereHist ? 'WHERE ' . implode(' AND ', $whereHist) : '';

try {
    $st = $pdo->prepare("SELECT r.*, u.nome, u.email, u.telefone
        FROM reagendamentos_live r
        LEFT JOIN users u ON u.id = r.user_id
        $whereHistSql
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 200");
    $st->execute($paramsHist);
    $historico = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $historico = [];
}

$whereTokens = [];
$paramsTokens = [];
if ($fAluno !== '') {
    $whereTokens[] = "(u.nome LIKE :aluno OR u.email LIKE :aluno OR u.telefone LIKE :aluno OR u.id = :aluno_id)";
    $paramsTokens[':aluno'] = '%' . $fAluno . '%';
    $paramsTokens[':aluno_id'] = ctype_digit($fAluno) ? (int)$fAluno : 0;
}
if ($fStatus === 'ativo') {
    $whereTokens[] = "t.used_at IS NULL AND t.expires_at >= NOW()";
} elseif ($fStatus === 'usado') {
    $whereTokens[] = "t.used_at IS NOT NULL";
} elseif ($fStatus === 'expirado') {
    $whereTokens[] = "t.used_at IS NULL AND t.expires_at < NOW()";
}
$whereTokensSql = $whereTokens ? 'WHERE ' . implode(' AND ', $whereTokens) : '';

try {
    $st = $pdo->prepare("SELECT t.*, u.nome, u.email, u.telefone
        FROM live_reschedule_tokens t
        LEFT JOIN users u ON u.id = t.user_id
        $whereTokensSql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 200");
    $st->execute($paramsTokens);
    $tokens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $tokens = [];
}

try {
    $alunosLiveWhere = [];
    if (rl_col_exists($pdo, 'users', 'codigo_turma')) $alunosLiveWhere[] = 'u.codigo_turma = turmas.codigo';
    if (rl_col_exists($pdo, 'users', 'turma_codigo')) $alunosLiveWhere[] = 'u.turma_codigo = turmas.codigo';
    $alunosSub = $alunosLiveWhere
        ? '(SELECT COUNT(*) FROM users u WHERE ' . implode(' OR ', $alunosLiveWhere) . ')'
        : '0';
    $st = $pdo->prepare("SELECT id, codigo, data_live,
        $alunosSub AS alunos
        FROM turmas
        WHERE data_live >= :now AND data_live <= :end
        ORDER BY data_live ASC, id ASC
        LIMIT 50");
    $st->execute([':now' => $nowSql, ':end' => $endSql]);
    $lives = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $lives = [];
}

require __DIR__ . '/_header.php';
?>
<style>
.rl-grid { display:grid; grid-template-columns:1.05fr .95fr; gap:16px; align-items:start; }
@media(max-width:1100px){ .rl-grid{grid-template-columns:1fr;} }
.rl-status { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.rl-status.ativo { background:var(--success-dim); color:#86efac; }
.rl-status.usado { background:var(--info-dim); color:#7dd3fc; }
.rl-status.expirado { background:var(--danger-dim); color:#fca5a5; }
.rl-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.rl-link-box { display:flex; gap:8px; align-items:center; min-width:0; }
.rl-link-box input { min-width:0; font-size:12px; }
.rl-table-small td { font-size:12px; }
.rl-copy { max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>

<?php if ($msg): ?>
<div class="alert <?= $msgTipo === 'ok' ? 'alert-ok' : 'alert-error' ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($generatedLink !== ''): ?>
<div class="alert alert-info">
    <div class="fw-700 mb-3">Link gerado</div>
    <div class="rl-link-box">
        <input type="text" id="generatedLink" value="<?= h($generatedLink) ?>" readonly>
        <button class="btn btn-primary btn-sm" type="button" onclick="copyText('generatedLink')">Copiar</button>
    </div>
</div>
<?php endif; ?>

<div class="topbar">
    <div>
        <div class="topbar-title">Reagendamentos de live</div>
        <div class="text-muted text-sm">Central para configurar a pagina publica, gerar links e acompanhar as trocas de turma/live feitas pelos alunos.</div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= h($publicBase . '/public/reagendar_live.php') ?>" target="_blank">Abrir pagina publica</a>
</div>

<div class="kpi-grid">
    <div class="kpi kpi-g"><div class="kpi-label">Links ativos</div><div class="kpi-value"><?= number_format($kpiTokensAtivos, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-b"><div class="kpi-label">Links usados</div><div class="kpi-value"><?= number_format($kpiTokensUsados, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-r"><div class="kpi-label">Links expirados</div><div class="kpi-value"><?= number_format($kpiTokensExpirados, 0, ',', '.') ?></div></div>
    <div class="kpi kpi-y"><div class="kpi-label">Reagendamentos</div><div class="kpi-value"><?= number_format($kpiReagTotal, 0, ',', '.') ?></div><div class="kpi-sub"><?= number_format($kpiReag7, 0, ',', '.') ?> nos ultimos 7 dias</div></div>
    <div class="kpi kpi-o"><div class="kpi-label">Lives disponiveis</div><div class="kpi-value"><?= number_format($kpiLivesDisponiveis, 0, ',', '.') ?></div><div class="kpi-sub">janela de <?= (int)$windowDays ?> dia(s)</div></div>
</div>

<div class="rl-grid">
    <div>
        <div class="card">
            <div class="card-header-title mb-3">Configuracoes</div>
            <form method="post">
                <input type="hidden" name="acao" value="salvar_config">
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Proximas lives exibidas</label>
                        <input type="number" min="1" max="10" name="reagendar_opcoes_qtd" value="<?= (int)$opcoesN ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Validade do link (horas)</label>
                        <input type="number" min="1" max="720" name="reagendar_token_ttl_hours" value="<?= (int)$ttlHours ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Janela de lives (dias)</label>
                        <input type="number" min="1" max="365" name="reagendar_window_days" value="<?= (int)$windowDays ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Webhook ao reagendar</label>
                    <input type="url" name="reagendar_webhook_url" value="<?= h($webhookUrl) ?>" placeholder="https://...">
                    <div class="text-xs text-muted mt-2">Chamado quando o aluno confirma nova turma/live.</div>
                </div>
                <button class="btn btn-primary">Salvar configuracoes</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header-title mb-3">Links da pagina publica</div>
            <div class="form-group">
                <label class="form-label">Com token</label>
                <div class="rl-link-box"><input id="linkToken" value="<?= h($publicTokenExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkToken')">Copiar</button></div>
            </div>
            <div class="form-group">
                <label class="form-label">Automatico por email e telefone</label>
                <div class="rl-link-box"><input id="linkAuto" value="<?= h($publicAutoExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkAuto')">Copiar</button></div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Gerador admin</label>
                <div class="rl-link-box"><input id="linkAdmin" value="<?= h($generatorExample) ?>" readonly><button type="button" class="btn btn-ghost btn-sm" onclick="copyText('linkAdmin')">Copiar</button></div>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Historico de reagendamentos</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Aluno</th><th>Antes</th><th>Depois</th><th>Quando</th></tr></thead>
                    <tbody>
                    <?php if (!$historico): ?>
                        <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px">Nenhum reagendamento encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($historico as $r): ?>
                        <tr>
                            <td>
                                <div class="fw-700"><?= h($r['nome'] ?? ('Aluno #' . (int)$r['user_id'])) ?></div>
                                <div class="text-xs text-muted">#<?= (int)$r['user_id'] ?> &middot; <?= h($r['email'] ?? '') ?></div>
                            </td>
                            <td>
                                <div class="fw-700"><?= h($r['old_codigo_turma'] ?: '-') ?></div>
                                <div class="text-xs text-muted"><?= h(rl_admin_dt($r['old_turma_live_at'] ?? null)) ?></div>
                            </td>
                            <td>
                                <div class="fw-700"><?= h($r['new_codigo_turma'] ?: '-') ?></div>
                                <div class="text-xs text-muted"><?= h(rl_admin_dt($r['new_turma_live_at'] ?? null)) ?></div>
                            </td>
                            <td>
                                <div><?= h(rl_admin_dt($r['created_at'] ?? null)) ?></div>
                                <div class="text-xs text-muted"><?= h($r['ip'] ?? '') ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header-title mb-3">Gerar link para aluno</div>
            <form method="post">
                <input type="hidden" name="acao" value="gerar_link">
                <div class="form-group">
                    <label class="form-label">ID do aluno</label>
                    <input type="number" min="1" name="user_id" value="<?= h($_GET['user_id'] ?? '') ?>" placeholder="Ex: 123" required>
                </div>
                <button class="btn btn-primary">Gerar link</button>
            </form>
        </div>

        <form method="get" class="filter-bar">
            <div class="filter-group" style="min-width:220px"><label>Aluno</label><input name="aluno" value="<?= h($fAluno) ?>" placeholder="Nome, email, telefone ou ID"></div>
            <div class="filter-group"><label>Status do link</label><select name="status"><option value="">Todos</option><option value="ativo" <?= $fStatus==='ativo'?'selected':'' ?>>Ativos</option><option value="usado" <?= $fStatus==='usado'?'selected':'' ?>>Usados</option><option value="expirado" <?= $fStatus==='expirado'?'selected':'' ?>>Expirados</option></select></div>
            <div class="filter-actions"><button class="btn btn-primary btn-sm">Filtrar</button><a class="reset-link" href="reagendamentos_live.php">Limpar</a></div>
        </form>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Links gerados</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Aluno</th><th>Status</th><th>Expira</th><th style="text-align:right">Acoes</th></tr></thead>
                    <tbody>
                    <?php if (!$tokens): ?>
                        <tr><td colspan="4" class="text-muted" style="text-align:center;padding:24px">Nenhum link encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tokens as $t):
                        $status = !empty($t['used_at']) ? 'usado' : ((strtotime((string)$t['expires_at']) < time()) ? 'expirado' : 'ativo');
                        $linkId = 'tok_' . (int)$t['id'];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-700"><?= h($t['nome'] ?? ('Aluno #' . (int)$t['user_id'])) ?></div>
                                <div class="text-xs text-muted">#<?= (int)$t['user_id'] ?> &middot; <?= h($t['email'] ?? '') ?></div>
                                <input id="<?= h($linkId) ?>" type="hidden" value="<?= h(rl_make_token_link((string)$t['token'])) ?>">
                            </td>
                            <td><span class="rl-status <?= h($status) ?>"><?= h($status) ?></span><?php if (!empty($t['used_at'])): ?><div class="text-xs text-muted"><?= h(rl_admin_dt($t['used_at'])) ?></div><?php endif; ?></td>
                            <td><?= h(rl_admin_dt($t['expires_at'] ?? null)) ?><div class="text-xs text-muted">Criado: <?= h(rl_admin_dt($t['created_at'] ?? null)) ?></div></td>
                            <td>
                                <div class="rl-actions">
                                    <button type="button" class="btn btn-ghost btn-xs" onclick="copyText('<?= h($linkId) ?>')">Copiar</button>
                                    <?php if ($status === 'ativo'): ?>
                                    <form method="post" onsubmit="return confirm('Revogar este link?')"><input type="hidden" name="acao" value="revogar_token"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-danger btn-xs">Revogar</button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border)" class="card-header-title">Lives disponiveis para reagendar</div>
            <div class="table-wrap">
                <table class="rl-table-small">
                    <thead><tr><th>Turma</th><th>Data live</th><th>Alunos</th></tr></thead>
                    <tbody>
                    <?php if (!$lives): ?>
                        <tr><td colspan="3" class="text-muted" style="text-align:center;padding:24px">Nenhuma live futura dentro da janela configurada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lives as $l): ?>
                        <tr>
                            <td class="fw-700"><?= h($l['codigo'] ?? '') ?></td>
                            <td><?= h(rl_admin_dt($l['data_live'] ?? null)) ?></td>
                            <td><?= number_format((int)($l['alunos'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function copyText(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var text = el.value || el.textContent || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
        return;
    }
    if (el.select) {
        el.select();
        document.execCommand('copy');
    }
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
