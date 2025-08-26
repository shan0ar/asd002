<?php
require_once __DIR__.'/../includes/db.php';
$db = getDb();

$now = date('Y-m-d H:i:00'); // arrondi à la minute
$schedules = $db->query("SELECT * FROM scan_schedules WHERE next_run <= '$now' AND next_run IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

foreach ($schedules as $sched) {
    $scan = $db->prepare("SELECT * FROM scans WHERE client_id=? AND scan_date = ? AND status != 'done'");
    $scan->execute([$sched['client_id'], $sched['next_run']]);
    if ($scan->fetch()) continue; // déjà lancé

    $stmt = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, ?, true, 'running') RETURNING id");
    $stmt->execute([$sched['client_id'], $sched['next_run']]);
    $scan_id = $stmt->fetchColumn();

    $assets = $db->prepare("SELECT asset_value FROM client_assets WHERE client_id=?");
    $assets->execute([$sched['client_id']]);
    $assets = $assets->fetchAll(PDO::FETCH_COLUMN);
    foreach ($assets as $asset) {
        $cmd = sprintf('bash %s/scan_runner.sh %s %d', __DIR__, escapeshellarg($asset), $scan_id);
        exec($cmd . ' > /dev/null 2>&1 &');
    }

    $db->prepare("UPDATE scan_schedules SET next_run=NULL WHERE id=?")->execute([$sched['id']]);
}
?>