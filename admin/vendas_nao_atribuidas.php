<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics_dashboard.php';
proteger_admin();

$menu = 'vendas_analytics';
$page_title = 'Vendas Não Atribuídas';
$pdo = getPDO();
metrics_ensure_schema($pdo);

if (empty($_SESSION['sales_csrf'])) {
    $_SESSION['sales_csrf'] = bin2hex(random_bytes(24));
}

// AJAX: Autocomplete para busca de leads
if ((string)($_GET['ajax'] ?? '') === 'lead_search') {
    header('Content-Type: application/json; charset=UTF-8');
    $term = trim((string)($_GET['q'] ?? ''));
    $rows = [];
    if (mb_strlen($term) >= 2) {
        $st = $pdo->prepare("SELECT id,source_user_id,lead_name,lead_email,lead_phone_raw,turma_codigo,created_at FROM attribution_leads WHERE lead_name LIKE :q OR lead_email LIKE :q OR lead_phone_raw LIKE :q OR CAST(source_user_id AS CHAR)=:exact ORDER BY created_at DESC LIMIT 20");
        $st->execute(['q' => '%' . $term . '%', 'exact' => $term]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// POST: Atribuição manual de venda a um lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'atribuir_venda_manual') {
    $returnQuery = (string)($_POST['return_query'] ?? '');
    try {
        if (!hash_equals((string)($_SESSION['sales_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessão expirada. Recarregue a página.');
        }
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $model = (string)($_POST['attribution_model'] ?? 'last_touch');
        if (!in_array($model, ['first_touch', 'last_touch'], true)) $model = 'last_touch';
        $sale = md_row($pdo, "SELECT * FROM attribution_sales WHERE source_sale_id=:id LIMIT 1", ['id' => $saleId]);
        $lead = md_row($pdo, "SELECT * FROM attribution_leads WHERE id=:id LIMIT 1", ['id' => $leadId]);
        if (!$sale || !$lead) throw new RuntimeException('Venda ou lead não encontrado para atribuição.');
        $resolved = [
            'campaign_group' => (string)$lead['utm_campaign_group'],
            'campaign_group_norm' => (string)$lead['utm_campaign_group_norm'],
            'campaign_name' => (string)$lead['utm_campaign_name'],
            'campaign_name_norm' => (string)$lead['utm_campaign_name_norm'],
            'ad_name' => (string)$lead['utm_ad_name'],
            'ad_name_norm' => (string)$lead['utm_ad_name_norm'],
            'integration_id' => null,
            'ad_account_name' => ''
        ];
        $candidate = resolve_meta_names_from_lead(build_meta_name_lookup($pdo, 0), $lead);
        if (!empty($candidate['matched'])) $resolved = array_merge($resolved, $candidate);
        $manual = $pdo->prepare("INSERT INTO manual_sale_attributions (transaction_code,attribution_model,campaign_group,campaign_group_norm,campaign_name,campaign_name_norm,ad_name,ad_name_norm,source_user_id,lead_utm_source,lead_utm_medium,lead_utm_campaign,lead_utm_term,lead_utm_content,assigned_by,notes) VALUES (:tx,:model,:cg,:cgn,:cn,:cnn,:ad,:adn,:uid,:us,:um,:uc,:ut,:uco,:by,'Atribuição manual pelo painel Vendas Não Atribuídas') ON DUPLICATE KEY UPDATE campaign_group=VALUES(campaign_group),campaign_group_norm=VALUES(campaign_group_norm),campaign_name=VALUES(campaign_name),campaign_name_norm=VALUES(campaign_name_norm),ad_name=VALUES(ad_name),ad_name_norm=VALUES(ad_name_norm),source_user_id=VALUES(source_user_id),assigned_by=VALUES(assigned_by),updated_at=NOW()");
        $manual->execute([
            'tx' => $sale['transaction_code'], 'model' => $model,
            'cg' => $resolved['campaign_group'], 'cgn' => $resolved['campaign_group_norm'],
            'cn' => $resolved['campaign_name'], 'cnn' => $resolved['campaign_name_norm'],
            'ad' => $resolved['ad_name'], 'adn' => $resolved['ad_name_norm'],
            'uid' => $lead['source_user_id'], 'us' => $lead['utm_source'],
            'um' => $lead['utm_campaign_group'], 'uc' => $lead['utm_campaign_name'],
            'ut' => $lead['utm_term'], 'uco' => $lead['utm_ad_name'],
            'by' => (string)($_SESSION['equipe_nome'] ?? 'Administrador')
        ]);
        $saleTs = strtotime((string)$sale['sale_date']);
        $leadTs = strtotime((string)$lead['created_at']);
        upsert_attribution_match($pdo, [
            'sale_id' => (int)$sale['id'], 'lead_id' => (int)$lead['id'],
            'attribution_model' => $model, 'match_type' => 'manual',
            'attribution_seconds_diff' => max(0, $saleTs - $leadTs),
            'lead_created_at' => $lead['created_at'], 'sale_date' => $sale['sale_date'],
            'campaign_group' => $resolved['campaign_group'], 'campaign_group_norm' => $resolved['campaign_group_norm'],
            'campaign_name' => $resolved['campaign_name'], 'campaign_name_norm' => $resolved['campaign_name_norm'],
            'ad_name' => $resolved['ad_name'], 'ad_name_norm' => $resolved['ad_name_norm'],
            'integration_id' => $resolved['integration_id'], 'ad_account_name' => $resolved['ad_account_name'],
            'revenue_value' => (float)$sale['producer_net'], 'product_name' => (string)$sale['product_name']
        ]);
        header('Location: vendas_nao_atribuidas.php?' . $returnQuery . '&manual_ok=1');
        exit;
    } catch (Throwable $e) {
        header('Location: vendas_nao_atribuidas.php?' . $returnQuery . '&manual_err=' . urlencode($e->getMessage()));
        exit;
    }
}

// Filtros da página
$preset = (string)($_GET['period'] ?? 'month');
if (!in_array($preset, ['today','7','30','90','365','month','quarter','year','custom'], true)) $preset = 'month';
$period = metrics_period($preset, $_GET['from'] ?? null, $_GET['to'] ?? null);
$model = ($_GET['model'] ?? '') === 'first_touch' ? 'first_touch' : 'last_touch';
$productFilter = trim((string)($_GET['product'] ?? ''));

function vna_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function vna_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function vna_num($value): string { return number_format((float)$value, 0, ',', '.'); }
function vna_selected($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }

$unattributedParams = ['model' => $model, 'start' => $period['start'] . ' 00:00:00', 'end' => $period['end'] . ' 23:59:59'];
$unattributedProductSql = '';
if ($productFilter !== '') {
    $unattributedProductSql = ' AND s.product_name = :product';
    $unattributedParams['product'] = $productFilter;
}

$unattributedRows = md_rows($pdo, "SELECT s.id,s.transaction_code,s.sale_date,s.product_name,s.gross_revenue,s.producer_net,s.buyer_name,s.buyer_email,s.buyer_phone FROM v_sales_master s JOIN attribution_sales axs ON axs.transaction_code=s.transaction_code LEFT JOIN attribution_matches am ON am.sale_id=axs.id AND am.attribution_model=:model WHERE " . md_approved_sql('s') . " AND s.sale_date BETWEEN :start AND :end AND am.id IS NULL{$unattributedProductSql} ORDER BY s.sale_date DESC LIMIT 100", $unattributedParams);

$options = md_filter_options($pdo);
$manualReturn = $_GET;
unset($manualReturn['manual_ok'], $manualReturn['manual_err']);
$manualReturnQuery = http_build_query($manualReturn);

include __DIR__ . '/_header.php';
?>

<style>
.vna-container { display: flex; flex-direction: column; gap: 16px; }
.vna-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.vna-title h1 { font-size: 22px; margin: 0; color: var(--text); }
.vna-title p { margin: 5px 0 0; color: var(--muted); font-size: 12px; }
.vna-filter { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 14px; }
.periods { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.periods a { padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 11px; font-weight: 650; text-decoration: none; }
.periods a:hover, .periods a.active { background: var(--primary-dim); border-color: rgba(250,204,21,.3); color: var(--primary); }
.filter-grid { display: grid; grid-template-columns: repeat(4, minmax(140px, 1fr)); gap: 9px; align-items: end; }
.fg label { display: block; font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.fg select, .fg input { width: 100%; height: 34px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--text); padding: 0 9px; font-size: 11px; }
.fg-actions { display: flex; align-items: flex-end; gap: 7px; }
.fg-actions .btn { height: 34px; display: inline-flex; align-items: center; justify-content: center; }
.section-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 16px; }
.section-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 13px; }
.section-head h2 { font-size: 15px; margin: 0; color: var(--text); }
.section-head p { font-size: 11px; color: var(--muted); margin: 3px 0 0; }
.manual-alert { padding: 10px 14px; border-radius: 9px; margin-bottom: 12px; font-size: 12px; }
.manual-alert.ok { background: var(--success-dim); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
.manual-alert.err { background: var(--danger-dim); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
.table-wrap { overflow: auto; border: 1px solid var(--border); border-radius: 10px; max-width: 100%; }
.bi-table { width: 100%; border-collapse: collapse; min-width: 950px; }
.bi-table th { position: sticky; top: 0; background: #101a2e; color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.bi-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 11.5px; color: var(--text); vertical-align: top; }
.bi-table tr:hover td { background: var(--bg-hover); }
.subtext { font-size: 10px; color: var(--muted); margin-top: 2px; }
.lead-picker { position: relative; min-width: 300px; }
.lead-picker input[type=search] { width: 100%; height: 34px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--text); padding: 0 10px; font-size: 11px; }
.lead-results { position: absolute; left: 0; right: 0; top: 38px; z-index: 20; background: #0f172a; border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow); max-height: 220px; overflow: auto; display: none; }
.lead-option { display: block; width: 100%; padding: 8px 10px; border: 0; border-bottom: 1px solid var(--border); background: transparent; color: var(--text); text-align: left; font-size: 10.5px; cursor: pointer; }
.lead-option:hover { background: var(--bg-hover); }
.lead-selected { margin: 6px 0 0; color: #86efac; font-size: 10px; font-weight: 600; }
.empty { padding: 32px; text-align: center; color: var(--muted); font-size: 12px; }
</style>

<div class="vna-container">
  <div class="vna-head">
    <div class="vna-title">
      <h1>Vendas Não Atribuídas</h1>
      <p>Transações de vendas aprovadas no período que ainda não possuem um lead vinculado no modelo selecionado.</p>
    </div>
  </div>

  <form class="vna-filter" method="get">
    <div class="periods">
      <?php foreach(['today'=>'Hoje','7'=>'7 dias','30'=>'30 dias','90'=>'90 dias','365'=>'365 dias','month'=>'Mês atual','quarter'=>'Trimestre','year'=>'Ano atual','custom'=>'Personalizado'] as $k=>$label): ?>
        <a class="<?= $preset===$k?'active':'' ?>" href="?<?= vna_h(http_build_query(array_merge($_GET,['period'=>$k]))) ?>"><?= vna_h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="period" value="<?= vna_h($preset) ?>">
    <div class="filter-grid">
      <?php if($preset==='custom'): ?>
        <div class="fg"><label>Início</label><input type="date" name="from" value="<?= vna_h($period['start']) ?>"></div>
        <div class="fg"><label>Fim</label><input type="date" name="to" value="<?= vna_h($period['end']) ?>"></div>
      <?php endif; ?>
      <div class="fg">
        <label>Modelo de Atribuição</label>
        <select name="model">
          <option value="last_touch"<?= vna_selected($model,'last_touch') ?>>Último toque (Last touch)</option>
          <option value="first_touch"<?= vna_selected($model,'first_touch') ?>>Primeiro toque (First touch)</option>
        </select>
      </div>
      <div class="fg">
        <label>Produto</label>
        <select name="product">
          <option value="">Todos os produtos</option>
          <?php foreach($options['products'] as $v): ?>
            <option<?= vna_selected($productFilter, $v) ?>><?= vna_h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg-actions">
        <button class="btn btn-primary" type="submit">Aplicar Filtros</button>
        <a class="btn btn-ghost" href="vendas_nao_atribuidas.php">Limpar</a>
      </div>
    </div>
  </form>

  <section class="section-card">
    <div class="section-head">
      <div>
        <h2>Relação de vendas pendentes de atribuição</h2>
        <p>Período de <strong><?= vna_h(date('d/m/Y', strtotime($period['start']))) ?></strong> a <strong><?= vna_h(date('d/m/Y', strtotime($period['end']))) ?></strong> (<?= (int)$period['days'] ?> dias). Exibindo <?= count($unattributedRows) ?> transação(ões) encontradas.</p>
      </div>
    </div>

    <?php if (isset($_GET['manual_ok'])): ?>
      <div class="manual-alert ok">✓ Atribuição manual salva com sucesso! O vínculo foi gravado e será preservado nas próximas sincronizações.</div>
    <?php endif; ?>

    <?php if (!empty($_GET['manual_err'])): ?>
      <div class="manual-alert err">✕ Erro ao salvar atribuição: <?= vna_h((string)$_GET['manual_err']) ?></div>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="bi-table">
        <thead>
          <tr>
            <th>Data / Transação</th>
            <th>Comprador</th>
            <th>Produto</th>
            <th>Valores</th>
            <th>Atribuir ao Lead (Busca)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($unattributedRows as $sale): ?>
          <tr>
            <td>
              <strong><?= vna_h(date('d/m/Y H:i', strtotime((string)$sale['sale_date']))) ?></strong>
              <div class="subtext"><?= vna_h((string)$sale['transaction_code']) ?></div>
            </td>
            <td>
              <strong><?= vna_h((string)($sale['buyer_name'] ?: 'Nome não informado')) ?></strong>
              <div class="subtext"><?= vna_h((string)($sale['buyer_email'] ?? '')) ?></div>
              <div class="subtext"><?= vna_h((string)($sale['buyer_phone'] ?? '')) ?></div>
            </td>
            <td>
              <strong><?= vna_h((string)($sale['product_name'] ?: 'Sem produto')) ?></strong>
            </td>
            <td>
              <strong>Bruto: <?= vna_money($sale['gross_revenue']) ?></strong>
              <div class="subtext">Líquido Produtor: <?= vna_money($sale['producer_net']) ?></div>
            </td>
            <td>
              <form method="post" class="manual-attribution-form">
                <input type="hidden" name="acao" value="atribuir_venda_manual">
                <input type="hidden" name="csrf" value="<?= vna_h((string)$_SESSION['sales_csrf']) ?>">
                <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
                <input type="hidden" name="lead_id" value="">
                <input type="hidden" name="attribution_model" value="<?= vna_h($model) ?>">
                <input type="hidden" name="return_query" value="<?= vna_h($manualReturnQuery) ?>">
                <div class="lead-picker">
                  <input type="search" class="lead-search" placeholder="Buscar por Nome, E-mail, Telefone ou ID" autocomplete="off">
                  <div class="lead-results"></div>
                  <div class="lead-selected">Nenhum lead selecionado</div>
                </div>
                <div style="margin-top: 6px;">
                  <button class="btn btn-primary btn-sm" type="submit" disabled style="height: 30px; font-size: 11px; padding: 0 12px;">Confirmar Atribuição</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$unattributedRows): ?>
          <tr><td colspan="5" class="empty">🎉 Todas as vendas aprovadas do período estão devidamente atribuídas!</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
document.querySelectorAll('.manual-attribution-form').forEach(function(form) {
  var input = form.querySelector('.lead-search');
  var results = form.querySelector('.lead-results');
  var selected = form.querySelector('.lead-selected');
  var leadId = form.querySelector('input[name=lead_id]');
  var submit = form.querySelector('button[type=submit]');
  var timer;

  input.addEventListener('input', function() {
    clearTimeout(timer);
    leadId.value = '';
    submit.disabled = true;
    selected.textContent = 'Nenhum lead selecionado';
    var q = input.value.trim();
    if (q.length < 2) {
      results.style.display = 'none';
      return;
    }
    timer = setTimeout(async function() {
      try {
        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ajax', 'lead_search');
        url.searchParams.set('q', q);
        var response = await fetch(url, { headers: { Accept: 'application/json' } });
        var data = await response.json();
        results.innerHTML = '';
        (data.rows || []).forEach(function(lead) {
          var option = document.createElement('button');
          option.type = 'button';
          option.className = 'lead-option';
          option.textContent = (lead.lead_name || 'Sem nome') + ' · ' + (lead.lead_email || lead.lead_phone_raw || 'ID ' + lead.source_user_id) + ' · Turma ' + (lead.turma_codigo || '-');
          option.addEventListener('click', function() {
            leadId.value = lead.id;
            input.value = lead.lead_name || lead.lead_email || lead.source_user_id;
            selected.textContent = '✓ Selecionado: ' + option.textContent;
            submit.disabled = false;
            results.style.display = 'none';
          });
          results.appendChild(option);
        });
        results.style.display = (data.rows || []).length ? 'block' : 'none';
      } catch (e) {
        results.style.display = 'none';
      }
    }, 300);
  });

  form.addEventListener('submit', function(e) {
    if (!leadId.value) {
      e.preventDefault();
    }
  });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
