<?php
require_once 'includes/db.php';
require_once 'includes/session_check.php';
$db = getDb();

$client_id = intval($_POST['client_id'] ?? $_GET['client_id'] ?? 0);
if (!$client_id) die("Client inconnu");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Création d'un scan
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled) VALUES (?, now(), false) RETURNING id");
    $stmt->execute([$client_id]);
    $scan_id = $stmt->fetchColumn();

    $assets = $db->prepare("SELECT asset_value FROM client_assets WHERE client_id=?");
    $assets->execute([$client_id]);
    $assets = $assets->fetchAll(PDO::FETCH_COLUMN);

    foreach ($assets as $asset) {
        // Lancer le bash pour chaque asset (asynchrone recommandé, ici sync pour simplicité)
        $cmd = sprintf('bash scripts/scan_runner.sh %s %d', escapeshellarg($asset), $scan_id);
        exec($cmd . ' > /dev/null 2>&1 &');
    }
    $db->commit();
    header("Location: client.php?id=$client_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Scanner le client</title>
</head>
<body>
<form method="post">
    <input type="hidden" name="client_id" value="<?=$client_id?>">
    <label>Lancer un scan personnalisé ?</label>
    <button type="submit">Lancer</button>
</form>
<a href="client.php?id=<?=$client_id?>">Retour</a>
</body>
</html>
