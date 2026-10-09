<?php
// FILE: admin/_integracoes_sino.php
// Conteudo da aba "Sino (WhatsApp)" da tela Integracoes.
// Os dados vem de _integracoes_sino_acoes.php (incluido antes do _header.php).
$snDt = static function ($v): string { $t = strtotime((string)$v); return $t ? date('d/m/Y H:i:s', $t) : ''; };
$snMask = static function (string $k): string { return $k === '' ? '' : substr($k, 0, 9) . str_repeat('•', 8) . substr($k, -4); };
$snDis = $snCanWrite ? '' : 'disabled';
?>
<style>
.sn-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:16px}
.sn-grid h2{margin:0 0 4px;font-size:16px}.sn-sub{margin:0 0 14px;font-size:12px}
.sn-field{margin-bottom:12px}.sn-field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}
.sn-field input[type=password],.sn-field input[type=url]{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:9px 11px;color:var(--text);font-size:13px}
.sn-note{font-size:11px;color:var(--muted);margin-top:4px}
.sn-check{display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:13px}
.sn-status{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800}
.sn-on{background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.35)}
.sn-off{background:rgba(148,163,184,.1);color:#cbd5e1;border:1px solid rgba(148,163,184,.3)}
.sn-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.sn-kpi{border:1px solid var(--border);border-radius:8px;padding:10px}.sn-kpi small{display:block;font-size:11px;color:var(--muted)}.sn-kpi strong{font-size:20px}
.sn-ok{color:#86efac}.sn-bad{color:#fca5a5}.sn-warn{color:#fcd34d}
.sn-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}.sn-table th,.sn-table td{padding:6px 8px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
.sn-pre{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12px;white-space:pre-wrap;word-break:break-word;margin-top:10px}
.sn-env{background:rgba(250,204,21,.08);border:1px solid rgba(250,204,21,.35);color:#fde68a;border-radius:8px;padding:9px 11px;font-size:12px;margin-bottom:12px}
.sn-inline{display:inline}
@media(max-width:900px){.sn-grid{grid-template-columns:1fr}.sn-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<?php if ($snNotice !== ''): ?><div class="alert alert-ok"><?= int_h($snNotice) ?></div><?php endif; ?>
<?php if ($snError !== ''): ?><div class="alert alert-error"><?= int_h($snError) ?></div><?php endif; ?>

<div class="sn-grid">
  <div>
    <div class="int-panel">
      <h2>Conexão com o Sino</h2>
      <p class="sn-sub int-muted">Envia ao Sino (WhatsApp) os alunos cadastrados, as mudanças de turma e de data/link da live, e os alunos dos blocos “Enviar para fluxo do Sino” das automações. Situação:
        <span class="sn-status <?= $snEnabled ? 'sn-on' : 'sn-off' ?>"><?= $snEnabled ? '● Ativa' : '○ Desligada' ?></span></p>
      <?php if ($snEnvKeys): ?>
        <div class="sn-env">O arquivo <code>.env</code> do servidor define <?= int_h(implode(', ', $snEnvKeys)) ?> e tem prioridade sobre os campos abaixo.</div>
      <?php endif; ?>
      <form method="post" action="integracoes.php?tab=sino">
        <input type="hidden" name="csrf" value="<?= int_h($snCsrf) ?>">
        <input type="hidden" name="action" value="sino_save">
        <label class="sn-check"><input type="checkbox" name="enabled" value="1" <?= $snEnabled ? 'checked' : '' ?> <?= $snDis ?>> Ativar integração com o Sino</label>
        <div class="sn-field">
          <label>Chave da API (x-api-key)</label>
          <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= $snApiKey !== '' ? int_h('Salva: ' . $snMask($snApiKey) . ' — deixe em branco para manter') : 'sk_sino_...' ?>" <?= $snDis ?>>
          <div class="sn-note">A chave fica salva no banco, nunca no código. Para trocar, cole a nova; em branco mantém a atual.</div>
          <?php if ($snApiKey !== ''): ?><label class="sn-check" style="margin:8px 0 0"><input type="checkbox" name="remove_key" value="1" <?= $snDis ?>> Remover a chave salva</label><?php endif; ?>
        </div>
        <div class="sn-field">
          <label>URL da API</label>
          <input type="url" name="api_url" value="<?= int_h($snApiUrl) ?>" placeholder="https://sino.professoremersonleite.site/v1" <?= $snDis ?>>
        </div>
        <div class="int-actions">
          <?php if ($snCanWrite): ?><button class="int-btn primary" type="submit">Salvar</button><?php endif; ?>
          <button class="int-btn" type="submit" form="snTestForm" <?= $snDis ?>>Testar conexão</button>
        </div>
      </form>
      <form id="snTestForm" method="post" action="integracoes.php?tab=sino" class="sn-inline">
        <input type="hidden" name="csrf" value="<?= int_h($snCsrf) ?>">
        <input type="hidden" name="action" value="sino_test">
      </form>
      <?php if ($snTest !== null): ?>
        <?php $snPingOk = $snTest['status'] === 200; ?>
        <div class="sn-pre <?= $snPingOk ? 'sn-ok' : 'sn-bad' ?>"><?= $snPingOk ? '✓ Conectado: a chave é válida.' : '✗ Falhou (HTTP ' . (int)$snTest['status'] . '): ' . int_h($snTest['error']) ?></div>
      <?php endif; ?>
    </div>

    <div class="int-panel">
      <h2>Carga inicial</h2>
      <p class="sn-sub int-muted">Envia ao Sino os alunos das turmas com live no futuro (data, link e turma), para os lembretes funcionarem. Não dispara boas-vindas para alunos antigos.</p>
      <div class="int-actions">
        <form method="post" action="integracoes.php?tab=sino" class="sn-inline"><input type="hidden" name="csrf" value="<?= int_h($snCsrf) ?>"><input type="hidden" name="action" value="sino_load_preview"><button class="int-btn" type="submit" <?= $snDis ?>>Simular (só contar)</button></form>
        <form method="post" action="integracoes.php?tab=sino" class="sn-inline" onsubmit="return confirm('Colocar na fila do Sino todos os alunos das turmas com live futura?')"><input type="hidden" name="csrf" value="<?= int_h($snCsrf) ?>"><input type="hidden" name="action" value="sino_load_run"><button class="int-btn primary" type="submit" <?= ($snCanWrite && $snEnabled) ? '' : 'disabled' ?>>Enviar carga inicial</button></form>
      </div>
      <?php if (!$snEnabled): ?><div class="sn-note">Ative a integração para liberar o envio.</div><?php endif; ?>
      <?php if ($snLoad !== null): ?>
        <table class="sn-table">
          <tr><th>Turma</th><th>Live</th><th>Alunos</th><th>Ignorados</th></tr>
          <?php foreach ($snLoad['turmas'] as $t): ?>
            <tr><td><?= int_h($t['codigo']) ?></td><td><?= int_h($snDt($t['data_live'])) ?></td><td><?= (int)$t['alunos'] ?></td><td><?= (int)array_sum($t['ignorados']) ?></td></tr>
          <?php endforeach; ?>
          <tr><td colspan="2">Reagendados (turma já encerrada)</td><td><?= (int)$snLoad['reagendados'] ?></td><td></td></tr>
        </table>
        <div class="sn-pre <?= $snLoad['dry_run'] ? '' : 'sn-ok' ?>"><?= $snLoad['dry_run'] ? 'Simulação: ' . (int)$snLoad['total'] . ' alunos seriam enviados.' : '✓ ' . (int)$snLoad['total'] . ' alunos colocados na fila. O envio acontece nos próximos minutos.' ?><?php if ($snLoad['ignorados']): ?> Ignorados: <?= int_h(implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($snLoad['ignorados']), $snLoad['ignorados']))) ?>.<?php endif; ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="int-panel">
      <h2>Fila de envio</h2>
      <p class="sn-sub int-muted">Enviada a cada minuto pelo cron <code>sino_outbox</code>, até 200 por rodada.<?= $snLastSent ? ' Último envio: ' . int_h($snDt($snLastSent)) . '.' : '' ?></p>
      <div class="sn-kpis">
        <div class="sn-kpi"><small>Pendentes</small><strong class="sn-warn"><?= (int)$snQueue['pendente'] ?></strong></div>
        <div class="sn-kpi"><small>Enviados</small><strong class="sn-ok"><?= (int)$snQueue['enviado'] ?></strong></div>
        <div class="sn-kpi"><small>Erro definitivo</small><strong class="sn-bad"><?= (int)$snQueue['erro_definitivo'] ?></strong></div>
        <div class="sn-kpi"><small>Ignorados</small><strong><?= (int)$snQueue['ignorado'] ?></strong></div>
      </div>
      <?php if ($snErrors): ?>
        <table class="sn-table">
          <tr><th>Quando</th><th>Tipo</th><th>Aluno/Turma</th><th>HTTP</th><th>Erro</th></tr>
          <?php foreach ($snErrors as $r): ?>
            <tr><td><?= int_h($snDt($r['criado_em'])) ?></td><td><?= int_h($r['tipo']) ?></td><td><?= int_h($r['ref']) ?></td><td><?= int_h((string)$r['ultimo_http']) ?></td><td><?= int_h(mb_substr((string)$r['ultimo_erro'], 0, 160)) ?> <span class="sn-note">(<?= int_h($r['status']) ?>, <?= (int)$r['tentativas'] ?> tent.)</span></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?>
        <div class="sn-note">Nenhum erro recente.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
