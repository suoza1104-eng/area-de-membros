<?php
declare(strict_types=1);

require_once __DIR__ . '/metrics.php'; // normalize_phone_value / normalize_email_value / app_log

/**
 * Colunas usadas para: (a) marcar quando net/fee de uma venda foi calculado por
 * estimativa em vez de vir direto do payload do gateway (fee_is_estimated); e
 * (b) ligar uma venda Firepay a sua "gemea" ja capturada via DOM/Pagar.me
 * direto, para nao contar a mesma venda real duas vezes quando o Firepay for
 * eventualmente incluido nos relatorios.
 */
function payment_reconciliation_ensure_schema(PDO $pdo): void
{
    foreach ([
        "ALTER TABLE payment_sales ADD COLUMN fee_is_estimated TINYINT(1) NOT NULL DEFAULT 0 AFTER fee_amount_cents",
        "ALTER TABLE payment_sales ADD COLUMN buyer_phone_norm VARCHAR(20) NULL AFTER buyer_phone",
        "ALTER TABLE payment_sales ADD KEY idx_payment_buyer_phone_norm (buyer_phone_norm)",
        "ALTER TABLE payment_sales ADD COLUMN gateway_twin_payment_sale_id BIGINT UNSIGNED NULL AFTER match_method",
        "ALTER TABLE payment_sales ADD COLUMN gateway_twin_match_confidence VARCHAR(20) NOT NULL DEFAULT 'unmatched' AFTER gateway_twin_payment_sale_id",
        "ALTER TABLE payment_sales ADD COLUMN gateway_twin_checked_at DATETIME NULL AFTER gateway_twin_match_confidence",
        "ALTER TABLE payment_sales ADD KEY idx_payment_gateway_twin (gateway_twin_payment_sale_id)",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (Throwable $e) {}
    }
}

/**
 * Busca em payment_sales (canais DOM/Pagar.me diretos) candidatos a "gemeo" de
 * uma venda Firepay: mesmo e-mail OU mesmo telefone normalizado, dentro de uma
 * janela de valor bruto e de data. Nao ha ID de transacao comparavel entre
 * Firepay e DOM/Pagar.me (esquemas de ID diferentes), entao a correspondencia
 * so pode ser feita por identidade do comprador + valor + tempo.
 */
function payment_reconciliation_find_candidates(PDO $pdo, string $emailNorm, string $phoneNorm, int $amountMin, int $amountMax, string $windowStart, string $windowEnd): array
{
    $identity = [];
    $params = [':amount_min' => $amountMin, ':amount_max' => $amountMax, ':window_start' => $windowStart, ':window_end' => $windowEnd];
    if ($emailNorm !== '') { $identity[] = 'LOWER(TRIM(buyer_email)) = :email'; $params[':email'] = $emailNorm; }
    if ($phoneNorm !== '') { $identity[] = 'buyer_phone_norm = :phone_norm'; $params[':phone_norm'] = $phoneNorm; }
    if (!$identity) return [];

    $sql = "SELECT id, provider, external_transaction_id, buyer_email, buyer_phone_norm, gross_amount_cents,
                   product_name, matched_user_id, first_received_at
            FROM payment_sales
            WHERE provider IN ('dom','pagarme')
              AND normalized_status IN ('APPROVED','REFUNDED','CHARGEBACK')
              AND gross_amount_cents BETWEEN :amount_min AND :amount_max
              AND first_received_at BETWEEN :window_start AND :window_end
              AND (" . implode(' OR ', $identity) . ")
            ORDER BY first_received_at ASC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function payment_reconciliation_score_candidate(array $firepaySale, array $candidate): int
{
    $score = 0;

    $emailA = strtolower(trim((string)($firepaySale['buyer_email'] ?? '')));
    $emailB = strtolower(trim((string)($candidate['buyer_email'] ?? '')));
    if ($emailA !== '' && $emailA === $emailB) $score += 50;

    $phoneA = (string)($firepaySale['buyer_phone_norm'] ?? '');
    $phoneB = (string)($candidate['buyer_phone_norm'] ?? '');
    if ($phoneA !== '' && $phoneA === $phoneB) $score += 30;

    $grossA = (int)($firepaySale['gross_amount_cents'] ?? 0);
    $grossB = (int)($candidate['gross_amount_cents'] ?? 0);
    if ($grossA > 0 && $grossB > 0) {
        $diffPct = abs($grossA - $grossB) / max($grossA, $grossB);
        if ($diffPct <= 0.005) $score += 20;
        elseif ($diffPct <= 0.03) $score += 10;
    }

    $userA = (int)($firepaySale['matched_user_id'] ?? 0);
    $userB = (int)($candidate['matched_user_id'] ?? 0);
    if ($userA > 0 && $userA === $userB) $score += 10;

    return $score;
}

function payment_reconciliation_confidence_from_score(int $score): string
{
    if ($score >= 80) return 'exact';
    if ($score >= 50) return 'probable';
    return 'unmatched';
}

function firepay_find_gateway_twin(PDO $pdo, array $firepaySale): array
{
    $email = strtolower(trim((string)($firepaySale['buyer_email'] ?? '')));
    $phoneNorm = (string)($firepaySale['buyer_phone_norm'] ?? '');
    $grossCents = (int)($firepaySale['gross_amount_cents'] ?? 0);
    $receivedAt = (string)($firepaySale['first_received_at'] ?? date('Y-m-d H:i:s'));

    if ($email === '' && $phoneNorm === '') {
        return ['confidence' => 'unmatched', 'twin_id' => null, 'score' => 0, 'reason' => 'no_identifying_data'];
    }

    try {
        $windowStart = (new DateTimeImmutable($receivedAt))->modify('-3 days')->format('Y-m-d H:i:s');
        $windowEnd = (new DateTimeImmutable($receivedAt))->modify('+3 days')->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $windowStart = date('Y-m-d H:i:s', strtotime('-3 days'));
        $windowEnd = date('Y-m-d H:i:s', strtotime('+3 days'));
    }
    $tolerance = max(100, (int)round($grossCents * 0.01));

    $candidates = payment_reconciliation_find_candidates(
        $pdo, $email, $phoneNorm, max(0, $grossCents - $tolerance), $grossCents + $tolerance, $windowStart, $windowEnd
    );
    if (!$candidates) {
        return ['confidence' => 'unmatched', 'twin_id' => null, 'score' => 0, 'reason' => 'no_candidate_in_window'];
    }

    $best = null;
    $bestScore = -1;
    foreach ($candidates as $candidate) {
        $score = payment_reconciliation_score_candidate($firepaySale, $candidate);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }
    $confidence = payment_reconciliation_confidence_from_score($bestScore);
    return [
        'confidence' => $confidence,
        'twin_id' => $confidence !== 'unmatched' ? (int)$best['id'] : null,
        'score' => $bestScore,
        'candidate' => $best,
    ];
}

/**
 * Roda o matching para uma venda Firepay especifica e grava o resultado nela
 * mesma (gateway_twin_*). Nunca lanca excecao para quem chamou no fluxo do
 * webhook — falha de matching so deve ser logada, nunca derrubar a resposta.
 */
function payment_reconciliation_link_firepay_twin(PDO $pdo, int $paymentSaleId): array
{
    payment_reconciliation_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM payment_sales WHERE id=:id AND provider='firepay' LIMIT 1");
    $stmt->execute([':id' => $paymentSaleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) return ['confidence' => 'unmatched', 'twin_id' => null, 'reason' => 'sale_not_found'];

    $result = firepay_find_gateway_twin($pdo, $sale);
    $pdo->prepare(
        "UPDATE payment_sales SET gateway_twin_payment_sale_id=:twin, gateway_twin_match_confidence=:confidence, gateway_twin_checked_at=NOW() WHERE id=:id"
    )->execute([
        ':twin' => $result['twin_id'],
        ':confidence' => $result['confidence'],
        ':id' => $paymentSaleId,
    ]);
    return $result;
}

/**
 * Job de recuperacao: revarre vendas Firepay ainda nao confirmadas como
 * correspondencia exata, cobrindo o caso da venda DOM/Pagar.me direta chegar
 * DEPOIS do webhook Firepay (ordem de chegada nao e garantida entre os dois).
 */
function payment_reconciliation_rescan_unmatched_firepay(PDO $pdo, int $lookbackDays = 14, int $limit = 200): array
{
    payment_reconciliation_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id FROM payment_sales
         WHERE provider='firepay' AND gateway_twin_match_confidence <> 'exact'
           AND first_received_at >= (NOW() - INTERVAL :days DAY)
         ORDER BY gateway_twin_checked_at IS NULL DESC, gateway_twin_checked_at ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':days', $lookbackDays, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $summary = ['checked' => 0, 'exact' => 0, 'probable' => 0, 'unmatched' => 0, 'errors' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        try {
            $result = payment_reconciliation_link_firepay_twin($pdo, (int)$id);
            $summary['checked']++;
            $summary[$result['confidence']] = ($summary[$result['confidence']] ?? 0) + 1;
        } catch (Throwable $e) {
            $summary['errors']++;
        }
    }
    return $summary;
}
