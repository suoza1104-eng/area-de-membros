<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
session_start();
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

$yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$ini = trim((string)($_GET['ini'] ?? $yesterday));
$fim = trim((string)($_GET['fim'] ?? $yesterday));
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
        ? "hs.status AS sale_status, hs.product_name, hs.price_name, hs.currency, hs.gross_revenue, hs.net_revenue, hs.producer_net,"
        : "NULL AS sale_status, NULL AS product_name, NULL AS price_name, NULL AS currency, NULL AS gross_revenue, NULL AS net_revenue, NULL AS producer_net,";

    $sql = "
        SELECT
            cla.id, cla.user_id, cla.turma_codigo, cla.offer_code, cla.transaction_code,
            cla.payload_json, cla.granted_at,
            u.nome AS aluno_nome, u.email AS aluno_email, u.telefone AS aluno_telefone,
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
            s.id, s.matched_user_id AS user_id, NULL AS turma_codigo, s.price_code AS offer_code,
            s.transaction_code, NULL AS payload_json, s.transaction_date AS granted_at,
            COALESCE(u.nome, s.buyer_name) AS aluno_nome,
            COALESCE(u.email, s.buyer_email) AS aluno_email,
            COALESCE(u.telefone, s.buyer_phone_norm) AS aluno_telefone,
            s.status AS sale_status, s.product_name, s.price_name, s.currency,
            s.gross_revenue, s.net_revenue, s.producer_net,
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
}
ksort($daily);
$dailyRows = array_values($daily);
$avgTicket = $totalSales > 0 ? $totalGross / $totalSales : 0.0;

require_once __DIR__ . '/_header.php';
?>

<style>
.vv-filter{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:end;margin-bottom:14px}
.vv-field label{display:block;font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:6px}
.vv-field input,.vv-field select{width:100%;height:40px;border:1px solid var(--border-light);border-radius:10px;background:var(--bg-card);color:var(--text);padding:0 10px}
.vv-btn{height:40px;border-radius:10px;background:var(--primary);color:#111827;font-weight:900;padding:0 14px}
.vv-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.vv-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px}
.vv-k{font-size:12px;color:var(--muted);font-weight:800;text-transform:uppercase}.vv-v{font-size:24px;font-weight:900;margin-top:4px}.vv-note{font-size:12px;color:var(--muted);margin-top:4px}
.vv-panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:14px}
.vv-table{width:100%;border-collapse:collapse}.vv-table th,.vv-table td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}.vv-table th{font-size:11px;color:var(--muted);text-transform:uppercase}.vv-money{font-weight:900;color:#bbf7d0}.vv-muted{font-size:12px;color:var(--muted)}.vv-empty{padding:20px;color:var(--muted);text-align:center}
.vv-alert{border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.09);color:#fde68a;border-radius:12px;padding:12px;margin-bottom:14px}
.vv-chart{height:280px}
@media(max-width:900px){.vv-filter,.vv-grid{grid-template-columns:1fr 1fr}.vv-filter .vv-wide{grid-column:1/-1}}@media(max-width:640px){.vv-filter,.vv-grid{grid-template-columns:1fr}.vv-table{min-width:860px}.vv-scroll{overflow:auto}}
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
</div>

<div class="vv-panel">
  <div class="vv-k">Vendas por data</div>
  <div class="vv-chart"><canvas id="vvChart"></canvas></div>
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
          <th>Transacao</th>
          <th>Origem</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="vv-empty">Nenhum registro encontrado.</td></tr>
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
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
