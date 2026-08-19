<?php
declare(strict_types=1);

function payment_amount_cents($value): int
{
    if (!is_scalar($value)) return 0;
    $raw = trim((string)$value);
    if ($raw === '') return 0;

    $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? '';
    if ($raw === '' || $raw === '-') return 0;

    if (str_contains($raw, ',')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
        return max(0, (int)round(((float)$raw) * 100));
    }

    if (preg_match('/^-?\d+\.\d{1,4}$/', $raw)) {
        return max(0, (int)round(((float)$raw) * 100));
    }

    return max(0, (int)round((float)$raw));
}

function payment_amount_find_by_keys($value, array $keys): int
{
    if (!is_array($value)) return 0;
    $needles = array_map(static fn($key) => strtolower((string)$key), $keys);

    foreach ($value as $key => $child) {
        $keyNorm = strtolower((string)$key);
        if (!in_array($keyNorm, $needles, true)) continue;

        if (is_scalar($child)) return payment_amount_cents($child);
        if (is_array($child)) {
            foreach (['amount', 'value', 'total', 'total_amount'] as $amountKey) {
                if (isset($child[$amountKey]) && is_scalar($child[$amountKey])) {
                    return payment_amount_cents($child[$amountKey]);
                }
            }
            $nestedAmount = payment_amount_find_by_keys($child, ['amount', 'value', 'total', 'total_amount']);
            if ($nestedAmount > 0) return $nestedAmount;
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $found = payment_amount_find_by_keys($child, $keys);
            if ($found > 0) return $found;
        }
    }

    return 0;
}

function payment_amount_method_key(string $paymentMethod): string
{
    $method = strtolower(trim($paymentMethod));
    if (str_contains($method, 'pix')) return 'pix';
    if (str_contains($method, 'boleto')) return 'boleto';
    if (str_contains($method, 'credit')) return 'credit_card';
    if (str_contains($method, 'cartao') || str_contains($method, 'card')) return 'credit_card';
    return preg_replace('/[^a-z0-9_]+/', '_', $method) ?: 'unknown';
}

function payment_amount_setting_percent(string $provider, string $paymentMethod, string $defaultPercent = '0'): float
{
    $key = strtolower(preg_replace('/[^a-z0-9_]+/', '_', $provider) ?? $provider);
    $method = payment_amount_method_key($paymentMethod);
    $setting = $key . '_' . $method . '_fee_percent';
    $value = function_exists('get_setting') ? get_setting($setting, $defaultPercent) : $defaultPercent;
    return (float)str_replace(',', '.', (string)$value);
}

function payment_amount_fee_cents(array $payloads, int $grossCents, string $provider, string $paymentMethod, string $defaultPercent = '0'): int
{
    $fee = payment_amount_find_by_keys($payloads, [
        'fee', 'fees', 'fee_amount', 'gateway_fee', 'processing_fee', 'transaction_fee',
        'tax_amount', 'mdr_amount', 'taxa', 'taxa_gateway',
    ]);
    if ($fee > 0) return min($fee, $grossCents);

    $percent = payment_amount_setting_percent($provider, $paymentMethod, $defaultPercent);
    if ($percent <= 0) return 0;

    return min((int)round($grossCents * ($percent / 100)), $grossCents);
}

function payment_amount_net_cents(array $payloads, int $grossCents, int $feeCents): int
{
    $net = payment_amount_find_by_keys($payloads, [
        'net_amount', 'liquid_amount', 'liquid_value', 'received_amount',
        'amount_received', 'total_liquid', 'total_net',
    ]);
    if ($net > 0) return min($net, $grossCents);

    return max(0, $grossCents - $feeCents);
}
