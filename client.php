<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';
require_once 'includes/session_check.php';
$db = getDb();
// GESTION AJAX — doit être AVANT tout HTML/ECHO !
if (isset($_GET['action']) && $_GET['action'] === 'add_asset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $client_id = intval($input['client_id'] ?? 0);
    $type = $input['asset_type'] ?? '';
    $values = $input['asset_values'] ?? '';
    if (!$client_id || !$type || !$values) { echo json_encode(['success'=>false,'error'=>'Données manquantes']); exit; }
    $db = getDb();
    $db->beginTransaction();
    try {
        foreach (explode("\n", $values) as $val) {
            $val = trim($val);
            if ($val !== "") {
                $stmt = $db->prepare("INSERT INTO client_assets (client_id, asset_type, asset_value) VALUES (?, ?, ?)");
                $stmt->execute([$client_id, $type, $val]);
            }
        }
        $db->commit();
        echo json_encode(['success'=>true]); exit;
    } catch(Exception $e) {
        $db->rollBack();
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
    }
}
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
    'nmap' => 'Nmap',
    'dork' => 'Dork'
];

// Enregistrement paramètres assets/outils
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_asset_tools'])) {
    // Ici on utilise la liste fusionnée $assets (découverts + base), pour gérer toutes les cases affichées
    foreach ($assets as $asset) {
        $asset_norm = strtolower(trim($asset));
        foreach ($scan_tools as $tool_key => $tool_label) {
            // Si la case est cochée, $_POST['asset_tools'][$asset][$tool_key] existe
            $enabled = !empty($_POST['asset_tools'][$asset][$tool_key]) ? 1 : 0;
            $db->prepare("
                INSERT INTO asset_scan_settings (client_id, asset, tool, enabled)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (client_id, asset, tool) DO UPDATE SET enabled=excluded.enabled
            ")->execute([$id, $asset_norm, $tool_key, $enabled]);
        }
    }
    echo "<div style='color:green;font-weight:bold;margin:12px 0'>Paramètres d’assets enregistrés !</div>";
}

// Lecture des paramètres enregistrés pour affichage
$stmt = $db->prepare("SELECT asset, tool, enabled FROM asset_scan_settings WHERE client_id = ?");
$stmt->execute([$id]);
$settings = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $asset_norm = strtolower(trim($row['asset']));
    $settings[$asset_norm][$row['tool']] = $row['enabled'];
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
    echo "<div style='color:green;font-weight:bold'>Nombre de tentatives bruteforce enregistré : $brute_count</div>";
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
<!-- Ajout Assets (en haut à droite) -->
<style>
.asset-add-box {
    position: absolute;
    top: 30px;
    right: 40px;
    background: #f8fafd;
    border: 1px solid #b1b6c9;
    border-radius: 12px;
    box-shadow: 0 2px 12px #b1b6c94d;
    padding: 20px 26px 14px 26px;
    z-index: 100;
    min-width: 320px;
}
.asset-add-box h3 { margin: 0 0 10px 0; font-size: 1.13em; color: #2957a4; }
.asset-add-box label { font-size: 1em; color: #23396a; }
.asset-add-box textarea, .asset-add-box select, .asset-add-box input[type="text"] {
    width:100%; border-radius:7px; border:1px solid #b1b6c9; margin:6px 0 14px 0; background:#fafdff; padding:6px 9px; font-size:1em;
}
.asset-add-box button { background: linear-gradient(90deg, #2957a4 0%, #4ed6e2 100%); color:#fff; border:none; border-radius:7px; font-weight:600; padding:6px 20px; font-size:1em; cursor:pointer;}
.asset-add-box .close { position:absolute; top:7px; right:13px; cursor:pointer; color:#888; font-size:1.2em;}
</style>
<div id="assetAddBox" class="asset-add-box" style="display:none;">
    <span class="close" onclick="document.getElementById('assetAddBox').style.display='none';">&times;</span>
    <h3>Ajouter des assets</h3>
    <form id="asset-add-form">
        <label for="asset_type">Type :</label>
        <select id="asset_type" required>
            <option value="domain">Nom de domaine</option>
            <option value="fqdn">FQDN</option>
            <option value="ip">IP publique</option>
            <option value="ip_range">Plage IP</option>
        </select>
        <label>Valeur(s) (une par ligne) :</label>
        <textarea id="asset_values" rows="3" required placeholder="Ex: sogedis.fr"></textarea>
        <button type="submit">Ajouter</button>
    </form>
    <div id="asset-add-msg" style="color:#2957a4;font-size:1em;margin-top:8px;"></div>
</div>
<button style="position:absolute;top:36px;right:40px;z-index:99;background:#4ed6e2;color:#2957a4;font-weight:600;padding:8px 22px;border:none;border-radius:8px;font-size:1.04em;cursor:pointer;box-shadow:0 2px 8px #b1b6c94d;" onclick="document.getElementById('assetAddBox').style.display='block';">+ Ajouter asset(s)</button>
<script>
document.getElementById('asset-add-form').onsubmit = function(ev) {
    ev.preventDefault();
    let type = document.getElementById('asset_type').value;
    let values = document.getElementById('asset_values').value.trim();
    let clientId = <?php echo (int)($_GET['id'] ?? 0); ?>;
    if (!clientId) { alert('Client inconnu'); return; }
    let msg = document.getElementById('asset-add-msg');
    msg.textContent = 'Ajout en cours...';
    fetch('client.php?action=add_asset', { // <= URL ABSOLUE
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({client_id: clientId, asset_type: type, asset_values: values})
    })
    .then(r=>{
        if(!r.ok) throw new Error("HTTP "+r.status);
        return r.json();
    })
    .then(resp=>{
        if(resp && resp.success) {
            msg.style.color = "#228f66";
            msg.textContent = "Asset(s) ajouté(s) !";
            document.getElementById('asset_values').value = '';
            setTimeout(()=>{ document.getElementById('assetAddBox').style.display='none'; msg.textContent=''; }, 900);
        } else {
            msg.style.color = "#c22";
            msg.textContent = "Erreur : " + (resp && resp.error ? resp.error : "inconnue");
        }
    }).catch((err)=>{
        msg.style.color="#c22";
        msg.textContent="Erreur réseau: "+err;
    });
}
</script>
<?php
// À placer en haut de ton client.php (avant tout output HTML)
if (isset($_GET['action']) && $_GET['action'] === 'add_asset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $client_id = intval($input['client_id'] ?? 0);
    $type = $input['asset_type'] ?? '';
    $values = $input['asset_values'] ?? '';
    if (!$client_id || !$type || !$values) { echo json_encode(['success'=>false,'error'=>'Données manquantes']); exit; }
    $db = getDb();
    $db->beginTransaction();
    try {
        foreach (explode("\n", $values) as $val) {
            $val = trim($val);
            if ($val !== "") {
                $stmt = $db->prepare("INSERT INTO client_assets (client_id, asset_type, asset_value) VALUES (?, ?, ?)");
                $stmt->execute([$client_id, $type, $val]);
            }
        }
        $db->commit();
        echo json_encode(['success'=>true]); exit;
    } catch(Exception $e) {
        $db->rollBack();
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
    }
}
?>
<?php include 'sidebar.php'; ?>
<div class="main">
    <div class="current-datetime">
        Date/heure actuelle : <b><?=$date_now?></b>
    </div>
    <h1 style="color:#5b6dfe;margin-bottom:16px;font-size:2em;">Calendrier des scans</h1>
<?php
$mois_fr = [1=>"Janvier",2=>"Février",3=>"Mars",4=>"Avril",5=>"Mai",6=>"Juin",7=>"Juillet",8=>"Août",9=>"Septembre",10=>"Octobre",11=>"Novembre",12=>"Décembre"];
$prevYear = $year - 1;
$nextYear = $year + 1;
$prevMonth = $month - 1;
$nextMonth = $month + 1;
$prevMonthYear = $year;
$nextMonthYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevMonthYear--; }
if ($nextMonth > 12) { $nextMonth = 1; $nextMonthYear++; }
?>
<div style="display:flex;align-items:center;gap:60px;margin-bottom:14px;">
  <div style="display:flex;flex-direction:column;">
    <div style="display:flex;align-items:center;justify-content:center;gap:14px;margin-bottom:0;">
      <!-- Flèche année - -->
      <form method="get" style="margin:0;display:inline;">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="year" value="<?=$prevYear?>">
        <input type="hidden" name="month" value="<?=$month?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;width:30px;padding:0 4px;">
          <span style="font-size:1.6em;color:#727fff;">&#60;</span>
        </button>
      </form>
      <span style="font-size:1.35em;font-weight:600;min-width:72px;text-align:center;"><?=$year?></span>
      <!-- Flèche année + -->
      <form method="get" style="margin:0;display:inline;">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="year" value="<?=$nextYear?>">
        <input type="hidden" name="month" value="<?=$month?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;width:30px;padding:0 4px;">
          <span style="font-size:1.6em;color:#727fff;">&#62;</span>
        </button>
      </form>
    </div>
    <div style="display:flex;align-items:center;justify-content:center;gap:14px;">
      <!-- Flèche mois - -->
      <form method="get" style="margin:0;display:inline;">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="year" value="<?=$prevMonthYear?>">
        <input type="hidden" name="month" value="<?=$prevMonth?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;width:30px;padding:0 4px;">
          <span style="font-size:1.6em;color:#727fff;">&#60;</span>
        </button>
      </form>
      <span style="font-size:1.2em;font-weight:500;min-width:110px;text-align:center;"><?=$mois_fr[$month]?></span>
      <!-- Flèche mois + -->
      <form method="get" style="margin:0;display:inline;">
        <input type="hidden" name="id" value="<?=$id?>">
        <input type="hidden" name="year" value="<?=$nextMonthYear?>">
        <input type="hidden" name="month" value="<?=$nextMonth?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;width:30px;padding:0 4px;">
          <span style="font-size:1.6em;color:#727fff;">&#62;</span>
        </button>
      </form>
    </div>
  </div>
  <div style="flex:1;">
    <!-- Tu peux ajouter ici tes sélecteurs/exports/liens, etc. -->
  </div>
</div>

<style>
.custom-calendar { background:#fff;border-radius:16px;padding:14px 13px;box-shadow:0 2px 14px #a5b6e630;display:inline-block;}
.custom-calendar th { color:#7d8eb3;background:#f2f4fa;font-weight:600;padding:5px 8px;border-radius:8px 8px 0 0;}
.custom-calendar td { text-align:center;padding:0;margin:0; }
.custom-calendar a { display:inline-block;width:34px;height:34px;line-height:34px;border-radius:8px;text-decoration:none;color:#345;text-align:center;transition:0.13s; }
.custom-calendar .today { background:#e4f3ff;border:2px solid #43aef7;color:#057;font-weight:600; }
.custom-calendar .scan-done { background:#d7f8e6;color:#167a3a;font-weight:700;border:1.5px solid #80c7b6; }
.custom-calendar .scan-running { background:#fff4c3;color:#d48a09;border:1.5px solid #ffd26a;}
.custom-calendar .scan-pending { background:#e5e9fa;color:#222;border:1.5px solid #b7c9eb;}
</style>
<table class="custom-calendar">
  <tr>
    <?php foreach(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
      <th><?=$d?></th>
    <?php endforeach ?>
  </tr>
  <tr>
  <?php
  $today = date('Y-m-d');
  $cell = 1;
  for($i=1;$i<$startDay;$i++): ?><td></td><?php $cell++; endfor;
  for($i=1;$i<=$daysInMonth;$i++,$cell++):
      $date = sprintf('%04d-%02d-%02d', $year, $month, $i);
      $classes = [];
      if ($date==$today)     $classes[] = "today";
      if (isset($scan_days[$date]) && $scan_days[$date]=='done')     $classes[] = "scan-done";
      if (isset($scan_days[$date]) && $scan_days[$date]=='running')  $classes[] = "scan-running";
      if (isset($scan_days[$date]) && $scan_days[$date]=='pending')  $classes[] = "scan-pending";
      $cl = count($classes) ? ' class="'.implode(' ',$classes).'"' : '';
      echo "<td$cl><a href='client.php?id=$id&year=$year&month=$month&day=$i'>$i</a></td>";
      if ($cell%7==0) echo "</tr><tr>";
  endfor;
  for(;$cell%7!=1;$cell++): ?><td></td><?php endfor;
  ?>
  </tr>
</table>

<?php
// --- Aperçu CTI pour le client (à insérer dans client.php, avant asset-settings-frame) ---
// Utilise $db et $id déjà définis dans client.php
try {
    // Récupère les patterns blacklistés pour ce client
    $blacklistStmt = $db->prepare("SELECT value FROM cti_blacklist WHERE type='victim' AND client_id = ?");
    $blacklistStmt->execute([$id]);
    $blacklisted_patterns = $blacklistStmt->fetchAll(PDO::FETCH_COLUMN);

    // Compte les résultats non blacklistés
    if (empty($blacklisted_patterns)) {
        $ctiCountStmt = $db->prepare("SELECT COUNT(*) FROM cti_results WHERE client_id = ?");
        $ctiCountStmt->execute([$id]);
    } else {
        $placeholders = implode(',', array_fill(0, count($blacklisted_patterns), '?'));
        $ctiCountStmt = $db->prepare("SELECT COUNT(*) FROM cti_results WHERE client_id = ? AND pattern NOT IN ($placeholders)");
        $params = array_merge([$id], $blacklisted_patterns);
        $ctiCountStmt->execute($params);
    }
    $cti_count = intval($ctiCountStmt->fetchColumn());

    // Récupère les derniers résultats non blacklistés
    if (empty($blacklisted_patterns)) {
        $ctiLastStmt = $db->prepare("SELECT r.*, c.name AS client_name FROM cti_results r JOIN clients c ON r.client_id=c.id WHERE r.client_id = ? ORDER BY r.added DESC LIMIT 5");
        $ctiLastStmt->execute([$id]);
    } else {
        $placeholders = implode(',', array_fill(0, count($blacklisted_patterns), '?'));
        $ctiLastStmt = $db->prepare("SELECT r.*, c.name AS client_name FROM cti_results r JOIN clients c ON r.client_id=c.id WHERE r.client_id = ? AND r.pattern NOT IN ($placeholders) ORDER BY r.added DESC LIMIT 5");
        $params = array_merge([$id], $blacklisted_patterns);
        $ctiLastStmt->execute($params);
    }
    $cti_rows = $ctiLastStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cti_count = 0;
    $cti_rows = [];
}

// Helper pour formater une date en "DD/MM/YYYY HH:MM"
function formatDateTime($s) {
    if (!$s) return '-';
    $ts = strtotime($s);
    if ($ts === false) {
        return htmlspecialchars($s);
    }
    return date('d/m/Y H:i', $ts);
}
?>
<style>
.cti-panel { border:1px solid #cfe0f5; background:#f8fbff; border-radius:8px; margin:1em 0; overflow:hidden; max-width:80vw; }
.cti-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; cursor:pointer; background:#eaf4ff; }
.cti-title { font-weight:700; color:#0b3a66; }
.cti-status { font-weight:700; padding:6px 10px; border-radius:6px; }
.cti-status.ok { background:#e6f8ee; color:#167a3a; }
.cti-status.alert { background:#fff0f0; color:#a81e1e; }
.cti-body { padding:12px 14px; display:none; }
.cti-table { width:100%; border-collapse:collapse; font-size:0.95em; }
.cti-table th, .cti-table td { border-bottom:1px solid #e0eaf6; padding:6px 8px; text-align:left; vertical-align:top; }
.cti-toggle-btn { font-size:16px; display:inline-block; transform:rotate(0deg); transition:transform .18s ease; margin-right:8px; }
.cti-meta { color:#556; font-size:0.92em; }
.cti-screenshot-thumb { max-width:120px; max-height:66px; border-radius:4px; cursor:pointer; border:1px solid #ddd; }
.cti-open-icon { font-size:18px; display:inline-block; min-width:22px; text-align:center; }
</style>

<div class="cti-panel" id="cti-panel">
    <div class="cti-header" onclick="toggleCtiPanel()" role="button" aria-pressed="false">
        <div style="display:flex;align-items:center;">
            <span id="cti-toggle" class="cti-toggle-btn">▶</span>
            <div>
                <div class="cti-title">Victime d'une cyberattaque ?</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($cti_count > 0): ?>
                <div class="cti-status alert">Victime — <?= $cti_count ?> résultat<?= $cti_count>1?'s':'' ?></div>
            <?php else: ?>
                <div class="cti-status ok">Aucune trace récente</div>
            <?php endif; ?>
            <!-- Remplacé le lien "Ouvrir CTI" par une icône flèche -->
            <span id="cti-open-icon" class="cti-open-icon" aria-label="Ouvrir CTI">▼</span>
        </div>
    </div>

    <div class="cti-body" id="cti-body">
        <?php if (count($cti_rows) === 0): ?>
            <div style="color:#556;">Aucun résultat CTI pour ce client.</div>
        <?php else: ?>
            <div style="margin-bottom:8px;color:#333;font-weight:600;">Derniers résultats (5 plus récents)</div>
            <div style="overflow:auto;">
            <table class="cti-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Pattern</th>
                        <th>Titre</th>
                        <th>Group</th>
                        <th>Date de publication</th>
                        <th>Date de l'attaque</th>
                        <th>Screenshot</th>
                        <th>Permalink</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cti_rows as $r):
                    $data = json_decode($r['data'], true) ?: [];
                    $title = $data['post_title'] ?? $data['victim'] ?? $data['title'] ?? '';
                    $group = $data['group_name'] ?? ($data['ransomware'] ?? '');
                    $discovered = $data['discovered'] ?? ($data['date'] ?? '');
                    $published = $data['published'] ?? ($data['added'] ?? $r['added']);
                    $screenshot = $data['screenshot'] ?? '';
                    $permalink = $data['permalink'] ?? ($data['url'] ?? '');
                    $clientName = htmlspecialchars($r['client_name'] ?? $client['name']);
                    $pattern = htmlspecialchars($r['pattern'] ?? '');
                    $title_s = htmlspecialchars(is_array($title) ? json_encode($title) : $title);
                    $group_s = htmlspecialchars(is_array($group) ? json_encode($group) : $group);
                    $discovered_s = formatDateTime($discovered);
                    $published_s = formatDateTime($published);
                    $screenshot_esc = htmlspecialchars($screenshot);
                    $permalink_esc = htmlspecialchars($permalink);
                ?>
                    <tr>
                        <td><?= $clientName ?></td>
                        <td><code><?= $pattern ?></code></td>
                        <td><?= $title_s ?></td>
                        <td><?= $group_s ?></td>
                        <td><?= $discovered_s ?></td>
                        <td><?= $published_s ?></td>
                        <td>
                            <?php if ($screenshot): ?>
                                <img src="<?= $screenshot_esc ?>" alt="screenshot" class="cti-screenshot-thumb" onclick="showCtiImg('<?= $screenshot_esc ?>')">
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($permalink): ?>
                                <a href="<?= $permalink_esc ?>" target="_blank" rel="noopener noreferrer">Lien</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div style="margin-top:8px;color:#666;font-size:0.92em;">Pour gérer / supprimer / blacklister, ouvre la page CTI complète.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal screenshot CTI -->
<div id="ctiImgModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.85);align-items:center;justify-content:center;z-index:9999;">
    <img id="ctiImgModalImg" src="" style="max-width:90vw;max-height:90vh;border:4px solid #fff;border-radius:6px;box-shadow:0 8px 40px #0006;">
</div>

<script>
(function(){
    var clientId = <?= json_encode(intval($id)) ?>;
    var storageKey = 'cti_panel_open_' + clientId;
    var body = document.getElementById('cti-body');
    var toggle = document.getElementById('cti-toggle');
    var openIcon = document.getElementById('cti-open-icon');
    var header = document.querySelector('.cti-header');

    function setOpen(open) {
        if (!body || !toggle || !openIcon || !header) return;
        if (open) {
            body.style.display = 'block';
            toggle.style.transform = 'rotate(90deg)';
            openIcon.textContent = '▲';
            header.setAttribute('aria-pressed', 'true');
            localStorage.setItem(storageKey, '1');
        } else {
            body.style.display = 'none';
            toggle.style.transform = 'rotate(0deg)';
            openIcon.textContent = '▼';
            header.setAttribute('aria-pressed', 'false');
            localStorage.setItem(storageKey, '0');
        }
    }
    window.toggleCtiPanel = function() {
        if (!body) return;
        var isHidden = (body.style.display === 'none' || body.style.display === '');
        setOpen(isHidden);
    };
    try {
        var stored = localStorage.getItem(storageKey);
        setOpen(stored === '1');
    } catch(e) {
        setOpen(false);
    }

    // Image modal handlers
    window.showCtiImg = function(url) {
        var modal = document.getElementById('ctiImgModal');
        var img = document.getElementById('ctiImgModalImg');
        img.src = url;
        modal.style.display = 'flex';
    };
    document.getElementById('ctiImgModal').onclick = function() {
        this.style.display = 'none';
        document.getElementById('ctiImgModalImg').src = '';
    };

    // Rendre l'icône cliquable indépendamment (optionnel)
    if (openIcon) {
        openIcon.style.cursor = 'pointer';
        openIcon.onclick = function(ev) { ev.stopPropagation(); toggleCtiPanel(); };
    }
})();
</script>

<!-- Remplacer l'ancien bloc asset-settings-frame par celui-ci -->
<div class="asset-settings-frame" id="asset-settings-frame" style="max-width: 80vw; width: 100vw; padding-left: 0; padding-right: 0;">
    <div class="asf-header" onclick="toggleAssetSettings()" role="button" aria-pressed="false"
         style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:10px 20px;border-bottom:1px solid #d0def5;background:#f4f8ff;">
        <div style="display:flex;align-items:center;">
            <span id="asset-toggle" style="font-size:18px;display:inline-block;margin-right:10px;">▶</span>
            <h2 style="margin:0;font-size:1.15em;color:#143a7a;">Paramètres assets & outils pour le prochain scan</h2>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="asset-open-icon" style="font-size:18px;display:inline-block;min-width:22px;text-align:center;" aria-label="ouvrir">▼</span>
        </div>
    </div>

    <div id="asset-settings-body" style="display:none;padding:14px 18px;">
        <form method="post" style="margin:0">
            <input type="hidden" name="save_asset_tools" value="1">
            <div class="table-container">
                <table class="param-table">
                    <tbody>
                        <tr>
                            <th style="min-width: 320px;">Asset</th>
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
</div>

<script>
(function(){
    var clientId = <?= json_encode(intval($id)) ?>;
    var storageKey = 'asset_settings_open_' + clientId;
    var body = document.getElementById('asset-settings-body');
    var toggle = document.getElementById('asset-toggle');
    var openIcon = document.getElementById('asset-open-icon');
    var header = document.querySelector('.asf-header');

    function setOpen(open) {
        if (!body || !toggle || !openIcon || !header) return;
        if (open) {
            body.style.display = 'block';
            toggle.style.transform = 'rotate(90deg)';
            openIcon.textContent = '▲';
            header.setAttribute('aria-pressed', 'true');
            localStorage.setItem(storageKey, '1');
        } else {
            body.style.display = 'none';
            toggle.style.transform = 'rotate(0deg)';
            openIcon.textContent = '▼';
            header.setAttribute('aria-pressed', 'false');
            localStorage.setItem(storageKey, '0');
        }
    }

    window.toggleAssetSettings = function() {
        if (!body) return;
        var isHidden = (body.style.display === 'none' || body.style.display === '');
        setOpen(isHidden);
    };

    // initialisation : fermé par défaut sauf si localStorage indique ouvert
    try {
        var stored = localStorage.getItem(storageKey);
        setOpen(stored === '1');
    } catch(e) {
        setOpen(false);
    }

    // rendre l'icône cliquable indépendamment (évite de devoir viser l'en-tête)
    if (openIcon) {
        openIcon.style.cursor = 'pointer';
        openIcon.onclick = function(ev) { ev.stopPropagation(); toggleAssetSettings(); };
    }
})();
</script>


    <h2>Planification des scans</h2>
    <form method="post">
        <label>Fréquence des scans :
            <?php
$freq_val = $schedule && isset($schedule['frequency']) ? $schedule['frequency'] : 'weekly';
?>
<select name="frequency">
    <option value="weekly"<?=($freq_val=='weekly'?' selected':'')?>>Hebdomadaire</option>
    <option value="monthly"<?=($freq_val=='monthly'?' selected':'')?>>Mensuel</option>
    <option value="quarterly"<?=($freq_val=='quarterly'?' selected':'')?>>Trimestriel</option>
    <option value="semiannual"<?=($freq_val=='semiannual'?' selected':'')?>>Semestriel</option>
    <option value="annual"<?=($freq_val=='annual'?' selected':'')?>>Annuel</option>
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

    <!-- Formulaire caché pour scan différé -->
    <form method="post" id="form-scan-in-3min" style="display:none;">
        <input type="hidden" name="action" value="scan_now">
        <input type="hidden" name="client_id" value="<?=$id?>">
    </form>

    <!-- Bouton pour lancer un scan dans 3 minutes -->
    <button type="button" id="btn-scan-in-3min" style="margin-bottom:1em;">Lancer le scan dans 3 minutes</button>

    <script>
    (function() {
        var btn = document.getElementById('btn-scan-in-3min');
        var form = document.getElementById('form-scan-in-3min');
        var timerId = null;
        var remainingSeconds = 0;
        var originalText = 'Lancer le scan dans 3 minutes';
        var DELAY_SECONDS = 180; // 3 minutes

        // Fonction utilitaire pour padStart (compatibilité IE)
        function padStart(str, targetLength, padString) {
            str = String(str);
            targetLength = targetLength >> 0;
            padString = String(typeof padString !== 'undefined' ? padString : ' ');
            if (str.length >= targetLength) {
                return str;
            } else {
                targetLength = targetLength - str.length;
                if (targetLength > padString.length) {
                    // Répéter padString sans utiliser repeat() (compatibilité IE)
                    var repeatedPad = '';
                    while (repeatedPad.length < targetLength) {
                        repeatedPad += padString;
                    }
                    padString = repeatedPad;
                }
                return padString.slice(0, targetLength) + str;
            }
        }

        function formatTime(seconds) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return 'Scan dans ' + 
                   padStart(mins, 2, '0') + ':' + 
                   padStart(secs, 2, '0');
        }

        function updateButton() {
            btn.textContent = formatTime(remainingSeconds);
            remainingSeconds--;
            if (remainingSeconds < 0) {
                // Temps écoulé, soumettre le formulaire
                stopTimer();
                form.submit();
            }
        }

        function startTimer() {
            remainingSeconds = DELAY_SECONDS;
            updateButton(); // Afficher immédiatement 03:00
            timerId = setInterval(updateButton, 1000);
        }

        function stopTimer() {
            if (timerId) {
                clearInterval(timerId);
                timerId = null;
            }
            remainingSeconds = 0;
            btn.textContent = originalText;
        }

        btn.addEventListener('click', function() {
            if (timerId) {
                // Le timer est actif, annuler
                stopTimer();
            } else {
                // Démarrer le timer
                startTimer();
            }
        });
    })();
    </script>

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

$dork_stmt = $db->prepare("SELECT * FROM dork_results WHERE scan_id=? ORDER BY id ASC");
$dork_stmt->execute([$scan_id]);
$dork_rows = $dork_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($dork_rows && count($dork_rows)) {
    echo "<h3>Résultats Google Dork</h3>
    <table class='data'><tr>
        <th>#</th>
        <th>Domain</th>
        <th>Type</th>
        <th>Titre</th>
        <th>URL</th>
        <th>Date</th>
    </tr>";
    $i = 1;
    foreach ($dork_rows as $row) {
        echo "<tr>
            <td>{$i}</td>
            <td>".htmlspecialchars($row['domain'])."</td>
            <td>".htmlspecialchars($row['filetype'])."</td>
            <td>".htmlspecialchars($row['title'])."</td>
            <td><a href=\"".htmlspecialchars($row['link'])."\" target=\"_blank\">".htmlspecialchars($row['link'])."</a></td>
            <td>".htmlspecialchars($row['found_at'])."</td>
        </tr>";
        $i++;
    }
    echo "</table>";
} else {
    echo "<div style='color:#888;'>Aucun résultat Dork pour ce scan.</div>";
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
<?php
// ... (autres parties de ton fichier)

// Supposons que tu as déjà $client (tableau contenant les infos du client)
// Et que tu as la date sélectionnée via un formulaire ou une variable, par exemple $selected_date

// Si tu utilises un formulaire/calendrier pour la date, récupère la valeur POST ou GET, sinon prends le dernier scan
if (!isset($selected_date) || !$selected_date) {
    $selected_date = '';
    // Récupère le dernier scan si aucune date sélectionnée
    if (isset($client['id']) && $client['id']) {
        $db = new PDO('pgsql:host=localhost;dbname=osintapp', 'thomas', 'thomas');
        $row = $db->query("SELECT scan_date FROM scans WHERE client_id = {$client['id']} ORDER BY scan_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) $selected_date = substr($row['scan_date'], 0, 10);
    }
}
?>
<?php
// Récupération de l'id client (existant)
$client_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Construction de la date à partir de l'URL
if (isset($_GET['year']) && isset($_GET['month']) && isset($_GET['day'])) {
    $year = intval($_GET['year']);
    $month = str_pad(intval($_GET['month']), 2, '0', STR_PAD_LEFT);
    $day = str_pad(intval($_GET['day']), 2, '0', STR_PAD_LEFT);
    $selected_scan_date = "$year-$month-$day";
} else {
    // Par défaut, dernière date de scan du client
    $selected_scan_date = '';
    if ($client_id) {
        $db = new PDO('pgsql:host=localhost;dbname=osintapp', 'thomas', 'thomas');
        $row = $db->query("SELECT scan_date FROM scans WHERE client_id = $client_id ORDER BY scan_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) $selected_scan_date = substr($row['scan_date'], 0, 10);
    }
}
?>
<form method="GET" action="export_report.php" style="position: fixed; bottom: 30px; right: 30px; z-index: 999;">
  <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
  <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_scan_date); ?>">
  <button type="submit" class="btn btn-primary">📄 Rapport</button>
</form>


<!-- ... reste du HTML ... -->
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
