<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/automation_flows.php';

$pdo = getPDO();
$result = ['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'skipped'=>0,'canceled'=>0,'priority'=>[]];

$priority = $pdo->query("
    SELECT DISTINCT f.id, f.name
      FROM automation_flows f
      JOIN automation_flow_runs r ON r.flow_id = f.id
      JOIN automation_flow_jobs j ON j.run_id = r.id
     WHERE f.status = 'active'
       AND f.name LIKE '%MANYCHAT%'
       AND f.name LIKE '%LIVE%'
       AND ((j.status IN ('queued','retry','scheduled') AND j.available_at<=NOW()) OR (j.status='processing' AND j.lease_until<NOW()))
  ORDER BY f.id DESC
     LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($priority as $flow) {
    $partial = automation_flow_process_queue($pdo, 40, (int)$flow['id']);
    $partial['flow_id'] = (int)$flow['id'];
    $partial['flow_name'] = (string)$flow['name'];
    $result['priority'][] = $partial;
    foreach (['processed','completed','scheduled','retry','failed','skipped','canceled'] as $key) {
        $result[$key] += (int)($partial[$key] ?? 0);
    }
}

$general = automation_flow_process_queue($pdo, 40);
$result['general'] = $general;
foreach (['processed','completed','scheduled','retry','failed','skipped','canceled'] as $key) {
    $result[$key] += (int)($general[$key] ?? 0);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
