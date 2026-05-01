<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../vendor/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Só admin pode testar
proteger_admin();

/**
 * Mesma função usada para gerar o certificado real
 * (é basicamente o código que você me mandou).
 */
function gerar_pdf_certificado(array $aluno, array $cert, array $config): string
{
    $nomeAluno  = $aluno['nome'] ?? '';
    $dataEmissao = isset($cert['emitido_em'])
        ? date('d/m/Y', strtotime($cert['emitido_em']))
        : date('d/m/Y');

    // ====== LAYOUT (JSON) ======
    $layout = [
        'front' => [],
        'back'  => [],
    ];
    if (!empty($config['layout_json'])) {
        $tmp = json_decode($config['layout_json'], true);
        if (is_array($tmp)) {
            $layout['front'] = $tmp['front'] ?? [];
            $layout['back']  = $tmp['back']  ?? [];
        }
    }

    // ====== IMAGENS DE FUNDO (FRENTE / VERSO) ======
    $basePublic = rtrim(BASE_URL, '/');                     // .../area_membros/public
    $baseRoot   = preg_replace('#/public$#', '', $basePublic); // .../area_membros

    $certImgBaseUrl = $baseRoot . '/uploads/certificados';

    $frontImageUrl = !empty($config['front_image'])
        ? $certImgBaseUrl . '/' . $config['front_image']
        : null;

    $backImageUrl = !empty($config['back_image'])
        ? $certImgBaseUrl . '/' . $config['back_image']
        : null;

    // ====== MAPA DOS VALORES DAS VARIÁVEIS ======
    $fieldValues = [
        'nome' => $nomeAluno,
        'data' => $dataEmissao,
    ];

    // ====== MONTA HTML ======
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
        }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        }
        .page {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        .bg-img {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .field {
            position: absolute;
            transform: translate(-50%, -50%);
            color: #000000;
            font-weight: bold;
            white-space: nowrap;
        }
    </style>
</head>
<body>

<?php if ($frontImageUrl && !empty($layout['front'])): ?>
    <!-- FRENTE -->
    <div class="page">
        <img src="<?= htmlspecialchars($frontImageUrl, ENT_QUOTES, 'UTF-8') ?>"
             class="bg-img" alt="Certificado frente">
        <?php foreach ($layout['front'] as $item): ?>
            <?php
                $fieldKey   = $item['field'] ?? '';
                $valorCampo = $fieldValues[$fieldKey] ?? '';
                if ($valorCampo === '') {
                    continue;
                }
                $x    = isset($item['x'])    ? (float)$item['x']    : 50.0;
                $y    = isset($item['y'])    ? (float)$item['y']    : 50.0;
                $font = isset($item['font']) ? (int)$item['font']   : 18;
            ?>
            <div class="field"
                 style="left: <?= $x ?>%; top: <?= $y ?>%; font-size: <?= $font ?>px;">
                <?= htmlspecialchars($valorCampo, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <!-- Fallback simples, se não tiver layout configurado -->
    <div class="page" style="background-color:#f5f5f5;display:flex;align-items:center;justify-content:center;">
        <div style="text-align:center;width:80%;">
            <div style="font-size:32px;font-weight:bold;margin-bottom:20px;">Certificado de Conclusão</div>
            <div style="font-size:16px;margin-bottom:20px;">Certificamos que</div>
            <div style="font-size:28px;font-weight:bold;margin:20px 0;">
                <?= htmlspecialchars($nomeAluno, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size:16px;line-height:1.5;margin-bottom:30px;">
                concluiu com êxito o treinamento oferecido por
                Professor Emerson Leite.<br>
                Emitido em <?= htmlspecialchars($dataEmissao, ENT_QUOTES, 'UTF-8') ?>.
            </div>
            <div style="font-size:10px;margin-top:40px;color:#555;">
                Código do certificado: <?= htmlspecialchars($cert['codigo_uid'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($backImageUrl && !empty($layout['back'])): ?>
    <!-- VERSO -->
    <div class="page">
        <img src="<?= htmlspecialchars($backImageUrl, ENT_QUOTES, 'UTF-8') ?>"
             class="bg-img" alt="Certificado verso">
        <?php foreach ($layout['back'] as $item): ?>
            <?php
                $fieldKey   = $item['field'] ?? '';
                $valorCampo = $fieldValues[$fieldKey] ?? '';
                if ($valorCampo === '') {
                    continue;
                }
                $x    = isset($item['x'])    ? (float)$item['x']    : 50.0;
                $y    = isset($item['y'])    ? (float)$item['y']    : 50.0;
                $font = isset($item['font']) ? (int)$item['font']   : 18;
            ?>
            <div class="field"
                 style="left: <?= $x ?>%; top: <?= $y ?>%; font-size: <?= $font ?>px;">
                <?= htmlspecialchars($valorCampo, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    // ====== Dompdf ======
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    // ====== Salva arquivo ======
    $dir = __DIR__ . '/../uploads/certificates';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $filename = ($cert['codigo_uid'] ?? uniqid('cert_', true)) . '.pdf';
    $filepath = $dir . '/' . $filename;

    file_put_contents($filepath, $dompdf->output());

    // ====== URL pública ======
    $basePublic = rtrim(BASE_URL, '/');              // .../area_membros/public
    $baseRoot   = preg_replace('#/public$#', '', $basePublic); // .../area_membros

    return $baseRoot . '/uploads/certificates/' . $filename;
}

// ====== Carrega configuração do certificado ======
$pdo = getPDO();
$stmt = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    die('Configuração de certificado não encontrada.');
}

// Aluno e certificado fictícios para teste
$alunoFake = [
    'id'    => 0,
    'nome'  => 'Aluno Teste Certificado',
    'email' => 'teste@professoremersonleite.com',
];

$certFake = [
    'codigo_uid' => 'CERT_TESTE_' . date('Ymd_His'),
    'emitido_em' => date('Y-m-d H:i:s'),
];

// Gera PDF e redireciona
$pdfUrl = gerar_pdf_certificado($alunoFake, $certFake, $config);

header('Location: ' . $pdfUrl);
exit;
