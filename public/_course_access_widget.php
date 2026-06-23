<?php
if (!isset($courseAccess) || !is_array($courseAccess)) return;
$caEnabled = !empty($courseAccess['enabled']);
$caExpired = !empty($courseAccess['expired']);
$caLifetime = !empty($courseAccess['lifetime']);
$caCountdown = $caEnabled && !$caLifetime && !empty($courseAccess['countdown_enabled']) && !empty($courseAccess['expires_at_iso']);
$caCheckout = trim((string)($courseAccess['checkout_url'] ?? ''));
$caMessage = trim((string)($courseAccess['message'] ?? ''));
if ($caMessage === '') {
    $caMessage = 'O conteúdo foi bloqueado porque o prazo máximo de acesso terminou. Libere o acesso vitalício para continuar estudando.';
}
if (!$caEnabled && !$caLifetime) return;
?>
<style>
.course-access-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 16px;margin-bottom:18px;border:1px solid rgba(250,204,21,.28);border-radius:14px;background:rgba(250,204,21,.08);color:#e5e7eb}
.course-access-bar.lifetime{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.08)}
.course-access-copy{display:flex;flex-direction:column;gap:3px}
.course-access-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#facc15}
.course-access-bar.lifetime .course-access-title{color:#4ade80}
.course-access-clock{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums}
.course-access-sub{font-size:11px;color:#9ca3af}
.course-access-cta{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border:0;border-radius:10px;background:#facc15;color:#111827;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer}
.course-access-modal{position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(2,6,23,.82);backdrop-filter:blur(5px)}
.course-access-modal.open{display:flex}
.course-access-dialog{width:min(480px,100%);padding:28px;border:1px solid rgba(250,204,21,.3);border-radius:18px;background:#0b1220;box-shadow:0 24px 80px rgba(0,0,0,.55);text-align:center;color:#e5e7eb}
.course-access-icon{width:56px;height:56px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:rgba(250,204,21,.12);font-size:26px}
.course-access-dialog h2{margin:0 0 10px;font-size:21px}
.course-access-dialog p{margin:0 0 20px;color:#9ca3af;font-size:14px;line-height:1.6}
.course-access-dialog .course-access-cta{width:100%;font-size:14px;padding:12px 16px}
</style>

<div class="course-access-bar <?= $caLifetime ? 'lifetime' : '' ?>">
    <div class="course-access-copy">
        <div class="course-access-title"><?= $caLifetime ? 'Acesso vitalício liberado' : ($caExpired ? 'Prazo encerrado' : 'Tempo restante de acesso') ?></div>
        <?php if ($caLifetime): ?>
            <div class="course-access-clock">Sem prazo de expiração</div>
        <?php elseif ($caCountdown): ?>
            <div class="course-access-clock" data-course-access-countdown data-expires-at="<?= htmlspecialchars((string)$courseAccess['expires_at_iso'], ENT_QUOTES, 'UTF-8') ?>">--d --h --m --s</div>
            <div class="course-access-sub">Após este prazo, o conteúdo será bloqueado.</div>
        <?php else: ?>
            <div class="course-access-clock">Prazo máximo encerrado</div>
        <?php endif; ?>
    </div>
    <?php if (!$caLifetime && $caCheckout !== ''): ?>
        <a class="course-access-cta" href="<?= htmlspecialchars($caCheckout, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Liberar acesso vitalício</a>
    <?php endif; ?>
</div>

<?php if ($caExpired): ?>
<div class="course-access-modal open" id="courseAccessExpiredModal" role="dialog" aria-modal="true" aria-labelledby="courseAccessExpiredTitle">
    <div class="course-access-dialog">
        <div class="course-access-icon">🔒</div>
        <h2 id="courseAccessExpiredTitle">Conteúdo bloqueado</h2>
        <p><?= nl2br(htmlspecialchars($caMessage, ENT_QUOTES, 'UTF-8')) ?></p>
        <?php if ($caCheckout !== ''): ?>
            <a class="course-access-cta" href="<?= htmlspecialchars($caCheckout, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Liberar acesso vitalício</a>
        <?php else: ?>
            <p>Entre em contato com o suporte para recuperar seu acesso.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($caCountdown): ?>
<script>
(function(){
    var el = document.querySelector('[data-course-access-countdown]');
    if (!el) return;
    var target = new Date(el.getAttribute('data-expires-at')).getTime();
    function tick(){
        var diff = target - Date.now();
        if (diff <= 0) {
            el.textContent = 'Prazo encerrado';
            window.setTimeout(function(){ window.location.reload(); }, 800);
            return;
        }
        var days = Math.floor(diff / 86400000);
        var hours = Math.floor((diff % 86400000) / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);
        el.textContent = days + 'd ' + String(hours).padStart(2,'0') + 'h ' + String(minutes).padStart(2,'0') + 'm ' + String(seconds).padStart(2,'0') + 's';
        window.setTimeout(tick, 1000);
    }
    tick();
})();
</script>
<?php endif; ?>
