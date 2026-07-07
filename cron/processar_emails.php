<?php
declare(strict_types=1);require_once __DIR__.'/../app/email_marketing.php';$pdo=getPDO();try{$result=email_process_queue($pdo);echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
