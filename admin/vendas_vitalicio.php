<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
proteger_admin();

$menu = 'vendas_vitalicio';
$page_title = 'Vendas Vitalicio';

$pdo = getPDO();
course_access_ensure_schema($pdo);

function vv_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vv_money(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function vv_date_br(?string $value): string {
    if (!$value) return '-';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
}

function vv_duration(?int $seconds): string {
    if ($seconds === null || $seconds < 0) return '-';
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    if ($days > 0) return $days . 'd ' . $hours . 'h';
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? $hours . 'h ' . $minutes . 'min' : $minutes . 'min';
}

function vv_table_exists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function vv_payload_value(array $payload, array $paths): ?float {
    foreach ($paths as $path) {
        $cur = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                $cur = null;
                break;
            }
            $cur = $cur[$part];
        }
        if (is_numeric($cur)) return (float)$cur;
        if (is_string($cur)) {
            $normalized = str_replace(['R$', ' '], '', $cur);
            if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '.', $normalized);
            }
            if (is_numeric($normalized)) return (float)$normalized;
        }
    }
    return null;
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('-29 days')->format('Y-m-d');
$ini = trim((string)($_GET['ini'] ?? $defaultStart));
$fim = trim((string)($_GET['fim'] ?? $today->format('Y-m-d')));
$mode = (string)($_GET['mode'] ?? 'lifetime');
$q = trim((string)($_GET['q'] ?? ''));
if (!in_array($mode, ['lifetime', 'hotmart'], true)) $mode = 'lifetime';

$rows = [];
$daily = [];
$totalSales = 0;
$totalGross = 0.0;
$totalNet = 0.0;
$hotmartReady = vv_table_exists($pdo, 'hotmart_sales');

if ($mode === 'lifetime') {
    $params = [
        ':ini' => $ini . ' 00:00:00',
        ':fim' => $fim . ' 23:59:59',
    ];
    $where = ["cla.granted_at BETWEEN :ini AND :fim"];
    if ($q !== '') {
        $where[] = "(cla.offer_code LIKE :q OR cla.transaction_code LIKE :q OR cla.turma_codigo LIKE :q OR u.nome LIKE :q OR u.email LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $joinHotmart = $hotmartReady ? "LEFT JOIN hotmart_sales hs ON hs.transaction_code = cla.transaction_code" : "";
    $selectHotmart = $hotmartReady
        ? "hs.status AS sale_status, hs.product_name, hs.price_name, hs.currency, hs.gross_revenue, hs.net_revenue, hs.producer_net, hs.transaction_date AS sale_at,"
        : "NULL AS sale_status, NULL AS product_name, NULL AS price_name, NULL AS currency, NULL AS gross_revenue, NULL AS net_revenue, NULL AS producer_net, NULL AS sale_at,";

    $sql = "
        SELECT
            cla.id, cla.user_id, COALESCE(NULLIF(cla.turma_codigo,''),u.codigo_turma) AS turma_codigo, cla.offer_code, cla.transaction_code,
            cla.payload_json, cla.granted_at,
            u.nome AS aluno_nome, u.email AS aluno_email, u.telefone AS aluno_telefone,
            u.created_at AS lead_created_at,
            u.utm_source, u.utm_medium, u.utm_campaign, u.utm_term, u.utm_content,
            $selectHotmart
            'course_lifetime_access' AS origem
        FROM course_lifetime_access cla
        LEFT JOIN users u ON u.id = cla.user_id
        $joinHotmart
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cla.granted_at DESC, cla.id DESC
        LIMIT 1000
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $gross = isset($row['gross_revenue']) ? (float)$row['gross_revenue'] : 0.0;
        $net = isset($row['producer_net']) ? (float)$row['producer_net'] : 0.0;
        if ($gross <= 0 && !empty($row['payload_json'])) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (is_array($payload)) {
                $payloadGross = vv_payload_value($payload, [
                    'data.purchase.price.value',
                    'data.purchase.full_price.value',
                    'data.purchase.original_offer_price.value',
                    'data.purchase.offer.price.value',
                    'purchase.price.value',
                    'price',
                    'valor',
                ]);
                if ($payloadGross !== null) $gross = $payloadGross;
            }
        }
        $row['dashboard_gross'] = $gross;
        $row['dashboard_net'] = $net;
    }
    unset($row);
} else {
    $params = [
        ':ini' => $ini . ' 00:00:00',
        ':fim' => $fim . ' 23:59:59',
    ];
    $where = [
        "s.transaction_date BETWEEN :ini AND :fim",
        "s.status IN ('Aprovado','Completo','APPROVED','COMPLETE','PAID')",
    ];
    if ($q !== '') {
        $where[] = "(s.product_name LIKE :q OR s.price_name LIKE :q OR s.transaction_code LIKE :q OR s.buyer_name LIKE :q OR s.buyer_email LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    } else {
        $where[] = "(LOWER(COALESCE(s.product_name,'')) LIKE '%vital%' OR LOWER(COALESCE(s.price_name,'')) LIKE '%vital%' OR LOWER(COALESCE(s.product_name,'')) LIKE '%desbloq%' OR LOWER(COALESCE(s.price_name,'')) LIKE '%desbloq%')";
    }

    $sql = "
        SELECT
            s.id, s.matched_user_id AS user_id, u.codigo_turma AS turma_codigo, s.price_code AS offer_code,
            s.transaction_code, NULL AS payload_json, s.transaction_date AS granted_at, s.transaction_date AS sale_at,
            COALESCE(u.nome, s.buyer_name) AS aluno_nome,
            COALESCE(u.email, s.buyer_email) AS aluno_email,
            COALESCE(u.telefone, s.buyer_phone_norm) AS aluno_telefone,
            u.created_at AS lead_created_at,
            s.status AS sale_status, s.product_name, s.price_name, s.currency,
            s.gross_revenue, s.net_revenue, s.producer_net,
            COALESCE(NULLIF(s.utm_source,''),u.utm_source) AS utm_source,
            COALESCE(NULLIF(s.utm_medium,''),u.utm_medium) AS utm_medium,
            COALESCE(NULLIF(s.utm_campaign,''),u.utm_campaign) AS utm_campaign,
            COALESCE(NULLIF(s.utm_term,''),u.utm_term) AS utm_term,
            COALESCE(NULLIF(s.utm_content,''),u.utm_content) AS utm_content,
            'hotmart_sales' AS origem
        FROM hotmart_sales s
        LEFT JOIN users u ON u.id = s.matched_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.transaction_date DESC, s.id DESC
        LIMIT 1000
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['dashboard_gross'] = (float)($row['gross_revenue'] ?? 0);
        $row['dashboard_net'] = (float)($row['producer_net'] ?? 0);
    }
    unset($row);
}

$turmaStats = [];
$conversionTimes = [];
$conversionBuckets = [
    'Até 1 dia' => 0,
    '2 a 3 dias' => 0,
    '4 a 7 dias' => 0,
    '8 a 14 dias' => 0,
    '15 a 30 dias' => 0,
    'Mais de 30 dias' => 0,
];
foreach ($rows as &$row) {
    $leadTs = !empty($row['lead_created_at']) ? strtotime((string)$row['lead_created_at']) : false;
    $saleTs = !empty($row['sale_at']) ? strtotime((string)$row['sale_at']) : strtotime((string)($row['granted_at'] ?? ''));
    $row['conversion_seconds'] = ($leadTs && $saleTs && $saleTs >= $leadTs) ? ($saleTs - $leadTs) : null;
}
unset($row);

foreach ($rows as $row) {
    $totalSales++;
    $gross = (float)($row['dashboard_gross'] ?? 0);
    $net = (float)($row['dashboard_net'] ?? 0);
    $totalGross += $gross;
    $totalNet += $net;
    $day = substr((string)($row['granted_at'] ?? ''), 0, 10) ?: 'Sem data';
    if (!isset($daily[$day])) $daily[$day] = ['date' => $day, 'qtd' => 0, 'gross' => 0.0, 'net' => 0.0];
    $daily[$day]['qtd']++;
    $daily[$day]['gross'] += $gross;
    $daily[$day]['net'] += $net;

    $turma = trim((string)($row['turma_codigo'] ?? '')) ?: 'Sem turma';
    if (!isset($turmaStats[$turma])) $turmaStats[$turma] = ['turma' => $turma, 'qtd' => 0, 'gross' => 0.0, 'net' => 0.0, 'tempo_total' => 0, 'tempo_qtd' => 0];
    $turmaStats[$turma]['qtd']++;
    $turmaStats[$turma]['gross'] += $gross;
    $turmaStats[$turma]['net'] += $net;

    if ($row['conversion_seconds'] !== null) {
        $seconds = (int)$row['conversion_seconds'];
        $conversionTimes[] = $seconds;
        $turmaStats[$turma]['tempo_total'] += $seconds;
        $turmaStats[$turma]['tempo_qtd']++;
        $daysToBuy = $seconds / 86400;
        if ($daysToBuy <= 1) $conversionBuckets['Até 1 dia']++;
        elseif ($daysToBuy <= 3) $conversionBuckets['2 a 3 dias']++;
        elseif ($daysToBuy <= 7) $conversionBuckets['4 a 7 dias']++;
        elseif ($daysToBuy <= 14) $conversionBuckets['8 a 14 dias']++;
        elseif ($daysToBuy <= 30) $conversionBuckets['15 a 30 dias']++;
        else $conversionBuckets['Mais de 30 dias']++;
    }
}
ksort($daily);
$dailyRows = array_values($daily);
$turmaRows = array_values($turmaStats);
usort($turmaRows, static fn(array $a, array $b): int => $b['qtd'] <=> $a['qtd']);
$turmaRows = array_slice($turmaRows, 0, 20);
$avgTicket = $totalSales > 0 ? $totalGross / $totalSales : 0.0;
$avgConversionSeconds = $conversionTimes ? (int)round(array_sum($conversionTimes) / count($conversionTimes)) : null;
$medianConversionSeconds = null;
if ($conversionTimes) {
    sort($conversionTimes);
    $middle = intdiv(count($conversionTimes), 2);
    $medianConversionSeconds = count($conversionTimes) % 2
        ? $conversionTimes[$middle]
        : (int)round(($conversionTimes[$middle - 1] + $conversionTimes[$middle]) / 2);
}
$journeyCoverage = $totalSales > 0 ? count($conversionTimes) / $totalSales * 100 : 0.0;
foreach ($turmaRows as &$turmaRow) {
    $turmaRow['tempo_medio'] = $turmaRow['tempo_qtd'] > 0 ? (int)round($turmaRow['tempo_total'] / $turmaRow['tempo_qtd']) : null;
}
unset($turmaRow);

require_once __DIR__ . '/_header.php';
?>

<style>
.vv-filter{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:end;margin-bottom:14px}
.vv-field label{display:block;font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:6px}
.vv-field input,.vv-field select{width:100%;height:40px;border:1px solid var(--border-light);border-radius:10px;background:var(--bg-card);color:var(--text);padding:0 10px}
.vv-btn{height:40px;border-radius:10px;background:var(--primary);color:#111827;font-weight:900;padding:0 14px}
.vv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px}
.vv-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px}
.vv-k{font-size:12px;color:var(--muted);font-weight:800;text-transform:uppercase}.vv-v{font-size:24px;font-weight:900;margin-top:4px}.vv-note{font-size:12px;color:var(--muted);margin-top:4px}
.vv-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:14px}
.vv-table{width:100%;border-collapse:collapse}.vv-table th,.vv-table td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}.vv-table th{font-size:11px;color:var(--muted);text-transform:uppercase}.vv-money{font-weight:900;color:#bbf7d0}.vv-muted{font-size:12px;color:var(--muted)}.vv-empty{padding:20px;color:var(--muted);text-align:center}
.vv-alert{border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.09);color:#fde68a;border-radius:12px;padding:12px;margin-bottom:14px}
.vv-chart{height:280px}
.vv-chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.vv-panel-title{margin-bottom:10px}.vv-journey{color:#bfdbfe;font-weight:800}.vv-utm{max-width:260px;overflow-wrap:anywhere}
@media(max-width:900px){.vv-filter{grid-template-columns:1fr 1fr}.vv-filter .vv-wide{grid-column:1/-1}.vv-chart-grid{grid-template-columns:1fr}}@media(max-width:640px){.vv-filter{grid-template-columns:1fr}.vv-table{min-width:1200px}.vv-scroll{overflow:auto}.vv-chart{height:240px}}
</style>

<form class="vv-filter" method="get">
  <div class="vv-field">
    <label>Inicio</label>
    <input type="date" name="ini" value="<?= vv_h($ini) ?>">
  </div>
  <div class="vv-field">
    <label>Fim</label>
    <input type="date" name="fim" value="<?= vv_h($fim) ?>">
  </div>
  <div class="vv-field">
    <label>Fonte</label>
    <select name="mode">
      <option value="lifetime" <?= $mode === 'lifetime' ? 'selected' : '' ?>>Desbloqueios vitalicios</option>
      <option value="hotmart" <?= $mode === 'hotmart' ? 'selected' : '' ?>>Hotmart por produto/preco</option>
    </select>
  </div>
  <div class="vv-field vv-wide">
    <label>Busca</label>
    <input type="text" name="q" value="<?= vv_h($q) ?>" placeholder="Produto, preco, oferta, transacao, aluno">
  </div>
  <button class="vv-btn" type="submit">Filtrar</button>
</form>

<?php if ($mode === 'lifetime' && $totalSales === 0): ?>
  <div class="vv-alert">
    Nenhum desbloqueio vitalicio foi encontrado em <strong><?= vv_h(date('d/m/Y', strtotime($ini))) ?></strong> ate <strong><?= vv_h(date('d/m/Y', strtotime($fim))) ?></strong>.
    Se a venda entrou apenas na Hotmart, altere a fonte para <strong>Hotmart por produto/preco</strong> e use a busca.
  </div>
<?php endif; ?>

<div class="vv-grid">
  <div class="vv-card"><div class="vv-k">Vendas</div><div class="vv-v"><?= (int)$totalSales ?></div><div class="vv-note">Registros no filtro atual</div></div>
  <div class="vv-card"><div class="vv-k">Valor bruto</div><div class="vv-v"><?= vv_money($totalGross) ?></div><div class="vv-note">Soma de venda</div></div>
  <div class="vv-card"><div class="vv-k">Liquido produtor</div><div class="vv-v"><?= vv_money($totalNet) ?></div><div class="vv-note">Quando houver Hotmart importada</div></div>
  <div class="vv-card"><div class="vv-k">Ticket medio</div><div class="vv-v"><?= vv_money($avgTicket) ?></div><div class="vv-note">Bruto / vendas</div></div>
  <div class="vv-card"><div class="vv-k">Tempo médio até comprar</div><div class="vv-v"><?= vv_h(vv_duration($avgConversionSeconds)) ?></div><div class="vv-note">Da inscrição à compra vitalícia</div></div>
  <div class="vv-card"><div class="vv-k">Mediana até comprar</div><div class="vv-v"><?= vv_h(vv_duration($medianConversionSeconds)) ?></div><div class="vv-note">Menos sensível a compras tardias</div></div>
  <div class="vv-card"><div class="vv-k">Jornadas identificadas</div><div class="vv-v"><?= number_format($journeyCoverage, 1, ',', '.') ?>%</div><div class="vv-note"><?= count($conversionTimes) ?> de <?= (int)$totalSales ?> vendas ligadas à inscrição</div></div>
</div>

<div class="vv-chart-grid">
  <div class="vv-panel" style="margin-bottom:0">
    <div class="vv-k vv-panel-title">Vendas por dia</div>
    <div class="vv-chart"><canvas id="vvChart"></canvas></div>
  </div>
  <div class="vv-panel" style="margin-bottom:0">
    <div class="vv-k vv-panel-title">Vendas por turma</div>
    <div class="vv-chart"><canvas id="vvTurmaChart"></canvas></div>
  </div>
</div>

<div class="vv-chart-grid">
  <div class="vv-panel" style="margin-bottom:0">
    <div class="vv-k vv-panel-title">Tempo entre inscrição e compra</div>
    <div class="vv-chart"><canvas id="vvJourneyChart"></canvas></div>
  </div>
  <div class="vv-panel" style="margin-bottom:0">
    <div class="vv-k vv-panel-title">Desempenho por turma</div>
    <div class="vv-scroll">
      <table class="vv-table">
        <thead><tr><th>Turma</th><th>Vendas</th><th>Bruto</th><th>Líquido</th><th>Tempo médio</th></tr></thead>
        <tbody>
        <?php foreach ($turmaRows as $turmaRow): ?>
          <tr><td><strong><?= vv_h((string)$turmaRow['turma']) ?></strong></td><td><?= (int)$turmaRow['qtd'] ?></td><td><?= vv_money((float)$turmaRow['gross']) ?></td><td><?= vv_money((float)$turmaRow['net']) ?></td><td><?= vv_h(vv_duration($turmaRow['tempo_medio'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$turmaRows): ?><tr><td colspan="5" class="vv-empty">Sem dados por turma.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="vv-panel">
  <div class="vv-k" style="margin-bottom:10px">Detalhes</div>
  <div class="vv-scroll">
    <table class="vv-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Aluno</th>
          <th>Produto / Oferta</th>
          <th>Valor</th>
          <th>Status</th>
          <th>Jornada</th>
          <th>UTMs</th>
          <th>Transacao</th>
          <th>Origem</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="vv-empty">Nenhum registro encontrado.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= vv_h(vv_date_br((string)($row['granted_at'] ?? ''))) ?></td>
          <td>
            <strong><?= vv_h((string)($row['aluno_nome'] ?? '-')) ?></strong>
            <div class="vv-muted"><?= vv_h((string)($row['aluno_email'] ?? '')) ?></div>
            <div class="vv-muted"><?= vv_h((string)($row['aluno_telefone'] ?? '')) ?></div>
          </td>
          <td>
            <strong><?= vv_h((string)($row['product_name'] ?: 'Acesso vitalicio')) ?></strong>
            <div class="vv-muted"><?= vv_h((string)($row['price_name'] ?: $row['offer_code'] ?: '-')) ?></div>
            <div class="vv-muted">Turma: <?= vv_h((string)($row['turma_codigo'] ?? '-')) ?></div>
          </td>
          <td>
            <div class="vv-money"><?= vv_money((float)($row['dashboard_gross'] ?? 0)) ?></div>
            <div class="vv-muted">Liquido: <?= vv_money((float)($row['dashboard_net'] ?? 0)) ?></div>
          </td>
          <td><?= vv_h((string)($row['sale_status'] ?: 'Liberado')) ?></td>
          <td>
            <div class="vv-journey"><?= vv_h(vv_duration($row['conversion_seconds'])) ?></div>
            <div class="vv-muted">Inscrição: <?= vv_h(vv_date_br((string)($row['lead_created_at'] ?? ''))) ?></div>
          </td>
          <td class="vv-utm">
            <strong><?= vv_h((string)($row['utm_source'] ?: 'Orgânico/não informado')) ?></strong>
            <div class="vv-muted">Medium: <?= vv_h((string)($row['utm_medium'] ?: '-')) ?></div>
            <div class="vv-muted">Campaign: <?= vv_h((string)($row['utm_campaign'] ?: '-')) ?></div>
            <div class="vv-muted">Term: <?= vv_h((string)($row['utm_term'] ?: '-')) ?></div>
            <div class="vv-muted">Content: <?= vv_h((string)($row['utm_content'] ?: '-')) ?></div>
          </td>
          <td><strong><?= vv_h((string)($row['transaction_code'] ?? '-')) ?></strong></td>
          <td><?= vv_h((string)($row['origem'] ?? '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const vvLabels = <?= json_encode(array_column($dailyRows, 'date'), JSON_UNESCAPED_UNICODE) ?>;
const vvQtd = <?= json_encode(array_map(static fn($r) => (int)$r['qtd'], $dailyRows)) ?>;
const vvGross = <?= json_encode(array_map(static fn($r) => round((float)$r['gross'], 2), $dailyRows)) ?>;
const vvTurmas = <?= json_encode(array_column($turmaRows, 'turma'), JSON_UNESCAPED_UNICODE) ?>;
const vvTurmaQtd = <?= json_encode(array_map(static fn($r) => (int)$r['qtd'], $turmaRows)) ?>;
const vvTurmaGross = <?= json_encode(array_map(static fn($r) => round((float)$r['gross'], 2), $turmaRows)) ?>;
const vvJourneyLabels = <?= json_encode(array_keys($conversionBuckets), JSON_UNESCAPED_UNICODE) ?>;
const vvJourneyValues = <?= json_encode(array_values($conversionBuckets)) ?>;
const vvFmt = (n) => new Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'}).format(n || 0);
new Chart(document.getElementById('vvChart'), {
  data: {
    labels: vvLabels,
    datasets: [
      {type:'bar', label:'Valor bruto', data:vvGross, backgroundColor:'rgba(34,197,94,.58)', borderRadius:4, yAxisID:'money'},
      {type:'line', label:'Vendas', data:vvQtd, borderColor:'#38bdf8', backgroundColor:'#38bdf8', tension:.3, yAxisID:'count'}
    ]
  },
  options: {
    responsive:true,
    maintainAspectRatio:false,
    plugins:{legend:{labels:{color:'#94a3b8'}}, tooltip:{callbacks:{label:(c)=> c.dataset.yAxisID === 'money' ? c.dataset.label + ': ' + vvFmt(c.parsed.y) : c.dataset.label + ': ' + c.parsed.y}}},
    scales:{
      x:{ticks:{color:'#64748b'}, grid:{color:'rgba(26,37,64,.55)'}},
      money:{beginAtZero:true, position:'left', ticks:{color:'#64748b', callback:(v)=>vvFmt(v)}, grid:{color:'rgba(26,37,64,.55)'}},
      count:{beginAtZero:true, position:'right', ticks:{color:'#64748b'}, grid:{drawOnChartArea:false}}
    }
  }
});
new Chart(document.getElementById('vvTurmaChart'), {
  data:{labels:vvTurmas,datasets:[
    {type:'bar',label:'Vendas',data:vvTurmaQtd,backgroundColor:'rgba(56,189,248,.62)',borderRadius:4,xAxisID:'count'},
    {type:'line',label:'Valor bruto',data:vvTurmaGross,borderColor:'#22c55e',backgroundColor:'#22c55e',tension:.25,xAxisID:'money'}
  ]},
  options:{
    responsive:true,maintainAspectRatio:false,indexAxis:'y',
    plugins:{legend:{labels:{color:'#94a3b8'}},tooltip:{callbacks:{label:(c)=>c.dataset.xAxisID==='money'?c.dataset.label+': '+vvFmt(c.parsed.x):c.dataset.label+': '+c.parsed.x}}},
    scales:{y:{ticks:{color:'#94a3b8'},grid:{display:false}},count:{beginAtZero:true,position:'bottom',ticks:{color:'#64748b'},grid:{color:'rgba(26,37,64,.55)'}},money:{beginAtZero:true,position:'top',ticks:{color:'#22c55e',callback:(v)=>vvFmt(v)},grid:{drawOnChartArea:false}}}
  }
});
new Chart(document.getElementById('vvJourneyChart'), {
  type:'bar',
  data:{labels:vvJourneyLabels,datasets:[{label:'Compras',data:vvJourneyValues,backgroundColor:['#38bdf8','#60a5fa','#818cf8','#a78bfa','#c084fc','#e879f9'],borderRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#94a3b8',maxRotation:25,minRotation:0},grid:{display:false}},y:{beginAtZero:true,ticks:{color:'#64748b',precision:0},grid:{color:'rgba(26,37,64,.55)'}}}}
});
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
