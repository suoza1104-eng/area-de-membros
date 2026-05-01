<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

/**
 * Descobre se uma coluna existe na tabela.
 */
function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $col]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Lista colunas existentes da tabela users.
 */
function users_columns(PDO $pdo): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r)=>$r['Field'], $rows ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Determina qual coluna usar como "data de cadastro".
 */
function detect_created_col(array $cols): ?string {
    $candidates = ['created_at','created','data_criacao','dt_criacao','created_on','dt_cadastro','data_cadastro','cadastrado_em'];
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

$cols = users_columns($pdo);
$createdCol = detect_created_col($cols);

// ========================= AJAX: retorna JSON para preencher a tabela =========================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    // filtros
    $q      = trim((string)($_GET['q'] ?? ''));
    $email  = trim((string)($_GET['email'] ?? ''));
    $phone  = trim((string)($_GET['phone'] ?? ''));
    $dfrom  = trim((string)($_GET['date_from'] ?? ''));
    $dto    = trim((string)($_GET['date_to'] ?? ''));

    // normaliza telefone (só dígitos)
    $phoneDigits = preg_replace('/\D+/', '', $phone ?? '');

    $select = [
        "u.id",
        "u.nome",
        "u.email",
        "u.telefone",
    ];

    if ($createdCol) {
        $select[] = "u.`$createdCol` AS created_at";
    } else {
        // fallback: não existe coluna de data — usa id como referência visual
        $select[] = "NULL AS created_at";
    }

    // extras (se existirem)
    $extras = ['codigo_turma','data_live','utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];
    foreach ($extras as $ex) {
        if (in_array($ex, $cols, true)) $select[] = "u.`$ex`";
    }

    $sql = "SELECT " . implode(", ", $select) . " FROM `users` u WHERE 1=1";
    $params = [];

    // filtro geral (nome/email/telefone)
    if ($q !== '') {
        $sql .= " AND (u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    if ($email !== '') {
        $sql .= " AND u.email LIKE :email";
        $params[':email'] = '%' . $email . '%';
    }

    if ($phoneDigits !== '') {
        $sql .= " AND u.telefone LIKE :phone";
        $params[':phone'] = '%' . $phoneDigits . '%';
    }

    if ($createdCol) {
        // datas em YYYY-MM-DD
        if ($dfrom !== '') {
            $sql .= " AND DATE(u.`$createdCol`) >= :dfrom";
            $params[':dfrom'] = $dfrom;
        }
        if ($dto !== '') {
            $sql .= " AND DATE(u.`$createdCol`) <= :dto";
            $params[':dto'] = $dto;
        }
        $sql .= " ORDER BY u.`$createdCol` DESC, u.id DESC";
    } else {
        $sql .= " ORDER BY u.id DESC";
    }

    $sql .= " LIMIT 300";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => 'Falha ao consultar users: ' . $e->getMessage(),
            'sql' => $sql,
        ]);
        exit;
    }

    // Monta payload completo por linha (para o botão "detalhes")
    $out = [];
    foreach ($rows as $r) {
        $payload = $r;

        // garante telefone só dígitos no payload (sem mexer no banco)
        if (isset($payload['telefone'])) {
            $payload['telefone'] = preg_replace('/\D+/', '', (string)$payload['telefone']);
        }

        $out[] = [
            'id' => (int)($r['id'] ?? 0),
            'nome' => (string)($r['nome'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'telefone' => (string)($r['telefone'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'payload' => $payload,
        ];
    }

    echo json_encode(['ok' => true, 'created_col' => $createdCol, 'rows' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================= Página HTML =========================
include __DIR__ . '/_header.php';
?>
<div class="card">
  <h3>Monitor de inscrições (entrada via api_inscrever.php)</h3>
  <p style="opacity:.85;max-width:980px">
    Este monitor mostra os usuários cadastrados no banco (<code>users</code>). Isso blinda o monitor contra casos em que o log não aparece,
    e te permite filtrar por nome/e-mail/telefone e por datas (se a tabela tiver coluna de data).
  </p>

  <div class="card" style="margin-top:14px;padding:14px;border:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.15)">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
      <div style="flex:1;min-width:220px">
        <label style="display:block;font-size:12px;opacity:.8;margin-bottom:6px">Busca geral (nome/email/telefone)</label>
        <input id="f_q" type="text" class="input" placeholder="Ex: Emerson / @hotmail / 3198..." style="width:100%">
      </div>

      <div style="flex:1;min-width:220px">
        <label style="display:block;font-size:12px;opacity:.8;margin-bottom:6px">E-mail</label>
        <input id="f_email" type="text" class="input" placeholder="Ex: aluno@..." style="width:100%">
      </div>

      <div style="flex:0.7;min-width:180px">
        <label style="display:block;font-size:12px;opacity:.8;margin-bottom:6px">Telefone (só números)</label>
        <input id="f_phone" type="text" class="input" placeholder="Ex: 31985278215" style="width:100%">
      </div>

      <div style="flex:0.5;min-width:170px">
        <label style="display:block;font-size:12px;opacity:.8;margin-bottom:6px">Data (de)</label>
        <input id="f_from" type="date" class="input" style="width:100%">
      </div>

      <div style="flex:0.5;min-width:170px">
        <label style="display:block;font-size:12px;opacity:.8;margin-bottom:6px">Data (até)</label>
        <input id="f_to" type="date" class="input" style="width:100%">
      </div>

      <div style="display:flex;gap:8px">
        <button id="btn_apply" class="btn">Filtrar</button>
        <button id="btn_clear" class="btn" style="background:#111827">Limpar</button>
      </div>
    </div>

    <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span id="status" style="font-size:12px;opacity:.8">Carregando...</span>
      <span id="hintDate" style="font-size:12px;opacity:.65"></span>
    </div>
  </div>

  <div style="margin-top:14px;overflow:auto;border-radius:12px;border:1px solid rgba(255,255,255,.08)">
    <table style="width:100%;border-collapse:collapse;min-width:820px">
      <thead>
        <tr style="background:rgba(255,255,255,.04)">
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">ID</th>
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">Nome</th>
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">Telefone</th>
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">E-mail</th>
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">Data</th>
          <th style="text-align:left;padding:10px 12px;font-size:12px;opacity:.8">Ações</th>
        </tr>
      </thead>
      <tbody id="tbody">
        <tr><td colspan="6" style="padding:14px;opacity:.7">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<style>
  .btn-mini{
    padding:6px 10px;
    border-radius:10px;
    font-size:12px;
    border:1px solid rgba(255,255,255,.12);
    background:#0b1220;
    color:#e5e7eb;
    cursor:pointer;
  }
  .btn-mini:hover{ filter:brightness(1.08); }
  .row-details td{
    background:rgba(0,0,0,.18);
    border-top:1px dashed rgba(255,255,255,.12);
    padding:0 !important;
  }
  .payload-box{
    margin:0;
    padding:12px;
    max-height:260px;
    overflow:auto;
    font-size:12px;
    line-height:1.35;
    white-space:pre-wrap;
  }
  .muted{ opacity:.75; font-size:12px; }
</style>

<script>
(function(){
  const tbody = document.getElementById('tbody');
  const status = document.getElementById('status');
  const hintDate = document.getElementById('hintDate');

  const f_q = document.getElementById('f_q');
  const f_email = document.getElementById('f_email');
  const f_phone = document.getElementById('f_phone');
  const f_from = document.getElementById('f_from');
  const f_to = document.getElementById('f_to');

  const btnApply = document.getElementById('btn_apply');
  const btnClear = document.getElementById('btn_clear');

  let timer = null;
  let lastSig = '';

  function params(){
    const p = new URLSearchParams();
    p.set('ajax','1');
    if (f_q.value.trim()) p.set('q', f_q.value.trim());
    if (f_email.value.trim()) p.set('email', f_email.value.trim());
    if (f_phone.value.trim()) p.set('phone', f_phone.value.trim());
    if (f_from.value) p.set('date_from', f_from.value);
    if (f_to.value) p.set('date_to', f_to.value);
    return p;
  }

  function renderRows(rows){
    if (!rows || !rows.length){
      tbody.innerHTML = '<tr><td colspan="6" style="padding:14px;opacity:.7">Nenhuma inscrição encontrada.</td></tr>';
      return;
    }

    let html = '';
    rows.forEach(r => {
      const id = r.id || '';
      const nome = (r.nome || '').replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));
      const email = (r.email || '').replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));
      const tel = (r.telefone || '').replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));
      const dt = (r.created_at || '') ? r.created_at : '<span class="muted">—</span>';

      const payloadStr = JSON.stringify(r.payload || {}, null, 2)
        .replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));

      const detailsId = 'details_' + id;

      html += `
        <tr>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06);font-size:13px;opacity:.9">${id}</td>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">${nome}</td>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">${tel}</td>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">${email}</td>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">${dt}</td>
          <td style="padding:10px 12px;border-top:1px solid rgba(255,255,255,.06)">
            <button class="btn-mini" data-toggle="${detailsId}">Ver payload</button>
          </td>
        </tr>
        <tr id="${detailsId}" class="row-details" style="display:none">
          <td colspan="6">
            <pre class="payload-box">${payloadStr}</pre>
          </td>
        </tr>
      `;
    });

    tbody.innerHTML = html;

    // toggle
    tbody.querySelectorAll('button[data-toggle]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-toggle');
        const tr = document.getElementById(id);
        if (!tr) return;
        const isOpen = tr.style.display !== 'none';
        tr.style.display = isOpen ? 'none' : '';
        btn.textContent = isOpen ? 'Ver payload' : 'Fechar payload';
      });
    });
  }

  async function load(){
    status.textContent = 'Carregando...';
    try{
      const res = await fetch('monitor_inscricoes.php?' + params().toString(), {cache:'no-store'});
      const data = await res.json();
      if (!data.ok){
        status.textContent = 'Erro: ' + (data.error || 'não foi possível carregar');
        tbody.innerHTML = '<tr><td colspan="6" style="padding:14px;color:#fca5a5">Erro ao carregar dados.</td></tr>';
        return;
      }

      // dica de data
      if (!data.created_col){
        hintDate.textContent = 'Obs.: sua tabela users não tem coluna de data de cadastro detectável — o filtro por datas ficará indisponível.';
      } else {
        hintDate.textContent = '';
      }

      const rows = data.rows || [];
      const sig = JSON.stringify(rows.map(x=>x.id)); // assinatura simples pra evitar re-render sem mudança
      if (sig !== lastSig){
        renderRows(rows);
        lastSig = sig;
      }
      status.textContent = `Mostrando ${rows.length} registro(s). Atualiza a cada 4s.`;
    }catch(e){
      status.textContent = 'Falha de rede ao carregar.';
    }
  }

  btnApply.addEventListener('click', (e)=>{ e.preventDefault(); lastSig=''; load(); });
  btnClear.addEventListener('click', (e)=>{
    e.preventDefault();
    f_q.value=''; f_email.value=''; f_phone.value=''; f_from.value=''; f_to.value='';
    lastSig=''; load();
  });

  // recarrega automaticamente
  load();
  timer = setInterval(load, 4000);
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
