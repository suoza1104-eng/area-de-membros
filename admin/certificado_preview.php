<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/certificado_pdf.php';

proteger_admin();

$pdo = getPDO();
$stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    die('Configuração de certificado não encontrada. Configure o certificado primeiro.');
}

$alunoFake = [
    'id'    => 0,
    'nome'  => 'Aluno Teste Certificado',
    'email' => 'teste@exemplo.com',
];

$certFake = [
    'codigo_uid' => 'CERT_TESTE_' . date('Ymd_His'),
    'emitido_em' => date('Y-m-d H:i:s'),
];

$pdfUrl = gerar_pdf_certificado($alunoFake, $certFake, $config);

header('Location: ' . $pdfUrl);
exit;
