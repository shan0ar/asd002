<?php
// /var/www/html/asd002/scripts/run_scan_jobs.php
// Worker verbeux : lit un job pending et l'exécute, logging to /var/log/asd002/run_scan_jobs.log

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../includes/db.php';

$LOG = '/var/log/asd002/run_scan_jobs.log';
@mkdir(dirname($LOG), 0755, true);

function vlog($m) {
    global $LOG;
    @file_put_contents($LOG, date('c') . ' ' . $m . PHP_EOL, FILE_APPEND | LOCK_EX);
}

vlog("=== Worker start ===");

try {
    $db = getDb();

    $db->beginTransaction();
    // pick one pending job ready to run
    $sel = $db->prepare("SELECT id, client_id, params FROM scan_jobs WHERE status='pending' AND scheduled_at <= now() ORDER BY scheduled_at ASC FOR UPDATE SKIP LOCKED LIMIT 1");
    $sel->execute();
    $job = $sel->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $db->commit();
        vlog("No pending jobs found.");
        exit(0);
    }

    vlog("Picked job id={$job['id']} client_id={$job['client_id']} params={$job['params']}");

    // mark running
    $upd = $db->prepare("UPDATE scan_jobs SET status='running', updated_at=now() WHERE id=?");
    $upd->execute([$job['id']]);
    $db->commit();

    $client_id = intval($job['client_id']);

    // Create a scan exactly like 'scan_now' (scheduled=false) to match behavior
    $ins = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, now(), false, 'running') RETURNING id");
    $ins->execute([$client_id]);
    $scan_id = $ins->fetchColumn();
    vlog("Created scan id={$scan_id} for client_id={$client_id} (job {$job['id']})");

    // fetch assets & enabled tools (same logic as scan_now)
    $assets_stmt = $db->prepare("SELECT DISTINCT asset FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $assets_stmt->execute([$client_id]);
    $assets = $assets_stmt->fetchAll(PDO::FETCH_COLUMN);

    $settings_stmt = $db->prepare("SELECT asset, tool FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $settings_stmt->execute([$client_id]);
    $enabled_tools = [];
    foreach ($settings_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $enabled_tools[$r['asset']][] = $r['tool'];
    }

    vlog("Assets for scan_id={$scan_id}: " . json_encode($assets));
    foreach ($assets as $asset) {
        $asset_tools = $enabled_tools[$asset] ?? [];
        foreach ($asset_tools as $tool) {
            // Determine script path as in scan_now
            $script = __DIR__ . "/scan_{$tool}.sh";
            if (!is_file($script) || !is_executable($script)) {
                vlog("Script missing or not executable: {$script} (tool={$tool}, asset={$asset})");
                continue;
            }

            $cmd = sprintf('bash %s %s %d', escapeshellarg($script), escapeshellarg($asset), $scan_id);
            vlog("Executing: {$cmd}");
            $out = [];
            $rc = 0;
            exec($cmd . ' 2>&1', $out, $rc);
            vlog("Return code: {$rc}; output: " . implode("\n", $out));
        }
    }

    // mark scan done
    $db->prepare("UPDATE scans SET status='done' WHERE id=?")->execute([$scan_id]);
    // mark job done
    $db->prepare("UPDATE scan_jobs SET status='done', updated_at=now() WHERE id=?")->execute([$job['id']]);

    vlog("Job {$job['id']} completed; scan_id={$scan_id}");
    vlog("=== Worker end ===");
    exit(0);

} catch (Exception $e) {
    $err = $e->getMessage();
    vlog("Exception: " . $err);
    if (!empty($job['id'])) {
        try {
            $db->prepare("UPDATE scan_jobs SET status='failed', updated_at=now() WHERE id=?")->execute([$job['id']]);
            vlog("Marked job {$job['id']} as failed");
        } catch (Exception $e2) {
            vlog("Failed to mark job failed: " . $e2->getMessage());
        }
    }
    exit(1);
}
