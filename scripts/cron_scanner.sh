<?php
require_once __DIR__.'/../includes/db.php';
$db = getDb();

$now = date('Y-m-d H:i:00'); // arrondi à la minute
$schedules = $db->query("SELECT * FROM scan_schedules WHERE next_run <= '$now' AND next_run IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

foreach ($schedules as $sched) {
    $client_id = $sched['client_id'];
    
    // Vérifier qu'aucun scan "pending" ou "running" n'est déjà en cours pour ce client à cette date
    $scan = $db->prepare("SELECT * FROM scans WHERE client_id=? AND scan_date = ? AND status != 'done'");
    $scan->execute([$client_id, $sched['next_run']]);
    if ($scan->fetch()) continue; // déjà lancé
    
    // Créer un scan avec la même logique que "scan_now"
    $stmt = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, ?, true, 'running') RETURNING id");
    $stmt->execute([$client_id, $sched['next_run']]);
    $scan_id = $stmt->fetchColumn();

    // 1. Liste des assets pour lesquels au moins un outil est coché
    $assets_stmt = $db->prepare("SELECT DISTINCT asset FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $assets_stmt->execute([$client_id]);
    $assets = $assets_stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Liste des outils cochés pour chaque asset
    $settings_stmt = $db->prepare("SELECT asset, tool FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $settings_stmt->execute([$client_id]);
    $enabled_tools = [];
    foreach ($settings_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $enabled_tools[$row['asset']][] = $row['tool'];
    }

    // 3. Exécuter les scans pour chaque asset et outil activé
    foreach ($assets as $asset) {
        $asset_tools = $enabled_tools[$asset] ?? [];
        foreach ($asset_tools as $tool) {
            $cmd = sprintf('bash /var/www/html/asd002/scripts/scan_%s.sh %s %d', $tool, escapeshellarg($asset), $scan_id);
            file_put_contents('/opt/asd002-logs/cron_scanner.log', date('c')." CMD: $cmd\n", FILE_APPEND);
            exec($cmd . ' >> /opt/asd002-logs/cron_scanner.log 2>&1 &');
        }
    }

    // Marquer le scan comme terminé
    $db->prepare("UPDATE scans SET status='done' WHERE id=?")->execute([$scan_id]);

    // Marquer la prochaine next_run
    // (à adapter selon la périodicité réelle, ici on remet le champ à NULL pour une planif unique)
    $db->prepare("UPDATE scan_schedules SET next_run=NULL WHERE id=?")->execute([$sched['id']]);
}
?>
