<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuration DB
$host = 'localhost';
$db   = 'osintapp';
$user = 'thomas';
$pass = 'thomas';

// Connexion PDO PostgreSQL
$dsn = "pgsql:host=$host;dbname=$db";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// ==== AJOUT AJAX PATTERNS (uniquement les patterns) ====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patterns' && isset($_GET['client_id'])) {
    $cid = intval($_GET['client_id']);
    $stmt = $pdo->prepare("SELECT pattern FROM cti_patterns WHERE client_id=? ORDER BY pattern ASC");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ==== AJAX DEBUG SCAN RAW ====
if (isset($_GET['ajax']) && $_GET['ajax'] === 'debug_scan_raw') {
    header('Content-Type: application/json');
    echo json_encode($_SESSION['last_scan_raw'] ?? []);
    exit;
}

// Récupération des clients
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

$success = false;
$error = '';
$newlyInsertedVictims = []; // Pour afficher les nouveaux ajoutés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_now'])) {
    // === SCAN NOW : lance les 2 requêtes pour chaque pattern ===
    $api_key = '8a899680-7b9b-4ef0-b836-929fe41fb60a';
    $patterns = $pdo->query("SELECT client_id, pattern FROM cti_patterns")->fetchAll();
    $new_results = 0;
    $debug_scan_raw = [];

    foreach ($patterns as $p) {
        $client_id = $p['client_id'];
        $pattern   = $p['pattern'];

        // 1. Search API
        $url = "https://api-pro.ransomware.live/victims/search?q=" . urlencode($pattern);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-API-KEY: ' . $api_key
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $error = 'Erreur cURL : ' . curl_error($ch);
        }
        curl_close($ch);

        $results = json_decode($resp, true);
        $debug_scan_raw[] = [
            'client_id' => $client_id,
            'pattern'   => $pattern,
            'endpoint'  => 'victims_search',
            'raw'       => $results,
        ];

        if (!empty($results['victims'])) {
            foreach ($results['victims'] as $victim) {
                // Vérifie si le résultat existe déjà
                $existStmt = $pdo->prepare("SELECT id FROM cti_results WHERE client_id = ? AND pattern = ? AND source = 'victims_search' AND data = ?::jsonb");
                $existStmt->execute([$client_id, $pattern, json_encode($victim)]);
                if ($existStmt->fetch()) {
                    continue; // déjà présent, ne compte pas comme nouveau
                }
                // Si non blacklisté
                $isBlacklisted = false;
                $blacklistStmt = $pdo->prepare("SELECT 1 FROM cti_blacklist WHERE type='victim' AND value=? AND client_id=?");
                $blacklistStmt->execute([$pattern, $client_id]);
                if ($blacklistStmt->fetch()) {
                    $isBlacklisted = true;
                }
                if ($isBlacklisted) {
                    continue;
                }

                $stmt = $pdo->prepare("INSERT INTO cti_results (client_id, pattern, source, data) VALUES (?, ?, 'victims_search', ?::jsonb) ON CONFLICT DO NOTHING");
                if ($stmt->execute([$client_id, $pattern, json_encode($victim)])) {
                    $new_results++;
                    $newlyInsertedVictims[] = [
                        'client_id' => $client_id,
                        'pattern'   => $pattern,
                        'source'    => 'victims_search',
                        'data'      => $victim
                    ];
                }
            }
        }

        // 2. Press API
        $url2 = "https://api-pro.ransomware.live/press/recent?country=FR";
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'X-API-KEY: ' . $api_key
        ]);
        $resp2 = curl_exec($ch2);
        if ($resp2 === false) {
            $error = 'Erreur cURL : ' . curl_error($ch2);
        }
        curl_close($ch2);

        $results2 = json_decode($resp2, true);
        $debug_scan_raw[] = [
            'client_id' => $client_id,
            'pattern'   => $pattern,
            'endpoint'  => 'press_recent',
            'raw'       => $results2,
        ];

        if (!empty($results2['results'])) {
            foreach ($results2['results'] as $press) {
                if (
                    (isset($press['victim']) && stripos($press['victim'], $pattern) !== false) ||
                    (isset($press['domain']) && stripos($press['domain'], $pattern) !== false)
                ) {
                    // Vérifie si le résultat existe déjà
                    $existStmt = $pdo->prepare("SELECT id FROM cti_results WHERE client_id = ? AND pattern = ? AND source = 'press_recent' AND data = ?::jsonb");
                    $existStmt->execute([$client_id, $pattern, json_encode($press)]);
                    if ($existStmt->fetch()) {
                        continue;
                    }
                    // Si non blacklisté
                    $isBlacklisted = false;
                    $blacklistStmt = $pdo->prepare("SELECT 1 FROM cti_blacklist WHERE type='victim' AND value=? AND client_id=?");
                    $blacklistStmt->execute([$pattern, $client_id]);
                    if ($blacklistStmt->fetch()) {
                        $isBlacklisted = true;
                    }
                    if ($isBlacklisted) {
                        continue;
                    }

                    $stmt = $pdo->prepare("INSERT INTO cti_results (client_id, pattern, source, data) VALUES (?, ?, 'press_recent', ?::jsonb) ON CONFLICT DO NOTHING");
                    if ($stmt->execute([$client_id, $pattern, json_encode($press)])) {
                        $new_results++;
                        $newlyInsertedVictims[] = [
                            'client_id' => $client_id,
                            'pattern'   => $pattern,
                            'source'    => 'press_recent',
                            'data'      => $press
                        ];
                    }
                }
            }
        }
    }

    $_SESSION['last_scan_raw'] = $debug_scan_raw;
    $_SESSION['last_scan_newly_inserted'] = $newlyInsertedVictims;
    if (!$error) {
        $success = "Scan terminé, $new_results nouveaux résultats enregistrés.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    $row = $pdo->query("SELECT * FROM cti_results WHERE id=$id")->fetch();
    if ($row) {
        $stmt = $pdo->prepare("INSERT INTO cti_blacklist (type, value, client_id) VALUES ('victim', ?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$row['pattern'], $row['client_id']]);
        $pdo->prepare("DELETE FROM cti_results WHERE id=?")->execute([$id]);
        echo 'OK';
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $patterns_raw = $_POST['patterns'] ?? '';

    if ($client_id <= 0 || empty($patterns_raw)) {
        $error = "Veuillez choisir un client et entrer au moins un pattern.";
    } else {
        $patterns = preg_split('/[\r\n,]+/', $patterns_raw);
        $patterns = array_filter(array_map('trim', $patterns));
        $inserted = 0;

        foreach ($patterns as $pattern) {
            if (!$pattern) continue;
            $stmt = $pdo->prepare("INSERT INTO cti_patterns (client_id, pattern) VALUES (?, ?) ON CONFLICT (client_id, pattern) DO NOTHING");
            if ($stmt->execute([$client_id, $pattern])) {
                $inserted++;
            }
        }
        $success = true;
    }
}

$selected_client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
$history = [];
if ($selected_client_id > 0) {
    $stmt = $pdo->prepare("SELECT pattern FROM cti_patterns WHERE client_id = ? ORDER BY pattern ASC");
    $stmt->execute([$selected_client_id]);
    $history = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Blacklist pour masquer les patterns supprimés
$blacklist = $pdo->query("SELECT value, client_id FROM cti_blacklist WHERE type='victim'")->fetchAll();
$blacklisted = [];
foreach ($blacklist as $b) {
    $blacklisted[$b['client_id']][] = $b['value'];
}

// Récupération des résultats
$results = $pdo->query("SELECT r.*, c.name FROM cti_results r JOIN clients c ON r.client_id = c.id ORDER BY r.added DESC LIMIT 200")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajout de patterns client</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        .container { max-width: 1200px; margin: 0 auto; }
        label { font-weight: bold; margin-top: 15px; display: block; }
        textarea { width: 100%; height: 80px; }
        select, input[type="submit"] { padding: 6px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        table { border-collapse: collapse; margin-top: 20px; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f3f3f3; }
        .scan-btn {
            background: #2196f3;
            color: #fff;
            border: none;
            font-weight: bold;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 16px;
        }
        .scan-btn:hover { background: #1769aa; }
        .debug-btn { background: #555; color: #fff; border: none; padding: 6px 16px; border-radius: 4px; cursor:pointer; margin-left:10px;}
        .debug-btn:hover { background: #333; }
        #debugScanModal { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.9); align-items:center; justify-content:center; z-index:9999;}
        #debugScanModal pre { background:#222; color:#fff; font-size:14px; margin:0 auto; padding:20px; border-radius:6px; max-width:90vw; max-height:90vh; overflow:auto; }
        #debugScanModal .close { position:absolute;top:30px;right:60px;font-size:30px;color:#fff;font-weight:bold;cursor:pointer;}
        .delbtn { color:#fff; background:#d33; border:none; border-radius:50%; width:24px; height:24px; font-weight:bold; cursor:pointer;}
        .delbtn:hover { background:#a00; }
        .img-icon { width:24px; height:24px; vertical-align:middle; cursor:pointer; }
        .permalink-icon { width:24px; height:24px; cursor:pointer; vertical-align:middle; }
        #imgModal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.8);
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        #imgModal.show {
            display: flex !important;
        }
        #imgModal img {
            max-width: 90vw;
            max-height: 90vh;
            border: 4px solid #fff;
            border-radius: 8px;
            box-shadow: 0 0 32px rgba(0,0,0,0.6);
        }
    </style>
    <script>
        function deleteResult(id, btn) {
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
        function showImg(url) {
            var modal = document.getElementById('imgModal');
            document.getElementById('imgModalImg').src = url;
            modal.classList.add('show');
        }
        window.onload = function(){
            document.getElementById('imgModal').onclick = function() {
                this.classList.remove('show');
            };
            document.getElementById('client_id').addEventListener('change', updateClientPatterns);
            if (document.getElementById('client_id').value) updateClientPatterns();
            // Debug Scan
            var dbgBtn = document.getElementById('debugScanBtn');
            if (dbgBtn) {
                dbgBtn.onclick = function() {
                    document.getElementById('debugScanModal').style.display = 'flex';
                    var pre = document.getElementById('debugScanModalPre');
                    pre.innerHTML = "Chargement...";
                    fetch('?ajax=debug_scan_raw')
                        .then(r => r.json())
                        .then(js => {
                            pre.textContent = JSON.stringify(js, null, 2);
                        });
                };
                document.getElementById('debugScanModalClose').onclick = function(){
                    document.getElementById('debugScanModal').style.display = 'none';
                };
            }
        };
        // Ajout pour affichage patterns dynamiques (uniquement les patterns)
        function updateClientPatterns() {
            var cid = document.getElementById('client_id').value;
            var div = document.getElementById('client-patterns');
            if (!cid) { div.innerHTML = ""; return; }
            div.innerHTML = "Chargement...";
            fetch('?ajax=patterns&client_id=' + cid)
                .then(r => r.json())
                .then(data => {
                    if (!data || data.length === 0) {
                        div.innerHTML = "<i>Aucun pattern enregistré pour ce client.</i>";
                    } else {
                        let html = "<b>Patterns déjà enregistrés :</b><ul style='margin-top:5px;'>";
                        data.forEach((pattern) => {
                            html += "<li><code>" + pattern.replace(/</g,"&lt;") + "</code></li>";
                        });
                        html += "</ul>";
                        div.innerHTML = html;
                    }
                });
        }
    </script>
</head>
<body>
<div class="container">
    <h1>Ajout de patterns client</h1>
    <form method="post" style="margin-bottom:20px;display:inline;">
        <input type="hidden" name="scan_now" value="1">
        <button type="submit" class="scan-btn">Scanner maintenant</button>
    </form>
    <?php if (isset($_SESSION['last_scan_raw']) && is_array($_SESSION['last_scan_raw']) && count($_SESSION['last_scan_raw'])): ?>
        <button type="button" class="debug-btn" id="debugScanBtn">Afficher debug scan</button>
    <?php endif; ?>

    <div id="debugScanModal">
        <span class="close" id="debugScanModalClose">&times;</span>
        <pre id="debugScanModalPre"></pre>
    </div>

    <?php if ($success && $success !== true): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php if (isset($_SESSION['last_scan_newly_inserted']) && count($_SESSION['last_scan_newly_inserted'])): ?>
            <div style="color:blue;font-weight:bold;">
                <?= count($_SESSION['last_scan_newly_inserted']) ?> nouveaux résultats réellement ajoutés en base !
            </div>
        <?php endif; ?>
    <?php elseif ($success): ?>
        <div class="success">Patterns enregistrés avec succès.</div>
    <?php elseif ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="client_id">Client :</label>
        <select name="client_id" id="client_id" required>
            <option value="">-- Choisir --</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($selected_client_id == $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div id="client-patterns" style="margin:15px 0;"></div>

        <label for="patterns">Patterns à enregistrer (un par ligne ou séparés par virgule) :</label>
        <textarea name="patterns" id="patterns" placeholder="Ex: Pentwest&#10;pent-west&#10;pentouest&#10;pentwest.com"></textarea>

        <input type="submit" value="Enregistrer">
    </form>

    <?php if ($selected_client_id > 0): ?>
        <h2>Historique des patterns enregistrés pour ce client</h2>
        <ul>
            <?php foreach ($history as $pattern): ?>
                <li><code><?= htmlspecialchars($pattern) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php
    // Affichage des nouveaux résultats du dernier scan (facultatif)
    if (isset($_SESSION['last_scan_newly_inserted']) && count($_SESSION['last_scan_newly_inserted'])) {
        echo '<h2>Résultats réellement nouvellement ajoutés lors du dernier scan</h2><table><tr><th>Client</th><th>Pattern</th><th>Source</th><th>Titre</th><th>Group</th><th>Date découverte</th><th>Date publication</th><th>Description</th><th>Activity</th><th>Pays</th><th>Website</th><th>Screenshot</th><th>Permalink</th></tr>';
        foreach ($_SESSION['last_scan_newly_inserted'] as $r) {
            $data = $r['data'];
            $clientName = '';
            foreach ($clients as $c) if ($c['id'] == $r['client_id']) $clientName = $c['name'];
            $title = $data['post_title'] ?? ($data['victim'] ?? '');
            $group = $data['group_name'] ?? ($data['ransomware'] ?? '');
            $desc = $data['description'] ?? ($data['summary'] ?? '');
            $discover = $data['discovered'] ?? ($data['date'] ?? '');
            $publish = $data['published'] ?? ($data['added'] ?? '');
            $activity = $data['activity'] ?? '';
            $country = $data['country'] ?? '';
            $website = $data['website'] ?? ($data['domain'] ?? '');
            $screenshot = $data['screenshot'] ?? '';
            $permalink = $data['permalink'] ?? ($data['url'] ?? '');
            echo '<tr>';
            echo '<td>' . htmlspecialchars($clientName) . '</td>';
            echo '<td>' . htmlspecialchars($r['pattern']) . '</td>';
            echo '<td>' . htmlspecialchars($r['source']) . '</td>';
            echo '<td>' . htmlspecialchars($title) . '</td>';
            echo '<td>' . htmlspecialchars($group) . '</td>';
            echo '<td>' . htmlspecialchars($discover) . '</td>';
            echo '<td>' . htmlspecialchars($publish) . '</td>';
            echo '<td>' . nl2br(htmlspecialchars($desc)) . '</td>';
            echo '<td>' . htmlspecialchars($activity) . '</td>';
            echo '<td>' . htmlspecialchars($country) . '</td>';
            echo '<td>';
            if ($website) echo '<a href="https://' . htmlspecialchars($website) . '" target="_blank">' . htmlspecialchars($website) . '</a>';
            echo '</td><td>';
            if ($screenshot) echo '<a href="#" onclick="showImg(\'' . htmlspecialchars($screenshot) . '\');return false;" title="Voir screenshot"><img class="img-icon" src="https://img.icons8.com/fluency/24/image.png" alt="screenshot"/></a>';
            echo '</td><td>';
            if ($permalink) echo '<a href="' . htmlspecialchars($permalink) . '" target="_blank" title="Accès rapide"><img class="permalink-icon" src="https://img.icons8.com/fluency/24/link.png" alt="permalink"/></a>';
            echo '</td></tr>';
        }
        echo '</table>';
    }
    ?>

    <h2>Résultats ransomware.live</h2>
    <table>
        <tr>
            <th>Client</th>
            <th>Pattern</th>
            <th>Source</th>
            <th>Titre</th>
            <th>Group</th>
            <th>Date découverte</th>
            <th>Date publication</th>
            <th>Description</th>
            <th>Activity</th>
            <th>Pays</th>
            <th>Website</th>
            <th>Screenshot</th>
            <th>Permalink</th>
            <th>Supprimer</th>
        </tr>
        <?php foreach ($results as $r):
            if (isset($blacklisted[$r['client_id']]) && in_array($r['pattern'], $blacklisted[$r['client_id']])) continue;

            $data = json_decode($r['data'], true);
            if ($r['source'] === 'victims_search') {
                $title = $data['post_title'] ?? '';
                $group = $data['group_name'] ?? '';
                $desc = $data['description'] ?? '';
                $discover = $data['discovered'] ?? '';
                $publish = $data['published'] ?? '';
                $activity = $data['activity'] ?? '';
                $country = $data['country'] ?? '';
                $website = $data['website'] ?? '';
                $screenshot = $data['screenshot'] ?? '';
                $permalink = $data['permalink'] ?? '';
            } else { // press_recent
                $title = $data['victim'] ?? '';
                $group = $data['ransomware'] ?? '';
                $desc = $data['summary'] ?? '';
                $discover = $data['date'] ?? '';
                $publish = $data['added'] ?? '';
                $activity = $data['activity'] ?? '';
                $country = $data['country'] ?? '';
                $website = $data['domain'] ?? '';
                $screenshot = $data['screenshot'] ?? '';
                $permalink = $data['url'] ?? '';
            }
        ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['pattern']) ?></td>
            <td><?= htmlspecialchars($r['source']) ?></td>
            <td><?= htmlspecialchars($title) ?></td>
            <td><?= htmlspecialchars($group) ?></td>
            <td><?= htmlspecialchars($discover) ?></td>
            <td><?= htmlspecialchars($publish) ?></td>
            <td><?= nl2br(htmlspecialchars($desc)) ?></td>
            <td><?= htmlspecialchars($activity) ?></td>
            <td><?= htmlspecialchars($country) ?></td>
            <td>
                <?php if ($website): ?>
                    <a href="https://<?= htmlspecialchars($website) ?>" target="_blank"><?= htmlspecialchars($website) ?></a>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($screenshot): ?>
                    <a href="#" onclick="showImg('<?= htmlspecialchars($screenshot) ?>');return false;" title="Voir screenshot">
                        <img class="img-icon" src="https://img.icons8.com/fluency/24/image.png" alt="screenshot"/>
                    </a>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($permalink): ?>
                    <a href="<?= htmlspecialchars($permalink) ?>" target="_blank" title="Accès rapide">
                        <img class="permalink-icon" src="https://img.icons8.com/fluency/24/link.png" alt="permalink"/>
                    </a>
                <?php endif; ?>
            </td>
            <td>
                <button class="delbtn" onclick="deleteResult(<?= $r['id'] ?>, this)">✗</button>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<div id="imgModal">
    <img id="imgModalImg" src="">
</div>
</body>
</html>
