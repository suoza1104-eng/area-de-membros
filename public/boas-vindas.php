<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/welcome_page.php';

$pdo = getPDO();
welcome_page_ensure_schema($pdo);
$settings = welcome_page_settings();

$token = trim((string)($_GET['w'] ?? $_GET['t'] ?? ''));
$user = welcome_page_user_by_token($pdo, $token);
if (!$user || (string)($settings['welcome_page_enabled'] ?? '1') !== '1') {
    http_response_code(404);
    echo 'Pagina nao encontrada.';
    exit;
}

$url = welcome_page_ensure_user_link($pdo, (int)$user['id']);
$user['welcome_url'] = $url;
$vars = welcome_page_vars($user, $settings, $pdo);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = static fn(string $key): string => welcome_page_replace((string)($settings[$key] ?? ''), $vars, false);
$link = static fn(string $key): string => welcome_page_replace_url((string)($settings[$key] ?? '#'), $vars);
$videoHtml = welcome_page_video_html($settings);
$supportUrl = trim($link('welcome_page_support_url'));
if ($supportUrl === '') {
    $supportUrl = trim((string)get_setting('support_entry_whatsapp_url', ''));
    if ($supportUrl === '') $supportUrl = trim((string)get_setting('whatsapp_help_url', ''));
}
$courseName = $text('welcome_page_course_name');
$headline = $text('welcome_page_headline');
$lead = $text('welcome_page_lead');
$steps = [
    ['n' => 1, 'icon' => 'group', 'title' => $text('welcome_page_button1_text'), 'desc' => $text('welcome_page_button1_desc'), 'label' => $text('welcome_page_button1_label'), 'url' => $link('welcome_page_button1_url'), 'tag' => 'Prioridade'],
    ['n' => 2, 'icon' => 'phone', 'title' => $text('welcome_page_button2_text'), 'desc' => $text('welcome_page_button2_desc'), 'label' => $text('welcome_page_button2_label'), 'url' => $link('welcome_page_button2_url'), 'tag' => 'App'],
    ['n' => 3, 'icon' => 'bell', 'title' => $text('welcome_page_button3_text'), 'desc' => $text('welcome_page_button3_desc'), 'label' => $text('welcome_page_button3_label'), 'url' => $link('welcome_page_button3_url'), 'tag' => 'Avisos'],
    ['n' => 4, 'icon' => 'book', 'title' => $text('welcome_page_button4_text'), 'desc' => $text('welcome_page_button4_desc'), 'label' => $text('welcome_page_button4_label'), 'url' => $link('welcome_page_button4_url'), 'tag' => 'Bonus'],
];
$icon = static function (string $name): string {
    $paths = [
        'group' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'phone' => '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
        'bell' => '<path d="M10.27 21a2 2 0 0 0 3.46 0"/><path d="M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8A6 6 0 0 0 6 8c0 4.5-1.41 5.96-2.74 7.33"/>',
        'book' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24">' . ($paths[$name] ?? $paths['book']) . '</svg>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($courseName ?: 'Boas-vindas') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--ink:#0B1220;--muted:#5B6474;--line:#E8EBF1;--surface:#fff;--bg:#F4F6F9;--accent:#FACC15;--accent-strong:#EAB308;--accent-deep:#A16207;--accent-soft:#FEFCE8;--on-accent:#1A1300;--wa:#25D366;--wa-deep:#128C7E;--reserve:350px;--vw:min(860px,calc(100vw - 32px),max(300px,calc((100svh - var(--reserve)) * 1.7778)))}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter',system-ui,-apple-system,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;overflow-x:hidden}a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer;border:0;background:none;color:inherit}svg.i{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.top{position:relative;overflow:hidden;padding:22px 16px 0;text-align:center;color:#fff;background:linear-gradient(to bottom,transparent calc(100% - var(--vw) * .28),var(--bg) calc(100% - var(--vw) * .28)),radial-gradient(120% 90% at 50% 0%,#1E2433 0%,var(--ink) 60%)}.top::before{content:"";position:absolute;width:640px;height:640px;left:50%;top:-380px;transform:translateX(-50%);background:radial-gradient(circle,rgba(250,204,21,.28),transparent 65%);pointer-events:none}.top>*{position:relative;z-index:1}.pill{display:inline-flex;align-items:center;gap:9px;padding:5px 14px 5px 5px;border-radius:999px;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.35);color:#FDE68A;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;animation:rise .6s .1s ease both}.pill .ok{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(180deg,#FDE047,var(--accent-strong));color:var(--on-accent);box-shadow:0 0 14px rgba(250,204,21,.6)}h1{font-size:clamp(26px,3.6vw,44px);line-height:1.1;font-weight:900;letter-spacing:-.035em;margin:14px auto 8px;max-width:1000px;animation:rise .6s .2s ease both}h1 span{background:linear-gradient(90deg,#FEF08A,#FACC15 45%,#FDE047);-webkit-background-clip:text;background-clip:text;color:transparent}.lead{font-size:clamp(14px,1.6vw,16.5px);line-height:1.55;color:#AAB4C5;max-width:720px;margin:0 auto;animation:rise .6s .3s ease both}.lead b{color:#fff;font-weight:600}
.video{position:relative;width:var(--vw);aspect-ratio:16/9;margin:20px auto 0;border-radius:20px;overflow:hidden;background:#0A101C;box-shadow:0 40px 80px -30px rgba(8,12,22,.6),0 0 0 1px rgba(255,255,255,.08);animation:rise .7s .4s ease both}.video iframe,.video video{position:absolute;inset:0;width:100%;height:100%;border:0}.poster{position:absolute;inset:0;display:grid;place-items:center;background:radial-gradient(circle at 50% 45%,#262B38,#0A101C 70%)}.play{position:relative;width:76px;height:76px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(180deg,#FDE047,var(--accent-strong));box-shadow:0 20px 40px -10px rgba(250,204,21,.6)}.play svg{width:28px;height:28px;fill:var(--on-accent);stroke:none;margin-left:4px}.poster-cap{position:absolute;left:20px;right:20px;bottom:16px;display:flex;justify-content:space-between;align-items:flex-end;gap:12px;text-align:left}.poster-cap strong{display:block;font-size:clamp(14px,1.8vw,18px);font-weight:700}.poster-cap small{font-size:13px;color:#AAB4C5}.dur{flex:none;font-size:12px;font-weight:700;padding:5px 9px;border-radius:8px;background:rgba(255,255,255,.14);backdrop-filter:blur(6px)}
.wrap{max-width:860px;margin:0 auto;padding:0 16px 120px}.sec-head{margin:26px 0 16px;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 20px}.eyebrow{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--accent-deep);display:flex;align-items:center;gap:6px}.sec-head h2{font-size:clamp(20px,3vw,28px);font-weight:800;letter-spacing:-.025em;margin-top:4px}.progress{min-width:200px;flex:1;max-width:280px}.progress-row{display:flex;justify-content:space-between;font-size:13px;font-weight:600;color:var(--muted);margin-bottom:7px}.progress-row b{color:var(--ink)}.track{height:8px;border-radius:8px;background:#E2E7EE;overflow:hidden}.track i{display:block;height:100%;width:0;border-radius:8px;background:linear-gradient(90deg,#FDE047,var(--accent-strong));transition:width .6s cubic-bezier(.2,.8,.2,1)}
.steps{display:grid;gap:16px}.step{position:relative;display:grid;grid-template-columns:auto 1fr;gap:20px;background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:22px 24px;transition:border-color .3s,box-shadow .3s}.step:hover{box-shadow:0 20px 40px -24px rgba(8,12,22,.3)}.num{position:relative;width:52px;height:52px;border-radius:16px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent-deep);box-shadow:inset 0 0 0 1.5px rgba(234,179,8,.4)}.num svg{width:24px;height:24px}.num em{position:absolute;top:-8px;right:-8px;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:var(--ink);color:var(--accent);font-style:normal;font-size:11.5px;font-weight:800;display:grid;place-items:center;border:2px solid #fff}.step h3{font-size:18px;font-weight:700;letter-spacing:-.015em;display:flex;align-items:center;gap:10px;flex-wrap:wrap}.tag{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 8px;border-radius:999px;background:var(--ink);color:var(--accent)}.step p{margin-top:6px;font-size:14.5px;line-height:1.6;color:var(--muted);max-width:560px}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.btn{position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:9px;padding:13px 18px;border-radius:12px;font-size:14.5px;font-weight:700;background:linear-gradient(180deg,#FDE047,var(--accent-strong));color:var(--on-accent);box-shadow:0 10px 22px -12px var(--accent-deep),inset 0 1px 0 rgba(255,255,255,.5);transition:transform .15s,box-shadow .2s}.btn:hover{transform:translateY(-1px);box-shadow:0 14px 28px -12px var(--accent-deep),inset 0 1px 0 rgba(255,255,255,.5)}.btn svg{width:18px;height:18px}.mark{display:inline-flex;align-items:center;gap:7px;padding:13px 6px;font-size:13px;font-weight:600;color:#8A93A3;transition:color .2s}.mark .box{width:18px;height:18px;border-radius:6px;border:1.5px solid #C9D0DA;display:grid;place-items:center;transition:.2s}.mark .box svg{width:12px;height:12px;stroke-width:3.5;opacity:0;color:var(--on-accent)}.step.done{border-color:rgba(234,179,8,.55);background:linear-gradient(160deg,#FFFDF0,#fff 60%)}.step.done .num{background:linear-gradient(180deg,#FDE047,var(--accent-strong));color:var(--on-accent);box-shadow:none}.step.done .mark{color:var(--accent-deep)}.step.done .mark .box{background:var(--accent);border-color:var(--accent-strong)}.step.done .mark .box svg{opacity:1}.final{margin-top:24px;border-radius:22px;padding:28px;text-align:center;background:var(--ink);color:#fff;display:none}.final.show{display:block;animation:rise .6s ease both}.final h3{font-size:22px;font-weight:800;letter-spacing:-.02em}.final p{margin-top:6px;color:#AAB4C5;font-size:15px}.foot{text-align:center;margin-top:44px;font-size:13px;color:#8A93A3;line-height:1.7}.support{position:fixed;right:20px;bottom:20px;z-index:20;display:flex;align-items:center;gap:10px}.support-tip{background:#fff;color:var(--ink);font-size:13.5px;font-weight:600;padding:10px 14px;border-radius:14px 14px 4px 14px;box-shadow:0 12px 30px -10px rgba(8,12,22,.35);white-space:nowrap}.support-btn{position:relative;width:60px;height:60px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(180deg,var(--wa),var(--wa-deep));color:#fff;box-shadow:0 14px 30px -8px rgba(18,140,126,.65)}.support-btn svg{width:30px;height:30px}@keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@media(max-width:600px){:root{--reserve:330px}.top{padding-top:18px}.video{margin-top:16px;border-radius:14px}.poster-cap small{display:none}.step{grid-template-columns:1fr;gap:14px;padding:20px}.btn{flex:1;justify-content:center}.support-tip{display:none}}
</style>
</head>
<body>
<header class="top">
  <div class="pill"><span class="ok"><svg class="i" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span><?= $h($text('welcome_page_badge_text')) ?></div>
  <h1><?= $h($headline) ?> <span><?= $h($courseName) ?></span></h1>
  <p class="lead"><?= $h($lead) ?></p>
  <div class="video" id="video">
    <?php if ($videoHtml !== ''): ?>
      <?= $videoHtml ?>
    <?php else: ?>
      <div class="poster">
        <div class="play"><svg class="i" viewBox="0 0 24 24"><path d="M6 4.5v15a1 1 0 0 0 1.5.86l12.5-7.5a1 1 0 0 0 0-1.72L7.5 3.64A1 1 0 0 0 6 4.5z"/></svg></div>
        <div class="poster-cap"><div><strong><?= $h($text('welcome_page_video_title')) ?></strong><small><?= $h($text('welcome_page_video_subtitle')) ?></small></div><span class="dur"><?= $h($text('welcome_page_video_duration')) ?></span></div>
      </div>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
  <div class="sec-head">
    <div><div class="eyebrow"><svg class="i" viewBox="0 0 24 24"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>Seus primeiros passos</div><h2>Complete os passos abaixo</h2></div>
    <div class="progress"><div class="progress-row"><span>Seu progresso</span><b id="pText">0 de 4</b></div><div class="track"><i id="pBar"></i></div></div>
  </div>
  <div class="steps" id="steps">
    <?php foreach ($steps as $step): ?>
      <article class="step" data-step="<?= (int)$step['n'] ?>">
        <div class="num"><?= $icon($step['icon']) ?><em><?= (int)$step['n'] ?></em></div>
        <div>
          <h3><?= $h($step['title']) ?> <span class="tag"><?= $h($step['tag']) ?></span></h3>
          <p><?= $h($step['desc']) ?></p>
          <div class="actions">
            <a class="btn" href="<?= $h($step['url'] ?: '#') ?>" target="_blank" rel="noopener"><?= $icon($step['icon']) ?><?= $h($step['label']) ?></a>
            <button class="mark" type="button"><span class="box"><svg class="i" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span>Ja fiz</button>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="final" id="final"><h3>Tudo pronto!</h3><p>Agora e com voce: abra o app e assista a primeira aula ainda hoje.</p></div>
  <p class="foot"><?= $h($text('welcome_page_footer_text')) ?><br>&copy; <span id="yr"></span> Professor Emerson Leite</p>
</main>
<?php if ($supportUrl !== ''): ?>
<div class="support"><span class="support-tip">Precisa de ajuda?</span><a class="support-btn" href="<?= $h($supportUrl) ?>" target="_blank" rel="noopener" aria-label="Falar com suporte"><svg class="i" viewBox="0 0 24 24"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg></a></div>
<?php endif; ?>
<script>
document.getElementById('yr').textContent = new Date().getFullYear();
const KEY = 'welcome-steps-<?= $h($token) ?>';
let done = [];
try { done = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch(e) {}
const steps = [...document.querySelectorAll('.step')];
function save(){ try { localStorage.setItem(KEY, JSON.stringify(done)); } catch(e) {} }
function render(){
  steps.forEach(s => s.classList.toggle('done', done.includes(s.dataset.step)));
  document.getElementById('pText').textContent = done.length + ' de ' + steps.length;
  document.getElementById('pBar').style.width = (done.length / steps.length * 100) + '%';
  document.getElementById('final').classList.toggle('show', done.length === steps.length);
}
function setDone(id, val){ done = done.filter(x => x !== id); if (val) done.push(id); save(); render(); }
steps.forEach(s => {
  const id = s.dataset.step;
  s.querySelector('.mark').onclick = () => setDone(id, !done.includes(id));
  s.querySelectorAll('.btn').forEach(b => b.addEventListener('click', () => setDone(id, true)));
});
render();
</script>
</body>
</html>
