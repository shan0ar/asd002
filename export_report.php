<?php
require_once __DIR__ . '/vendor/autoload.php';

$db = new PDO('pgsql:host=localhost;dbname=osintapp', 'thomas', 'thomas');
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if ($client_id <= 0 || !$date) {
    die('Paramètres manquants.');
}

// 1. Assets découverts jusqu'à la date donnée (detected_at <= $date)
$asset_sql = "
    SELECT asset, source, detected_at, last_seen
    FROM assets_discovered
    WHERE client_id = :client_id
      AND detected_at::date <= :date
    ORDER BY detected_at DESC, asset
";
$assets = $db->prepare($asset_sql);
$assets->execute([':client_id' => $client_id, ':date' => $date]);

// 2. Whatweb jusqu'à la date donnée (scan_date <= $date)
$whatweb_sql = "
    SELECT domain_ip, technologie, valeur, version
    FROM whatweb
    WHERE client_id = :client_id
      AND scan_date::date <= :date
    ORDER BY domain_ip, technologie, valeur, version
";
$whatweb = $db->prepare($whatweb_sql);
$whatweb->execute([':client_id' => $client_id, ':date' => $date]);

// Mise en forme PDF
$rapport = "<h1>Rapport de scan du $date</h1>";

$rapport .= "<h2>Découverte d'assets</h2>";
$rapport .= "<table border='1' cellpadding='5'><tr><th>Asset</th><th>Source</th><th>Detecté le</th><th>Dernière apparition</th></tr>";
foreach ($assets as $a) {
    $rapport .= "<tr>
        <td>{$a['asset']}</td>
        <td>{$a['source']}</td>
        <td>{$a['detected_at']}</td>
        <td>{$a['last_seen']}</td>
    </tr>";
}
$rapport .= "</table>";

// Tableau Whatweb SANS doublons stricts
$rapport .= "<h2>Empreinte technologique (Whatweb)</h2>";
$rapport .= "<table border='1' cellpadding='5'><tr><th>IP/Domaine</th><th>Technologie</th><th>Valeur</th><th>Version</th></tr>";

$seen = [];
foreach ($whatweb as $w) {
    // Génère une clé unique pour chaque ligne
    $key = "{$w['domain_ip']}|{$w['technologie']}|{$w['valeur']}|{$w['version']}";
    if (isset($seen[$key])) continue; // Si déjà affichée, on saute
    $seen[$key] = true;
    $rapport .= "<tr>
        <td>{$w['domain_ip']}</td>
        <td>{$w['technologie']}</td>
        <td>{$w['valeur']}</td>
        <td>{$w['version']}</td>
    </tr>";
}
$rapport .= "</table>";

// Génération PDF
$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML($rapport);

// Téléchargement du PDF
$pdfname = "rapport_client{$client_id}_$date.pdf";
$mpdf->Output($pdfname, \Mpdf\Output\Destination::DOWNLOAD);
exit;
?>
