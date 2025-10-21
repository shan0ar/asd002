<?php
require_once 'includes/session_check.php';

// Connexion à la base
$dbhost = 'localhost';
$dbuser = 'thomas';
$dbpass = ''; // à adapter
$dbname = 'osintapp';
try {
    $db = new PDO("pgsql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("<b>Erreur de connexion BDD :</b> " . htmlspecialchars($e->getMessage()));
}

// Fonctions périodes
function period_start($period) {
    $now = new DateTimeImmutable();
    switch ($period) {
        case 'week':      return $now->modify('-7 days')->format('Y-m-d 00:00:00');
        case 'month':     return $now->modify('-1 month')->format('Y-m-d 00:00:00');
        case 'quarter':   return $now->modify('-3 months')->format('Y-m-d 00:00:00');
        case 'semester':  return $now->modify('-6 months')->format('Y-m-d 00:00:00');
        case 'year':      return $now->modify('-1 year')->format('Y-m-d 00:00:00');
        default:          return '1970-01-01 00:00:00';
    }
}
$periods = [
    "week"     => "Semaine",
    "month"    => "Mois",
    "quarter"  => "Trimestre",
    "semester" => "Semestre",
    "year"     => "Année"
];
$period = $_GET['period'] ?? 'month';
if (!isset($periods[$period])) $period = 'month';
$period_start = period_start($period);

// Récupération clients
$clients = $db->query("SELECT id, name FROM clients ORDER BY LOWER(name)")->fetchAll(PDO::FETCH_ASSOC);
$client_ids = array_column($clients, 'id');

// Récupération données pour stats
$all_scans = $db->query("SELECT client_id, COUNT(*) as c FROM scans GROUP BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $db->prepare("SELECT client_id, COUNT(*) as c FROM scans WHERE scan_date >= ? GROUP BY client_id");
$stmt->execute([$period_start]);
$period_scans = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$all_assets = $db->query("SELECT client_id, COUNT(*) as c FROM assets_discovered GROUP BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $db->prepare("SELECT client_id, COUNT(*) as c FROM assets_discovered WHERE detected_at >= ? GROUP BY client_id");
$stmt->execute([$period_start]);
$period_assets = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$last_scan = $db->query("SELECT client_id, MAX(scan_date) as last FROM scans GROUP BY client_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $db->prepare("SELECT DISTINCT client_id FROM scans WHERE scan_date >= ?");
$stmt->execute([$period_start]);
$scanned_clients = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
$top_scans = $all_scans; arsort($top_scans);
$top_assets = $all_assets; arsort($top_assets);

// === Victimes ransomware/live filtrées (depuis cti.php) ===
// On ne compte que les résultats qui NE SONT PAS dans la blacklist
// et qui sont réellement en base
$ransom_clients = [];
$ransom_count = [];
$blacklist = [];
foreach ($db->query("SELECT value, client_id FROM cti_blacklist WHERE type='victim'") as $b) {
    $blacklist[$b['client_id']][] = $b['value'];
}

// On compte pour chaque client les résultats non filtrés
$res = $db->query("SELECT client_id, pattern, COUNT(*) as nb FROM cti_results GROUP BY client_id, pattern");
$client_attack_count = [];
foreach($res as $row) {
    $cid = $row['client_id'];
    $pattern = $row['pattern'];
    // Filtre blacklist
    if (isset($blacklist[$cid]) && in_array($pattern, $blacklist[$cid])) continue;
    if (!isset($client_attack_count[$cid])) $client_attack_count[$cid] = 0;
    $client_attack_count[$cid] += $row['nb'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>ASD - Dashboard</title>
    <link rel="stylesheet" href="static/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-container { margin-left: 260px; padding: 32px 16px 16px 16px; transition: margin-left 0.22s; }
        .sidebar-asd-collapsed ~ .dashboard-container { margin-left: 54px; }
        .period-btns { margin-bottom: 22px; }
        .period-btns button {
            margin-right: 7px;
            padding: 8px 22px;
            border-radius: 18px;
            border: none;
            background: #e9edfb;
            color: #4361ee;
            font-weight: 600;
            font-size: 1.08em;
            cursor: pointer;
            transition: background 0.13s;
        }
        .period-btns button.active,
        .period-btns button:hover { background: #4361ee; color: #fff; }
        .dashboard-row { display: flex; flex-wrap: wrap; gap: 32px; }
        .dashboard-col { background: #fff; border-radius: 16px; box-shadow: 0 0 8px #eee; padding: 18px 22px; flex:1 1 310px; min-width: 310px; margin-bottom:16px;}
        .dashboard-col h3 { margin-top:0; color: #4361ee; }
        .dashboard-table { width:100%; border-collapse: collapse; margin-top:18px; }
        .dashboard-table th, .dashboard-table td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; }
        .dashboard-table th { background: #f1f3fa; }
        .client-ok { background: #e9f8ea !important; color: #1e7347; }
        .client-nok { background: #fbeaea !important; color: #c12d2d; }
        .counter { font-size:2.3em; font-weight:700; color:#4361ee; display:inline-block; margin-right:10px; }
        .counter-plus { color:#23b26d; font-size:1em; font-weight:600; margin-left:10px; }
        .chart-container { position:relative; height:220px; width:100%; margin-bottom: 8px;}
        .debug-block {max-width:900px; margin:25px auto 10px auto; background:#ffe; border:1px solid #ccc; border-radius:7px; padding:16px;}
        pre.debug {overflow-x:auto; font-size:1em; background:none; border:none; margin:0;}
        .ransom-table {margin-top:36px;}
        .ransom-table th, .ransom-table td {padding:9px 10px;}
        .ransom-client-yes {background:#fff4e6;}
        .ransom-client-no {background:#ecf4ff;}
        .ransom-attack {color:#d33;font-weight:bold;}
        @media (max-width: 900px) {
            .dashboard-container { margin-left: 54px; padding:12px;}
            .dashboard-row { flex-direction:column; gap:16px;}
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="dashboard-container">
    <h1>Tableau de bord</h1>
    <div class="period-btns">
        <?php foreach($periods as $k => $label): ?>
            <form method="get" style="display:inline;">
                <input type="hidden" name="period" value="<?=$k?>">
                <button type="submit" class="<?=($period==$k)?'active':''?>"><?=$label?></button>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-row">
        <div class="dashboard-col">
            <h3>Top clients par scans</h3>
            <div class="chart-container">
                <canvas id="chartScans"></canvas>
            </div>
            <?php
            $top = array_slice($top_scans,0,5,true);
            foreach($top as $cid=>$c) {
                $plus = $period_scans[$cid]??0;
                echo "<div><span class='counter'>$c</span> ".htmlspecialchars($clients[array_search($cid,$client_ids)]['name']);
                if($plus>0) echo "<span class='counter-plus'>+".$plus."</span>";
                echo "</div>";
            }
            ?>
        </div>
        <div class="dashboard-col">
            <h3>Top clients par assets découverts</h3>
            <div class="chart-container">
                <canvas id="chartAssets"></canvas>
            </div>
            <?php
            $top = array_slice($top_assets,0,5,true);
            foreach($top as $cid=>$c) {
                $plus = $period_assets[$cid]??0;
                echo "<div><span class='counter'>$c</span> ".htmlspecialchars($clients[array_search($cid,$client_ids)]['name']);
                if($plus>0) echo "<span class='counter-plus'>+".$plus."</span>";
                echo "</div>";
            }
            ?>
        </div>
    </div>

    <div class="dashboard-row">
        <div class="dashboard-col" style="flex:2 1 600px">
            <h3>Statut des clients (scannés / non scannés)</h3>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Dernier scan</th>
                        <th>Assets découverts</th>
                        <th>Scans sur la période</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($clients as $cl):
                    $cid = $cl['id'];
                    $is_scanned = isset($scanned_clients[$cid]);
                    $row_class = $is_scanned ? 'client-ok' : 'client-nok';
                    ?>
                    <tr class="<?=$row_class?>">
                        <td><?=htmlspecialchars($cl['name'])?></td>
                        <td><?=isset($last_scan[$cid]) ? date('d/m/Y', strtotime($last_scan[$cid])) : "<span style='color:#c12d2d'>Jamais</span>"?></td>
                        <td>
                            <?=$all_assets[$cid]??0?>
                            <?php if(($period_assets[$cid]??0)>0): ?>
                                <span class="counter-plus">+<?=$period_assets[$cid]?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?=$period_scans[$cid]??0?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="font-size:0.98em; color:#888; margin-top:8px;">
                <span style="background:#e9f8ea;color:#1e7347;padding:2px 8px;border-radius:4px;">Scanné</span>
                <span style="background:#fbeaea;color:#c12d2d;padding:2px 8px;border-radius:4px;margin-left:15px;">Non scanné</span>
                <span style="margin-left:15px;">+N = assets découverts récemment</span>
            </div>
        </div>
    </div>

    <!-- Nouveau tableau : Clients victimes d'attaque ransomware/live -->
    <div class="dashboard-row">
        <div class="dashboard-col" style="flex:2 1 600px">
            <h3>Clients victimes d'attaque cyber</h3>
            <table class="dashboard-table ransom-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Victime ?</th>
                        <th>Nombre de cas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($clients as $cl):
                    $cid = $cl['id'];
                    $isVictim = isset($client_attack_count[$cid]) && $client_attack_count[$cid] > 0;
                    ?>
                    <tr class="<?= $isVictim ? 'ransom-client-yes' : 'ransom-client-no' ?>">
                        <td><?= htmlspecialchars($cl['name']) ?></td>
                        <td>
                            <?php if($isVictim): ?>
                                <span class="ransom-attack">Oui</span>
                            <?php else: ?>
                                Non
                            <?php endif; ?>
                        </td>
                        <td><?= $client_attack_count[$cid] ?? 0 ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="font-size:0.98em; color:#d33; margin-top:8px;">
                <span class="ransom-attack">Oui</span> = Client avec des cas d'attaque recensés (source ransomware.live via API)
            </div>
        </div>
    </div>
</div>
<script>
const clients = <?=json_encode(array_column($clients,'name','id'))?>;
const topScans = <?=json_encode(array_slice($top_scans,0,8,true))?>;
const periodScans = <?=json_encode($period_scans)?>;
const topAssets = <?=json_encode(array_slice($top_assets,0,8,true))?>;
const periodAssets = <?=json_encode($period_assets)?>;

// Bar chart clients by scans
new Chart(document.getElementById('chartScans').getContext('2d'), {
    type: 'bar',
    data: {
        labels: Object.keys(topScans).map(cid=>clients[cid]),
        datasets: [
            { label: 'Total scans', data: Object.values(topScans), backgroundColor: '#4361ee' },
            { label: '+ Sur la période', data: Object.keys(topScans).map(cid=>periodScans[cid]||0), backgroundColor: '#23b26d' }
        ]
    },
    options: {
        responsive:true,
        plugins: { legend:{display:true} },
        scales: { y: { beginAtZero:true, ticks:{stepSize:1} } }
    }
});

// Bar chart clients by assets
new Chart(document.getElementById('chartAssets').getContext('2d'), {
    type: 'bar',
    data: {
        labels: Object.keys(topAssets).map(cid=>clients[cid]),
        datasets: [
            { label: 'Total assets découverts', data: Object.values(topAssets), backgroundColor: '#4361ee' },
            { label: '+ Nouveaux assets', data: Object.keys(topAssets).map(cid=>periodAssets[cid]||0), backgroundColor: '#23b26d' }
        ]
    },
    options: {
        responsive:true,
        plugins: { legend:{display:true} },
        scales: { y: { beginAtZero:true, ticks:{stepSize:1} } }
    }
});
</script>
</body>
</html>
