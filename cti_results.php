<?php
// Configuration DB
$host = 'localhost';
$db   = 'osintapp';
$user = 'thomas';
$pass = 'thomas';

// Connexion PDO PostgreSQL
$dsn = "pgsql:host=$host;dbname=$db";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// Blacklist suppression AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    $row = $pdo->query("SELECT * FROM cti_results WHERE id=$id")->fetch();
    if ($row) {
        $stmt = $pdo->prepare("INSERT INTO cti_blacklist (type, value, client_id) VALUES ('victim', ?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$row['pattern'], $row['client_id']]);
        $pdo->prepare("DELETE FROM cti_results WHERE id=?")->execute([$id]);
        echo 'OK';
        exit;
    }
}

// 1. Récupère tous les patterns (client_id, pattern)
$patterns = $pdo->query("SELECT client_id, pattern FROM cti_patterns")->fetchAll();
$api_key = '8a899680-7b9b-4ef0-b836-929fe41fb60a';

// 2. Récupère les blacklistés (pour ne pas afficher)
$blacklist = $pdo->query("SELECT value, client_id FROM cti_blacklist WHERE type='victim'")->fetchAll();
$blacklisted = [];
foreach ($blacklist as $b) {
    $blacklisted[$b['client_id']][] = $b['value'];
}

// SCAN ON DEMAND: si scan=1, on lance le scan
$scan_mode = isset($_GET['scan']) && $_GET['scan'] == '1';

if ($scan_mode) {
    foreach ($patterns as $p) {
        $client_id = $p['client_id'];
        $pattern = $p['pattern'];

        // Search API
        $url = "https://api-pro.ransomware.live/victims/search?q=".urlencode($pattern);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-API-KEY: ' . $api_key
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $results = json_decode($resp, true);
        if (!empty($results['victims'])) {
            foreach ($results['victims'] as $victim) {
                if (isset($blacklisted[$client_id]) && in_array($pattern, $blacklisted[$client_id])) continue;
                $stmt = $pdo->prepare("INSERT INTO cti_results (client_id, pattern, source, data) VALUES (?, ?, 'victims_search', ?::jsonb) ON CONFLICT DO NOTHING");
                $stmt->execute([$client_id, $pattern, json_encode($victim)]);
            }
        }

        // Press API
        $url = "https://api-pro.ransomware.live/press/recent?country=FR";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-API-KEY: ' . $api_key
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $results = json_decode($resp, true);
        if (!empty($results['results'])) {
            foreach ($results['results'] as $press) {
                if (isset($blacklisted[$client_id]) && in_array($pattern, $blacklisted[$client_id])) continue;
                if (stripos($press['victim'] ?? '', $pattern) !== false || stripos($press['domain'] ?? '', $pattern) !== false) {
                    $stmt = $pdo->prepare("INSERT INTO cti_results (client_id, pattern, source, data) VALUES (?, ?, 'press_recent', ?::jsonb) ON CONFLICT DO NOTHING");
                    $stmt->execute([$client_id, $pattern, json_encode($press)]);
                }
            }
        }
    }
    // Si scan AJAX, retourne juste OK
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        echo 'OK';
        exit;
    }
}

// 4. Affiche tous les résultats non blacklistés
$results = $pdo->query("SELECT r.*, c.name FROM cti_results r JOIN clients c ON r.client_id = c.id ORDER BY r.added DESC LIMIT 200")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Résultats OSINT ransomware.live</title>
    <style>
        body { font-family:sans-serif; margin:30px; }
        table { border-collapse:collapse; width:100%; margin-top:20px; }
        th, td { border:1px solid #ccc; padding:6px; }
        th { background:#f0f0f0; }
        .delbtn { color:#fff; background:#d33; border:none; border-radius:50%; width:24px; height:24px; font-weight:bold; cursor:pointer;}
        .delbtn:hover { background:#a00; }
        #scanbtn { background:#0074D9; color:#fff; border:none; border-radius:5px; padding:10px 20px; font-size:16px; cursor:pointer; }
        #scanbtn:disabled { background:#aaa; cursor:not-allowed;}
    </style>
    <script>
        function deleteResult(id, btn) {
            if (!confirm("Supprimer ce résultat et le bloquer définitivement ?")) return;
            btn.disabled = true;
            fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'delete_id='+id})
                .then(resp => resp.text())
                .then(txt => {
                    if (txt === 'OK') {
                        btn.parentNode.parentNode.remove();
                    } else {
                        alert('Erreur suppression');
                        btn.disabled = false;
                    }
                });
        }
        function scanNow(btn) {
            btn.disabled = true;
            btn.innerText = "Scan en cours...";
            fetch("?scan=1&ajax=1")
                .then(resp => resp.text())
                .then(txt => {
                    if (txt === 'OK') {
                        location.reload();
                    } else {
                        alert('Erreur scan');
                        btn.disabled = false;
                        btn.innerText = "Scanner maintenant";
                    }
                });
        }
    </script>
</head>
<body>
    <h1>Résultats OSINT ransomware.live</h1>
    <button id="scanbtn" onclick="scanNow(this)">Scanner maintenant</button>
    <table>
        <tr>
            <th>Client</th>
            <th>Pattern</th>
            <th>Source</th>
            <th>Résumé</th>
            <th>Date</th>
            <th>Supprimer</th>
        </tr>
        <?php foreach ($results as $r):
            $data = json_decode($r['data'], true);
            if ($r['source'] === 'victims_search') {
                $summary = htmlspecialchars($data['post_title'] ?? '') . " (" . htmlspecialchars($data['group_name'] ?? '') . ")";
                $date = htmlspecialchars($data['discovered'] ?? '');
            } else {
                $summary = htmlspecialchars($data['victim'] ?? '') . " (" . htmlspecialchars($data['domain'] ?? '') . ")";
                $date = htmlspecialchars($data['date'] ?? '');
            }
        ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['pattern']) ?></td>
            <td><?= htmlspecialchars($r['source']) ?></td>
            <td><?= $summary ?></td>
            <td><?= $date ?></td>
            <td><button class="delbtn" onclick="deleteResult(<?= $r['id'] ?>, this)">✗</button></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
