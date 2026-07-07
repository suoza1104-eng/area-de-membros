<?php
declare(strict_types=1);require_once __DIR__.'/../app/email_flow_engine.php';$pdo=getPDO();try{$campaigns=email_process_queue($pdo);$flows=email_flow_process_queue($pdo,25);echo json_encode(['ok'=>true,'campaigns'=>$campaigns,'flows'=>$flows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
