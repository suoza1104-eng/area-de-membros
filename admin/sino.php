<?php
// FILE: admin/sino.php
// Tela da integracao com o Sino (WhatsApp): chave da API, liga/desliga,
// teste de conexao, situacao da fila sino_outbox e carga inicial.
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

$menu = 'sino';
$page_title = 'Integração Sino';
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['integracoes']['escrever']);
}
if (empty($_SESSION['sino_csrf'])) $_SESSION['sino_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['sino_csrf'];

function sn_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sn_dt($v): string { $t = strtotime((string)$v); return $t ? date('d/m/Y H:i:s', $t) : ''; }
function sn_mask(string $key): string { return $key === '' ? '' : substr($key, 0, 9) . str_repeat('•', 8) . substr($key, -4); }

$notice = (string)($_GET['msg'] ?? '');
$error = '';
$testResult = null;
$loadResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Sem permissao para alterar a integracao.');
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $url = rtrim(trim((string)($_POST['api_url'] ?? '')), '/');
            if ($url !== '' && !preg_match('~^https://~i', $url)) throw new RuntimeException('A URL da API precisa comecar com https://');
            set_setting('sino_api_url', $url);
            $key = trim((string)($_POST['api_key'] ?? ''));
            if ($key !== '') set_setting('sino_api_key', $key); // em branco = mantem a chave atual
            if (!empty($_POST['remove_key'])) set_setting('sino_api_key', '');
            set_setting('sino_enabled', !empty($_POST['enabled']) ? 'true' : 'false');
            header('Location: sino.php?msg=' . rawurlencode('Configuracao salva.'));
            exit;
        }
        if ($action === 'test') {
            $testResult = sino_request('GET', '/ping');
        }
        if ($action === 'load_preview') {
            $loadResult = sino_initial_load($pdo, true) + ['dry_run' => true];
        }
        if ($action === 'load_run') {
            if (!sino_enabled()) throw new RuntimeException('Ative a integracao antes de enviar a carga inicial.');
            $loadResult = sino_initial_load($pdo, false) + ['dry_run' => false];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$apiUrl = sino_api_url();
$apiKey = sino_api_key();
$enabled = sino_enabled();
$envKeys = array_filter(['SINO_API_URL', 'SINO_API_KEY', 'SINO_ENABLED'], 'sino_config_from_env');

$queue = ['pendente' => 0, 'enviado' => 0, 'erro_definitivo' => 0, 'ignorado' => 0];
$recentErrors = [];
$lastSent = '';
try {
    sino_ensure_schema($pdo);
    foreach ($pdo->query("SELECT status, COUNT(*) qtd FROM sino_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $queue[(string)$r['status']] = (int)$r['qtd'];
    }
    $lastSent = (string)$pdo->query("SELECT MAX(enviado_em) FROM sino_outbox WHERE status = 'enviado'")->fetchColumn();
    $recentErrors = $pdo->query("SELECT id, tipo, ref, status, tentativas, ultimo_http, ultimo_erro, criado_em
                                   FROM sino_outbox
                                  WHERE ultimo_erro IS NOT NULL AND ultimo_erro <> '' AND status <> 'enviado'
                               ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/_header.php';
?>
<style>
.sn-wrap{max-width:1100px;margin:0 auto}
.sn-head h1{margin:0 0 4px;font-size:22px}.sn-head p{margin:0 0 18px;color:var(--muted,#9ca3af);font-size:13px}
.sn-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:16px}
.sn-card{background:var(--bg-card,#020617);border:1px solid var(--border,#1f2937);border-radius:14px;padding:18px;margin-bottom:16px}
.sn-card h2{margin:0 0 4px;font-size:16px}.sn-card .sn-sub{margin:0 0 14px;color:var(--muted,#9ca3af);font-size:12px}
.sn-field{margin-bottom:12px}.sn-field label{display:block;font-size:12px;font-weight:600;margin-bottom:5px}
.sn-field input[type=text],.sn-field input[type=password],.sn-field input[type=url]{width:100%;background:var(--bg);border:1px solid var(--border,#1f2937);border-radius:9px;padding:9px 11px;color:inherit;font-size:13px}
.sn-note{font-size:11px;color:var(--muted,#9ca3af);margin-top:4px}
.sn-check{display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:13px}
.sn-status{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
.sn-on{background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.35)}
.sn-off{background:rgba(148,163,184,.1);color:#cbd5e1;border:1px solid rgba(148,163,184,.3)}
.sn-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.sn-kpi{border:1px solid var(--border,#1f2937);border-radius:10px;padding:10px}.sn-kpi small{display:block;font-size:11px;color:var(--muted,#9ca3af)}.sn-kpi strong{font-size:20px}
.sn-ok{color:#86efac}.sn-bad{color:#fca5a5}.sn-warn{color:#fcd34d}
.sn-actions{display:flex;gap:8px;flex-wrap:wrap}
.sn-table{width:100%;border-collapse:collapse;font-size:12px}.sn-table th,.sn-table td{padding:6px 8px;border-bottom:1px solid var(--border,#1f2937);text-align:left;vertical-align:top}
.sn-pre{background:var(--bg);border:1px solid var(--border,#1f2937);border-radius:9px;padding:10px;font-size:12px;white-space:pre-wrap;word-break:break-all;margin:10px 0 0}
.sn-env{background:rgba(250,204,21,.08);border:1px solid rgba(250,204,21,.35);color:#fde68a;border-radius:9px;padding:9px 11px;font-size:12px;margin-bottom:12px}
@media(max-width:900px){.sn-grid{grid-template-columns:1fr}.sn-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="sn-wrap">
  <div class="sn-head">
    <h1>Integração Sino (WhatsApp)</h1>
    <p>Envia ao Sino os alunos cadastrados, as mudanças de turma e de data/link da live, e os alunos dos blocos “Enviar para fluxo do Sino” das automações.</p>
  </div>

  <?php if ($notice !== ''): ?><div class="alert alert-ok"><?= sn_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= sn_h($error) ?></div><?php endif; ?>

  <div class="sn-grid">
    <div>
      <div class="sn-card">
        <h2>Conexão com o Sino</h2>
        <p class="sn-sub">Situação: <span class="sn-status <?= $enabled ? 'sn-on' : 'sn-off' ?>"><?= $enabled ? '● Ativa' : '○ Desligada' ?></span></p>
        <?php if ($envKeys): ?>
          <div class="sn-env">O arquivo <code>.env</code> do servidor define <?= sn_h(implode(', ', $envKeys)) ?> e tem prioridade sobre os campos abaixo.</div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= sn_h($csrf) ?>">
          <input type="hidden" name="action" value="save">
          <label class="sn-check"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> <?= $canWrite ? '' : 'disabled' ?>> Ativar integração com o Sino</label>
          <div class="sn-field">
            <label>Chave da API (x-api-key)</label>
            <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= $apiKey !== '' ? sn_h('Salva: ' . sn_mask($apiKey) . ' — deixe em branco para manter') : 'sk_sino_...' ?>" <?= $canWrite ? '' : 'disabled' ?>>
            <div class="sn-note">A chave fica salva no banco, nunca no código. Para trocar, cole a nova; em branco mantém a atual.</div>
            <?php if ($apiKey !== ''): ?><label class="sn-check" style="margin:8px 0 0"><input type="checkbox" name="remove_key" value="1"> Remover a chave salva</label><?php endif; ?>
          </div>
          <div class="sn-field">
            <label>URL da API</label>
            <input type="url" name="api_url" value="<?= sn_h($apiUrl) ?>" placeholder="https://sino.professoremersonleite.site/v1" <?= $canWrite ? '' : 'disabled' ?>>
          </div>
          <?php if ($canWrite): ?><button class="btn btn-primary" type="submit">Salvar</button><?php endif; ?>
        </form>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?= sn_h($csrf) ?>">
          <input type="hidden" name="action" value="test">
          <button class="btn btn-ghost" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Testar conexão</button>
        </form>
        <?php if ($testResult !== null): ?>
          <?php $pingOk = $testResult['status'] === 200; ?>
          <div class="sn-pre <?= $pingOk ? 'sn-ok' : 'sn-bad' ?>"><?= $pingOk ? '✓ Conectado: a chave é válida.' : '✗ Falhou (HTTP ' . (int)$testResult['status'] . '): ' . sn_h($testResult['error']) ?></div>
        <?php endif; ?>
      </div>

      <div class="sn-card">
        <h2>Carga inicial</h2>
        <p class="sn-sub">Envia ao Sino os alunos das turmas com live no futuro (data, link e turma), para os lembretes funcionarem. Não dispara boas-vindas para alunos antigos.</p>
        <div class="sn-actions">
          <form method="post"><input type="hidden" name="csrf" value="<?= sn_h($csrf) ?>"><input type="hidden" name="action" value="load_preview"><button class="btn btn-ghost" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Simular (só contar)</button></form>
          <form method="post" onsubmit="return confirm('Colocar na fila do Sino todos os alunos das turmas com live futura?')"><input type="hidden" name="csrf" value="<?= sn_h($csrf) ?>"><input type="hidden" name="action" value="load_run"><button class="btn btn-primary" type="submit" <?= ($canWrite && $enabled) ? '' : 'disabled' ?>>Enviar carga inicial</button></form>
        </div>
        <?php if (!$enabled): ?><div class="sn-note">Ative a integração para liberar o envio.</div><?php endif; ?>
        <?php if ($loadResult !== null): ?>
          <table class="sn-table" style="margin-top:12px">
            <tr><th>Turma</th><th>Live</th><th>Alunos</th><th>Ignorados</th></tr>
            <?php foreach ($loadResult['turmas'] as $t): ?>
              <tr><td><?= sn_h($t['codigo']) ?></td><td><?= sn_h(sn_dt($t['data_live'])) ?></td><td><?= (int)$t['alunos'] ?></td><td><?= (int)array_sum($t['ignorados']) ?></td></tr>
            <?php endforeach; ?>
            <tr><td colspan="2">Reagendados (turma já encerrada)</td><td><?= (int)$loadResult['reagendados'] ?></td><td></td></tr>
          </table>
          <div class="sn-pre <?= $loadResult['dry_run'] ? '' : 'sn-ok' ?>"><?= $loadResult['dry_run'] ? 'Simulação: ' . (int)$loadResult['total'] . ' alunos seriam enviados.' : '✓ ' . (int)$loadResult['total'] . ' alunos colocados na fila. O envio acontece nos próximos minutos.' ?>
<?php if ($loadResult['ignorados']): ?>Ignorados: <?= sn_h(implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($loadResult['ignorados']), $loadResult['ignorados']))) ?><?php endif; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="sn-card">
        <h2>Fila de envio</h2>
        <p class="sn-sub">Enviada a cada minuto pelo cron <code>sino_outbox</code>, até 200 por rodada.<?= $lastSent ? ' Último envio: ' . sn_h(sn_dt($lastSent)) . '.' : '' ?></p>
        <div class="sn-kpis">
          <div class="sn-kpi"><small>Pendentes</small><strong class="sn-warn"><?= (int)$queue['pendente'] ?></strong></div>
          <div class="sn-kpi"><small>Enviados</small><strong class="sn-ok"><?= (int)$queue['enviado'] ?></strong></div>
          <div class="sn-kpi"><small>Erro definitivo</small><strong class="sn-bad"><?= (int)$queue['erro_definitivo'] ?></strong></div>
          <div class="sn-kpi"><small>Ignorados</small><strong><?= (int)$queue['ignorado'] ?></strong></div>
        </div>
        <?php if ($recentErrors): ?>
          <table class="sn-table">
            <tr><th>Quando</th><th>Tipo</th><th>Aluno/Turma</th><th>HTTP</th><th>Erro</th></tr>
            <?php foreach ($recentErrors as $r): ?>
              <tr><td><?= sn_h(sn_dt($r['criado_em'])) ?></td><td><?= sn_h($r['tipo']) ?></td><td><?= sn_h($r['ref']) ?></td><td><?= sn_h((string)$r['ultimo_http']) ?></td><td><?= sn_h(mb_substr((string)$r['ultimo_erro'], 0, 160)) ?> <span class="sn-note">(<?= sn_h($r['status']) ?>, <?= (int)$r['tentativas'] ?> tent.)</span></td></tr>
            <?php endforeach; ?>
          </table>
        <?php else: ?>
          <div class="sn-note">Nenhum erro recente.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/_footer.php';
