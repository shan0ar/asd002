<?php
require_once __DIR__."/includes/db.php";
require_once 'includes/session_check.php';

$db = getDb();

// Récupère la liste des clients
$clients = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$selected_client = isset($_POST['client_id']) ? intval($_POST['client_id']) : '';
$selected_date = isset($_POST['report_date']) ? $_POST['report_date'] : date('Y-m-d');
$selected_format = isset($_POST['format']) ? $_POST['format'] : 'csv';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Redirection pour download ou génération du rapport
    header("Location: report_download.php?client_id=$selected_client&report_date=$selected_date&format=$selected_format");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Extraction de rapport</title>
    <style>
    body { font-family: Arial, sans-serif; }
    .extract-form {
        max-width: 440px;
        margin: 80px auto;
        padding: 32px 28px;
        background: #f7f8fa;
        border-radius: 10px;
        box-shadow: 0 2px 18px #0001;
    }
    .extract-form label { font-weight: 500; margin-bottom: 6px; display: block; }
    .extract-form select, .extract-form input[type=date] {
        width: 100%; padding: 7px; margin-bottom: 16px; border: 1px solid #bbb; border-radius: 5px;
    }
    .extract-form button {
        padding: 12px 28px; background: #1976d2; color: #fff; border: none; border-radius: 5px; font-size: 1em;
        font-weight: 600; cursor: pointer;
    }
    .extract-form button:hover { background: #1565c0; }
    </style>
</head>
<body>
    <form class="extract-form" method="post">
        <h2 style="text-align:center;">Exporter un rapport</h2>
        <label for="client_id">Client :</label>
        <select name="client_id" id="client_id" required>
            <option value="">-- Sélectionner --</option>
            <?php foreach($clients as $client): ?>
                <option value="<?=$client['id']?>" <?=($selected_client==$client['id']?'selected':'')?>><?=htmlspecialchars($client['name'])?></option>
            <?php endforeach; ?>
        </select>
        <label for="report_date">Date du rapport :</label>
        <input type="date" name="report_date" id="report_date" value="<?=htmlspecialchars($selected_date)?>" required>
        <label for="format">Format :</label>
        <select name="format" id="format">
            <option value="csv" <?=($selected_format=='csv'?'selected':'')?>>CSV</option>
            <option value="docx" <?=($selected_format=='docx'?'selected':'')?>>DOCX</option>
            <option value="pdf" <?=($selected_format=='pdf'?'selected':'')?>>PDF</option>
        </select>
        <button type="submit">Générer l'export</button>
    </form>
</body>
</html>
