<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';

$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$token=(string)($_GET['t']??$_POST['t']??'');
$data=$token!==''?email_parse_unsubscribe_token($token):null;

if(!$data){
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Descadastro</title><p>Link de descadastro inválido ou expirado.</p>';
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'||isset($_GET['one_click'])){
    email_mark_unsubscribed($pdo,(int)$data['user_id'],(string)$data['email'],isset($_GET['one_click'])?'one_click':'unsubscribe_page');
    http_response_code(200);
    echo '<!doctype html><meta charset="utf-8"><title>Descadastro confirmado</title><p>Seu e-mail foi removido dos envios de marketing.</p>';
    exit;
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Descadastro</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f6f7fb;color:#111827;margin:0;display:grid;place-items:center;min-height:100vh}
    main{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:28px;max-width:460px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
    h1{font-size:22px;margin:0 0 10px}
    p{line-height:1.5;color:#475569}
    button{background:#111827;color:#fff;border:0;border-radius:6px;padding:11px 16px;font-weight:700;cursor:pointer}
  </style>
</head>
<body>
<main>
  <h1>Confirmar descadastro</h1>
  <p>Ao confirmar, <?=email_h((string)$data['email'])?> deixará de receber e-mails de marketing.</p>
  <form method="post">
    <input type="hidden" name="t" value="<?=email_h($token)?>">
    <button type="submit">Confirmar descadastro</button>
  </form>
</main>
</body>
</html>
