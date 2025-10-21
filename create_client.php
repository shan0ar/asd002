<?php
require_once 'includes/db.php';
require_once 'includes/session_check.php';
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_name'])) {
    $name = trim($_POST['client_name']);
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO clients (name) VALUES (?) RETURNING id");
    $stmt->execute([$name]);
    $client_id = $stmt->fetchColumn();

    foreach (['ip', 'ip_range', 'fqdn', 'domain'] as $type) {
        if (!empty($_POST[$type])) {
            foreach (explode("\n", $_POST[$type]) as $value) {
                $value = trim($value);
                if ($value !== "") {
                    $stmt = $db->prepare("INSERT INTO client_assets (client_id, asset_type, asset_value) VALUES (?, ?, ?)");
                    $stmt->execute([$client_id, $type, $value]);
                }
            }
        }
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
    <title>ASD - Créer un client</title>
    <link rel="stylesheet" href="static/style.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main">
    <h1>Créer un nouveau client</h1>
    <form method="POST">
        <label>Nom du client :</label>
        <input type="text" name="client_name" required><br>
        <label>Plages d'IP (une par ligne) :</label><br>
        <textarea name="ip_range" rows="2"></textarea><br>
        <label>IP publique(s) (une par ligne) :</label><br>
        <textarea name="ip" rows="2"></textarea><br>
        <label>FQDN(s) (une par ligne) :</label><br>
        <textarea name="fqdn" rows="2"></textarea><br>
        <label>Nom(s) de domaine (une par ligne) :</label><br>
        <textarea name="domain" rows="2"></textarea><br>
        <button type="submit">Créer le client</button>
    </form>
</div>
</body>
</html>
