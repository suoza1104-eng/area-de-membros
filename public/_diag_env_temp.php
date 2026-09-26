<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$keys = [
    'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_REGION',
    'RESEND_API_KEY', 'RESEND_WEBHOOK_SECRET',
    'SES_CONFIGURATION_SET', 'SES_CONTACT_LIST', 'SES_DEFAULT_TOPIC', 'SES_FROM_EMAIL',
    'SES_FROM_NAME', 'SES_REPLY_TO', 'SES_WEBHOOK_SECRET',
    'EMAIL_MAX_PER_MINUTE', 'EMAIL_BATCH_SIZE',
    'TELNYX_API_KEY', 'TELNYX_PUBLIC_KEY',
];

foreach ($keys as $k) {
    $v = getenv($k);
    if ($v === false || $v === '') {
        echo "$k: NAO DEFINIDA\n";
    } else {
        $len = strlen($v);
        $prefix = substr($v, 0, 3);
        echo "$k: definida (tamanho=$len, comeca com '$prefix***')\n";
    }
}
