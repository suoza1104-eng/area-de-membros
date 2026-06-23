<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
session_start();
proteger_admin();

function normalizarTelefone(?string $tel): string {
    $tel = (string)$tel;
    $digits = preg_replace('/\D+/', '', $tel) ?? '';
    // Se vier com 55 + DDD + número (13 dígitos) ou 55 + DDD + número (12 dígitos em alguns casos)
    if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
        $digits = substr($digits, 2);
    }
    // Remove zeros à esquerda (raros, mas ajudam em alguns casos)
    $digits = ltrim($digits, '0');
    return $digits;
}

function parseDataHotmart(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '' || strtolower($s) === '(none)') return null;
    // Formato: 26/02/2026 13:11:29
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $s);
    if (!$dt) return null;
    return $dt->format('Y-m-d H:i:s');
}

function parseMoney(?string $s): ?float {
    $s = trim((string)$s);
    if ($s === '' || strtolower($s) === '(none)') return null;

    // Hotmart costuma vir com ponto decimal (ex: 347.34). Mas pode vir com vírgula em alguns exports.
    // Normaliza: remove separadores de milhar e garante ponto decimal.
    $s = str_replace(['R$', ' '], '', $s);
    // Se tiver vírgula e também ponto, assume ponto milhar e vírgula decimal (pt-BR)
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        // Se só tiver vírgula, assume decimal
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

$pdo = getPDO();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Erro no upload do arquivo.';
    } else {
        $tmp = $_FILES['csv']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $msg = 'Não foi possível ler o arquivo.';
        } else {
            // Hotmart: CSV com BOM e separador ';'
            $header = fgetcsv($fh, 0, ';');
            if (!$header) {
                $msg = 'CSV sem cabeçalho.';
            } else {
                // Remove BOM do primeiro campo se existir
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

                $map = [];
                foreach ($header as $i => $col) {
                    $map[trim($col)] = $i;
                }

                // Campos usados do seu CSV (pelos nomes do Hotmart PT-BR)
                $col = fn(string $name) => $map[$name] ?? null;

                $required = [
                    'Código da transação',
                    'Status da transação',
                    'Data da transação',
                    'Confirmação do pagamento',
                    'Código do produto',
                    'Produto',
                    'Código do preço',
                    'Nome deste preço',
                    'Moeda de compra',
                    'Faturamento bruto (sem impostos)',
                    'Faturamento líquido',
                    'Faturamento líquido do(a) Produtor(a)',
                    'Comprador(a)',
                    'Email do(a) Comprador(a)',
                    'Telefone',
                ];
                foreach ($required as $r) {
                    if ($col($r) === null) {
                        $msg = "Coluna obrigatória não encontrada no CSV: {$r}";
                        fclose($fh);
                        goto render;
                    }
                }

                $stmtUserPhone = $pdo->prepare("SELECT id, utm_source, utm_medium, utm_campaign, utm_term, utm_content FROM users WHERE telefone = :t LIMIT 1");
                $stmtUserEmail = $pdo->prepare("SELECT id, utm_source, utm_medium, utm_campaign, utm_term, utm_content FROM users WHERE LOWER(email) = :e LIMIT 1");

                $stmtUpsert = $pdo->prepare("
                    INSERT INTO hotmart_sales (
                        transaction_code, status, transaction_date, payment_confirmed_at,
                        product_code, product_name, price_code, price_name, currency,
                        gross_revenue, net_revenue, producer_net,
                        buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm,
                        matched_user_id, match_method,
                        utm_source, utm_medium, utm_campaign, utm_term, utm_content
                    ) VALUES (
                        :transaction_code, :status, :transaction_date, :payment_confirmed_at,
                        :product_code, :product_name, :price_code, :price_name, :currency,
                        :gross_revenue, :net_revenue, :producer_net,
                        :buyer_name, :buyer_email, :buyer_phone_raw, :buyer_phone_norm,
                        :matched_user_id, :match_method,
                        :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content
                    )
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        transaction_date = VALUES(transaction_date),
                        payment_confirmed_at = VALUES(payment_confirmed_at),
                        product_code = VALUES(product_code),
                        product_name = VALUES(product_name),
                        price_code = VALUES(price_code),
                        price_name = VALUES(price_name),
                        currency = VALUES(currency),
                        gross_revenue = VALUES(gross_revenue),
                        net_revenue = VALUES(net_revenue),
                        producer_net = VALUES(producer_net),
                        buyer_name = VALUES(buyer_name),
                        buyer_email = VALUES(buyer_email),
                        buyer_phone_raw = VALUES(buyer_phone_raw),
                        buyer_phone_norm = VALUES(buyer_phone_norm),
                        matched_user_id = VALUES(matched_user_id),
                        match_method = VALUES(match_method),
                        utm_source = VALUES(utm_source),
                        utm_medium = VALUES(utm_medium),
                        utm_campaign = VALUES(utm_campaign),
                        utm_term = VALUES(utm_term),
                        utm_content = VALUES(utm_content),
                        updated_at = CURRENT_TIMESTAMP
                ");

                $pdo->beginTransaction();
                $count = 0;
                $matchedPhone = 0;
                $matchedEmail = 0;
                $organic = 0;
                $lifetimeGranted = 0;

                while (($row = fgetcsv($fh, 0, ';')) !== false) {
                    $transactionCode = trim((string)$row[$col('Código da transação')]);

                    if ($transactionCode === '') continue;

                    $status = trim((string)$row[$col('Status da transação')]);
                    $transactionDate = parseDataHotmart($row[$col('Data da transação')]);
                    $payConfirmed = parseDataHotmart($row[$col('Confirmação do pagamento')]);

                    $productCode = (int)($row[$col('Código do produto')] ?? 0);
                    $productName = trim((string)$row[$col('Produto')]);
                    $priceCode = trim((string)$row[$col('Código do preço')]);
                    $priceName = trim((string)$row[$col('Nome deste preço')]);

                    $currency = trim((string)$row[$col('Moeda de compra')]);

                    $gross = parseMoney($row[$col('Faturamento bruto (sem impostos)')]);
                    $net = parseMoney($row[$col('Faturamento líquido')]);
                    $producerNet = parseMoney($row[$col('Faturamento líquido do(a) Produtor(a)')]);

                    $buyerName = trim((string)$row[$col('Comprador(a)')]);
                    $buyerEmail = trim((string)$row[$col('Email do(a) Comprador(a)')]);
                    $buyerEmailLower = mb_strtolower($buyerEmail);
                    $buyerPhoneRaw = trim((string)$row[$col('Telefone')]);
                    $buyerPhoneNorm = normalizarTelefone($buyerPhoneRaw);

                    // Match: telefone -> email -> orgânico
                    $matchedUserId = null;
                    $matchMethod = 'none';
                    $utm = [
                        'utm_source' => 'organico',
                        'utm_medium' => null,
                        'utm_campaign' => null,
                        'utm_term' => null,
                        'utm_content' => null,
                    ];

                    if ($buyerPhoneNorm !== '') {
                        $stmtUserPhone->execute([':t' => $buyerPhoneNorm]);
                        $u = $stmtUserPhone->fetch(PDO::FETCH_ASSOC);
                        if ($u) {
                            $matchedUserId = (int)$u['id'];
                            $matchMethod = 'phone';
                            $utm = [
                                'utm_source' => $u['utm_source'] ?? 'organico',
                                'utm_medium' => $u['utm_medium'] ?? null,
                                'utm_campaign' => $u['utm_campaign'] ?? null,
                                'utm_term' => $u['utm_term'] ?? null,
                                'utm_content' => $u['utm_content'] ?? null,
                            ];
                            $matchedPhone++;
                        }
                    }

                    if (!$matchedUserId && $buyerEmailLower !== '') {
                        $stmtUserEmail->execute([':e' => $buyerEmailLower]);
                        $u = $stmtUserEmail->fetch(PDO::FETCH_ASSOC);
                        if ($u) {
                            $matchedUserId = (int)$u['id'];
                            $matchMethod = 'email';
                            $utm = [
                                'utm_source' => $u['utm_source'] ?? 'organico',
                                'utm_medium' => $u['utm_medium'] ?? null,
                                'utm_campaign' => $u['utm_campaign'] ?? null,
                                'utm_term' => $u['utm_term'] ?? null,
                                'utm_content' => $u['utm_content'] ?? null,
                            ];
                            $matchedEmail++;
                        }
                    }

                    if (!$matchedUserId) $organic++;

                    $stmtUpsert->execute([
                        ':transaction_code' => $transactionCode,
                        ':status' => $status,
                        ':transaction_date' => $transactionDate,
                        ':payment_confirmed_at' => $payConfirmed,
                        ':product_code' => $productCode ?: null,
                        ':product_name' => $productName ?: null,
                        ':price_code' => $priceCode ?: null,
                        ':price_name' => $priceName ?: null,
                        ':currency' => $currency ?: null,
                        ':gross_revenue' => $gross,
                        ':net_revenue' => $net,
                        ':producer_net' => $producerNet,
                        ':buyer_name' => $buyerName ?: null,
                        ':buyer_email' => $buyerEmailLower ?: null,
                        ':buyer_phone_raw' => $buyerPhoneRaw ?: null,
                        ':buyer_phone_norm' => $buyerPhoneNorm ?: null,
                        ':matched_user_id' => $matchedUserId,
                        ':match_method' => $matchMethod,
                        ':utm_source' => $utm['utm_source'],
                        ':utm_medium' => $utm['utm_medium'],
                        ':utm_campaign' => $utm['utm_campaign'],
                        ':utm_term' => $utm['utm_term'],
                        ':utm_content' => $utm['utm_content'],
                    ]);

                    $lifetimeAttempt = course_access_try_grant_lifetime_purchase($pdo, [
                        'user_id' => $matchedUserId,
                        'offer_code' => $priceCode,
                        'transaction_code' => $transactionCode,
                        'status' => $status,
                        'email' => $buyerEmailLower,
                        'phone' => $buyerPhoneNorm ?: $buyerPhoneRaw,
                        'source' => 'hotmart_sales',
                        'payload' => [
                            'transaction_code' => $transactionCode,
                            'status' => $status,
                            'transaction_date' => $transactionDate,
                            'payment_confirmed_at' => $payConfirmed,
                            'product_code' => $productCode ?: null,
                            'product_name' => $productName ?: null,
                            'price_code' => $priceCode ?: null,
                            'price_name' => $priceName ?: null,
                            'currency' => $currency ?: null,
                            'gross_revenue' => $gross,
                            'net_revenue' => $net,
                            'producer_net' => $producerNet,
                            'buyer_name' => $buyerName ?: null,
                            'buyer_email' => $buyerEmailLower ?: null,
                            'buyer_phone_norm' => $buyerPhoneNorm ?: null,
                        ],
                    ]);
                    if (!empty($lifetimeAttempt['granted'])) {
                        $lifetimeGranted++;
                    }

                    $count++;
                }

                $pdo->commit();
                fclose($fh);

                $msg = "Importação concluída! Registros processados: {$count} | Match por telefone: {$matchedPhone} | Match por email: {$matchedEmail} | Orgânico: {$organic} | Vitalícios liberados: {$lifetimeGranted}";
            }
        }
    }
}

render:
require_once __DIR__ . '/_header.php';
?>
<div class="container" style="max-width: 900px; margin: 20px auto;">
  <h2>Importar Vendas Hotmart (CSV)</h2>

  <?php if ($msg): ?>
    <div style="padding:12px;border-radius:8px;background:#f3f3f3;margin:12px 0;">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" style="padding:16px;border:1px solid #eee;border-radius:10px;">
    <label><strong>Arquivo CSV da Hotmart:</strong></label><br>
    <input type="file" name="csv" accept=".csv" required style="margin:10px 0;"><br>
    <button type="submit" class="btn btn-primary">Importar</button>
  </form>

  <p style="margin-top:14px;">
    Depois de importar, acesse a página de análise:
    <a href="vendas_analytics.php">vendas_analytics.php</a>
  </p>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
