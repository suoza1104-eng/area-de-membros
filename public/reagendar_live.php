<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();

function rl_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rl_log(string $msg, string $file = 'reagendar_live.log'): void {
    $dir = __DIR__ . '/../app/error_log';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}
function rl_get_setting_db(PDO $pdo, string $key, ?string $default = null): ?string {
    if (function_exists('get_setting')) {
        try {
            $v = get_setting($key);
            if ($v !== null && $v !== '') return (string)$v;
        } catch (Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $v = $st->fetchColumn();
        return ($v !== false && (string)$v !== '') ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
}
function rl_norm_phone(?string $v): string {
    $digits = preg_replace('/\D+/', '', (string)$v) ?: '';
    return strlen($digits) > 11 ? substr($digits, -11) : $digits;
}
function rl_find_user(PDO $pdo, ?string $email, ?string $telefone): ?array {
    $email = $email ? trim(strtolower($email)) : null;
    $telDigits = rl_norm_phone($telefone);
    if ($email) {
        $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            if ($telDigits !== '' && rl_norm_phone((string)($u['telefone'] ?? '')) !== $telDigits) return null;
            return $u;
        }
    }
    if ($telDigits !== '') {
        $st = $pdo->prepare("SELECT * FROM users WHERE telefone = :t OR telefone LIKE :tl LIMIT 1");
        $st->execute([':t' => $telDigits, ':tl' => '%' . $telDigits]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }
    return null;
}

$tokenGet = trim((string)($_GET['t'] ?? ''));
$emailGet = isset($_GET['email']) ? (string)$_GET['email'] : null;
$telGet = isset($_GET['telefone']) ? (string)$_GET['telefone'] : (isset($_GET['tel']) ? (string)$_GET['tel'] : null);
$user = null;
$modo = 'guest';
try {
    $alunoIdSess = (int)($_SESSION['aluno_id'] ?? 0);
    if ($tokenGet !== '') {
        $st = $pdo->prepare("SELECT t.id AS token_id, t.user_id, u.* FROM live_reschedule_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token = :token
              AND t.used_at IS NULL
              AND t.expires_at >= NOW()
            LIMIT 1");
        $st->execute([':token' => $tokenGet]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            header('Location: login.php?erro=reagendar_link_invalido');
            exit;
        }
        $_SESSION['reagendar_guest_uid'] = (int)$row['user_id'];
        $_SESSION['reagendar_guest_exp'] = time() + 2 * 3600;
        $_SESSION['reagendar_token_id'] = (int)$row['token_id'];
        $user = $row;
        $modo = 'token';
    } elseif ($alunoIdSess > 0) {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $alunoIdSess]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $modo = 'logado';
    } else {
        $user = rl_find_user($pdo, $emailGet, $telGet);
        $modo = 'link';
        if ($user) {
            $_SESSION['reagendar_guest_uid'] = (int)$user['id'];
            $_SESSION['reagendar_guest_exp'] = time() + 2 * 3600;
        }
    }
} catch (Throwable $e) {
    rl_log('Erro ao identificar aluno: ' . $e->getMessage());
}
if (!$user) {
    header('Location: login.php?erro=reagendar_nao_encontrado');
    exit;
}

$appCfg = ['course_title'=>'Area de Membros','primary_color'=>'#facc15','secondary_color'=>'#22c55e','background_color'=>'#020617','logo_url'=>''];
try {
    $cfg = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cfg) $appCfg = array_merge($appCfg, $cfg);
} catch (Throwable $e) {}

$opcoesN = (int)rl_get_setting_db($pdo, 'reagendar_opcoes_qtd', (string)rl_get_setting_db($pdo, 'reagendar_next_lives_count', '3'));
if ($opcoesN < 1) $opcoesN = 1;
if ($opcoesN > 30) $opcoesN = 30;
$janelaDias = (int)rl_get_setting_db($pdo, 'reagendar_window_days', '30');
if ($janelaDias < 1) $janelaDias = 1;
if ($janelaDias > 365) $janelaDias = 365;
$liveTime = trim((string)rl_get_setting_db($pdo, 'reagendar_live_time', '19:30'));
if (!preg_match('/^\d{2}:\d{2}$/', $liveTime)) $liveTime = '19:30';
$liveUrl = trim((string)rl_get_setting_db($pdo, 'reagendar_live_url', ''));
$blackouts = array_flip(array_filter(array_map('trim', explode(',', (string)rl_get_setting_db($pdo, 'reagendar_blackout_dates', '')))));

$now = new DateTimeImmutable('now');
$map = [];
$slotsCount = 0;
for ($i = 0; $i < $janelaDias; $i++) {
    $day = $now->modify('+' . $i . ' days');
    $key = $day->format('Y-m-d');
    $blocked = isset($blackouts[$key]);
    $slot = new DateTimeImmutable($key . ' ' . $liveTime . ':00');
    $available = !$blocked && $slot > $now && $slotsCount < $opcoesN;
    if ($available) {
        $slotsCount++;
        $map[$key][] = [
            'data_live' => $slot->format('Y-m-d H:i:s'),
            'data_br' => $slot->format('d/m/Y'),
            'hora' => $slot->format('H:i'),
            'live_url' => $liveUrl,
        ];
    }
}

$days = [];
for ($i = 0; $i < $janelaDias; $i++) {
    $d = $now->modify('+' . $i . ' days');
    $k = $d->format('Y-m-d');
    $days[] = ['key'=>$k, 'd'=>$d, 'blocked'=>isset($blackouts[$k]), 'has'=>!empty($map[$k]), 'items'=>$map[$k] ?? []];
}

$primary = (string)($appCfg['primary_color'] ?? '#facc15');
$bgColor = (string)($appCfg['background_color'] ?? '#020617');
$courseTitle = (string)($appCfg['course_title'] ?? 'Area de Membros');
$logoUrl = (string)($appCfg['logo_url'] ?? '');
$alunoNome = (string)($user['nome'] ?? 'Aluno');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= rl_h($courseTitle) ?> - Reagendar Live</title>
<style>
:root{--bg:<?= rl_h($bgColor) ?>;--card:#020617;--line:#1f2937;--text:#e5e7eb;--muted:#94a3b8;--primary:<?= rl_h($primary) ?>;--danger:#ef4444;--ok:#22c55e}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}.wrap{max-width:1120px;margin:0 auto;padding:16px}.top,.card{border:1px solid var(--line);border-radius:16px;background:rgba(2,6,23,.82)}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px}.brand{display:flex;align-items:center;gap:10px;min-width:0}.logo{width:42px;height:42px;border-radius:999px;background:#0b1220;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;overflow:hidden}.logo img{width:100%;height:100%;object-fit:cover}.t1{font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.t2,.muted{font-size:12px;color:var(--muted)}.badge{border:1px solid var(--line);border-radius:999px;padding:6px 10px;color:var(--muted);font-size:12px}.card{margin-top:14px;padding:14px}h1{margin:0 0 6px;font-size:20px}p{margin:0;color:var(--muted);font-size:13px;line-height:1.45}.grid{margin-top:14px;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px}.dow{text-align:center;color:var(--muted);font-size:11px;padding:6px 0}.day{border:1px solid var(--line);border-radius:14px;min-height:92px;background:#0b1220;padding:10px;display:flex;flex-direction:column;gap:6px}.day.first{grid-column-start:var(--start-col)}.day.available{border-color:rgba(34,197,94,.42);cursor:pointer;box-shadow:0 0 0 1px rgba(34,197,94,.12) inset}.day.blocked{opacity:.55}.n{font-weight:800}.pill{width:max-content;border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-size:11px}.pill.ok{color:#bbf7d0;border-color:rgba(34,197,94,.4)}.pill.no{color:#fecaca;border-color:rgba(239,68,68,.35)}.btn{border:0;border-radius:12px;padding:10px 12px;background:var(--primary);color:#111827;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}.btn.secondary{background:transparent;color:var(--text);border:1px solid var(--line)}.modal-back{position:fixed;inset:0;background:rgba(0,0,0,.58);display:none;align-items:center;justify-content:center;padding:18px;z-index:99}.modal{width:min(520px,100%);background:#0b1220;border:1px solid var(--line);border-radius:16px;padding:16px}.times{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}.time-btn{border:1px solid var(--line);background:transparent;color:var(--text);border-radius:12px;padding:10px 12px;cursor:pointer;font-weight:800}.actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:12px}.hint{font-size:12px;color:var(--muted);margin-top:10px}.spinner{width:16px;height:16px;border-radius:50%;border:2px solid rgba(0,0,0,.25);border-top-color:#111827;display:none;animation:spin .8s linear infinite}.loading .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:720px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}.dow{display:none}.day.first{grid-column-start:auto}.top{align-items:flex-start;flex-direction:column}.btn{width:100%}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand">
      <div class="logo"><?php if ($logoUrl): ?><img src="<?= rl_h($logoUrl) ?>" alt="Logo"><?php else: ?>AE<?php endif; ?></div>
      <div><div class="t1"><?= rl_h($courseTitle) ?></div><div class="t2">Reagendamento de Live</div></div>
    </div>
    <div class="badge">Ola, <?= rl_h($alunoNome) ?></div>
  </div>
  <div class="card">
    <h1>Escolha uma nova data para sua live</h1>
    <p>As opcoes abaixo sao do webinario diario de repescagem. Dias indisponiveis aparecem bloqueados no calendario.</p>
    <?php if ($liveUrl !== ''): ?><p style="margin-top:6px">Link da live: <strong><?= rl_h($liveUrl) ?></strong></p><?php endif; ?>
    <div class="grid">
      <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sab'] as $dow): ?><div class="dow"><?= rl_h($dow) ?></div><?php endforeach; ?>
      <?php $dayIndex = 0; ?>
      <?php foreach ($days as $day): $d=$day['d']; $items=$day['items']; $cls='day' . ($dayIndex === 0 ? ' first' : '') . ($day['has'] ? ' available' : '') . ($day['blocked'] ? ' blocked' : ''); $style = $dayIndex === 0 ? ' style="--start-col:' . ((int)$d->format('w') + 1) . '"' : ''; $dayIndex++; ?>
      <div class="<?= rl_h($cls) ?>"<?= $style ?> data-items="<?= $day['has'] ? rl_h(json_encode($items, JSON_UNESCAPED_UNICODE)) : '' ?>">
        <div class="n"><?= rl_h($d->format('d/m/y')) ?></div>
        <?php if ($day['has']): ?><div class="pill ok">Liberada</div><div class="muted"><?= rl_h($liveTime) ?></div>
        <?php elseif ($day['blocked']): ?><div class="pill no">Indisponivel</div><div class="muted">Sem repescagem</div>
        <?php else: ?><div class="pill no">Esgotado</div><div class="muted">Sem opcao</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:center;margin-top:16px"><a class="btn secondary" href="trilha.php">Voltar para as aulas</a></div>
  </div>
</div>

<div class="modal-back" id="modalBack">
  <div class="modal">
    <h3 id="modalTitle">Confirmar reagendamento</h3>
    <p id="modalText">Selecione um horario.</p>
    <div class="times" id="times"></div>
    <div class="actions" id="modalActions">
      <button class="btn secondary" id="btnCancel" type="button">Cancelar</button>
      <button class="btn" id="btnConfirm" type="button" disabled>Confirmar <span class="spinner"></span></button>
    </div>
    <div class="hint" id="modalHint"></div>
    <div class="actions" id="successActions" style="display:none"><a class="btn" href="trilha.php">Voltar para as aulas</a></div>
  </div>
</div>
<script>
(function(){
const modalBack=document.getElementById('modalBack'),times=document.getElementById('times'),btnConfirm=document.getElementById('btnConfirm'),btnCancel=document.getElementById('btnCancel'),modalHint=document.getElementById('modalHint'),modalTitle=document.getElementById('modalTitle'),modalText=document.getElementById('modalText'),modalActions=document.getElementById('modalActions'),successActions=document.getElementById('successActions');
let selected=null;
function openModal(items){selected=null;times.innerHTML='';btnConfirm.disabled=true;modalHint.textContent='';modalTitle.textContent='Confirmar reagendamento';modalText.textContent='Selecione um horario:';modalActions.style.display='flex';successActions.style.display='none';times.style.display='flex';items.forEach(it=>{const b=document.createElement('button');b.type='button';b.className='time-btn';b.textContent=(it.data_br||'')+' '+(it.hora||'');b.onclick=()=>{selected=it;btnConfirm.disabled=false;modalHint.textContent='Voce escolheu: '+b.textContent};times.appendChild(b)});modalBack.style.display='flex'}
btnCancel.onclick=()=>modalBack.style.display='none';modalBack.onclick=e=>{if(e.target===modalBack)modalBack.style.display='none'};
btnConfirm.onclick=async()=>{if(!selected)return;btnConfirm.disabled=true;btnConfirm.classList.add('loading');try{const res=await fetch('api_reagendar_live.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data_live:selected.data_live})});const j=await res.json();if(!j||!j.ok){modalHint.textContent=(j&&(j.message||j.error))?(j.message||j.error):'Falha ao reagendar.';btnConfirm.disabled=false;btnConfirm.classList.remove('loading');return}modalTitle.textContent='Reagendamento confirmado';modalText.textContent='Sua nova live: '+(j.live_nova||'');modalHint.textContent=j.live_url?'Link da live: '+j.live_url:'Data confirmada.';times.style.display='none';modalActions.style.display='none';successActions.style.display='flex'}catch(e){modalHint.textContent='Erro de rede. Tente novamente.';btnConfirm.disabled=false}btnConfirm.classList.remove('loading')};
document.querySelectorAll('.day.available').forEach(el=>el.onclick=()=>{try{openModal(JSON.parse(el.getAttribute('data-items')||'[]'))}catch(e){openModal([])}});
})();
</script>
</body>
</html>
