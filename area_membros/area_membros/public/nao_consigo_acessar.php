<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Não consigo acessar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        <?= theme_inline_css_vars(); ?>
        body{margin:0;font-family:Arial,sans-serif;background:var(--bg-main);color:var(--text-main);}
        .wrap{max-width:600px;margin:40px auto;padding:16px;}
        a{color:var(--secondary);}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Não consegue acessar?</h1>
    <p>Verifique se:</p>
    <ul>
        <li>Você está usando o <strong>mesmo e-mail</strong> utilizado na inscrição;</li>
        <li>A senha é o <strong>telefone cadastrado</strong> apenas com números.</li>
    </ul>
    <p>Se ainda assim não conseguir, entre em contato com nosso suporte informando seu nome, e-mail e telefone:</p>
    <p><strong>WhatsApp:</strong> atualize aqui o link direto no código (ou em configurações futuras).</p>
    <p><a href="login.php">&larr; Voltar para o login</a></p>
</div>
</body>
</html>
