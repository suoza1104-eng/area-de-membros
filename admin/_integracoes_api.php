<?php
declare(strict_types=1);

// Aba "Chaves API" de integracoes.php (incluida por ela; nao acessar direto).
if (!function_exists('int_h') || !isset($pdo)) { http_response_code(404); exit; }

$apiKeys = [];
$apiLogs = [];
try {
    $apiKeys = student_api_list_keys($pdo);
    $apiLogs = student_api_recent_logs($pdo, 50);
} catch (Throwable $e) {
    $msgError = $msgError ?: 'Erro ao carregar chaves: ' . $e->getMessage();
}
$apiEndpoint = rtrim(BASE_URL, '/') . '/api/v1/aluno.php';
$apiResultLabels = [
    'found' => ['Encontrado', 'ok'],
    'not_found' => ['Não encontrado', ''],
    'ambiguous' => ['Ambíguo', ''],
    'unauthorized' => ['Chave inválida', 'err'],
    'revoked' => ['Chave revogada', 'err'],
    'bad_request' => ['Requisição inválida', 'err'],
    'error' => ['Erro', 'err'],
];
$inputStyle = 'width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);';
$labelStyle = 'display:block;font-size:10px;color:var(--muted);font-weight:800;text-transform:uppercase;margin-bottom:4px;';
?>
<?php if ($msgOk !== ''): ?>
  <div class="int-panel" style="border-color:#22c55e;color:#86efac;background:rgba(34,197,94,0.1);font-weight:600;padding:12px 16px;">✅ <?= int_h($msgOk) ?></div>
<?php endif; ?>
<?php if ($msgError !== ''): ?>
  <div class="int-panel" style="border-color:#ef4444;color:#fca5a5;background:rgba(239,68,68,0.1);font-weight:600;padding:12px 16px;">❌ <?= int_h($msgError) ?></div>
<?php endif; ?>

<?php if ($apiNewKey !== ''): ?>
  <div class="int-panel" style="border-color:rgba(250,204,21,.6);background:rgba(250,204,21,.08)">
    <h2 style="margin:0 0 6px;font-size:16px;">Nova chave de API</h2>
    <p class="int-muted" style="margin:0 0 10px;font-size:12px;">Copie e entregue para a plataforma agora. Por segurança, guardamos só uma impressão digital dela: se perder, crie outra e revogue esta.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <input id="apiNewKey" readonly value="<?= int_h($apiNewKey) ?>" style="<?= $inputStyle ?>flex:1;min-width:260px;font-family:monospace;">
      <button class="int-btn primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('apiNewKey').value);this.textContent='Copiada!'">Copiar</button>
    </div>
  </div>
<?php endif; ?>

<div class="int-panel">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
    <div>
      <h2 style="margin:0;font-size:18px;">API de consulta de aluno</h2>
      <p class="int-muted" style="margin:4px 0 0;font-size:12px;">Plataformas externas enviam e-mail e/ou telefone e recebem o perfil completo do aluno: cadastro, turma, live, progresso, bloqueio, vitalício, certificado, tags e compras.</p>
    </div>
    <a class="int-btn" href="api_aluno_docs.php" target="_blank">📄 Documentação da API</a>
  </div>
  <div style="margin-top:12px;font-size:12px;">
    <span class="int-muted">Endpoint:</span>
    <code style="background:#050914;border:1px solid var(--border);border-radius:6px;padding:4px 8px;">POST <?= int_h($apiEndpoint) ?></code>
  </div>
</div>

<div class="int-panel">
  <h2 style="margin:0 0 4px;font-size:16px;">Criar chave</h2>
  <p class="int-muted" style="margin:0 0 14px;font-size:12px;">Uma chave por plataforma: assim dá para revogar o acesso de uma sem afetar as outras e ver nos logs quem consultou o quê.</p>
  <form method="post" action="integracoes.php?tab=api" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;align-items:end;">
    <input type="hidden" name="action" value="api_create_key">
    <div>
      <label style="<?= $labelStyle ?>">Plataforma / nome</label>
      <input name="api_name" required maxlength="120" placeholder="Ex.: ManyChat, n8n, Agente de suporte" style="<?= $inputStyle ?>">
    </div>
    <div>
      <label style="<?= $labelStyle ?>">Limite por minuto</label>
      <input type="number" name="api_rate" min="1" max="1000" value="60" style="<?= $inputStyle ?>">
    </div>
    <div>
      <label style="<?= $labelStyle ?>">Observação</label>
      <input name="api_notes" maxlength="500" placeholder="Opcional" style="<?= $inputStyle ?>">
    </div>
    <div>
      <label style="display:flex;gap:8px;align-items:center;font-size:12px;cursor:pointer;margin-bottom:10px;">
        <input type="checkbox" name="api_scope_financial" value="1" checked> Incluir compras e valores
      </label>
    </div>
    <div><button class="int-btn primary" type="submit">Gerar chave</button></div>
  </form>
</div>

<div class="int-panel int-scroll">
  <h2 style="margin:0 0 12px;font-size:16px;">Chaves cadastradas</h2>
  <?php if (!$apiKeys): ?>
    <div class="int-empty">Nenhuma chave criada ainda.</div>
  <?php else: ?>
    <table class="int-table">
      <thead><tr><th>Plataforma</th><th style="width:130px">Chave</th><th style="width:110px">Escopo</th><th style="width:90px">Status</th><th style="width:140px">Criada</th><th style="width:150px">Último uso</th><th style="width:80px">Consultas</th><th style="width:190px">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($apiKeys as $k): $active = $k['status'] === 'active'; ?>
        <tr>
          <td><strong><?= int_h($k['name']) ?></strong><?php if (!empty($k['notes'])): ?><br><span class="int-muted"><?= int_h($k['notes']) ?></span><?php endif; ?></td>
          <td><code><?= int_h($k['key_prefix']) ?>…</code></td>
          <td><?= (int)$k['scope_financial'] === 1 ? 'Completo' : 'Sem compras' ?><br><span class="int-muted"><?= (int)$k['rate_limit_per_minute'] ?>/min</span></td>
          <td><span class="int-badge <?= $active ? 'ok' : 'err' ?>"><?= $active ? 'Ativa' : 'Revogada' ?></span></td>
          <td><?= int_h(int_dt_br($k['created_at'])) ?><?php if (!empty($k['created_by'])): ?><br><span class="int-muted"><?= int_h($k['created_by']) ?></span><?php endif; ?></td>
          <td><?= $k['last_used_at'] ? int_h(int_dt_br($k['last_used_at'])) . '<br><span class="int-muted">' . int_h((string)$k['last_used_ip']) . '</span>' : '<span class="int-muted">Nunca</span>' ?></td>
          <td><?= (int)$k['total_requests'] ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <form method="post" action="integracoes.php?tab=api" style="margin:0" <?= $active ? "onsubmit=\"return confirm('Revogar esta chave? A plataforma perde o acesso na hora.')\"" : '' ?>>
                <input type="hidden" name="action" value="<?= $active ? 'api_revoke_key' : 'api_activate_key' ?>">
                <input type="hidden" name="api_key_id" value="<?= (int)$k['id'] ?>">
                <button class="int-btn" type="submit" style="padding:6px 10px;font-size:11px;"><?= $active ? 'Revogar' : 'Reativar' ?></button>
              </form>
              <?php if (!$active): ?>
              <form method="post" action="integracoes.php?tab=api" style="margin:0" onsubmit="return confirm('Excluir esta chave definitivamente?')">
                <input type="hidden" name="action" value="api_delete_key">
                <input type="hidden" name="api_key_id" value="<?= (int)$k['id'] ?>">
                <button class="int-btn" type="submit" style="padding:6px 10px;font-size:11px;">Excluir</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="int-panel">
  <h2 style="margin:0 0 4px;font-size:16px;">Testar consulta</h2>
  <p class="int-muted" style="margin:0 0 14px;font-size:12px;">Mostra exatamente o JSON que a plataforma recebe (com escopo completo). O teste pelo painel não usa chave e não entra nos logs.</p>
  <form method="post" action="integracoes.php?tab=api" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end;">
    <input type="hidden" name="action" value="api_test_lookup">
    <div><label style="<?= $labelStyle ?>">E-mail</label><input type="email" name="api_test_email" value="<?= int_h($apiTestEmail) ?>" style="<?= $inputStyle ?>"></div>
    <div><label style="<?= $labelStyle ?>">Telefone</label><input name="api_test_phone" value="<?= int_h($apiTestPhone) ?>" placeholder="(11) 99999-9999" style="<?= $inputStyle ?>"></div>
    <div><button class="int-btn primary" type="submit">Consultar</button></div>
  </form>
  <?php if (is_array($apiTestResult)): ?>
    <div style="margin-top:14px;">
      <span class="int-badge <?= $apiTestResult['status'] === 'found' ? 'ok' : '' ?>"><?= int_h($apiResultLabels[$apiTestResult['status']][0] ?? $apiTestResult['status']) ?></span>
      <pre style="white-space:pre-wrap;max-height:520px;overflow:auto;background:#050914;border:1px solid var(--border);border-radius:8px;padding:12px;font-size:11px;margin-top:10px;"><?= int_h((string)json_encode($apiTestResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  <?php endif; ?>
</div>

<div class="int-panel int-scroll">
  <h2 style="margin:0 0 12px;font-size:16px;">Últimas consultas</h2>
  <?php if (!$apiLogs): ?>
    <div class="int-empty">Nenhuma consulta registrada ainda.</div>
  <?php else: ?>
    <table class="int-table">
      <thead><tr><th style="width:140px">Data</th><th style="width:160px">Plataforma</th><th>Buscou</th><th style="width:130px">Resultado</th><th style="width:90px">Aluno</th><th style="width:70px">HTTP</th><th style="width:70px">Tempo</th></tr></thead>
      <tbody>
      <?php foreach ($apiLogs as $l): $lbl = $apiResultLabels[$l['result']] ?? [$l['result'], '']; ?>
        <tr>
          <td><?= int_h(int_dt_br($l['created_at'])) ?></td>
          <td><?= $l['key_name'] !== null ? int_h($l['key_name']) : '<span class="int-muted">—</span>' ?><br><span class="int-muted"><?= int_h((string)$l['ip']) ?></span></td>
          <td><?= int_h(trim((string)$l['query_email'] . '  ' . (string)$l['query_phone'])) ?></td>
          <td><span class="int-badge <?= int_h($lbl[1]) ?>"><?= int_h($lbl[0]) ?></span></td>
          <td><?= $l['user_id'] ? '#' . (int)$l['user_id'] : '' ?></td>
          <td><?= (int)$l['http_status'] ?></td>
          <td><?= $l['duration_ms'] !== null ? (int)$l['duration_ms'] . ' ms' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
