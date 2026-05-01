<?php
// FILE: public/reagendar_live.php
// Página pública para o aluno reagendar a live (SEM TOKEN) usando email/telefone no link.
// Ex.: reagendar_live.php?email=aluno@x.com&telefone=31999999999
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();

/**
 * Escape HTML.
 */
if (!function_exists('h')) {
    function h(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Escape HTML (prefixado para evitar conflito e garantir que exista).
 */
if (!function_exists('rl_h')) {
    function rl_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Logger simples com fallback.
 */
function rl_log(string $msg, string $file = 'reagendar_live.log'): void {
    $dir = __DIR__ . '/../app/error_log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    $path = $dir . '/' . $file;
    if (@file_put_contents($path, $line, FILE_APPEND) === false) {
        error_log('[reagendar_live] ' . $msg);
    }
}

/**
 * Lê uma configuração da tabela settings (chave/valor), se existir.
 */
function rl_get_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    // Se existir get_setting do projeto, usa primeiro
    if (function_exists('get_setting')) {
        try {
            $v = get_setting($key);
            if ($v === null || $v === '') return $default;
            return (string)$v;
        } catch (\Throwable $e) {
            // cai pro fallback SQL
        }
    }

    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave = :k LIMIT 1");
        $st->execute(['k' => $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        $val = (string)($row['valor'] ?? '');
        return $val !== '' ? $val : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function rl_norm_phone(?string $v): string {
    $v = (string)$v;
    $digits = preg_replace('/\D+/', '', $v) ?: '';
    // se vier com DDI, pega últimos 11 (DDD+numero)
    if (strlen($digits) > 11) $digits = substr($digits, -11);
    return $digits;
}

/**
 * Busca aluno por email/telefone (telefone normalizado).
 * Regras:
 *  - se ambos informados: encontra por email e valida o telefone do mesmo user (se possível)
 *  - se só email: busca por email
 *  - se só telefone: busca por telefone (com e sem máscara)
 */
function rl_find_user(PDO $pdo, ?string $email, ?string $telefone): ?array {
    $email = $email ? trim(strtolower($email)) : null;
    $telDigits = rl_norm_phone($telefone);

    // 1) tenta por email
    if ($email) {
        $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = :e LIMIT 1");
        $st->execute(['e' => $email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            // se telefone informado, tenta validar
            if ($telDigits !== '') {
                $uTel = rl_norm_phone((string)($u['telefone'] ?? ''));
                if ($uTel !== '' && $uTel !== $telDigits) {
                    // email existe mas telefone não confere -> bloqueia (evita forja)
                    return null;
                }
            }
            return $u;
        }
    }

    // 2) tenta por telefone
    if ($telDigits !== '') {
        // tenta match direto (se o banco já guarda só dígitos)
        $st = $pdo->prepare("SELECT * FROM users WHERE telefone = :t LIMIT 1");
        $st->execute(['t' => $telDigits]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;

        // tenta match por LIKE (caso tenha máscara armazenada)
        $st2 = $pdo->prepare("SELECT * FROM users WHERE telefone LIKE :t LIMIT 1");
        $st2->execute(['t' => '%' . $telDigits]);
        $u2 = $st2->fetch(PDO::FETCH_ASSOC);
        if ($u2) return $u2;
    }

    return null;
}

// -----------------------------------------------------------------------------
// Identificação do aluno
// -----------------------------------------------------------------------------
$emailGet = isset($_GET['email']) ? (string)$_GET['email'] : null;
$telGet   = isset($_GET['telefone']) ? (string)$_GET['telefone'] : (isset($_GET['tel']) ? (string)$_GET['tel'] : null);

$alunoIdSess = (int)($_SESSION['aluno_id'] ?? 0);

$user = null;
$modo = 'guest';

try {
    if ($alunoIdSess > 0) {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute(['id' => $alunoIdSess]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $modo = 'logado';
    } else {
        $user = rl_find_user($pdo, $emailGet, $telGet);
        $modo = 'link';
        if ($user) {
            // cria uma sessão "guest" só para autorizar a chamada da API
            $_SESSION['reagendar_guest_uid'] = (int)$user['id'];
            $_SESSION['reagendar_guest_exp'] = time() + 2 * 3600; // 2 horas
        }
    }
} catch (\Throwable $e) {
    rl_log('Erro ao identificar aluno: ' . $e->getMessage());
    $user = null;
}

if (!$user) {
    // Se não achou, manda pro login
    rl_log('Aluno não identificado. email=' . ($emailGet ?? '') . ' tel=' . ($telGet ?? ''));
    header('Location: login.php?erro=reagendar_nao_encontrado');
    exit;
}

$userId = (int)($user['id'] ?? 0);

// -----------------------------------------------------------------------------
// Config visual e configs da página
// -----------------------------------------------------------------------------
$appCfg = [
    'course_title' => 'Área de Membros',
    'primary_color' => '#facc15',
    'secondary_color' => '#22c55e',
    'background_color' => '#020617',
    'logo_url' => '',
];

try {
    $stCfg = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
    $rowCfg = $stCfg ? $stCfg->fetch(PDO::FETCH_ASSOC) : null;
    if ($rowCfg) $appCfg = array_merge($appCfg, $rowCfg);
} catch (\Throwable $e) {
    // ignora
}

$primary   = (string)($appCfg['primary_color'] ?? '#facc15');
$secondary = (string)($appCfg['secondary_color'] ?? '#22c55e');
$bgColor   = (string)($appCfg['background_color'] ?? '#020617');
$courseTitle = (string)($appCfg['course_title'] ?? 'Área de Membros');
$logoUrl = (string)($appCfg['logo_url'] ?? '');

$opcoesN = (int)rl_get_setting($pdo, 'reagendar_opcoes_qtd', '3');
if ($opcoesN < 1) $opcoesN = 1;
if ($opcoesN > 10) $opcoesN = 10;

$webhookUrl = (string)rl_get_setting($pdo, 'reagendar_webhook_url', '');

// -----------------------------------------------------------------------------
// Carrega turmas disponíveis (próximos 15 dias) e limita às próximas N
// Regra "disponível": ter data_live futura dentro dos próximos 15 dias (ignora janela_inicio/janela_fim)
// -----------------------------------------------------------------------------
$now = new DateTimeImmutable('now');
$end = $now->modify('+15 days');

$turmasAll = [];
try {
    $st = $pdo->prepare("
        SELECT *
        FROM turmas
        WHERE data_live >= :now
          AND data_live <= :end
        ORDER BY data_live ASC, id ASC
        LIMIT 200
    ");
    $st->execute([
        'now' => $now->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
    ]);
    $turmasAll = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    rl_log('reagendar_live: turmasAll=' . count($turmasAll) . ' range=' . $now->format('Y-m-d H:i:s') . ' .. ' . $end->format('Y-m-d H:i:s'), 'debug');
} catch (\Throwable $e) {
    rl_log('Erro ao buscar turmas: ' . $e->getMessage());
    $turmasAll = [];
}

// Filtra turmas "disponíveis" para reagendamento
// Regra:
// - a query já trouxe somente data_live entre agora e +15 dias
// - NÃO exige janela_inicio já ter iniciado (para o calendário já mostrar as próximas lives)
// - apenas evita turmas expiradas (janela_fim no passado), quando janela_fim existir
$turmasElegiveis = $turmasAll; // ignora janela_inicio/janela_fim

$turmasLista = array_slice($turmasElegiveis, 0, $opcoesN);
rl_log('reagendar_live: turmasElegiveis=' . count($turmasElegiveis) . ' | turmasLista=' . count($turmasLista) . ' (opcoesN=' . $opcoesN . ')', 'debug');

// Mapa por dia (calendário)
$map = []; // Y-m-d => [ {codigo, data_live, hora, ...}, ... ]
foreach ($turmasElegiveis as $t) {

    $dt = (string)($t['data_live'] ?? '');
    if ($dt === '') continue;
    try {
        $d = new DateTimeImmutable($dt);
    } catch (\Throwable $e) {
        continue;
    }
    $key = $d->format('Y-m-d');
    $map[$key] = $map[$key] ?? [];
    $map[$key][] = [
        'codigo' => (string)($t['codigo'] ?? ''),
        'data_live' => $d->format('Y-m-d H:i:s'),
        'data_br' => $d->format('d/m/Y'),
        'hora' => $d->format('H:i'),
    ];
}

// Gera os próximos 15 dias para calendário
$days = [];
for ($i=0; $i<15; $i++) {
    $d = (clone $now)->modify("+$i days");
    $k = $d->format('Y-m-d');
    $days[] = [
        'key' => $k,
        'd' => $d,
        'has' => isset($map[$k]) && count($map[$k]) > 0,
        'items' => $map[$k] ?? [],
    ];
}

$alunoNome = (string)($user['nome'] ?? 'Aluno');

rl_log("Calendário carregado. modo={$modo} user_id={$userId} opcoes={$opcoesN} turmas_elegiveis=" . count($turmasElegiveis) . " turmas_lista=" . count($turmasLista));

// -----------------------------------------------------------------------------
// HTML
// -----------------------------------------------------------------------------
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title><?= rl_h($courseTitle) ?> - Reagendar Live</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root{
      --bg: <?= rl_h($bgColor) ?>;
      --card:#020617;
      --border:#1f2937;
      --primary: <?= rl_h($primary) ?>;
      --secondary: <?= rl_h($secondary) ?>;
      --text:#e5e7eb;
      --muted:#9ca3af;
      --danger:#ef4444;
    }
    *{box-sizing:border-box}
    body{
      margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
      background: var(--bg); color: var(--text);
    }
    .container{max-width:1100px;margin:0 auto;padding:16px;}
    .top{
      display:flex;align-items:center;gap:12px;justify-content:space-between;
      padding:12px 14px;border:1px solid var(--border);border-radius:14px;background:rgba(2,6,23,.8);
      backdrop-filter: blur(6px);
    }
    .brand{display:flex;align-items:center;gap:10px;min-width:0;}
    .logo{
      width:42px;height:42px;border-radius:999px;border:1px solid var(--border);
      background:#0b1220;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;
    }
    .logo img{width:100%;height:100%;object-fit:cover;}
    .titles{min-width:0;}
    .titles .t1{font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .titles .t2{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .badge{
      font-size:12px;color:var(--muted);border:1px solid var(--border);padding:6px 10px;border-radius:999px;
    }
    .card{
      margin-top:14px;
      border:1px solid var(--border);border-radius:16px;background:rgba(2,6,23,.75);
      padding:14px;
    }
    .intro{
      display:flex;flex-direction:column;gap:8px;
    }
    .intro h1{margin:0;font-size:18px;}
    .intro p{margin:0;color:var(--muted);font-size:13px;line-height:1.4;}
    .grid{
      margin-top:12px;
      display:grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap:10px;
    }
    .dow{font-size:11px;color:var(--muted);text-align:center;padding:6px 0;}
    .day{
      border:1px solid var(--border);border-radius:14px;min-height:86px;
      background:#0b1220;
      padding:10px;
      display:flex;flex-direction:column;gap:6px;
      cursor: default;
    }
    .day .n{font-weight:800;font-size: 15px;}
    .day .s{font-size:11px;color:var(--muted);line-height:1.2;}
    .day.available{
      border-color: rgba(34,197,94,.35);
      box-shadow: 0 0 0 1px rgba(34,197,94,.12) inset;
      cursor: pointer;
    }
    .pill{
      display:inline-flex;align-items:center;gap:6px;
      font-size:11px;border:1px solid var(--border);padding:4px 8px;border-radius:999px;
      width:max-content;
    }
    .pill.ok{border-color: rgba(34,197,94,.4); color: #bbf7d0;}
    .pill.no{border-color: rgba(239,68,68,.35); color: #fecaca;}
    .list{
      margin-top:14px;
      display:flex;flex-direction:column;gap:10px;
    }
    .row{
      border:1px solid var(--border);border-radius:14px;padding:12px;background:#0b1220;
      display:flex;justify-content:space-between;align-items:center;gap:10px;
    }
    .row .meta{display:flex;flex-direction:column;gap:2px;min-width:0;}
    .row .meta b{font-size:13px;}
    .row .meta span{font-size:12px;color:var(--muted);}
    .btn{
      border:none;border-radius:12px;padding:10px 12px;font-weight:800;
      background: var(--primary); color:#111827; cursor:pointer;
      display:inline-flex;align-items:center;gap:8px;
      white-space:nowrap;
    }
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .btn.secondary{background:transparent;color:var(--text);border:1px solid var(--border);}
    .spinner{
      width:16px;height:16px;border-radius:50%;
      border:2px solid rgba(255,255,255,.35);
      border-top-color: rgba(255,255,255,.95);
      display:none;
      animation: spin .8s linear infinite;
    }
    .btn.loading .spinner{display:inline-block;}
    @keyframes spin { to { transform: rotate(360deg);} }

    /* Modal */
    .modal-back{
      position:fixed;inset:0;background:rgba(0,0,0,.55);
      display:none;align-items:center;justify-content:center;padding:18px;
      z-index:9999;
    }
.modal-back.active{display:flex;}

    .modal{
      width:min(520px, 100%);
      background:#0b1220;border:1px solid var(--border);border-radius:16px;
      padding:14px;
    }
    .modal h3{margin:0 0 6px 0;font-size:16px;}
    .modal p{margin:0 0 10px 0;color:var(--muted);font-size:13px;line-height:1.35;}
    .times{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0;}
    .time-btn{
      border:1px solid var(--border);background:transparent;color:var(--text);
      border-radius:12px;padding:10px 12px;cursor:pointer;font-weight:800;font-size:13px;
    }
    .time-btn:hover{border-color: rgba(250,204,21,.55);}
    .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap;}
    .hint{margin-top:10px;font-size:12px;color:var(--muted);}
    @media (max-width:720px){
      .grid{grid-template-columns: repeat(3, minmax(0, 1fr));}
      .dow{display:none;}
      .row{flex-direction:column;align-items:flex-start}
      .btn{width:100%;justify-content:center}
    }
  </style>
</head>
<body>
<div class="container">
  <div class="top">
    <div class="brand">
      <div class="logo">
        <?php if ($logoUrl): ?>
          <img src="<?= rl_h($logoUrl) ?>" alt="Logo">
        <?php else: ?>
          ⚡
        <?php endif; ?>
      </div>
      <div class="titles">
        <div class="t1"><?= rl_h($courseTitle) ?></div>
        <div class="t2">Reagendamento de Live</div>
      </div>
    </div>
    <div class="badge">Olá, <?= rl_h($alunoNome) ?></div>
  </div>

  <div class="card">
    <div class="intro">
      <h1>Escolha uma nova data para sua live</h1>
      <p>
        Aqui você vê as <b>próximas opções disponíveis</b> para reagendar. Datas em <b style="color:#bbf7d0;">verde</b> estão liberadas.
        Datas sem opção aparecem como <b style="color:#fecaca;">esgotadas</b>.
      </p>
      <p class="hint">
        Ao selecionar, você confirma no popup e o sistema atualiza automaticamente sua turma e a data da live.
      </p>
    </div>

    <div class="grid" id="calGrid">
      <?php
        // Cabeçalho (dias da semana) - só em desktop
        $dows = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        foreach ($dows as $dow) echo '<div class="dow">'.rl_h($dow).'</div>';
      ?>
      <?php foreach ($days as $day): 
        $d = $day['d'];
        $items = $day['items'];
        $isAvail = $day['has'];
        $label = $d->format('d/m');
        $dowNum = (int)$d->format('w'); // 0-6
        $cls = 'day' . ($isAvail ? ' available' : '');
        $dataJson = $isAvail ? rl_h(json_encode($items, JSON_UNESCAPED_UNICODE)) : '';
      ?>
        <div class="<?= rl_h($cls) ?>"
             data-date="<?= rl_h($day['key']) ?>"
             data-items="<?= $dataJson ?>">
          <div class="n"><?= rl_h($d->format('d/m/y')) ?></div>
          <?php if ($isAvail): ?>
            <div class="pill ok">✅ Liberada</div>
            <div class="s"><?= rl_h(count($items)) ?> horário(s)</div>
          <?php else: ?>
            <div class="pill no">⛔ Esgotado</div>
            <div class="s">Sem vagas/opções</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Removido: lista inferior de opções. O aluno escolhe direto no calendário. -->

    <div style="display:flex; justify-content:center; margin-top:16px;">
      <a class="btn secondary"
         href="https://professoremersonleite.com/area_membros/public/trilha.php"
         style="text-decoration:none;">
        Voltar para as aulas
      </a>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal-back" id="modalBack" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true">
    <h3 id="modalTitle">Confirmar reagendamento</h3>
    <p id="modalText">Selecione um horário.</p>

    <div class="times" id="times"></div>

    <div class="actions" id="modalActions">
      <button class="btn secondary" id="btnCancel" type="button">Cancelar</button>
      <button class="btn" id="btnConfirm" type="button" disabled>
        Confirmar <span class="spinner" aria-hidden="true"></span>
      </button>
    </div>
    <div class="hint" id="modalHint"></div>

    <div class="actions" id="successActions" style="display:none; margin-top:12px; justify-content:stretch;">
      <a class="btn" id="btnBackAulas" href="https://professoremersonleite.com/area_membros/public/trilha.php" style="width:100%; justify-content:center; text-decoration:none;">
        Voltar para as aulas
      </a>
    </div>
  </div>
</div>

<script>
(function(){
  const modalBack = document.getElementById('modalBack');
  const modalTitle = document.getElementById('modalTitle');
  const timesWrap = document.getElementById('times');
  const modalActions = document.getElementById('modalActions');
  const successActions = document.getElementById('successActions');
  const btnCancel = document.getElementById('btnCancel');
  const btnConfirm = document.getElementById('btnConfirm');
  const modalText = document.getElementById('modalText');
  const modalHint = document.getElementById('modalHint');

  let selected = null; // {codigo, data_live, data_br, hora}

  function openModal(items){
    selected = null;
    modalTitle.textContent = 'Confirmar reagendamento';
    modalActions.style.display = 'flex';
    successActions.style.display = 'none';
    timesWrap.style.display = 'flex';
    btnConfirm.disabled = true;
    btnConfirm.classList.remove('loading');
    modalHint.textContent = '';
    timesWrap.innerHTML = '';

    if (!items || !items.length){
      modalText.textContent = 'Sem horários disponíveis.';
      modalBack.style.display = 'flex';
      return;
    }

    modalText.textContent = 'Selecione um horário para reagendar:';

    items.forEach(it => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'time-btn';
      b.textContent = (it.data_br || '') + ' ' + (it.hora || '');
      b.addEventListener('click', () => {
        selected = it;
        btnConfirm.disabled = false;
        modalHint.textContent = 'Você escolheu: ' + (it.data_br || '') + ' ' + (it.hora || '');
      });
      timesWrap.appendChild(b);
    });

    modalBack.style.display = 'flex';
  }

  function closeModal(){
    modalBack.style.display = 'none';
  }

  btnCancel.addEventListener('click', closeModal);
  modalBack.addEventListener('click', (e)=>{ if(e.target === modalBack) closeModal(); });

  async function doReagendar(codigo, dataLive, dataBr){
    // trava UI
    btnConfirm.disabled = true;
    btnConfirm.classList.add('loading');
    btnConfirm.querySelector('.spinner').style.display = 'inline-block';

    try{
      const res = await fetch('api_reagendar_live.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ codigo_turma: codigo, data_live: dataLive })
      });
      const j = await res.json();
      if(!j || !j.ok){
        modalHint.textContent = (j && (j.message || j.error)) ? (j.message || j.error) : 'Falha ao reagendar.';
        btnConfirm.classList.remove('loading');
        btnConfirm.disabled = false;
        return;
      }

      // Sucesso: transforma o modal em tela de parabéns
      modalTitle.textContent = 'Parabéns! 🎉';
      modalText.textContent = 'Reagendamento confirmado com sucesso!';
      const liveNova = (j.live_nova || dataBr || '').trim();
      modalHint.textContent = liveNova ? ('Nova data da live: ' + liveNova) : 'Nova data da live confirmada.';

      timesWrap.style.display = 'none';
      modalActions.style.display = 'none';
      successActions.style.display = '';

      // mantém botão travado
      btnConfirm.classList.remove('loading');
      btnConfirm.disabled = true;
    }catch(err){
      modalHint.textContent = 'Erro de rede. Tente novamente.';
      btnConfirm.classList.remove('loading');
      btnConfirm.disabled = false;
    }
  }

  btnConfirm.addEventListener('click', () => {
    if(!selected) return;
    const codigo = selected.codigo;
    const dt = selected.data_live;
    const br = (selected.data_br || '') + ' ' + (selected.hora || '');
    doReagendar(codigo, dt, br);
  });

  // Clique no calendário
  document.querySelectorAll('.day.available').forEach(el => {
    el.addEventListener('click', () => {
      try{
        const raw = el.getAttribute('data-items') || '[]';
        const items = JSON.parse(raw);
        openModal(items);
      }catch(e){
        openModal([]);
      }
    });
  });

  // Lista inferior removida: escolha é feita diretamente no calendário.

})();
</script>
</body>
</html>
