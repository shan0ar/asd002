<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';
$db = getDb();

$scan_id = isset($_GET['scan_id']) ? intval($_GET['scan_id']) : 0;

// Pour le bouton retour, on tente de retrouver le client_id pour ce scan
$client_id = null;
if ($scan_id) {
    $stmt = $db->prepare("SELECT client_id FROM scans WHERE id=?");
    $stmt->execute([$scan_id]);
    $client_id = $stmt->fetchColumn();
}

// Lecture des résultats nmap
$nmap_stmt = $db->prepare("SELECT port, state, service, version FROM nmap_results WHERE scan_id=? ORDER BY port ASC");
$nmap_stmt->execute([$scan_id]);
$nmap_results = $nmap_stmt->fetchAll(PDO::FETCH_ASSOC);

// DEBUG (peut être supprimé ensuite)
echo "<!-- DEBUG scan_id=$scan_id client_id=$client_id nmap_count=" . count($nmap_results) . " -->\n";
if ($nmap_results) {
    echo "<!-- DEBUG nmap_results: " . htmlspecialchars(print_r($nmap_results, true)) . " -->\n";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Résultats Nmap - Scan <?=$scan_id?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9fb; color: #222; }
        h2 { margin-top: 1.3em; }
        table { border-collapse: collapse; margin: 20px 0 30px 0; background: #fff; }
        th, td { border: 1px solid #bbb; padding: 6px 16px; }
        th { background: #eee; }
        tr:nth-child(even) td { background: #f6faff; }
        .empty { color: #888; font-style: italic; }
        .container { max-width: 900px; margin: 30px auto; background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 24px 36px; box-shadow: 0 2px 8px #ddd; }
        .return { float: right; font-size: 1.05em; color: #444; text-decoration: none; }
        .return:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <a href="client.php?id=<?=htmlspecialchars($client_id)?>" class="return">&larr; Retour</a>
    <h2>Résultats Nmap pour le scan #<?=$scan_id?></h2>

    <?php if ($nmap_results && count($nmap_results)): ?>
        <table>
            <tr>
                <th>Port</th>
                <th>État</th>
                <th>Service</th>
                <th>Version</th>
            </tr>
            <?php foreach ($nmap_results as $row): ?>
            <tr>
                <td><?=htmlspecialchars($row['port'])?></td>
                <td><?=htmlspecialchars($row['state'])?></td>
                <td><?=htmlspecialchars($row['service'])?></td>
                <td><?=htmlspecialchars($row['version'])?></td>
            </tr>
            <?php endforeach ?>
        </table>
    <?php else: ?>
        <div class="empty">Aucun résultat Nmap trouvé pour ce scan.</div>
    <?php endif; ?>

</div>
</body>
</html>
