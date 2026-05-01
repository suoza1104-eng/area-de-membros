<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

if (function_exists('proteger_admin')) {
    proteger_admin();
}

$pdo = getPDO();

function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}


$uid = (int)($_GET['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(400);
    echo "Parâmetro user_id é obrigatório.";
    exit;
}

$st = $pdo->prepare("SELECT id, nome, email, telefone FROM users WHERE id = :id LIMIT 1");
$st->execute([':id' => $uid]);
$aluno = $st->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    http_response_code(404);
    echo "Aluno não encontrado.";
    exit;
}

// precisa das tabelas
if (!table_exists($pdo, 'live_reschedule_tokens')) {
    http_response_code(500);
    echo "Tabela live_reschedule_tokens não existe. Rode o SQL do pacote primeiro.";
    exit;
}

$ttl = (int)get_setting('reagendar_token_ttl_hours', '72');
if ($ttl < 1) $ttl = 72;
if ($ttl > 720) $ttl = 720;

// Gera token
$token = bin2hex(random_bytes(16)); // 32 chars
$expires = (new DateTime())->modify('+' . $ttl . ' hours')->format('Y-m-d H:i:s');

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$createdBy = $adminId > 0 ? $adminId : null;

$ins = $pdo->prepare("
    INSERT INTO live_reschedule_tokens
        (user_id, token, expires_at, used_at, created_at, created_by_admin_id)
    VALUES
        (:u, :t, :e, NULL, NOW(), :a)
");
$ins->execute([
    ':u' => (int)$aluno['id'],
    ':t' => $token,
    ':e' => $expires,
    ':a' => $createdBy,
]);

$publicBase = rtrim(dirname(BASE_URL_ADMIN, 1), '/');
$link = $publicBase . '/public/reagendar_live.php?t=' . urlencode($token);

function fmt_ptbr(?string $dt, string $fallback = '-') {
    if (!$dt) return $fallback;
    $ts = strtotime($dt);
    if ($ts === false) return $fallback;
    return date('d/m/Y H:i', $ts);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Link de reagendamento</title>
<style>
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#1a1a1a;color:#eaeaea}
    .wrap{max-width:980px;margin:0 auto;padding:24px}
    .card{background:#0f0f10;border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:16px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
    h1{font-size:18px;margin:0 0 12px}
    .muted{opacity:.75}
    code{display:block;padding:12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);word-break:break-all}
    .btn{background:#facc15;color:#111;border:none;border-radius:999px;padding:10px 14px;font-weight:700;cursor:pointer;margin-top:12px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Link de reagendamento gerado</h1>
        <div class="muted" style="margin-bottom:10px">
            Aluno: <b><?= h($aluno['nome']) ?></b> • <?= h($aluno['email']) ?> • <?= h($aluno['telefone']) ?><br>
            Válido até: <b><?= h(fmt_ptbr($expires)) ?></b>
        </div>

        <code id="link"><?= h($link) ?></code>

        <div class="row">
            <button class="btn" id="copy">Copiar link</button>
            <a class="btn" style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.15);text-decoration:none" href="<?= h(BASE_URL_ADMIN . '/alunos.php') ?>">Voltar para alunos</a>
        </div>
    </div>
</div>
<script>
document.getElementById('copy').addEventListener('click', async ()=>{
    const text = document.getElementById('link').innerText;
    try{
        await navigator.clipboard.writeText(text);
        alert('Copiado ✅');
    }catch(e){
        alert('Não foi possível copiar automaticamente. Selecione e copie manualmente.');
    }
});
</script>
</body>
</html>
