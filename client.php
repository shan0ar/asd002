<?php
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

// Lancer un scan immédiat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_now') {
    $stmt = $db->prepare("INSERT INTO scans (client_id, scan_date, scheduled, status) VALUES (?, now(), false, 'running') RETURNING id");
    $stmt->execute([$id]);
    $scan_id = $stmt->fetchColumn();

    $assets = $db->prepare("SELECT asset_value FROM client_assets WHERE client_id=?");
    $assets->execute([$id]);
    $assets = $assets->fetchAll(PDO::FETCH_COLUMN);

    foreach ($assets as $asset) {
        $cmd = sprintf('bash /var/www/html/asd002/scripts/scan_launcher.sh %s %d', escapeshellarg($asset), $scan_id);
        file_put_contents('/opt/asd002-logs/php_exec.log', date('c')." CMD: $cmd\n", FILE_APPEND);
        exec($cmd . ' > /opt/asd002-logs/php_exec.log 2>&1', $output, $ret);
    }

    header("Location: client.php?id=$id&just_launched=$scan_id");
    exit;
}

// Liste des scans pour calendrier
$scans = $db->prepare("SELECT id, scan_date, status FROM scans WHERE client_id=? ORDER BY scan_date ASC");
$scans->execute([$id]);
$scans = $scans->fetchAll(PDO::FETCH_ASSOC);

// DEBUG: Affiche tous les scans de ce client
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
<div class="sidebar">
    <a href="index.php">← Retour</a>
    <h2><?=htmlspecialchars($client['name'])?></h2>
</div>
<div class="main" style="position:relative;">
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

    // Affichage automatique des résultats du scan tout juste lancé
    if ($just_launched && !$day) {
        // Récupère la date du scan_id
        $stmt = $db->prepare("SELECT scan_date FROM scans WHERE id=?");
        $stmt->execute([$just_launched]);
        $scan_date = $stmt->fetchColumn();
        $scan_day = substr($scan_date, 8, 2); // ex: "2025-08-22 11:05:44.458036" -> "22"
        echo "<script>window.location = 'client.php?id=$id&year=$year&month=$month&day=$scan_day&auto_poll=1&scan_id=$just_launched';</script>";
        exit;
    }

    // Poll AJAX si on vient de lancer un scan
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

    // Affichage tabulaire des résultats structurés pour le scan sélectionné
    if ($day !== null && $scan && $scan['status'] == 'done') {
        $scan_id = $scan['id'];

        // DEBUG: Affiche les whatweb du scan_id sélectionné
        $whatweb = $db->prepare("SELECT * FROM whatweb WHERE scan_id=?");
        $whatweb->execute([$scan_id]);
        $whatweb_rows = $whatweb->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre style='background:#efe;border:1px solid #ccc;padding:5px'>DEBUG whatweb (" . count($whatweb_rows) . " lignes) pour scan_id=$scan_id\n";
        foreach ($whatweb_rows as $row) {
            echo "id={$row['id']} domaine={$row['domain_ip']} techno={$row['technologie']} version={$row['version']} valeur={$row['valeur']}\n";
        }
        echo "</pre>";

        // WHOIS (reste inchangé)
        $whois = $db->prepare("SELECT * FROM whois_data WHERE scan_id=?");
        $whois->execute([$scan_id]);
        $whois = $whois->fetch(PDO::FETCH_ASSOC);
        if ($whois) {
            echo "<h3>WHOIS</h3>";
            echo "<table class='data'><tr>
                <th>Domaine</th><th>Registrar</th><th>Création</th><th>Expiration</th>
                <th>NS1</th><th>NS2</th><th>DNSSEC</th>
                <th><span class='info-icon' onclick=\"toggleRaw('whois-{$whois['id']}')\">i</span></th>
            </tr><tr>
                <td>{$whois['domain']}</td>
                <td>{$whois['registrar']}</td>
                <td>{$whois['creation_date']}</td>
                <td>{$whois['expiry_date']}</td>
                <td>{$whois['name_server_1']}</td>
                <td>{$whois['name_server_2']}</td>
                <td>{$whois['dnssec']}</td>
                <td>
                  <span class='info-icon' onclick=\"toggleRaw('whois-{$whois['id']}')\">i</span>
                  <div id='whois-{$whois['id']}' style='display:none;white-space:pre;font-size:0.9em;border:1px solid #ccc;padding:4px;background:#fafafa;'>{$whois['raw_output']}</div>
                </td>
            </tr></table>";
        }

        // WhatWeb (direct in whatweb table, one row per technology)
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
        } else {
            echo "<div style='color:red;font-weight:bold'>Aucun résultat WhatWeb pour ce scan.</div>";
        }

        // DIG A
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

        // DIG NS
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

        // DIG MX
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

        // DIG TXT
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

        // DIG BRUTEFORCE (top 50)
        $dig_brute = $db->prepare("SELECT * FROM dig_bruteforce WHERE scan_id=? ORDER BY id ASC LIMIT 50");
$dig_brute->execute([$scan_id]);
$dig_brute_rows = $dig_brute->fetchAll(PDO::FETCH_ASSOC);
if ($dig_brute_rows && count($dig_brute_rows)) {
    echo "<h3>Résultats DIG Bruteforce (top 50)</h3>
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
        // --- fin affichage DIG ---
    }
    ?>
</div>
</body>
</html>
