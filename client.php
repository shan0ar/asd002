<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
$db = getDb();

$id = intval($_GET['id']);
$client = $db->prepare("SELECT * FROM clients WHERE id=?");
$client->execute([$id]);
$client = $client->fetch(PDO::FETCH_ASSOC);
if (!$client) die("Client introuvable");

$schedule = $db->prepare("SELECT * FROM scan_schedules WHERE client_id=?");
$schedule->execute([$id]);
$schedule = $schedule->fetch(PDO::FETCH_ASSOC);

// 1. Assets découverts
$stmt = $db->prepare("SELECT DISTINCT LOWER(asset) AS asset FROM assets_discovered WHERE client_id=?");
$stmt->execute([$id]);
$assets_discovered = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 2. Assets de base
$stmt = $db->prepare("SELECT DISTINCT LOWER(asset_value) AS asset FROM client_assets WHERE client_id=?");
$stmt->execute([$id]);
$assets_base = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 3. Fusion des assets pour affichage dans la matrice outils/assets
$assets = array_unique(array_merge($assets_discovered, $assets_base));
sort($assets);

// Liste des outils
$scan_tools = [
    'whois' => 'Whois',
    'amass' => 'Amass',
    'dig_bruteforce' => 'DIG_Bruteforce',
    'dig_mx' => 'DIG_MX',
    'dig_txt' => 'DIG_TXT',
    'dig_a' => 'DIG_A',
    'whatweb' => 'Whatweb/Nuclei',
    'nmap' => 'Nmap'
];

// $scan_id, $client_id connus ici
// $asset_sources est un tableau associatif asset => array(source1, source2, ...)
// par exemple: $asset_sources['foo.example.com'] = ['Amass', 'DIG_A']
$asset_sources = $asset_sources ?? [];
foreach ($asset_sources as $asset => $sources) {
    $source_str = implode(' & ', $sources);
    // Vérifie déjà présent pour ce scan+asset
    $check = $db->prepare("SELECT id FROM assets_discovered WHERE scan_id=? AND asset=?");
    $check->execute([$scan_id, $asset]);
    if (!$check->fetch()) {
        $ins = $db->prepare("INSERT INTO assets_discovered (scan_id, detected_at, client_id, asset, source) VALUES (?, now(), ?, ?, ?)");
        $ins->execute([$scan_id, $client_id, $asset, $source_str]);
    }
}
// Enregistrement paramètres assets/outils
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_asset_tools'])) {
    $asset_params = $_POST['asset_tools'] ?? [];
    foreach ($asset_params as $asset => $tools) {
        foreach ($scan_tools as $tool_key => $tool_label) {
            $enabled = isset($tools[$tool_key]) ? 1 : 0;
            $db->prepare("
                INSERT INTO asset_scan_settings (client_id, asset, tool, enabled)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (client_id, asset, tool) DO UPDATE SET enabled=excluded.enabled
            ")->execute([$id, $asset, $tool_key, $enabled]);
        }
    }
    echo "<div style='color:green;font-weight:bold;margin:12px 0'>Paramètres d’assets enregistrés !</div>";
}

// Lecture des paramètres enregistrés
$stmt = $db->prepare("SELECT asset, tool, enabled FROM asset_scan_settings WHERE client_id=?");
$stmt->execute([$id]);
$settings = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[$row['asset']][$row['tool']] = $row['enabled'];
}

// ======= GESTION NB TENTATIVES BRUTEFORCE =======
$brute_count = 50; // valeur par défaut
$info_stmt = $db->prepare("SELECT brute_count FROM information WHERE client_id=?");
$info_stmt->execute([$id]);
if ($info_row = $info_stmt->fetch(PDO::FETCH_ASSOC)) {
    $brute_count = intval($info_row['brute_count']);
}

// Enregistrement du nombre de tentatives bruteforce
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['brute_count'])) {
    $new_brute_count = max(1, intval($_POST['brute_count']));
    // Insert/update
    $exists = $db->prepare("SELECT id FROM information WHERE client_id=?");
    $exists->execute([$id]);
    if ($exists->fetch()) {
        $upd = $db->prepare("UPDATE information SET brute_count=?, updated_at=now() WHERE client_id=?");
        $upd->execute([$new_brute_count, $id]);
    } else {
        $ins = $db->prepare("INSERT INTO information (client_id, brute_count) VALUES (?, ?)");
        $ins->execute([$id, $new_brute_count]);
    }
    $brute_count = $new_brute_count;
    echo "<div style='color:green;font-weight:bold'>Nombre de tentatives bruteforce enregistré : $brute_count</div>";
}

// Enregistrement de la planification personnalisée
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['frequency'])) {
    $freq = $_POST['frequency'];
    $day = isset($_POST['day_of_week']) ? intval($_POST['day_of_week']) : null;
    $time = $_POST['time'] ?? '00:00:00';
    $next_run = (isset($_POST['custom_date']) && $_POST['custom_date'] && isset($_POST['custom_time']) && $_POST['custom_time'])
        ? ($_POST['custom_date'] . ' ' . $_POST['custom_time'])
        : null;
    if ($schedule) {
        $stmt = $db->prepare("UPDATE scan_schedules SET frequency=?, day_of_week=?, time=?, next_run=? WHERE client_id=?");
        $stmt->execute([$freq, $day, $time, $next_run, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO scan_schedules (client_id, frequency, day_of_week, time, next_run) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $freq, $day, $time, $next_run]);
    }
    header("Location: client.php?id=$id");
    exit;
}

// Lancer un scan immédiat (corrigé pour prendre en compte les outils cochés)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_now') {
    $stmt = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, now(), false, 'running') RETURNING id");
    $stmt->execute([$id]);
    $scan_id = $stmt->fetchColumn();

    // 1. Liste des assets pour lesquels au moins un outil est coché
    $assets_stmt = $db->prepare("SELECT DISTINCT asset FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $assets_stmt->execute([$id]);
    $assets = $assets_stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Liste des outils cochés pour chaque asset
    $settings_stmt = $db->prepare("SELECT asset, tool FROM asset_scan_settings WHERE client_id=? AND enabled=true");
    $settings_stmt->execute([$id]);
    $enabled_tools = [];
    foreach ($settings_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $enabled_tools[$row['asset']][] = $row['tool'];
    }

    foreach ($assets as $asset) {
        $asset_tools = $enabled_tools[$asset] ?? [];
        foreach ($asset_tools as $tool) {
            $cmd = sprintf('bash /var/www/html/asd002/scripts/scan_%s.sh %s %d', $tool, escapeshellarg($asset), $scan_id);
            file_put_contents('/opt/asd002-logs/php_exec.log', date('c')." CMD: $cmd\n", FILE_APPEND);
            exec($cmd . ' >> /opt/asd002-logs/php_exec.log 2>&1');
        }
    }
    $db->prepare("UPDATE scans SET status='done' WHERE id=?")->execute([$scan_id]);
    header("Location: client.php?id=$id&just_launched=$scan_id");
    exit;
}

// Liste des scans pour calendrier
$scans = $db->prepare("SELECT id, scan_date, status FROM scans WHERE client_id=? ORDER BY scan_date ASC");
$scans->execute([$id]);
$scans = $scans->fetchAll(PDO::FETCH_ASSOC);

echo "<pre style='background:#ffe;border:1px solid #ccc;padding:6px'>DEBUG scans<br>";
foreach ($scans as $s) echo "scan_id={$s['id']} scan_date={$s['scan_date']} status={$s['status']}\n";
echo "</pre>";

function scan_days_array($scans) {
    $out = [];
    foreach ($scans as $s) {
        $day = date('Y-m-d', strtotime($s['scan_date']));
        $out[$day] = $s['status'] ?? true;
    }
    return $out;
}
$scan_days = scan_days_array($scans);

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$startDay = date('N', $firstDay);

$date_now = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Client: <?=htmlspecialchars($client['name'])?></title>
    <link rel="stylesheet" href="static/style.css">
    <style>
    .current-datetime {
        position: absolute;
        top: 20px;
        right: 40px;
        background: #eef;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 1.05em;
        color: #333;
        box-shadow: 0 2px 6px #aaa2;
    }
    .scan-status { font-weight: bold; margin-bottom: 10px; }
    .scan-status.pending { color: #3498db; }
    .scan-status.running { color: #e67e22; }
    .scan-status.done { color: #27ae60; }
    .data { border-collapse: collapse; margin-bottom: 1em; }
    .data th, .data td { border: 1px solid #bbb; padding: 4px 10px; }
    .info-icon {
      cursor: pointer;
      color: #00a;
      border-radius: 50%;
      border: 1px solid #00a;
      padding: 0 4px;
      font-weight: bold;
      font-family: sans-serif;
      margin-left: 3px;
    }
    .info-icon:hover { background: #eef; }
    ul.wwtech { margin:0; padding-left:20px;}
    ul.wwtech li { margin-bottom:2px;}
    pre.raw-dig { background: #f6f8fa; border: 1px solid #ccc; padding: 8px; font-size: 0.95em; max-height: 320px; overflow:auto;}
    .asset-settings-frame { background:#eef;border:1px solid #88a;padding:15px 25px;margin:2em 0 2em 0;max-width:1100px;}
    .asset-settings-frame h2 {margin-top:0}
    .asset-settings-frame td, .asset-settings-frame th {text-align:center;}
    .asset-settings-frame button {margin-top:12px;}
    </style>
    <script>
    function toggleRaw(id) {
      var el = document.getElementById(id);
      el.style.display = (el.style.display == "none") ? "block" : "none";
    }
    function pollScanStatus(scanId) {
        fetch('scan_status.php?scan_id=' + scanId)
            .then(r => r.json())
            .then(data => {
                if(data.status === 'done' || data.status === 'failed') {
                    location.reload();
                } else {
                    setTimeout(() => pollScanStatus(scanId), 3000);
                }
            });
    }
    </script>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main">
    <div class="current-datetime">
        Date/heure actuelle : <b><?=$date_now?></b>
    </div>
    <h1>Calendrier des scans</h1>
    <form method="get" style="margin-bottom:1em;">
        <input type="hidden" name="id" value="<?=$id?>">
        Mois: <select name="month">
            <?php for($m=1;$m<=12;$m++): ?>
                <option value="<?=$m?>"<?=$m==$month?' selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
            <?php endfor ?>
        </select>
        Année: <select name="year">
            <?php for($y=$year-2;$y<=$year+2;$y++): ?>
                <option value="<?=$y?>"<?=$y==$year?' selected':''?>><?=$y?></option>
            <?php endfor ?>
        </select>
        <button type="submit">Changer</button>
    </form>
    <table class="calendar">
        <tr>
            <?php foreach(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
                <th><?=$d?></th>
            <?php endforeach ?>
        </tr>
        <tr>
        <?php
        $d = 1;
        $cell = 1;
        for($i=1;$i<$startDay;$i++): ?><td></td><?php $cell++; endfor;
        for($i=1;$i<=$daysInMonth;$i++,$cell++):
            $date = sprintf('%04d-%02d-%02d', $year, $month, $i);
            $style = '';
            if (isset($scan_days[$date])) {
                $status = $scan_days[$date];
                if ($status == 'running') $style = ' style="background:#ffe39c"';
                elseif ($status == 'done') $style = ' style="background:#aee"';
                elseif ($status == 'pending') $style = ' style="background:#add8e6"';
            }
            echo "<td$style><a href='client.php?id=$id&year=$year&month=$month&day=$i'>$i</a></td>";
            if ($cell%7==0) echo "</tr><tr>";
        endfor;
        for(;$cell%7!=1;$cell++): ?><td></td><?php endfor;
        ?>
        </tr>
    </table>

<div class="asset-settings-frame">
    <h2>Paramètres assets & outils pour le prochain scan</h2>
    <form method="post" style="margin:0">
        <input type="hidden" name="save_asset_tools" value="1">
        <div class="table-container">
            <table class="param-table">
                <tbody>
                    <tr>
                        <th>Asset</th>
                        <?php foreach($scan_tools as $tool_key => $tool_label): ?>
                            <th><?=$tool_label?></th>
                        <?php endforeach ?>
                    </tr>
                    <?php
                    $colors = ["color1", "color2", "color3"];
                    $rownum = 0;
                    foreach($assets as $asset):
                        $color_class = $colors[$rownum % 3];
                    ?>
                    <tr class="<?=$color_class?>">
                        <td class="asset-cell"><?=htmlspecialchars($asset)?></td>
                        <?php foreach($scan_tools as $tool_key => $tool_label): ?>
                            <td>
                                <input type="checkbox"
                                       name="asset_tools[<?=htmlspecialchars($asset)?>][<?=$tool_key?>]"
                                       value="1"
                                       id="<?=md5($asset.$tool_key)?>"
                                       <?= (isset($settings[$asset][$tool_key]) && $settings[$asset][$tool_key]) ? 'checked' : '' ?>>
                                <label for="<?=md5($asset.$tool_key)?>"></label>
                            </td>
                        <?php endforeach ?>
                    </tr>
                    <?php
                    $rownum++;
                    endforeach;
                    ?>
                </tbody>
            </table>
        </div>
        <button type="submit">Enregistrer ces paramètres</button>
    </form>
    <div style="font-size:0.93em;color:#888;margin-top:8px;">Chaque outil sera utilisé ou non pour chaque asset au prochain scan, selon vos choix ici.</div>
</div>
<!-- === Fin cadre assets/outils ON/OFF === -->


    <h2>Planification des scans</h2>
    <form method="post">
        <label>Fréquence des scans :
            <select name="frequency">
                <option value="weekly"<?=($schedule['frequency']=='weekly'?' selected':'')?>>Hebdomadaire</option>
                <option value="monthly"<?=($schedule['frequency']=='monthly'?' selected':'')?>>Mensuel</option>
                <option value="quarterly"<?=($schedule['frequency']=='quarterly'?' selected':'')?>>Trimestriel</option>
                <option value="semiannual"<?=($schedule['frequency']=='semiannual'?' selected':'')?>>Semestriel</option>
                <option value="annual"<?=($schedule['frequency']=='annual'?' selected':'')?>>Annuel</option>
            </select>
        </label>
        <label>Jour (0=Lundi, 6=Dimanche): <input type="number" name="day_of_week" min="0" max="6" value="<?=htmlspecialchars($schedule['day_of_week']??'')?>"></label>
        <label>Heure : <input type="time" name="time" value="<?=htmlspecialchars($schedule['time']??'00:00')?>"></label>
        <label>Date personnalisée : <input type="date" name="custom_date"></label>
        <label>Heure personnalisée : <input type="time" name="custom_time"></label>
        <button type="submit">Enregistrer</button>
    </form>

    <form method="post" style="margin:1em 0;">
        <label for="brute_count"><b>Nombre de tentatives bruteforce (lignes de la wordlist à tester)</b> :</label>
        <input type="number" min="1" max="10000" name="brute_count" id="brute_count" value="<?=htmlspecialchars($brute_count)?>" required>
        <input type="submit" value="Enregistrer">
    </form>

    <form method="post" action="custom_scan.php">
        <input type="hidden" name="client_id" value="<?=$id?>">
        <button type="submit">Personnaliser le prochain scan</button>
    </form>
    <form method="post" style="margin-bottom:1em;">
        <input type="hidden" name="action" value="scan_now">
        <button type="submit">Lancer un scan maintenant</button>
    </form>
    <?php
    $just_launched = isset($_GET['just_launched']) ? intval($_GET['just_launched']) : null;
    $day = isset($_GET['day']) ? intval($_GET['day']) : null;

    if ($just_launched && !$day) {
        $stmt = $db->prepare("SELECT scan_date FROM scans WHERE id=?");
        $stmt->execute([$just_launched]);
        $scan_date = $stmt->fetchColumn();
        $scan_day = substr($scan_date, 8, 2);
        echo "<script>window.location = 'client.php?id=$id&year=$year&month=$month&day=$scan_day&auto_poll=1&scan_id=$just_launched';</script>";
        exit;
    }

    if (isset($_GET['auto_poll']) && isset($_GET['scan_id'])) {
        $poll_scan_id = intval($_GET['scan_id']);
        echo "<script>pollScanStatus($poll_scan_id);</script>";
        echo "<div class='scan-status running'>Scan en cours… <img src='static/spinner.gif' style='vertical-align:middle;width:18px'></div>";
    }

    if ($day !== null) {
        $sel_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $scan = $db->prepare("SELECT * FROM scans WHERE client_id=? AND scan_date::date=?::date ORDER BY scan_date DESC LIMIT 1");
        $scan->execute([$id, $sel_date]);
        $scan = $scan->fetch(PDO::FETCH_ASSOC);

        if ($scan) {
            $scan_id = $scan['id'];
            $scan_status = $scan['status'];
            $scan_date = $scan['scan_date'];
            echo "<pre style='background:#eef;border:1px solid #ccc;padding:5px'>DEBUG : scan sélectionné id=$scan_id status=$scan_status date=$scan_date</pre>";
            if ($scan_status == 'running') echo "<div class='scan-status running'>Scan en cours…</div>";
            elseif ($scan_status == 'pending') echo "<div class='scan-status pending'>Scan planifié pour " . htmlspecialchars($scan_date) . "</div>";
            elseif ($scan_status == 'done') echo "<div class='scan-status done'>Scan terminé le " . htmlspecialchars($scan_date) . "</div>";

            $nmap_stmt = $db->prepare("SELECT asset, port, state, service, version FROM nmap_results WHERE scan_id=? ORDER BY asset, port ASC");
$nmap_stmt->execute([$scan_id]);
$nmap_results = $nmap_stmt->fetchAll(PDO::FETCH_ASSOC);
if ($nmap_results && count($nmap_results)) {
    echo "<h3>Résultats Nmap</h3>
    <table class='data'><tr>
        <th>Asset</th>
        <th>Port</th>
        <th>État</th>
        <th>Service</th>
        <th>Version</th>
    </tr>";
    foreach ($nmap_results as $row) {
        echo "<tr>
            <td>".htmlspecialchars($row['asset'])."</td>
            <td>".htmlspecialchars($row['port'])."</td>
            <td>".htmlspecialchars($row['state'])."</td>
            <td>".htmlspecialchars($row['service'])."</td>
            <td>".htmlspecialchars($row['version'])."</td>
        </tr>";
    }
    echo "</table>";
            } else {
                echo "<div style='color:#888;'>Aucun résultat Nmap pour ce scan.</div>";
            }

            $stmt = $db->prepare("SELECT asset, source FROM assets_discovered WHERE scan_id=?");
            $stmt->execute([$scan_id]);
            $assets_discovered = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($assets_discovered && count($assets_discovered)) {
                $asset_sources = [];
                foreach ($assets_discovered as $a) {
                    $dom = strtolower(trim($a['asset']));
                    $srcs = array_map('trim', explode('&', str_replace(' & ', '&', $a['source'])));
                    if (!isset($asset_sources[$dom])) $asset_sources[$dom] = [];
                    foreach ($srcs as $src)
                        if ($src && !in_array($src, $asset_sources[$dom])) $asset_sources[$dom][] = $src;
                }
                echo "<h3>Domaines/sous-domaines détectés (tous outils confondus, sans doublon):</h3><pre style='background:#eef;border:1px solid #ccc;padding:7px'>";
                foreach ($asset_sources as $dom => $srcs) {
                    echo htmlspecialchars($dom) . " [" . htmlspecialchars(implode(' & ', $srcs)) . "]\n";
                }
                echo "</pre>";
            }

// === WHOIS RESULT ===
$whois_stmt = $db->prepare("SELECT * FROM whois_data WHERE scan_id=?");
$whois_stmt->execute([$scan_id]);
$whois_rows = $whois_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($whois_rows && count($whois_rows)) {
    echo "<h3>Résultat Whois</h3>";
    foreach ($whois_rows as $row) {
        echo "<div style='margin-bottom:1.1em;border:1px solid #bbb;background:#f8fafc;padding:8px 14px;border-radius:6px'>";
        echo "<b>Domaine :</b> " . htmlspecialchars($row['domain'] ?? '') . "<br>";
        echo "<b>Registrar :</b> " . htmlspecialchars($row['registrar'] ?? '') . "<br>";
        echo "<b>Date création :</b> " . htmlspecialchars($row['creation_date'] ?? '') . "<br>";
        echo "<b>Date expiration :</b> " . htmlspecialchars($row['expiry_date'] ?? '') . "<br>";
        // Affichage de tous les serveurs DNS (issus de nserver)
        $all_ns = [];
        if (!empty($row['name_servers'])) {
            $ns_list = array_filter(explode('|', $row['name_servers']));
            $all_ns = $ns_list;
        } else {
            if (!empty($row['name_server_1'])) $all_ns[] = $row['name_server_1'];
            if (!empty($row['name_server_2'])) $all_ns[] = $row['name_server_2'];
        }
        echo "<b>Serveurs DNS :</b>";
        if (count($all_ns)) {
            echo " ";
            echo implode(', ', array_map('htmlspecialchars', $all_ns));
        } else {
            echo "N/A";
        }
        echo "<br>";
        // Propriétaire
        if (!empty($row['registrant'])) {
            echo "<b>Propriétaire :</b> " . htmlspecialchars($row['registrant']) . "<br>";
            $registrant = trim(strtoupper($row['registrant']));
            $anonymes = [
                'REDACTED FOR PRIVACY',
                'GDPR MASKED',
                'GDPR REDACTED',
                'PRIVACY PROTECTION',
                'NOT DISCLOSED',
                'PRIVATE PERSON'
            ];
            if (!in_array($registrant, $anonymes)) {
                $linked_stmt = $db->prepare("SELECT domain FROM whois_data WHERE registrant=? AND domain<>?");
                $linked_stmt->execute([$row['registrant'], $row['domain']]);
                $linked_domains = $linked_stmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($linked_domains)) {
                    echo "<b>Autres domaines liés à ce propriétaire :</b> " . implode(', ', array_map('htmlspecialchars', $linked_domains)) . "<br>";
                }
            }
        }
        echo "<details><summary>WHOIS complet</summary><pre style='max-width:800px;overflow-x:auto;font-size:0.97em'>" . htmlspecialchars($row['raw_output'] ?? '') . "</pre></details>";
        echo "</div>";
    }
}




            $whatweb = $db->prepare("SELECT * FROM whatweb WHERE scan_id=?");
            $whatweb->execute([$scan_id]);
            $whatweb_rows = $whatweb->fetchAll(PDO::FETCH_ASSOC);
            if ($whatweb_rows && count($whatweb_rows)) {
                echo "<h3>WhatWeb</h3>";
                echo "<table class='data'><tr>
                        <th>IP/Domaine</th>
                        <th>Technologie</th>
                        <th>Valeur</th>
                        <th>Version</th>
                      </tr>";
                foreach ($whatweb_rows as $row) {
                    echo "<tr>
                            <td>".htmlspecialchars($row['domain_ip'])."</td>
                            <td>".htmlspecialchars($row['technologie'])."</td>
                            <td>".htmlspecialchars($row['valeur'])."</td>
                            <td>".htmlspecialchars($row['version'])."</td>
                          </tr>";
                }
                echo "</table>";
            }

            $dig_a = $db->prepare("SELECT * FROM dig_a WHERE scan_id=?");
            $dig_a->execute([$scan_id]);
            $dig_a = $dig_a->fetch(PDO::FETCH_ASSOC);
            if ($dig_a) {
                echo "<h3>Résultat DIG A</h3>
                <table class='data'><tr>
                <th>Domaine</th><th>IP</th><th>TTL</th><th>Raw</th>
                </tr><tr>
                <td>{$dig_a['domain']}</td>
                <td>{$dig_a['ip']}</td>
                <td>{$dig_a['ttl']}</td>
                <td><span class='info-icon' onclick=\"toggleRaw('dig-a-{$dig_a['id']}')\">i</span>
                    <div id='dig-a-{$dig_a['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($dig_a['raw_output'])."</div>
                </td>
                </tr></table>";
            }

            $amass = $db->prepare("SELECT * FROM amass_results WHERE scan_id=? ORDER BY id ASC");
            $amass->execute([$scan_id]);
            $amass_rows = $amass->fetchAll(PDO::FETCH_ASSOC);
            if ($amass_rows && count($amass_rows)) {
                echo "<h3>Résultats Amass</h3>
                <table class='data'><tr>
                    <th>#</th>
                    <th>Sous-domaine</th>
                    <th>Type</th>
                    <th>Valeur</th>
                    <th>Ligne brute</th>
                </tr>";
                $i = 1;
                foreach ($amass_rows as $row) {
                    echo "<tr>
                        <td>{$i}</td>
                        <td>".htmlspecialchars($row['subdomain'])."</td>
                        <td>".htmlspecialchars($row['record_type'])."</td>
                        <td>".htmlspecialchars($row['value'])."</td>
                        <td><span class='info-icon' onclick=\"toggleRaw('amass-{$row['id']}')\">i</span>
                            <div id='amass-{$row['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($row['raw_output'])."</div>
                        </td>
                    </tr>";
                    $i++;
                }
                echo "</table>";
            }

            $dig_ns = $db->prepare("SELECT * FROM dig_ns WHERE scan_id=?");
            $dig_ns->execute([$scan_id]);
            $dig_ns_rows = $dig_ns->fetchAll(PDO::FETCH_ASSOC);
            if ($dig_ns_rows && count($dig_ns_rows)) {
                echo "<h3>Résultat DIG NS</h3>
                <table class='data'><tr>
                <th>Domaine</th><th>NS</th><th>TTL</th><th>Raw</th>
                </tr>";
                foreach ($dig_ns_rows as $row) {
                    echo "<tr>
                    <td>{$row['domain']}</td>
                    <td>{$row['ns']}</td>
                    <td>{$row['ttl']}</td>
                    <td><span class='info-icon' onclick=\"toggleRaw('dig-ns-{$row['id']}')\">i</span>
                      <div id='dig-ns-{$row['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($row['raw_output'])."</div>
                    </td>
                    </tr>";
                }
                echo "</table>";
            }

            $dig_mx = $db->prepare("SELECT * FROM dig_mx WHERE scan_id=?");
            $dig_mx->execute([$scan_id]);
            $dig_mx_rows = $dig_mx->fetchAll(PDO::FETCH_ASSOC);
            if ($dig_mx_rows && count($dig_mx_rows)) {
                echo "<h3>Résultat DIG MX</h3>
                <table class='data'><tr>
                <th>Domaine</th><th>Préférence</th><th>Exchange</th><th>TTL</th><th>Raw</th>
                </tr>";
                foreach ($dig_mx_rows as $row) {
                    echo "<tr>
                    <td>{$row['domain']}</td>
                    <td>{$row['preference']}</td>
                    <td>{$row['exchange']}</td>
                    <td>{$row['ttl']}</td>
                    <td><span class='info-icon' onclick=\"toggleRaw('dig-mx-{$row['id']}')\">i</span>
                      <div id='dig-mx-{$row['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($row['raw_output'])."</div>
                    </td>
                    </tr>";
                }
                echo "</table>";
            }

            $dig_txt = $db->prepare("SELECT * FROM dig_txt WHERE scan_id=?");
            $dig_txt->execute([$scan_id]);
            $dig_txt_rows = $dig_txt->fetchAll(PDO::FETCH_ASSOC);
            if ($dig_txt_rows && count($dig_txt_rows)) {
                echo "<h3>Résultat DIG TXT</h3>
                <table class='data'><tr>
                <th>Domaine</th><th>TXT</th><th>TTL</th><th>Raw</th>
                </tr>";
                foreach ($dig_txt_rows as $row) {
                    echo "<tr>
                    <td>{$row['domain']}</td>
                    <td>".htmlspecialchars($row['txt'])."</td>
                    <td>{$row['ttl']}</td>
                    <td><span class='info-icon' onclick=\"toggleRaw('dig-txt-{$row['id']}')\">i</span>
                      <div id='dig-txt-{$row['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($row['raw_output'])."</div>
                    </td>
                    </tr>";
                }
                echo "</table>";
            }

            $stmt = $db->prepare("SELECT bruteforce_attempts FROM scans WHERE id=?");
            $stmt->execute([$scan_id]);
            $nb_tentatives = $stmt->fetchColumn();
            $nb_tentatives_txt = ($nb_tentatives !== null && $nb_tentatives !== false) ? intval($nb_tentatives) : "?";
            $dig_brute = $db->prepare("SELECT * FROM dig_bruteforce WHERE scan_id=? ORDER BY id ASC");
            $dig_brute->execute([$scan_id]);
            $dig_brute_rows = $dig_brute->fetchAll(PDO::FETCH_ASSOC);
            if ($dig_brute_rows && count($dig_brute_rows)) {
                echo "<h3>Résultats DIG Bruteforce ($nb_tentatives_txt tentatives)</h3>
                <table class='data'><tr>
                <th>Subdomain</th><th>IP</th><th>Raw (premières lignes)</th>
                </tr>";
                foreach ($dig_brute_rows as $row) {
                    $short_raw = implode("\n", array_slice(explode("\n", $row['raw_output']), 0, 20));
                    echo "<tr>
                    <td>{$row['subdomain']}</td>
                    <td>{$row['ip']}</td>
                    <td>
                        <span class='info-icon' onclick=\"toggleRaw('dig-bruteforce-{$row['id']}')\">i</span>
                        <div id='dig-bruteforce-{$row['id']}' class='raw-dig' style='display:none;'>".htmlspecialchars($short_raw)."</div>
                    </td>
                    </tr>";
                }
                echo "</table>";
            }
        } else {
            $sched = $db->prepare("SELECT next_run FROM scan_schedules WHERE client_id=?");
            $sched->execute([$id]);
            $next = $sched->fetchColumn();
            if ($next) {
                echo "<div class='scan-status pending'>Scan planifié pour $next</div>";
            }
        }
    } else {
        $sched = $db->prepare("SELECT next_run FROM scan_schedules WHERE client_id=?");
        $sched->execute([$id]);
        $next = $sched->fetchColumn();
        if ($next) {
            echo "<div class='scan-status pending'>Prochain scan planifié pour $next</div>";
        }
    }
    ?>
</div>
<!-- Bouton flottant export -->
<a href="report_extract.php" id="reportExportBtn" title="Exporter rapport">
    🗎 Exporter rapport
</a>
<style>
#reportExportBtn {
    position: fixed;
    right: 28px;
    bottom: 24px;
    z-index: 9999;
    background: #1976d2;
    color: #fff;
    padding: 16px 22px 16px 24px;
    border-radius: 40px;
    box-shadow: 0 4px 16px #0003;
    font-size: 1.15em;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s;
}
#reportExportBtn:hover {
    background: #1565c0;
    color: #fff;
}
</style>
</body>
</html>
