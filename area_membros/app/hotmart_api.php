<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/course_access.php';
require_once __DIR__ . '/payment_events.php';

function hotmart_get_oauth_token(string $clientId, string $clientSecret): ?string
{
    $basic = base64_encode($clientId . ':' . $clientSecret);
    $url = "https://api-sec-vlc.hotmart.com/security/oauth/token?grant_type=client_credentials";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $basic
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$res) return null;

    $json = json_decode($res, true);
    return is_array($json) && !empty($json['access_token']) ? (string)$json['access_token'] : null;
}

function hotmart_sync_sales_api(PDO $pdo, int $days = 7): array
{
    $clientId = trim((string)get_setting('hotmart_client_id', ''));
    $clientSecret = trim((string)get_setting('hotmart_client_secret', ''));

    if ($clientId === '' || $clientSecret === '') {
        return [
            'ok' => false,
            'message' => 'Client ID ou Client Secret da Hotmart não configurados.'
        ];
    }

    $token = hotmart_get_oauth_token($clientId, $clientSecret);
    if (!$token) {
        return [
            'ok' => false,
            'message' => 'Falha ao autenticar na API da Hotmart. Verifique Client ID e Client Secret.'
        ];
    }

    $endDateMs   = (int)(time() * 1000);
    $startDateMs = (int)(strtotime("-{$days} days") * 1000);

    $url = "https://developers.hotmart.com/payments/api/v1/sales/history?start_date={$startDateMs}&end_date={$endDateMs}&transaction_status=APPROVED&max_results=100";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$res) {
        return [
            'ok' => false,
            'message' => "Erro ao consultar histórico de vendas Hotmart (HTTP {$httpCode})."
        ];
    }

    $json = json_decode($res, true);
    $items = is_array($json['items'] ?? null) ? $json['items'] : [];

    $synced = 0;
    $approved = 0;

    foreach ($items as $item) {
        $purchase = is_array($item['purchase'] ?? null) ? $item['purchase'] : [];
        $buyer    = is_array($item['buyer'] ?? null) ? $item['buyer'] : [];
        $product  = is_array($item['product'] ?? null) ? $item['product'] : [];

        $transaction = trim((string)($purchase['transaction'] ?? ''));
        if ($transaction === '') continue;

        $txCode = 'hotmart:' . $transaction;
        $status = 'APPROVED';
        $bName  = trim((string)($buyer['name'] ?? ''));
        $bEmail = strtolower(trim((string)($buyer['email'] ?? '')));
        $bPhone = trim((string)($buyer['checkout_phone_code'] ?? '') . (string)($buyer['checkout_phone'] ?? ''));
        $bPhoneNorm = preg_replace('/\D+/', '', $bPhone);

        $gross = (float)($purchase['price']['value'] ?? 0.0);
        $prodName = trim((string)($product['name'] ?? 'Curso Hotmart'));

        $saleDateMs = $purchase['order_date'] ?? $purchase['approved_date'] ?? time() * 1000;
        $saleDate   = date('Y-m-d H:i:s', (int)floor($saleDateMs / 1000));

        $st = $pdo->prepare("
            INSERT INTO hotmart_sales (
                transaction_code, status, product_name, gross_revenue, net_revenue, producer_net, fees,
                buyer_name, buyer_email, buyer_phone,
                sale_date, payment_confirmed_at, created_at, updated_at
            ) VALUES (
                :tx, :status, :pname, :gross, :gross, :gross, 0,
                :bname, :bemail, :bphone,
                :sdate, :sdate, NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                gross_revenue = VALUES(gross_revenue),
                buyer_name = IF(VALUES(buyer_name) <> '', VALUES(buyer_name), buyer_name),
                buyer_email = IF(VALUES(buyer_email) <> '', VALUES(buyer_email), buyer_email),
                payment_confirmed_at = VALUES(payment_confirmed_at),
                updated_at = NOW()
        ");

        $st->execute([
            'tx' => $txCode,
            'status' => $status,
            'pname' => $prodName,
            'gross' => $gross,
            'bname' => $bName,
            'bemail' => $bEmail,
            'bphone' => $bPhone,
            'sdate' => $saleDate
        ]);

        $synced++;
        $approved++;

        // Liberação de Acesso Vitalício
        if ($bEmail !== '') {
            $stU = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stU->execute(['email' => $bEmail]);
            $matchedUid = (int)$stU->fetchColumn();

            course_access_try_grant_lifetime_purchase($pdo, [
                'user_id' => $matchedUid > 0 ? $matchedUid : null,
                'offer_code' => $prodName,
                'transaction_code' => $txCode,
                'status' => 'paid',
                'event' => 'HOTMART_PAID_API',
                'email' => $bEmail,
                'phone' => $bPhone,
                'payload' => $item,
                'source' => 'hotmart_api',
            ]);
        }
    }

    return [
        'ok' => true,
        'synced_count' => $synced,
        'approved_count' => $approved,
        'message' => "Sincronização Hotmart concluída com sucesso ({$synced} vendas processadas)."
    ];
}

