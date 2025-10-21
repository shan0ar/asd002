<?php
require_once 'includes/db.php';
require_once 'includes/session_check.php';
$db = getDb();
$client_id = intval($_GET['client_id']);
$stmt = $db->prepare("SELECT subdomain, ip, first_detected FROM discovered_subdomains WHERE client_id=? ORDER BY first_detected DESC");
$stmt->execute([$client_id]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Tous les sous-domaines détectés</title>
    <link rel="stylesheet" href="static/style.css">
</head>
<body>
<h2>Tous les sous-domaines détectés</h2>
<table>
    <tr><th>Sous-domaines</th><th>IP</th><th>Première détection</th></tr>
    <?php foreach ($subs as $row): ?>
    <tr>
        <td><?=htmlspecialchars($row['subdomain'])?></td>
        <td><?=htmlspecialchars($row['ip'])?></td>
        <td><?=htmlspecialchars($row['first_detected'])?></td>
    </tr>
    <?php endforeach ?>
</table>
<a href="javascript:history.back()">Retour</a>
</body>
</html>
