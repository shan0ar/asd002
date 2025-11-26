<?php
// Worker amélioré :
// - Traite les schedules (scan_schedules) dont next_run <= now(): crée un job dans scan_jobs pour each, met à jour next_run ou désactive si fin atteinte
// - Ensuite, exécute un job pending (comme avant)
// Usage: php scripts/run_scan_jobs.php
chdir(__DIR__ . '/../');
require_once __DIR__ . '/../includes/db.php';
$LOG = '/var/log/asd002/run_scan_jobs.log';
@mkdir(dirname($LOG), 0755, true);
function vlog($m) { global $LOG; @file_put_contents($LOG, date('c')." $m\n", FILE_APPEND | LOCK_EX); }
vlog("=== Worker start ===");

try {
    $db = getDb();

    // 0) Process due schedules -> create jobs
    // We'll select schedules that are active and next_run <= now()
    $schedSel = $db->prepare("SELECT id, client_id, frequency, day_of_week, time, next_run, end_date, occurrences_remaining FROM scan_schedules WHERE active = true AND next_run <= now() ORDER BY next_run ASC FOR UPDATE SKIP LOCKED");
    $schedSel->execute();
    $schedules = $schedSel->fetchAll(PDO::FETCH_ASSOC);
    foreach ($schedules as $s) {
        $sid = $s['id'];
        $client_id = intval($s['client_id']);
        $scheduled_at = $s['next_run'];

        vlog("Schedule due id={$sid} client={$client_id} next_run={$scheduled_at}");

        // Create a scan_jobs row for this schedule occurrence
        $params = json_encode(['schedule_id' => $sid, 'via' => 'schedule']);
        $ins = $db->prepare("INSERT INTO scan_jobs (client_id, params, status, scheduled_at, created_at) VALUES (:client, :params, 'pending', :scheduled_at, now()) RETURNING id");
        $ins->execute([':client' => $client_id, ':params' => $params, ':scheduled_at' => $scheduled_at]);
        $job_id = $ins->fetchColumn();
        vlog("Created scan_job id={$job_id} for schedule {$sid}");

        // Compute next_run for the schedule according to frequency
        $freq = $s['frequency']; // weekly, monthly, quarterly, semiannual, annual
        $day_of_week = intval($s['day_of_week']); // 1..7 (Mon..Sun)
        $time = $s['time']; // HH:MM
        $next = new DateTime($s['next_run']);

        // Helper to compute next occurrence
        switch ($freq) {
            case 'weekly':
                $next->modify('+1 week');
                break;
            case 'monthly':
                $next->modify('+1 month');
                break;
            case 'quarterly':
                $next->modify('+3 months');
                break;
            case 'semiannual':
                $next->modify('+6 months');
                break;
            case 'annual':
                $next->modify('+1 year');
                break;
            default:
                // fallback weekly
                $next->modify('+1 week');
        }
        // If schedule uses day_of_week and the computed date's weekday does not match, adjust:
        if ($day_of_week >= 1 && $day_of_week <= 7) {
            // set to next occurrence of that weekday
            $candidate = clone $next;
            $candidate->setTime(intval(substr($time,0,2)), intval(substr($time,3,2)), 0);
            // adjust to correct weekday
            $current_wd = intval($candidate->format('N')); // 1..7
            $delta = ($day_of_week - $current_wd + 7) % 7;
            if ($delta > 0) $candidate->modify("+{$delta} days");
            $next = $candidate;
        } else {
            // ensure time is set
            $parts = explode(':', $time);
            if (count($parts) >= 2) $next->setTime(intval($parts[0]), intval($parts[1]), 0);
        }

        // Decrement occurrences_remaining or check end_date
        $will_disable = false;
        if (!empty($s['occurrences_remaining'])) {
            $remaining = intval($s['occurrences_remaining']) - 1;
            if ($remaining <= 0) {
                // reached end: disable schedule
                $upd = $db->prepare("UPDATE scan_schedules SET occurrences_remaining = 0, active = false, next_run = NULL WHERE id = ?");
                $upd->execute([$sid]);
                $will_disable = true;
                vlog("Schedule {$sid} reached occurrences limit -> disabled");
            } else {
                $upd = $db->prepare("UPDATE scan_schedules SET occurrences_remaining = ?, next_run = ? WHERE id = ?");
                $upd->execute([$remaining, $next->format('Y-m-d H:i:s'), $sid]);
                vlog("Schedule {$sid} decremented occurrences_remaining -> {$remaining}, next_run set to {$next->format('Y-m-d H:i:s')}");
            }
        } elseif (!empty($s['end_date'])) {
            $end = new DateTime($s['end_date']);
            if ($end < $next) {
                // end_date passed: disable
                $upd = $db->prepare("UPDATE scan_schedules SET active = false, next_run = NULL WHERE id = ?");
                $upd->execute([$sid]);
                $will_disable = true;
                vlog("Schedule {$sid} end_date passed -> disabled");
            } else {
                $upd = $db->prepare("UPDATE scan_schedules SET next_run = ? WHERE id = ?");
                $upd->execute([$next->format('Y-m-d H:i:s'), $sid]);
                vlog("Schedule {$sid} next_run updated to {$next->format('Y-m-d H:i:s')}");
            }
        } else {
            // no end -> update next_run
            $upd = $db->prepare("UPDATE scan_schedules SET next_run = ? WHERE id = ?");
            $upd->execute([$next->format('Y-m-d H:i:s'), $sid]);
            vlog("Schedule {$sid} next_run updated to {$next->format('Y-m-d H:i:s')}");
        }
    }

    // 1) Now run existing pending jobs (single job, same as before)
    $db->beginTransaction();
    $sel = $db->prepare("SELECT id, client_id, params FROM scan_jobs WHERE status='pending' AND scheduled_at <= now() ORDER BY scheduled_at ASC FOR UPDATE SKIP LOCKED LIMIT 1");
    $sel->execute();
    $job = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $db->commit();
        vlog("No pending job found.");
        exit(0);
    }
    vlog("Picked job id={$job['id']} client_id={$job['client_id']}");
    $upd = $db->prepare("UPDATE scan_jobs SET status='running', updated_at=now() WHERE id=?");
    $upd->execute([$job['id']]);
    $db->commit();

    $client_id = intval($job['client_id']);
    // create scans row like scan_now
    $ins = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, now(), false, 'running') RETURNING id");
    $ins->execute([$client_id]);
    $scan_id = $ins->fetchColumn();
    vlog("Created scan id={$scan_id} for job {$job['id']}");

    // get assets & tools and execute scripts (same as before)
    $assets_stmt = $db->prepare("SELECT DISTINCT asset FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $assets_stmt->execute([$client_id]);
    $assets = $assets_stmt->fetchAll(PDO::FETCH_COLUMN);

    $settings_stmt = $db->prepare("SELECT asset, tool FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $settings_stmt->execute([$client_id]);
    $enabled_tools = [];
    foreach ($settings_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $enabled_tools[$r['asset']][] = $r['tool'];
    }

    foreach ($assets as $asset) {
        $asset_tools = $enabled_tools[$asset] ?? [];
        foreach ($asset_tools as $tool) {
            $script = __DIR__ . "/scan_{$tool}.sh";
            if (!is_file($script) || !is_executable($script)) {
                vlog("Missing script: {$script} for tool={$tool} asset={$asset}");
                continue;
            }
            $cmd = sprintf('bash %s %s %d', escapeshellarg($script), escapeshellarg($asset), $scan_id);
            vlog("Executing: {$cmd}");
            $out = [];
            $rc = 0;
            exec($cmd . ' 2>&1', $out, $rc);
            vlog("Cmd rc={$rc} output=" . implode("\n", $out));
        }
    }

    $db->prepare("UPDATE scans SET status='done' WHERE id=?")->execute([$scan_id]);
    $db->prepare("UPDATE scan_jobs SET status='done', updated_at=now() WHERE id=?")->execute([$job['id']]);
    vlog("Job {$job['id']} finished (scan_id={$scan_id}).");
    vlog("=== Worker end ===");
    exit(0);

} catch (Exception $e) {
    vlog("Exception: " . $e->getMessage());
    if (!empty($job['id'])) {
        try { $db->prepare("UPDATE scan_jobs SET status='failed', updated_at=now() WHERE id=?")->execute([$job['id']]); } catch(Exception $ee) { vlog("Failed set failed: ".$ee->getMessage()); }
    }
    exit(1);
}
