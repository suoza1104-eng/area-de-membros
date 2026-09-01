<?php
declare(strict_types=1);
$menu = 'alunos';
require_once __DIR__ . '/_header.php';

$pdo = getPDO();

// --- FILTROS E PARÂMETROS ---
$q           = trim($_GET['q'] ?? '');
$preset      = trim($_GET['period'] ?? '30');
$leadFilter  = trim($_GET['lead_filter'] ?? 'all');
$salesFilter = trim($_GET['sales_filter'] ?? 'all');
$reason      = trim($_GET['reason'] ?? 'all');
$page        = max(1, (int)($_GET['page'] ?? 1));
$export      = trim($_GET['export'] ?? '');
$perPage     = 50;

// Calcular datas do período
$today = date('Y-m-d');
$startDate = '';
$endDate = '';

switch ($preset) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        break;
    case '7':
        $startDate = date('Y-m-d', strtotime('-6 days'));
        $endDate = $today;
        break;
    case '30':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate = $today;
        break;
    case '90':
        $startDate = date('Y-m-d', strtotime('-89 days'));
        $endDate = $today;
        break;
    case '365':
        $startDate = date('Y-m-d', strtotime('-364 days'));
        $endDate = $today;
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'custom':
        $startDate = trim($_GET['from'] ?? date('Y-m-01'));
        $endDate   = trim($_GET['to'] ?? $today);
        break;
    default:
        $preset = 'all';
        $startDate = '';
        $endDate = '';
        break;
}

// 1. Carregar conjunto de leads (attribution_leads) em memória para cruzamento ultra-rápido
$leadRows = $pdo->query("SELECT LOWER(TRIM(lead_email)) AS email, turma_codigo, created_at FROM attribution_leads WHERE lead_email IS NOT NULL AND lead_email <> ''")->fetchAll(PDO::FETCH_ASSOC);
$leadMap = [];
foreach ($leadRows as $lr) {
    $e = strtolower(trim((string)$lr['email']));
    if ($e !== '' && !isset($leadMap[$e])) {
        $leadMap[$e] = [
            'turma' => $lr['turma_codigo'] ?: 'Sem Turma',
            'created_at' => $lr['created_at']
        ];
    }
}

// 2. Carregar conjunto de vendas (v_sales_master) em memória
$salesRows = $pdo->query("SELECT DISTINCT LOWER(TRIM(buyer_email)) AS email, provider FROM v_sales_master WHERE buyer_email IS NOT NULL AND buyer_email <> ''")->fetchAll(PDO::FETCH_ASSOC);
$salesMap = [];
foreach ($salesRows as $sr) {
    $e = strtolower(trim((string)$sr['email']));
    if ($e !== '') {
        $salesMap[$e] = $sr['provider'] ?: 'hotmart';
    }
}

// 3. Montar Cláusulas SQL de login_events
$whereClauses = ["(le.success = 0 OR le.user_id IS NULL OR le.user_id = 0)"];
$params = [];

if ($startDate !== '' && $endDate !== '') {
    $whereClauses[] = "le.logged_at BETWEEN :start_date AND :end_date";
    $params['start_date'] = $startDate . ' 00:00:00';
    $params['end_date']   = $endDate . ' 23:59:59';
}

if ($reason !== '' && $reason !== 'all') {
    $whereClauses[] = "le.failure_reason = :reason";
    $params['reason'] = $reason;
}

if ($q !== '') {
    $whereClauses[] = "(le.email LIKE :q OR le.ip LIKE :q OR le.failure_reason LIKE :q OR le.user_agent LIKE :q)";
    $params['q'] = '%' . $q . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// Buscar todos os registros do período para cruzamento e aplicação de filtros em memória (lead_filter, sales_filter)
$allSql = "SELECT le.id, le.user_id, le.logged_at, le.ip, le.user_agent, le.method, le.success, le.failure_reason, LOWER(TRIM(le.email)) AS email
           FROM login_events le
           {$whereSql}
           ORDER BY le.logged_at DESC, le.id DESC";

$stmtAll = $pdo->prepare($allSql);
$stmtAll->execute($params);
$rawEvents = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Cruzamento e Filtragem
$filteredEvents = [];
$uniqueEmails = [];
$inLeadCount = 0;
$inSalesCount = 0;
$noLeadCount = 0;

foreach ($rawEvents as $ev) {
    $email = strtolower(trim((string)($ev['email'] ?? '')));
    $hasLead  = isset($leadMap[$email]);
    $hasSale  = isset($salesMap[$email]);
    
    // Aplicar Filtros de Lead e Vendas
    if ($leadFilter === 'has_lead' && !$hasLead) continue;
    if ($leadFilter === 'no_lead' && $hasLead) continue;
    if ($salesFilter === 'has_sale' && !$hasSale) continue;
    if ($salesFilter === 'no_sale' && $hasSale) continue;

    $ev['lead_info']  = $hasLead ? $leadMap[$email] : null;
    $ev['sales_info'] = $hasSale ? $salesMap[$email] : null;

    $filteredEvents[] = $ev;

    if ($email !== '') {
        $uniqueEmails[$email] = true;
        if ($hasLead) $inLeadCount++;
        else $noLeadCount++;
        if ($hasSale) $inSalesCount++;
    }
}

// Exportação CSV
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="logins_nao_identificados_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID Evento', 'Data e Hora', 'E-mail Digitado', 'Motivo da Falha',
        'Status em Leads', 'Turma Lead', 'Status em Vendas', 'IP Origem', 'Método', 'User Agent'
    ]);

    foreach ($filteredEvents as $r) {
        $leadStr = $r['lead_info'] ? 'SIM (Em Leads)' : 'NÃO (Fora de Leads)';
        $turmaStr = $r['lead_info'] ? ($r['lead_info']['turma'] ?? '-') : '-';
        $saleStr = $r['sales_info'] ? 'SIM (' . strtoupper((string)$r['sales_info']) . ')' : 'NÃO';

        fputcsv($output, [
            $r['id'],
            $r['logged_at'],
            $r['email'],
            $r['failure_reason'] ?: 'user_not_found',
            $leadStr,
            $turmaStr,
            $saleStr,
            $r['ip'],
            $r['method'],
            $r['user_agent']
        ]);
    }
    fclose($output);
    exit;
}

// Padrão de Paginação
$totalRecords = count($filteredEvents);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$offset       = ($page - 1) * $perPage;
$pagedEvents  = array_slice($filteredEvents, $offset, $perPage);

function lni_reason_badge(?string $reason): string {
    $r = strtolower(trim((string)$reason));
    switch ($r) {
        case 'user_not_found':
            return '<span class="reason-badge r-not-found"><i class="ph ph-user-minus"></i> Usuário Não Cadastrado</span>';
        case 'invalid_password':
            return '<span class="reason-badge r-password"><i class="ph ph-key-return"></i> Senha Incorreta</span>';
        case 'blocked_user':
            return '<span class="reason-badge r-blocked"><i class="ph ph-prohibit"></i> Usuário Bloqueado</span>';
        default:
            return '<span class="reason-badge r-default">' . htmlspecialchars($reason ?: 'Não identificado') . '</span>';
    }
}
?>

<style>
.lni-container { display: flex; flex-direction: column; gap: 18px; font-family: system-ui, -apple-system, sans-serif; color: var(--text); }
.lni-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; background: var(--bg-card); border: 1px solid var(--border); padding: 18px 20px; border-radius: 12px; }
.lni-title h1 { font-size: 22px; margin: 0; font-weight: 750; color: #f8fafc; }
.lni-title p { margin: 4px 0 0; font-size: 13px; color: var(--muted); }
.lni-nav-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 10px; color: #60a5fa; font-weight: 650; font-size: 13px; text-decoration: none; transition: all .15s ease; }
.lni-nav-btn:hover { background: rgba(59,130,246,0.25); border-color: rgba(59,130,246,0.5); color: #93c5fd; }

.lni-cards { display: grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 12px; }
.lni-card { background: var(--bg-card); border: 1px solid var(--border); padding: 14px 16px; border-radius: 10px; position: relative; overflow: hidden; }
.lni-card small { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); display: block; }
.lni-card strong { font-size: 22px; font-weight: 800; margin-top: 6px; display: block; color: #f1f5f9; }
.lni-card.lead-ok strong { color: #4ade80; }
.lni-card.alert-card strong { color: #fb923c; }

.lni-filter-box { background: var(--bg-card); border: 1px solid var(--border); padding: 16px; border-radius: 12px; }
.lni-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.lni-presets a { padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 12px; font-weight: 600; text-decoration: none; }
.lni-presets a.active, .lni-presets a:hover { background: rgba(250,204,21,0.15); border-color: rgba(250,204,21,0.4); color: #facc15; }

.lni-grid { display: grid; grid-template-columns: 2fr 1.2fr 1fr 1fr auto; gap: 10px; align-items: end; }
@media (max-width: 1100px) { .lni-grid { grid-template-columns: 1fr 1fr 1fr; } }
@media (max-width: 700px) { .lni-grid { grid-template-columns: 1fr; } .lni-cards { grid-template-columns: 1fr 1fr; } }

.lni-fg label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 5px; }
.lni-fg input, .lni-fg select { width: 100%; height: 38px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: #f1f5f9; padding: 0 10px; font-size: 12px; }
.lni-actions { display: flex; gap: 8px; }
.lni-btn { height: 38px; padding: 0 16px; border-radius: 8px; border: 0; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
.lni-btn-primary { background: #3b82f6; color: #fff; }
.lni-btn-primary:hover { background: #2563eb; }
.lni-btn-csv { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
.lni-btn-csv:hover { background: rgba(34,197,94,0.3); }

.lni-table-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; overflow: auto; }
.lni-table { width: 100%; border-collapse: collapse; min-width: 1050px; font-size: 12px; }
.lni-table th { background: #0f172a; color: var(--muted); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; padding: 12px; text-align: left; border-bottom: 1px solid var(--border); position: sticky; top: 0; }
.lni-table td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
.lni-table tr:hover td { background: rgba(255,255,255,0.02); }

.reason-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 750; }
.r-not-found { background: rgba(249,115,22,0.18); color: #fb923c; border: 1px solid rgba(249,115,22,0.3); }
.r-password { background: rgba(234,179,8,0.18); color: #facc15; border: 1px solid rgba(234,179,8,0.3); }
.r-blocked { background: rgba(239,68,68,0.18); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.r-default { background: var(--bg-hover); color: var(--text); }

.lead-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 11px; font-weight: 650; }
.lb-yes { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
.lb-no { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }

.sales-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 11px; font-weight: 650; }
.sb-yes { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.sb-no { background: rgba(148,163,184,0.12); color: #94a3b8; border: 1px solid rgba(148,163,184,0.25); }

.lni-paging { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-top: 1px solid var(--border); color: var(--muted); font-size: 12px; }
.lni-page-links { display: flex; gap: 6px; }
.lni-page-links a, .lni-page-links span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 7px; text-decoration: none; color: var(--text); }
.lni-page-links .active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
</style>

<div class="lni-container">
  
  <!-- CABEÇALHO COM NAVEGAÇÃO -->
  <div class="lni-head">
    <div class="lni-title">
      <h1>🚨 Tentativas de Login Não Identificadas</h1>
      <p>Lista de e-mails que tentaram acessar a área de membros sem cadastro de usuário atrelado.</p>
    </div>
    <a href="alunos.php" class="lni-nav-btn">
      <i class="ph ph-arrow-left"></i> ⬅️ Voltar para a Lista de Alunos
    </a>
  </div>

  <!-- 1. FORMULÁRIO DE FILTROS (NO TOPO) -->
  <div class="lni-filter-box">
    <div class="lni-presets">
      <?php 
      $presetOptions = [
          'all' => 'Todas as Datas',
          'today' => 'Hoje',
          '7' => '7 Dias',
          '30' => '30 Dias',
          '90' => '90 Dias',
          '365' => '365 Dias',
          'month' => 'Mês Atual',
          'custom' => 'Personalizado'
      ];
      foreach ($presetOptions as $k => $lbl): 
      ?>
        <a class="<?= $preset === $k ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['period' => $k, 'page' => 1]))) ?>">
          <?= htmlspecialchars($lbl) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="lni-grid">
      <input type="hidden" name="period" value="<?= htmlspecialchars($preset) ?>">
      
      <div class="lni-fg">
        <label>Buscar E-mail / IP / Dispositivo</label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="E-mail digitado ou IP...">
      </div>

      <div class="lni-fg">
        <label>Motivo do Erro</label>
        <select name="reason">
          <option value="all" <?= $reason === 'all' ? 'selected' : '' ?>>Todos os Motivos</option>
          <option value="user_not_found" <?= $reason === 'user_not_found' ? 'selected' : '' ?>>Usuário Não Cadastrado</option>
          <option value="invalid_password" <?= $reason === 'invalid_password' ? 'selected' : '' ?>>Senha Incorreta</option>
          <option value="blocked_user" <?= $reason === 'blocked_user' ? 'selected' : '' ?>>Usuário Bloqueado</option>
        </select>
      </div>

      <div class="lni-fg">
        <label>Status em Leads</label>
        <select name="lead_filter">
          <option value="all" <?= $leadFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="has_lead" <?= $leadFilter === 'has_lead' ? 'selected' : '' ?>>Presente em Leads</option>
          <option value="no_lead" <?= $leadFilter === 'no_lead' ? 'selected' : '' ?>>Fora da Lista de Leads</option>
        </select>
      </div>

      <div class="lni-fg">
        <label>Status em Vendas</label>
        <select name="sales_filter">
          <option value="all" <?= $salesFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="has_sale" <?= $salesFilter === 'has_sale' ? 'selected' : '' ?>>Possui Compra Registrada</option>
          <option value="no_sale" <?= $salesFilter === 'no_sale' ? 'selected' : '' ?>>Sem Compra Registrada</option>
        </select>
      </div>

      <?php if ($preset === 'custom'): ?>
        <div class="lni-fg">
          <label>Data Início</label>
          <input type="date" name="from" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="lni-fg">
          <label>Data Fim</label>
          <input type="date" name="to" value="<?= htmlspecialchars($endDate) ?>">
        </div>
      <?php endif; ?>

      <div class="lni-actions">
        <button type="submit" class="lni-btn lni-btn-primary">
          <i class="ph ph-magnifying-glass"></i> Filtrar
        </button>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="lni-btn lni-btn-csv">
          <i class="ph ph-download-simple"></i> Exportar CSV
        </a>
      </div>
    </form>
  </div>

  <!-- 2. CARDS DE RESUMO (ABAIXO DOS FILTROS) -->
  <div class="lni-cards">
    <div class="lni-card">
      <small>Tentativas Auditadas</small>
      <strong><?= number_format($totalRecords, 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card">
      <small>E-mails Únicos Tentando Acesso</small>
      <strong><?= number_format(count($uniqueEmails), 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card lead-ok">
      <small>Presentes na Captura de Leads</small>
      <strong><?= number_format($inLeadCount, 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card alert-card">
      <small>Fora da Captura de Leads</small>
      <strong><?= number_format($noLeadCount, 0, ',', '.') ?></strong>
    </div>
  </div>

  <!-- 3. TABELA DE TENTATIVAS DE LOGIN -->
  <div class="lni-table-wrap">
    <table class="lni-table">
      <thead>
        <tr>
          <th>Data / Hora</th>
          <th>E-mail Digitado</th>
          <th>Motivo do Erro</th>
          <th>Status em Leads</th>
          <th>Status em Vendas</th>
          <th>IP & Método</th>
          <th>Navegador / Dispositivo</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pagedEvents)): ?>
          <tr>
            <td colspan="7" style="text-align:center; padding: 40px; color: var(--muted);">
              Nenhuma tentativa de login não identificada encontrada para os filtros aplicados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($pagedEvents as $r): ?>
            <tr>
              <td>
                <strong style="color: #f1f5f9; font-size: 12px; display:block;">
                  <?= date('d/m/Y H:i:s', strtotime((string)$r['logged_at'])) ?>
                </strong>
              </td>
              <td>
                <strong style="color: #93c5fd; font-size: 13px;">
                  <?= htmlspecialchars((string)($r['email'] ?: 'Não informado')) ?>
                </strong>
              </td>
              <td>
                <?= lni_reason_badge((string)$r['failure_reason']) ?>
              </td>
              <td>
                <?php if ($r['lead_info']): ?>
                  <span class="lead-badge lb-yes">
                    <i class="ph ph-check"></i> Lead (Turma <?= htmlspecialchars((string)$r['lead_info']['turma']) ?>)
                  </span>
                <?php else: ?>
                  <span class="lead-badge lb-no">
                    <i class="ph ph-x"></i> Fora da Lista
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['sales_info']): ?>
                  <span class="sales-badge sb-yes">
                    <i class="ph ph-shopping-cart-simple"></i> Comprador (<?= htmlspecialchars(strtoupper((string)$r['sales_info'])) ?>)
                  </span>
                <?php else: ?>
                  <span class="sales-badge sb-no">
                    <i class="ph ph-minus"></i> Sem Compra
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-family: monospace; font-size: 11px; color: #cbd5e1;">
                  <?= htmlspecialchars((string)$r['ip']) ?>
                </div>
                <div style="font-size: 10px; color: var(--muted); margin-top: 2px;">
                  <?= htmlspecialchars((string)($r['method'] ?: 'normal')) ?>
                </div>
              </td>
              <td>
                <div style="font-size: 10px; color: var(--muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars((string)$r['user_agent']) ?>">
                  <?= htmlspecialchars((string)$r['user_agent']) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPages > 1): ?>
      <div class="lni-paging">
        <div>
          Exibindo página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (Total: <?= number_format($totalRecords, 0, ',', '.') ?> tentativas)
        </div>
        <div class="lni-page-links">
          <?php if ($page > 1): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Anterior</a>
          <?php endif; ?>
          
          <?php
          $startP = max(1, $page - 2);
          $endP   = min($totalPages, $page + 2);
          for ($i = $startP; $i <= $endP; $i++):
          ?>
            <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Próxima</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
