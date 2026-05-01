<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../vendor/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function fetch_qr_data_uri(string $verifyUrl, int $size): string
{
    if (!function_exists('curl_init')) return '';
    $apiUrl = 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size
            . '&cht=qr&choe=UTF-8&chl=' . urlencode($verifyUrl);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return ($raw && strlen((string)$raw) > 200)
        ? 'data:image/png;base64,' . base64_encode((string)$raw)
        : '';
}

/**
 * Gera o PDF do certificado usando o layout visual configurado.
 * Salva em /uploads/certificates e retorna a URL pública.
 */
function gerar_pdf_certificado(array $aluno, array $cert, array $config): string
{
    $nomeAluno   = $aluno['nome'] ?? '';
    $dataEmissao = isset($cert['emitido_em'])
        ? date('d/m/Y', strtotime($cert['emitido_em']))
        : date('d/m/Y');

    $codigoUid = $cert['codigo_uid'] ?? uniqid('cert_', true);
    $cursoNome = $cert['course']     ?? '';

    $layout = ['front' => [], 'back' => []];
    if (!empty($config['layout_json'])) {
        $tmp = json_decode($config['layout_json'], true);
        if (is_array($tmp)) {
            $layout['front'] = $tmp['front'] ?? [];
            $layout['back']  = $tmp['back']  ?? [];
        }
    }

    $basePublic     = rtrim(BASE_URL, '/');
    $baseRoot       = preg_replace('#/public$#', '', $basePublic);
    $certImgBaseUrl = $baseRoot . '/uploads/certificados';

    $frontImageUrl = !empty($config['front_image']) ? $certImgBaseUrl . '/' . $config['front_image'] : null;
    $backImageUrl  = !empty($config['back_image'])  ? $certImgBaseUrl . '/' . $config['back_image']  : null;

    $verifyUrl = $basePublic . '/verificar_certificado.php?c=' . urlencode($codigoUid);

    $fieldValues = [
        'nome'  => $nomeAluno,
        'data'  => $dataEmissao,
        'qr'    => $verifyUrl,
        'curso' => $cursoNome,
    ];

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
@page { size: A4 landscape; margin: 0; }
html, body { margin: 0; padding: 0; height: 100%; }
body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; }
.page { position: relative; width: 100%; height: 100%; overflow: hidden; }
.bg-img { position: absolute; left: 0; top: 0; width: 100%; height: 100%; object-fit: cover; }
.field {
    position: absolute;
    transform: translate(-50%, -50%);
    color: #000000;
    font-weight: bold;
    white-space: nowrap;
}
.field-qr {
    position: absolute;
    transform: translate(-50%, -50%);
}
</style>
</head>
<body>

<?php if ($frontImageUrl && !empty($layout['front'])): ?>
<div class="page">
    <img src="<?= htmlspecialchars($frontImageUrl, ENT_QUOTES, 'UTF-8') ?>" class="bg-img" alt="Certificado frente">
    <?php foreach ($layout['front'] as $item):
        $fieldKey   = $item['field']      ?? '';
        $x          = (float)($item['x']  ?? 50);
        $y          = (float)($item['y']  ?? 50);
        $font       = (int)($item['font'] ?? 18);
        $fontFamily = htmlspecialchars($item['fontFamily'] ?? 'DejaVu Sans', ENT_QUOTES, 'UTF-8');

        if ($fieldKey === 'qr'):
            $qrSize    = max(50, min(400, $font));
            $qrDataUri = fetch_qr_data_uri($fieldValues['qr'], $qrSize);
    ?>
    <div class="field-qr" style="left:<?= $x ?>%;top:<?= $y ?>%;">
        <?php if ($qrDataUri): ?>
        <img src="<?= $qrDataUri ?>" width="<?= $qrSize ?>" height="<?= $qrSize ?>" alt="QR">
        <?php else: ?>
        <div style="width:<?= $qrSize ?>px;height:<?= $qrSize ?>px;background:#eee;border:1px solid #999;font-size:10px;color:#666;">[QR]</div>
        <?php endif; ?>
    </div>
    <?php else:
        $valor = $fieldValues[$fieldKey] ?? '';
        if ($valor === '') continue;
    ?>
    <div class="field" style="left:<?= $x ?>%;top:<?= $y ?>%;font-size:<?= $font ?>px;font-family:<?= $fontFamily ?>;">
        <?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; endforeach; ?>
</div>

<?php else: ?>
<div class="page" style="background:#f5f5f5;">
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
        <div style="text-align:center;width:80%;">
            <div style="font-size:32px;font-weight:bold;margin-bottom:20px;">Certificado de Conclusão</div>
            <div style="font-size:16px;margin-bottom:20px;">Certificamos que</div>
            <div style="font-size:28px;font-weight:bold;margin:20px 0;"><?= htmlspecialchars($nomeAluno, ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size:16px;line-height:1.5;margin-bottom:30px;">
                concluiu com êxito <?= htmlspecialchars($cursoNome ?: 'o curso', ENT_QUOTES, 'UTF-8') ?>.
                Emitido em <?= htmlspecialchars($dataEmissao, ENT_QUOTES, 'UTF-8') ?>.
            </div>
            <div style="font-size:10px;margin-top:40px;color:#555;">Código: <?= htmlspecialchars($codigoUid, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($backImageUrl && !empty($layout['back'])): ?>
<div class="page">
    <img src="<?= htmlspecialchars($backImageUrl, ENT_QUOTES, 'UTF-8') ?>" class="bg-img" alt="Certificado verso">
    <?php foreach ($layout['back'] as $item):
        $fieldKey   = $item['field']      ?? '';
        $x          = (float)($item['x']  ?? 50);
        $y          = (float)($item['y']  ?? 50);
        $font       = (int)($item['font'] ?? 18);
        $fontFamily = htmlspecialchars($item['fontFamily'] ?? 'DejaVu Sans', ENT_QUOTES, 'UTF-8');

        if ($fieldKey === 'qr'):
            $qrSize    = max(50, min(400, $font));
            $qrDataUri = fetch_qr_data_uri($fieldValues['qr'], $qrSize);
    ?>
    <div class="field-qr" style="left:<?= $x ?>%;top:<?= $y ?>%;">
        <?php if ($qrDataUri): ?>
        <img src="<?= $qrDataUri ?>" width="<?= $qrSize ?>" height="<?= $qrSize ?>" alt="QR">
        <?php else: ?>
        <div style="width:<?= $qrSize ?>px;height:<?= $qrSize ?>px;background:#eee;border:1px solid #999;font-size:10px;color:#666;">[QR]</div>
        <?php endif; ?>
    </div>
    <?php else:
        $valor = $fieldValues[$fieldKey] ?? '';
        if ($valor === '') continue;
    ?>
    <div class="field" style="left:<?= $x ?>%;top:<?= $y ?>%;font-size:<?= $font ?>px;font-family:<?= $fontFamily ?>;">
        <?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $dir = __DIR__ . '/../uploads/certificates';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $filename = $codigoUid . '.pdf';
    $filepath = $dir . '/' . $filename;
    file_put_contents($filepath, $dompdf->output());

    $pdfUrl = $baseRoot . '/uploads/certificates/' . $filename;
    return $pdfUrl;
}
