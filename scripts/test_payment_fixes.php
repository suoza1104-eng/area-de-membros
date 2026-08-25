<?php
declare(strict_types=1);

/**
 * Script de verificacao manual (CLI, nunca exposto via HTTP) dos fixes de
 * reconciliacao financeira: fallback via API do DOM quando o webhook chega
 * sem liquid_amount, protecao contra sobrescrita destrutiva no
 * ON DUPLICATE KEY UPDATE, e o matching Firepay<->DOM/Pagar.me.
 *
 * Uso: php scripts/test_payment_fixes.php
 *
 * ATENCAO: este script escreve linhas reais (sinteticas, prefixadas com
 * TEST_) em payment_sales/dom_webhook_events/hotmart_sales_live no banco
 * configurado em app/config.php. Nao ha banco de staging neste projeto —
 * rode com cuidado. Usa e-mail fictício (@example.invalid) para que nenhum
 * usuario real seja casado/notificado. Todo dado de teste e removido antes
 * e depois de cada cenario.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so pode ser executado via linha de comando.');
}

require_once __DIR__ . '/../app/dom_pagamentos.php';

$pdo = getPDO();

const TEST_EMAIL = 'teste-reconciliacao@example.invalid';

function tpf_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM payment_sales WHERE external_transaction_id LIKE 'TEST\\_%' OR external_transaction_id LIKE 'dom:TEST\\_%'");
    $pdo->exec("DELETE FROM dom_webhook_events WHERE external_transaction_id LIKE 'TEST\\_%'");
    $pdo->exec("DELETE FROM hotmart_sales_live WHERE transaction_code LIKE '%TEST\\_%'");
    $pdo->exec("DELETE FROM hotmart_sales WHERE transaction_code LIKE '%TEST\\_%'");
}

function tpf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
    echo "  OK: {$message}\n";
}

echo "=== Limpeza inicial ===\n";
tpf_cleanup($pdo);

$results = ['A' => false, 'B' => false, 'C' => false];

// get_setting() cacheia TODAS as settings num array estatico na primeira
// chamada do processo; set_setting() so grava no banco, nao invalida esse
// cache. Por isso, para o teste enxergar require_signature=0, o valor tem
// que estar no banco ANTES da primeira get_setting() deste processo — lemos
// e escrevemos via SQL cru, nunca via get_setting()/set_setting() aqui.
$settingStmt = $pdo->prepare("SELECT valor FROM settings WHERE chave = 'dom_pagamentos_require_signature'");
$settingStmt->execute();
$originalRequireSignatureRaw = $settingStmt->fetchColumn();
$pdo->prepare("INSERT INTO settings (chave, valor) VALUES ('dom_pagamentos_require_signature', '0') ON DUPLICATE KEY UPDATE valor='0'")->execute();

try {
    // --- Cenario A: fallback via API do DOM quando o webhook nao traz liquid_amount ---
    echo "\n=== Cenario A: fallback via API (liquid_amount ausente no webhook) ===\n";
    try {
        $GLOBALS['dom_api_request_override'] = function (string $path, array $query) {
            return [
                'id' => 'TEST_DOM_001',
                'status' => 'paid',
                'amount' => 19900,
                'liquid_amount' => 18900,
                'fee_details' => ['amount' => 1000],
                'customer' => ['email' => TEST_EMAIL, 'name' => 'Teste Reconciliacao', 'mobile_phone' => '11999990001'],
                'items' => [['description' => 'Produto Teste A', 'price' => 19900]],
            ];
        };

        $payload = [
            'event' => 'CHARGE-APPROVED',
            'signature' => '',
            'data' => [
                'id' => 'TEST_DOM_001',
                'status' => 'paid',
                'amount' => '199.00',
                // liquid_amount propositalmente ausente
                'customer' => ['email' => TEST_EMAIL, 'name' => 'Teste Reconciliacao', 'mobile_phone' => '11999990001'],
            ],
        ];
        dom_process_webhook($pdo, $payload, json_encode($payload));

        $row = $pdo->query("SELECT net_amount_cents, fee_amount_cents, fee_is_estimated FROM payment_sales WHERE provider='dom' AND external_transaction_id='dom:TEST_DOM_001'")->fetch(PDO::FETCH_ASSOC);
        tpf_assert($row !== false, 'venda TEST_DOM_001 foi gravada em payment_sales');
        tpf_assert((int)$row['net_amount_cents'] === 18900, "net_amount_cents = 18900 (veio " . $row['net_amount_cents'] . ")");
        tpf_assert((int)$row['fee_amount_cents'] === 1000, "fee_amount_cents = 1000 (veio " . $row['fee_amount_cents'] . ")");
        tpf_assert((int)$row['fee_is_estimated'] === 0, "fee_is_estimated = 0 (fallback de API resolveu, nao e estimativa)");

        // --- Cenario B: evento subsequente degradado nao pode piorar o valor ja correto ---
        echo "\n=== Cenario B: evento subsequente sem liquid_amount nao regride o valor ja correto ===\n";
        unset($GLOBALS['dom_api_request_override']); // simula falha/API indisponivel no 2o evento
        dom_process_webhook($pdo, $payload, json_encode($payload));
        $row2 = $pdo->query("SELECT net_amount_cents, fee_amount_cents, fee_is_estimated FROM payment_sales WHERE provider='dom' AND external_transaction_id='dom:TEST_DOM_001'")->fetch(PDO::FETCH_ASSOC);
        tpf_assert((int)$row2['net_amount_cents'] === 18900, "net_amount_cents continua 18900 (nao regrediu, veio " . $row2['net_amount_cents'] . ")");
        tpf_assert((int)$row2['fee_amount_cents'] === 1000, "fee_amount_cents continua 1000 (nao regrediu, veio " . $row2['fee_amount_cents'] . ")");

        $results['A'] = true;
        $results['B'] = true;
    } catch (Throwable $e) {
        echo "  ERRO: " . $e->getMessage() . "\n";
    } finally {
        unset($GLOBALS['dom_api_request_override']);
    }
} finally {
    if ($originalRequireSignatureRaw === false) {
        $pdo->prepare("DELETE FROM settings WHERE chave = 'dom_pagamentos_require_signature'")->execute();
    } else {
        $pdo->prepare("UPDATE settings SET valor = :v WHERE chave = 'dom_pagamentos_require_signature'")->execute([':v' => $originalRequireSignatureRaw]);
    }
}

// --- Cenario C: matching Firepay <-> DOM/Pagar.me ---
echo "\n=== Cenario C: matching Firepay <-> DOM/Pagar.me ===\n";
try {
    $now = date('Y-m-d H:i:s');
    $insertDom = $pdo->prepare("INSERT INTO payment_sales
        (provider,external_transaction_id,provider_status,normalized_status,currency,gross_amount_cents,net_amount_cents,fee_amount_cents,
         buyer_email,buyer_phone,buyer_phone_norm,raw_payload_json,first_received_at,last_received_at)
        VALUES ('dom','TEST_DOM_C',:status,'APPROVED','BRL',:gross,:net,:fee,:email,:phone,:phone_norm,'{}',:received,:received)");
    $insertDom->execute([
        ':status' => 'paid', ':gross' => 39700, ':net' => 36000, ':fee' => 3700,
        ':email' => TEST_EMAIL, ':phone' => '11999990002', ':phone_norm' => '11999990002', ':received' => $now,
    ]);
    $domId = (int)$pdo->lastInsertId();

    $insertFirepay = $pdo->prepare("INSERT INTO payment_sales
        (provider,external_transaction_id,provider_status,normalized_status,currency,gross_amount_cents,net_amount_cents,fee_amount_cents,
         buyer_email,buyer_phone,buyer_phone_norm,raw_payload_json,first_received_at,last_received_at)
        VALUES ('firepay','TEST_FIREPAY_C',:status,'APPROVED','BRL',:gross,:net,:fee,:email,:phone,:phone_norm,'{}',:received,:received)");
    $insertFirepay->execute([
        ':status' => 'paid', ':gross' => 39700, ':net' => 36000, ':fee' => 3700,
        ':email' => TEST_EMAIL, ':phone' => '11999990002', ':phone_norm' => '11999990002', ':received' => $now,
    ]);
    $firepayId = (int)$pdo->lastInsertId();

    $matchResult = payment_reconciliation_link_firepay_twin($pdo, $firepayId);
    tpf_assert($matchResult['confidence'] === 'exact', "venda com mesmo e-mail/telefone/valor casa como 'exact' (veio '{$matchResult['confidence']}')");
    tpf_assert((int)$matchResult['twin_id'] === $domId, "gateway_twin_payment_sale_id aponta para a venda DOM correta");

    // Caso negativo: dados divergentes nao devem casar.
    $insertFirepayNoMatch = $pdo->prepare("INSERT INTO payment_sales
        (provider,external_transaction_id,provider_status,normalized_status,currency,gross_amount_cents,net_amount_cents,fee_amount_cents,
         buyer_email,buyer_phone,buyer_phone_norm,raw_payload_json,first_received_at,last_received_at)
        VALUES ('firepay','TEST_FIREPAY_C_NOMATCH',:status,'APPROVED','BRL',:gross,:net,:fee,:email,:phone,:phone_norm,'{}',:received,:received)");
    $insertFirepayNoMatch->execute([
        ':status' => 'paid', ':gross' => 5000, ':net' => 4500, ':fee' => 500,
        ':email' => 'outro-email@example.invalid', ':phone' => '11999990099', ':phone_norm' => '11999990099', ':received' => $now,
    ]);
    $firepayNoMatchId = (int)$pdo->lastInsertId();
    $noMatchResult = payment_reconciliation_link_firepay_twin($pdo, $firepayNoMatchId);
    tpf_assert($noMatchResult['confidence'] === 'unmatched', "venda com e-mail/telefone/valor divergentes fica 'unmatched' (veio '{$noMatchResult['confidence']}')");

    $results['C'] = true;
} catch (Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Limpeza final ===\n";
tpf_cleanup($pdo);

echo "\n=== Resumo ===\n";
foreach ($results as $scenario => $ok) {
    echo "  Cenario {$scenario}: " . ($ok ? 'PASSOU' : 'FALHOU') . "\n";
}

$allPassed = !in_array(false, $results, true);
exit($allPassed ? 0 : 1);
