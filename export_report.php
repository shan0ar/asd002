<?php
require_once __DIR__ . '/vendor/autoload.php';

$db = new PDO('pgsql:host=localhost;dbname=osintapp', 'thomas', 'thomas');
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if ($client_id <= 0 || !$date) {
    die('Paramètres manquants.');
}

// --- Remplace "XXXXXX" par ton base64 réel (sans préfixe data:) ---
$logo_base64 = 'XXXXXX';
$logo_data = 'data:image/png;base64,' . $logo_base64;

// Helper to format dates into DD/MM/YYYY (ignore time). Returns empty string if invalid.
function fmt_date_day($val) {
    if (!$val) return '';
    $ts = false;
    if (is_int($val)) $ts = $val;
    else {
        $val = trim((string)$val);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
            $ts = strtotime($val);
        } else {
            $ts = strtotime($val);
        }
    }
    if ($ts === false) return '';
    return date('d/m/Y', $ts);
}

// Récupération du nom du client
$client = $db->prepare("SELECT name FROM clients WHERE id=?");
$client->execute([$client_id]);
$clientName = $client->fetchColumn() ?: "Nom du client";

// CTI: Victime / Non victime
$cti_blacklistStmt = $db->prepare("SELECT value FROM cti_blacklist WHERE type='victim' AND client_id = ?");
$cti_blacklistStmt->execute([$client_id]);
$cti_blacklisted_patterns = $cti_blacklistStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($cti_blacklisted_patterns)) {
    $ctiCountStmt = $db->prepare("SELECT COUNT(*) FROM cti_results WHERE client_id = ?");
    $ctiCountStmt->execute([$client_id]);
} else {
    $placeholders = implode(',', array_fill(0, count($cti_blacklisted_patterns), '?'));
    $ctiCountStmt = $db->prepare("SELECT COUNT(*) FROM cti_results WHERE client_id = ? AND pattern NOT IN ($placeholders)");
    $params = array_merge([$client_id], $cti_blacklisted_patterns);
    $ctiCountStmt->execute($params);
}
$cti_count = intval($ctiCountStmt->fetchColumn());

// Small CTI badge (green for safe, red for danger)
if ($cti_count > 0) {
    $victime_html = '<div class="cti-badge cti-danger">
        <div class="cti-icon"><img src="https://img.icons8.com/color/48/000000/high-risk.png" alt="risk" /></div>
        <div class="cti-text"><strong>Incidents CTI détectés :</strong> <span class="cti-count">'.htmlspecialchars($cti_count).'</span></div>
    </div>';
} else {
    $victime_html = '<div class="cti-badge cti-safe">
        <div class="cti-icon"><img src="https://img.icons8.com/color/48/000000/safe.png" alt="safe" /></div>
        <div class="cti-text"><strong>Aucune cyberattaque détectée</strong><div class="cti-sub">selon nos sources CTI récentes pour ce client</div></div>
    </div>';
}

// --- Requêtes (WHOIS, MX, assets, nmap, whatweb, dork) ---
$whois_sql = "
    SELECT domain, registrar, registrant_name, registrant_org, registrant_country,
           creation_date, expiry_date, name_servers, name_server_1, name_server_2
    FROM whois_data
    WHERE scan_id IN (
        SELECT id FROM scans WHERE client_id = :client_id AND scan_date::date = :date
    )
    AND domain IS NOT NULL AND domain <> ''
    ORDER BY domain ASC
";
$whois = $db->prepare($whois_sql);
$whois->execute([':client_id'=>$client_id, ':date'=>$date]);

$dig_mx_sql = "
    SELECT domain, exchange AS serveur, ttl
    FROM dig_mx
    WHERE scan_id IN (
        SELECT id FROM scans WHERE client_id = :client_id AND scan_date::date = :date
    )
    AND domain IS NOT NULL AND domain <> ''
    ORDER BY domain
";
$dig_mx = $db->prepare($dig_mx_sql);
$dig_mx->execute([':client_id'=>$client_id, ':date'=>$date]);

$assets_sql = "
    SELECT asset, MIN(detected_at) AS premiere_detection, MAX(last_seen) AS derniere_detection
    FROM assets_discovered
    WHERE client_id = :client_id
      AND detected_at::date <= :date
    GROUP BY asset
    ORDER BY asset
";
$assets = $db->prepare($assets_sql);
$assets->execute([':client_id' => $client_id, ':date' => $date]);

$nmap_sql = "
    SELECT asset, port, service, version
    FROM nmap_results
    WHERE scan_id IN (
        SELECT id FROM scans WHERE client_id = :client_id AND scan_date::date = :date
    )
    AND asset IS NOT NULL AND asset <> ''
    ORDER BY asset, port
";
$nmap = $db->prepare($nmap_sql);
$nmap->execute([':client_id'=>$client_id, ':date'=>$date]);

$whatweb_sql = "
    SELECT domain_ip, technologie, valeur, version
    FROM whatweb
    WHERE client_id = :client_id
      AND scan_date::date = :date
    ORDER BY domain_ip, technologie, valeur, version
";
$whatweb = $db->prepare($whatweb_sql);
$whatweb->execute([':client_id' => $client_id, ':date' => $date]);

$dork_sql = "
    SELECT domain, filetype AS type, title, link, found_at
    FROM dork_results
    WHERE scan_id IN (
        SELECT id FROM scans WHERE client_id = :client_id AND scan_date::date = :date
    )
    AND domain IS NOT NULL AND domain <> ''
    ORDER BY found_at DESC, domain, type
";
$dorks = $db->prepare($dork_sql);
$dorks->execute([':client_id'=>$client_id, ':date'=>$date]);

// ------------ Styles: enhanced description box + truncation + small tables --------------
$css = <<<CSS
@font-face { font-family: 'Roboto'; src: local('Roboto'), local('Roboto-Regular'); }
body { font-family: 'Roboto', Arial, sans-serif; background: #ffffff; color: #17233d; font-size: 12pt; margin:0; padding:0; }

/* Description box (design professionnel) */
.desc-box {
    background: linear-gradient(90deg, #f3f8ff 0%, #eef6ff 100%);
    border-left: 6px solid #5aa0ff;
    padding: 12px 16px;
    border-radius: 10px;
    color: #324a6e;
    box-shadow: 0 6px 20px rgba(45,92,246,0.05);
    margin-bottom: 14px;
    font-size: 0.98em;
}

/* CTI badges */
.cti-badge {
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    border-radius:8px;
    font-weight:600;
    font-size:0.95em;
    box-shadow: 0 2px 8px rgba(10,20,50,0.06);
    margin-bottom:14px;
}
.cti-badge .cti-icon img { height:24px; width:auto; display:block; }
.cti-safe { border: 3px solid #26b243; background: rgba(38,178,67,0.06); color: #1d7a2d; }
.cti-danger { border: 3px solid #d22626; background: rgba(210,38,38,0.04); color: #9e1e1e; }

/* Chapter title design (bigger, professional blue) */
.chapter-title {
    display:inline-block;
    padding:12px 20px;
    border-radius:10px;
    background: linear-gradient(180deg, #ffffff 0%, #f6fbff 100%);
    border-left:10px solid #2d5cf6;
    box-shadow: 0 10px 30px rgba(45,92,246,0.12);
    margin: 14px 0 22px 0;
    color: #12365a;
    font-weight:900;
    font-size:1.9em;
    letter-spacing:0.3px;
}
.chapter-sub { display:block; color:#42516b; font-weight:600; margin-top:8px; font-size:1.0em; }

/* Table container: thin blue contour near tables, closer spacing */
.table-wrap {
    border-radius:8px;
    overflow:hidden;
    box-shadow:0 2px 12px rgba(45,92,246,0.06);
    background:#fff;
    margin-bottom:18px;
    border:1.3pt solid #5aa0ff;
    padding:6px;
}

/* default table styles */
.table-audit { border-collapse: separate; width:100%; font-size:0.98em; }
.table-audit th { background: #2d5cf6; color: #fff; font-weight:700; padding:10px 8px; font-size:0.98em; border-bottom:2px solid #1b3799; text-align:left;}
.table-audit td { padding:9px 8px; font-size:0.96em; background: #fbfeff; border-bottom:1px solid #e9eef8;}
.table-audit tr:nth-child(even) td { background: #f6f9ff;}
.table-audit tr:last-child td { border-bottom:none; }
.table-audit th, .table-audit td { border-right: 1px solid #f1f5fb;}
.table-audit th:last-child, .table-audit td:last-child { border-right: none;}
.table-audit tr td a { color: #2052af; text-decoration:underline; word-break:break-all; }

/* Specific smaller tables for technological sections (nmap & whatweb & assets) */
.table-audit.small-table { font-size:0.82em; }
.table-audit.small-table th { font-size:0.88em; padding:7px 6px; }
.table-audit.small-table td { font-size:0.78em; padding:6px 6px; vertical-align:top; }

/* WHOIS servers cell: slightly smaller to fit multiple lines */
.table-audit .dns-cell { font-size:0.9em; line-height:1.1em; }

/* Domain cell in whatweb: ensure link looks like text but clickable */
.domain-cell a {
    color: #184baf !important;
    text-decoration: none !important;
    font-weight: 600;
}
.domain-cell a[title] { text-decoration: none !important; }
.domain-cell a:hover { text-decoration: underline !important; }

/* TOC styles */
.toc-hd { font-size:1.95em; color:#184baf;font-weight:800; margin-bottom:10px; text-align:center; }
.mpdf_toc { width:92%; margin:0 auto 18px auto; border-radius:10px; background:#fff; font-size:1.02em; border-collapse:collapse; box-shadow:0 2px 12px rgba(45,92,246,0.10); }
.mpdf_toc th, .mpdf_toc td { padding:10px 12px; text-align:left; vertical-align:middle; }
.mpdf_toc th { background:#f6fafd; color:#29437d; font-weight:700; border-bottom:2px solid #e3e9fb; }
.mpdf_toc td { border-bottom:1px solid #eaeff8; color:#27415f; }
.mpdf_toc .mpdf_toc_list_number { font-weight:700; color:#2d5cf6; padding-right:14px;}
.mpdf_toc a, .mpdf_toc .mpdf_toc_section, .mpdf_toc em, .mpdf_toc i, .mpdf_toc span {
    color: #184baf !important;
    text-decoration: none !important;
    font-style: normal !important;
    font-weight: 600 !important;
}
.mpdf_toc .mpdf_toc_pagenum { font-weight:800; color:#2052af; text-align:right; }
.toc-title { display:none; }

@page { margin-top: 26mm; margin-bottom: 18mm; margin-left: 15mm; margin-right: 15mm; }
CSS;

// Date affichée
$date_today = date('d/m/Y');

$sections = [
    [ 'id' => 'whois',   'label' => "Résultats WHOIS", 'subtitle' => "Informations d'enregistrement des domaines" ],
    [ 'id' => 'mail',    'label' => "Serveurs mail", 'subtitle' => "Enregistrements MX détectés" ],
    [ 'id' => 'assets',  'label' => "Découverte d'assets", 'subtitle' => "Domaines, IP, FQDNs" ],
    [ 'id' => 'nmap',    'label' => "Empreinte technologique (système)", 'subtitle' => "Ports & services détectés" ],
    [ 'id' => 'whatweb', 'label' => "Empreinte technologique web", 'subtitle' => "Technologies web identifiées" ],
    [ 'id' => 'dork',    'label' => "Recherche de documents confidentiels sur le web", 'subtitle' => "Documents publics potentiellement sensibles" ],
];

// ---------------------------------------------------- //
// Construction du rapport HTML
// ---------------------------------------------------- //

// ---- Cover page simplified ----
$cover = '<div style="height:100%;padding:40pt;">';
$cover .= '<div style="position:absolute;top:40pt;right:40pt;width:96pt;height:96pt;background:#05124d;"></div>';
$cover .= '<div style="margin-top:140pt;width:420pt;border:4pt solid #5aa0ff;padding:12pt;"><img src="'.htmlspecialchars($logo_data).'" style="max-height:86px;"></div>';
$cover .= '<div style="margin-top:24pt;width:420pt;background:#5aa0ff;padding:22pt 24pt;color:#fff;"><h1 style="margin:0;font-size:28pt;">Rapport d\'audit</h1><p style="margin-top:12pt;font-size:12pt;color:#eef6ff;">Audit d\'exposition</p></div>';
$cover .= '<div style="margin-top:18pt;width:200pt;border:4pt solid #5aa0ff;padding:10pt;color:#1a66c9;font-weight:700;">'.htmlspecialchars($clientName).'</div>';
$cover .= '<div style="position:absolute;left:72pt;bottom:48pt;width:480pt;height:16pt;background:#05124d;"></div>';
$cover .= '<div style="position:absolute;left:72pt;bottom:60pt;width:40pt;height:12pt;background:#5aa0ff;"></div>';
$cover .= '<div style="position:absolute;left:116pt;bottom:64pt;width:16pt;height:16pt;background:#05124d;"></div>';
$cover .= '</div>';
$rapport = $cover;
$rapport .= '<pagebreak />';

// Header for following pages
$rapport .= '<div class="header-client">';
$rapport .= '<div class="header-title">'.htmlspecialchars($clientName).'</div>';
$rapport .= '<img src="'.htmlspecialchars($logo_data).'" class="client-logo" alt="Logo" />';
$rapport .= '<div class="header-subtitle">Rapport d\'audit de sécurité</div>';
$rapport .= '<div class="audit-date-chip">'.htmlspecialchars($date_today).'</div>';
$rapport .= '</div>';

// CTI badge
$rapport .= $victime_html;

// Intro (use desc-box for a professional framed description)
$rapport .= '<div class="desc-box">Ce rapport présente les informations-clés collectées lors de la dernière analyse de sécurité, par OSINT et reconnaissance technique. Il permet d’obtenir une vision synthétique, exhaustive et professionnelle de la surface exposée sur Internet, pour le client <b>'.htmlspecialchars($clientName).'</b>. Les tableaux sont conçus pour fournir un accès rapide à chaque type de donnée critique, utile pour la gestion des risques et la remédiation.</div>';

// --------- TOC: header then automatic TOC (clickable) ---------
$toc_header = "<div class='toc-hd'>Sommaire</div>";
$rapport .= '<pagebreak />';
// Use toc-preHTML encoded to ensure header is printed cleanly on the TOC page
$rapport .= '<tocpagebreak toc-preHTML="'.htmlspecialchars($toc_header, ENT_QUOTES).'" toc-bookmark-level="0" links="1" />';

// ---------------- Sections (bookmarks + tocentry) -----------------
$firstSection = true;
foreach ($sections as $section) {
    $safeId = 'sec_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $section['id']);
    $title = $section['label'];
    $subtitle = $section['subtitle'] ?? '';

    if ($firstSection) {
        $firstSection = false;
    } else {
        $rapport .= '<pagebreak />';
    }

    // Bookmark / anchor / TOC entry
    $rapport .= '<bookmark content="'.htmlspecialchars($title).'" name="'.htmlspecialchars($safeId).'" />';
    $rapport .= '<a id="'.htmlspecialchars($safeId).'"></a>';
    $rapport .= '<tocentry content="'.htmlspecialchars($title).'" level="1" id="'.htmlspecialchars($safeId).'" />';

    // Chapter title block (styled)
    $rapport .= '<div class="chapter-title">'.htmlspecialchars($title).'</div>';
    if ($subtitle) {
        $rapport .= '<div class="chapter-sub">'.htmlspecialchars($subtitle).'</div>';
    }

    // Section content
    if ($section['id'] === 'whois') {
        $rapport .= '<div class="desc-box">Ce tableau regroupe les informations d’enregistrement des domaines identifiés, issues des bases WHOIS : registraire, propriétaire, pays, dates importantes, et serveurs DNS associés.</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit"><tr>
            <th>Domaine</th><th>Registraire</th><th>Propriétaire</th><th>Pays</th><th>Création</th><th>Expiration</th><th>Serveurs DNS</th>
            </tr>';
        foreach ($whois as $w) {
            $owner = $w['registrant_name'] ?: $w['registrant_org'];
            $ns_raw = '';
            if (!empty($w['name_servers'])) {
                $ns_raw = $w['name_servers'];
            } else {
                $ns_raw = trim((string)($w['name_server_1'] ?? '') . ' ' . (string)($w['name_server_2'] ?? ''));
            }
            $ns_items = preg_split('/[,\;\|\s]+/', trim($ns_raw));
            $ns_items = array_values(array_filter(array_map('trim', $ns_items), function($v){ return $v !== ''; }));
            if (!empty($ns_items)) {
                $ns_html = implode('<br/>', array_map('htmlspecialchars', $ns_items));
            } else {
                $ns_html = '';
            }

            $creation = fmt_date_day($w['creation_date'] ?? '');
            $expiry = fmt_date_day($w['expiry_date'] ?? '');

            $rapport .= '<tr>
                <td>'.htmlspecialchars($w['domain']??'').'</td>
                <td>'.htmlspecialchars($w['registrar']??'').'</td>
                <td>'.htmlspecialchars($owner??'').'</td>
                <td>'.htmlspecialchars($w['registrant_country']??'').'</td>
                <td>'.htmlspecialchars($creation).'</td>
                <td>'.htmlspecialchars($expiry).'</td>
                <td class="dns-cell">'.$ns_html.'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    } elseif ($section['id'] === 'mail') {
        $rapport .= '<div class="desc-box">Cette section montre pour chaque domaine les serveurs email (MX) configurés, avec leur TTL (durée de vie).</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit"><tr><th>Domaine</th><th>Serveur</th><th>TTL</th></tr>';
        foreach ($dig_mx as $mx) {
            $rapport .= '<tr>
                <td>'.htmlspecialchars($mx['domain']).'</td>
                <td>'.htmlspecialchars($mx['serveur']).'</td>
                <td>'.htmlspecialchars($mx['ttl']).'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    } elseif ($section['id'] === 'assets') {
        $rapport .= '<div class="desc-box">Ci-dessous figurent tous les assets découverts : domaines, IP, FQDNs, avec leur date de première et dernière détection.</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit small-table"><tr><th>Asset</th><th>Première détection</th><th>Dernière détection</th></tr>';
        foreach ($assets as $a) {
            $pd = fmt_date_day($a['premiere_detection'] ?? '');
            $ld = fmt_date_day($a['derniere_detection'] ?? '');
            $rapport .= '<tr>
                <td>'.htmlspecialchars($a['asset']).'</td>
                <td>'.htmlspecialchars($pd).'</td>
                <td>'.htmlspecialchars($ld).'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    } elseif ($section['id'] === 'nmap') {
        $rapport .= '<div class="desc-box">Ce tableau regroupe pour chaque asset les ports ouverts détectés, associés aux services actifs et à leur version.</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit small-table"><tr><th>Asset</th><th>Port</th><th>Service</th><th>Version</th></tr>';
        foreach ($nmap as $n) {
            $rapport .= '<tr>
                <td>'.htmlspecialchars($n['asset']).'</td>
                <td>'.htmlspecialchars($n['port']).'</td>
                <td>'.htmlspecialchars($n['service']).'</td>
                <td>'.htmlspecialchars($n['version']).'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    } elseif ($section['id'] === 'whatweb') {
        $rapport .= '<div class="desc-box">Pour chaque domaine ou IP, on liste ici les technologies web identifiées (CMS, frameworks, composants, etc.), leur version et valeur.</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit small-table"><tr><th>Domaine/IP</th><th>Technologie</th><th>Valeur</th><th>Version</th></tr>';
        foreach ($whatweb as $ww) {
            $domain_raw = (string)($ww['domain_ip'] ?? '');
            $display = htmlspecialchars($domain_raw);
            // truncate to 150 chars but keep link to full domain
            if (mb_strlen($domain_raw, 'UTF-8') > 150) {
                $short = mb_substr($domain_raw, 0, 150, 'UTF-8') . '...';
                $display = htmlspecialchars($short);
            }
            // ensure href has a scheme so PDF link works: add http:// if missing
            $href = $domain_raw;
            if ($href !== '' && !preg_match('/^https?:\\/\\//i', $href)) {
                $href = 'http://' . $href;
            }
            // title attribute contains full domain for tooltip in viewers
            $title_attr = htmlspecialchars($domain_raw);
            if ($domain_raw !== '') {
                $domain_html = '<a href="'.htmlspecialchars($href).'" title="'. $title_attr .'" target="_blank">'.$display.'</a>';
            } else {
                $domain_html = '';
            }

            $rapport .= '<tr>
                <td class="domain-cell">'.$domain_html.'</td>
                <td>'.htmlspecialchars($ww['technologie']).'</td>
                <td>'.htmlspecialchars($ww['valeur']).'</td>
                <td>'.htmlspecialchars($ww['version']).'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    } elseif ($section['id'] === 'dork') {
        $rapport .= '<div class="desc-box">Ce tableau présente les documents indexés par Google considérés comme potentiellement confidentiels ou sensibles.</div>';
        $rapport .= '<div class="table-wrap"><table class="table-audit"><tr><th>Domaine</th><th>Type</th><th>Titre</th><th>URL</th><th>Date</th></tr>';
        foreach ($dorks as $d) {
            $titre = ($d['title'] === 'Untitled' || $d['title'] === '' || strtolower($d['title']) === 'untitled') ? 'Sans titre' : $d['title'];
            $found = fmt_date_day($d['found_at'] ?? '');
            $rapport .= '<tr>
                <td>'.htmlspecialchars($d['domain']).'</td>
                <td>'.htmlspecialchars($d['type']).'</td>
                <td>'.htmlspecialchars($titre).'</td>
                <td><a href="'.htmlspecialchars($d['link']).'">'.htmlspecialchars($d['link']).'</a></td>
                <td>'.htmlspecialchars($found).'</td>
            </tr>';
        }
        $rapport .= '</table></div>';
    }
}

// ==== Génération PDF ====
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'default_font' => 'Roboto',
    'margin_top' => 26,
    'margin_left' => 15,
    'margin_right' => 15,
]);

$mpdf->WriteHTML('<style>'.$css.'</style>', 1);
$mpdf->WriteHTML($rapport);

// Output
$pdfname = "rapport_client{$client_id}_$date.pdf";
$mpdf->Output($pdfname, \Mpdf\Output\Destination::DOWNLOAD);
exit;
?>
