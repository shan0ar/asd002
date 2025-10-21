<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__."/includes/db.php";
require_once __DIR__ . '/vendor/autoload.php';
require_once 'includes/session_check.php';

$db = getDb();

function clean($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'pdf';

if (!$client_id || !$report_date || !in_array($format, ['csv', 'pdf', 'docx'])) {
    die("Paramètres invalides.");
}

// Récup client
$client = $db->prepare("SELECT * FROM clients WHERE id=?");
$client->execute([$client_id]);
$client = $client->fetch(PDO::FETCH_ASSOC);
if (!$client) die("Client inconnu.");

// 1. Récupérer le scan id du bon jour
$scan = $db->prepare("SELECT * FROM scans WHERE client_id=? AND scan_date::date=?::date ORDER BY scan_date DESC LIMIT 1");
$scan->execute([$client_id, $report_date]);
$scan = $scan->fetch(PDO::FETCH_ASSOC);
if ($scan) {
    $scan_id = $scan['id'];
    $scan_status = $scan['status'];
    $scan_date = $scan['scan_date'];
} else {
    $scan_id = null;
    $scan_status = null;
    $scan_date = null;
}

if ($format === 'pdf') {
    $mpdf = new \Mpdf\Mpdf([
        'tempDir' => '/tmp',
        'margin_left' => 18,
        'margin_right' => 18,
        'margin_top' => 22,
        'margin_bottom' => 22,
        'format' => 'A4'
    ]);
    $html = '<style>
        h1 { color: #1976d2; font-size: 26px; margin-bottom: 0; }
        h2 { color: #333; font-size: 19px; margin-top: 30px; }
        h3 { color: #444; font-size: 17px; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        th, td { border: 1px solid #999; padding: 7px 10px; font-size: 12px; }
        th { background: #e3e7f7; font-weight: bold; }
        tr:nth-child(even) td { background: #f7f8fa; }
        pre { background: #eef; border: 1px solid #ccc; padding: 7px; font-size: 13px; }
        .section { margin-bottom: 28px; }
    </style>';

    $html .= "<h1>Rapport technique du {$report_date}</h1>";
    $html .= "<div style='font-size:15px;margin-bottom:18px;'>Client : <b>".clean($client['name'])."</b></div>";

    if ($scan_id) {
        // Nmap
        $nmap_stmt = $db->prepare("SELECT asset, port, state, service, version FROM nmap_results WHERE scan_id=? ORDER BY asset, port ASC");
        $nmap_stmt->execute([$scan_id]);
        $nmap_results = $nmap_stmt->fetchAll(PDO::FETCH_ASSOC);
        $html .= "<h2>Résultats Nmap</h2><table><tr>
            <th>Asset</th><th>Port</th><th>État</th><th>Service</th><th>Version</th></tr>";
        if ($nmap_results && count($nmap_results)) {
            foreach ($nmap_results as $row) {
                $html .= "<tr>
                    <td>".clean($row['asset'])."</td>
                    <td>".clean($row['port'])."</td>
                    <td>".clean($row['state'])."</td>
                    <td>".clean($row['service'])."</td>
                    <td>".clean($row['version'])."</td>
                </tr>";
            }
        } else {
            $html .= "<tr><td colspan='5' style='text-align:center;color:#999;'>Aucun résultat Nmap pour ce scan.</td></tr>";
        }
        $html .= "</table>";

        // Assets découverts
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
            $html .= "<h2>Domaines/sous-domaines détectés (tous outils confondus, sans doublon):</h2><pre>";
            foreach ($asset_sources as $dom => $srcs) {
                $html .= clean($dom) . " [" . clean(implode(' & ', $srcs)) . "]\n";
            }
            $html .= "</pre>";
        }

        // WHOIS
        $whois_stmt = $db->prepare("SELECT * FROM whois_data WHERE scan_id=?");
        $whois_stmt->execute([$scan_id]);
        $whois_rows = $whois_stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($whois_rows && count($whois_rows)) {
            $html .= "<h2>Résultat Whois</h2>";
            foreach ($whois_rows as $row) {
                $html .= "<div style='margin-bottom:1.1em;border:1px solid #bbb;background:#f8fafc;padding:8px 14px;border-radius:6px'>";
                $html .= "<b>Domaine :</b> " . clean($row['domain'] ?? '') . "<br>";
                $html .= "<b>Registrar :</b> " . clean($row['registrar'] ?? '') . "<br>";
                $html .= "<b>Date création :</b> " . clean($row['creation_date'] ?? '') . "<br>";
                $html .= "<b>Date expiration :</b> " . clean($row['expiry_date'] ?? '') . "<br>";
                // serveurs DNS
                $all_ns = [];
                if (!empty($row['name_servers'])) {
                    $ns_list = array_filter(explode('|', $row['name_servers']));
                    $all_ns = $ns_list;
                } else {
                    if (!empty($row['name_server_1'])) $all_ns[] = $row['name_server_1'];
                    if (!empty($row['name_server_2'])) $all_ns[] = $row['name_server_2'];
                }
                $html .= "<b>Serveurs DNS :</b> ";
                if (count($all_ns)) {
                    $html .= implode(', ', array_map('clean', $all_ns));
                } else {
                    $html .= "N/A";
                }
                $html .= "<br>";
                if (!empty($row['registrant'])) {
                    $html .= "<b>Propriétaire :</b> " . clean($row['registrant']) . "<br>";
                }
                $html .= "<details><summary>WHOIS complet</summary><pre style='max-width:800px;overflow-x:auto;font-size:0.97em'>" . clean($row['raw_output'] ?? '') . "</pre></details>";
                $html .= "</div>";
            }
        }

        // Whatweb
        $whatweb = $db->prepare("SELECT * FROM whatweb WHERE scan_id=?");
        $whatweb->execute([$scan_id]);
        $whatweb_rows = $whatweb->fetchAll(PDO::FETCH_ASSOC);
        if ($whatweb_rows && count($whatweb_rows)) {
            $html .= "<h2>WhatWeb</h2>";
            $html .= "<table><tr>
                    <th>IP/Domaine</th>
                    <th>Technologie</th>
                    <th>Valeur</th>
                    <th>Version</th>
                  </tr>";
            foreach ($whatweb_rows as $row) {
                $html .= "<tr>
                        <td>".clean($row['domain_ip'])."</td>
                        <td>".clean($row['technologie'])."</td>
                        <td>".clean($row['valeur'])."</td>
                        <td>".clean($row['version'])."</td>
                      </tr>";
            }
            $html .= "</table>";
        }

        // DIG A
        $dig_a = $db->prepare("SELECT * FROM dig_a WHERE scan_id=?");
        $dig_a->execute([$scan_id]);
        $dig_a = $dig_a->fetch(PDO::FETCH_ASSOC);
        if ($dig_a) {
            $html .= "<h2>Résultat DIG A</h2>
            <table><tr>
            <th>Domaine</th><th>IP</th><th>TTL</th><th>Raw</th>
            </tr><tr>
            <td>".clean($dig_a['domain'])."</td>
            <td>".clean($dig_a['ip'])."</td>
            <td>".clean($dig_a['ttl'])."</td>
            <td><pre>".clean($dig_a['raw_output'])."</pre></td>
            </tr></table>";
        }

        // Amass
        $amass = $db->prepare("SELECT * FROM amass_results WHERE scan_id=? ORDER BY id ASC");
        $amass->execute([$scan_id]);
        $amass_rows = $amass->fetchAll(PDO::FETCH_ASSOC);
        if ($amass_rows && count($amass_rows)) {
            $html .= "<h2>Résultats Amass</h2>
            <table><tr>
                <th>#</th>
                <th>Sous-domaine</th>
                <th>Type</th>
                <th>Valeur</th>
                <th>Ligne brute</th>
            </tr>";
            $i = 1;
            foreach ($amass_rows as $row) {
                $html .= "<tr>
                    <td>{$i}</td>
                    <td>".clean($row['subdomain'])."</td>
                    <td>".clean($row['record_type'])."</td>
                    <td>".clean($row['value'])."</td>
                    <td><pre>".clean($row['raw_output'])."</pre></td>
                </tr>";
                $i++;
            }
            $html .= "</table>";
        }

        // DIG NS
        $dig_ns = $db->prepare("SELECT * FROM dig_ns WHERE scan_id=?");
        $dig_ns->execute([$scan_id]);
        $dig_ns_rows = $dig_ns->fetchAll(PDO::FETCH_ASSOC);
        if ($dig_ns_rows && count($dig_ns_rows)) {
            $html .= "<h2>Résultat DIG NS</h2>
            <table><tr>
            <th>Domaine</th><th>NS</th><th>TTL</th><th>Raw</th>
            </tr>";
            foreach ($dig_ns_rows as $row) {
                $html .= "<tr>
                <td>".clean($row['domain'])."</td>
                <td>".clean($row['ns'])."</td>
                <td>".clean($row['ttl'])."</td>
                <td><pre>".clean($row['raw_output'])."</pre></td>
                </tr>";
            }
            $html .= "</table>";
        }

        // DIG MX
        $dig_mx = $db->prepare("SELECT * FROM dig_mx WHERE scan_id=?");
        $dig_mx->execute([$scan_id]);
        $dig_mx_rows = $dig_mx->fetchAll(PDO::FETCH_ASSOC);
        if ($dig_mx_rows && count($dig_mx_rows)) {
            $html .= "<h2>Résultat DIG MX</h2>
            <table><tr>
            <th>Domaine</th><th>Préférence</th><th>Exchange</th><th>TTL</th><th>Raw</th>
            </tr>";
            foreach ($dig_mx_rows as $row) {
                $html .= "<tr>
                <td>".clean($row['domain'])."</td>
                <td>".clean($row['preference'])."</td>
                <td>".clean($row['exchange'])."</td>
                <td>".clean($row['ttl'])."</td>
                <td><pre>".clean($row['raw_output'])."</pre></td>
                </tr>";
            }
            $html .= "</table>";
        }

        // DIG TXT
        $dig_txt = $db->prepare("SELECT * FROM dig_txt WHERE scan_id=?");
        $dig_txt->execute([$scan_id]);
        $dig_txt_rows = $dig_txt->fetchAll(PDO::FETCH_ASSOC);
        if ($dig_txt_rows && count($dig_txt_rows)) {
            $html .= "<h2>Résultat DIG TXT</h2>
            <table><tr>
            <th>Domaine</th><th>TXT</th><th>TTL</th><th>Raw</th>
            </tr>";
            foreach ($dig_txt_rows as $row) {
                $html .= "<tr>
                <td>".clean($row['domain'])."</td>
                <td>".clean($row['txt'])."</td>
                <td>".clean($row['ttl'])."</td>
                <td><pre>".clean($row['raw_output'])."</pre></td>
                </tr>";
            }
            $html .= "</table>";
        }

        // DIG Bruteforce
        $stmt = $db->prepare("SELECT bruteforce_attempts FROM scans WHERE id=?");
        $stmt->execute([$scan_id]);
        $nb_tentatives = $stmt->fetchColumn();
        $nb_tentatives_txt = ($nb_tentatives !== null && $nb_tentatives !== false) ? intval($nb_tentatives) : "?";
        $dig_brute = $db->prepare("SELECT * FROM dig_bruteforce WHERE scan_id=? ORDER BY id ASC");
        $dig_brute->execute([$scan_id]);
        $dig_brute_rows = $dig_brute->fetchAll(PDO::FETCH_ASSOC);
        if ($dig_brute_rows && count($dig_brute_rows)) {
            $html .= "<h2>Résultats DIG Bruteforce ({$nb_tentatives_txt} tentatives)</h2>
            <table><tr>
            <th>Subdomain</th><th>IP</th><th>Raw (premières lignes)</th>
            </tr>";
            foreach ($dig_brute_rows as $row) {
                $short_raw = implode("\n", array_slice(explode("\n", $row['raw_output']), 0, 20));
                $html .= "<tr>
                <td>".clean($row['subdomain'])."</td>
                <td>".clean($row['ip'])."</td>
                <td><pre>".clean($short_raw)."</pre></td>
                </tr>";
            }
            $html .= "</table>";
        }
    } else {
        $html .= "<div style='color:#999;font-size:17px;'>Aucun scan trouvé pour cette date.</div>";
    }

    $mpdf->WriteHTML($html);
    $mpdf->Output("report_{$client_id}_{$report_date}.pdf", "D");
    exit;
}

// Pour CSV/DOCX, copier la même logique (demandes si tu veux ces formats !)

die("Format non supporté.");
