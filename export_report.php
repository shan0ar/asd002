<?php
require_once __DIR__ . '/vendor/autoload.php';

$db = new PDO('pgsql:host=localhost;dbname=osintapp', 'thomas', 'thomas');
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$date = isset($_GET['date']) ? $_GET['date'] : '';

if ($client_id <= 0 || !$date) {
    die('Paramètres manquants.');
}

$logo_base64 = 'iVBORw0KGgoAAAANSUhEUgAABAAAAAG6CAMAAAChqsQFAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAC9FBMVEVHcEwAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluZQluYAAFoAAFoAAFpQluYAAFpQluZQluYAAFoAAFoAAFpQluYAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluZQluYAAFoAAFoAAFpQluYAAFoAAFoAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluYAAFoAAFoAAFoAAFoAAFpQluYAAFoAAFoAAFpQluYAAFoAAFoAAFpQluYAAFpQluYAAFoAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluYAAFoAAFoAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluYAAFoAAFoAAFpQluYAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFoAAFpQluZQluYAAFpQluYAAFoAAFpQluZQluZQluYAAFpQluZQluZQluYAAFoAAFpQluZQluZQluYAAFpQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluYAAFpQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluZQluYAAFpQlub63OAZAAAA+nRSTlMA9Sge+2KRAgzv/Qbtp5kS8Vb5LpmJuzbZBGwYKO9QFPNM680gszAsn+H3CP08zxACevEKKhblJhjD59/jtRIapWQErTg+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';
$logo_data = 'data:image/png;base64,' . $logo_base64;

$COVER_CONFIG = [
    'dark_square' => [
        'left_pt'  => null,
        'right_pt' => -81,
        'top_pt'   => -142,
        'width_pt' => 256,
        'height_pt'=> 256,
        'color'    => '#05124d',
    ],
    'logo_frame' => [
        'left_pt'  => -81,
        'top_pt'   => -142,
        'width_pt' => 320,
        'height_pt'=> 256,
        'border_color' => '#5aa0ff',
    ],
    'big_title' => [
        'left_pt'  => -81,
        'top_pt'   => 115,
        'width_pt' => 285,
        'height_pt'=> 350,
        'bg_color' => '#5aa0ff',
        'title_font_size_pt' => 28,
    ],
    'client_box' => [
        'left_pt'  => 280,
        'top_pt'   => 226,
        'width_pt' => 200,
        'height_pt'=> 78,
        'border_color' => '#5aa0ff',
    ],
    'bottom_decor' => [
        'left_pt' => 90,
        'bottom_pt' => -90,
        'long_strip_width_pt' => 18,
        'long_strip_height_pt' => 18,
        'small_light_left_offset_pt' => -90,
        'small_light_top_offset_pt' => -16,
        'small_light_width_pt' => 90,
        'small_light_height_pt' => 18,
        'small_dark_left_offset_pt' => 55,
        'small_dark_top_offset_pt' => -40,
        'small_dark_size_pt' => 16,
        'light_color' => '#5aa0ff',
        'dark_color' => '#05124d',
    ],
];

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

$client = $db->prepare("SELECT name FROM clients WHERE id=?");
$client->execute([$client_id]);
$clientName = $client->fetchColumn() ?: "Nom du client";

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

$date_today = date('d/m/Y');

$sections = [
    [ 'id' => 'whois',   'label' => "Résultats WHOIS", 'subtitle' => "Informations d'enregistrement des domaines" ],
    [ 'id' => 'mail',    'label' => "Serveurs mail", 'subtitle' => "Enregistrements MX détectés" ],
    [ 'id' => 'assets',  'label' => "Découverte d'assets", 'subtitle' => "Domaines, IP, FQDNs" ],
    [ 'id' => 'nmap',    'label' => "Empreinte technologique (système)", 'subtitle' => "Ports & services détectés" ],
    [ 'id' => 'whatweb', 'label' => "Empreinte technologique web", 'subtitle' => "Technologies web identifiées" ],
    [ 'id' => 'dork',    'label' => "Recherche de documents confidentiels sur le web", 'subtitle' => "Documents publics potentiellement sensibles" ],
];

function mm2pt($mm) { return $mm * 2.83464567; }

$page_margin_left_pt  = mm2pt(15);
$page_margin_right_pt = mm2pt(15);
$page_margin_top_pt   = mm2pt(26);
$page_margin_bottom_pt= mm2pt(18);

$dark = $COVER_CONFIG['dark_square'];
$logoF = $COVER_CONFIG['logo_frame'];
$big   = $COVER_CONFIG['big_title'];
$cb    = $COVER_CONFIG['client_box'];
$bd    = $COVER_CONFIG['bottom_decor'];

$cover = '';

if (!empty($dark['left_pt'])) {
    $css_left = $page_margin_left_pt + (float)$dark['left_pt'];
    $css_top  = $page_margin_top_pt  + (float)$dark['top_pt'];
    $cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left).'pt; top:'.htmlspecialchars($css_top).'pt; width:'.htmlspecialchars((float)$dark['width_pt']).'pt; height:'.htmlspecialchars((float)$dark['height_pt']).'pt; background:'.htmlspecialchars($dark['color']).';"></div>';
} else {
    $css_right = $page_margin_right_pt + (float)($dark['right_pt'] ?? 0);
    $css_top   = $page_margin_top_pt   + (float)$dark['top_pt'];
    $cover .= '<div style="position:fixed; right:'.htmlspecialchars($css_right).'pt; top:'.htmlspecialchars($css_top).'pt; width:'.htmlspecialchars((float)$dark['width_pt']).'pt; height:'.htmlspecialchars((float)$dark['height_pt']).'pt; background:'.htmlspecialchars($dark['color']).';"></div>';
}

$css_left_logo = $page_margin_left_pt + (float)$logoF['left_pt'];
$css_top_logo  = $page_margin_top_pt  + (float)$logoF['top_pt'];
$imgMaxHeight = max(0, (float)$logoF['height_pt'] - ((float)$logoF['padding_pt'] * 2));
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_logo).'pt; top:'.htmlspecialchars($css_top_logo).'pt; width:'.htmlspecialchars((float)$logoF['width_pt']).'pt; height:'.htmlspecialchars((float)$logoF['height_pt']).'pt; border:4pt solid '.htmlspecialchars($logoF['border_color']).'; box-sizing:border-box; display:flex; align-items:center; justify-content:center; padding:'.htmlspecialchars((float)$logoF['padding_pt']).'pt; background:#fff;">';
$cover .= '<img src="'.htmlspecialchars($logo_data).'" style="max-height:'.htmlspecialchars($imgMaxHeight).'px; max-width:100%;">';
$cover .= '</div>';

$css_left_big = $page_margin_left_pt + (float)$big['left_pt'];
$css_top_big  = $page_margin_top_pt  + (float)$big['top_pt'];
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_big).'pt; top:'.htmlspecialchars($css_top_big).'pt; width:'.htmlspecialchars((float)$big['width_pt']).'pt; height:'.htmlspecialchars((float)$big['height_pt']).'pt; background:'.htmlspecialchars($big['bg_color']).'; color:#fff; padding:22pt 24pt; box-sizing:border-box;">';
$cover .= '<h1 style="margin:0;font-size:'.htmlspecialchars((float)$big['title_font_size_pt']).'pt;font-weight:800;">Rapport d\'audit</h1>';
$cover .= '<p style="margin-top:12pt;font-size:12pt;color:#eef6ff;">Audit d\'exposition</p>';
$cover .= '</div>';

$css_left_cb = $page_margin_left_pt + (float)$cb['left_pt'];
$css_top_cb  = $page_margin_top_pt  + (float)$cb['top_pt'];
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_cb).'pt; top:'.htmlspecialchars($css_top_cb).'pt; width:'.htmlspecialchars((float)$cb['width_pt']).'pt; height:'.htmlspecialchars((float)$cb['height_pt']).'pt; border:4pt solid '.htmlspecialchars($cb['border_color']).'; display:flex; align-items:center; justify-content:center; box-sizing:border-box; background:#fff; color:#1a66c9; font-weight:700;">';
$cover .= htmlspecialchars($clientName);
$cover .= '</div>';

$css_left_bd = $page_margin_left_pt + (float)$bd['left_pt'];
$css_bottom_bd = $page_margin_bottom_pt + (float)$bd['bottom_pt'];
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_bd).'pt; bottom:'.htmlspecialchars($css_bottom_bd).'pt; width:'.htmlspecialchars((float)$bd['long_strip_width_pt']).'pt; height:'.htmlspecialchars((float)$bd['long_strip_height_pt']).'pt; background:'.htmlspecialchars($bd['dark_color']).';"></div>';
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_bd + $bd['small_light_left_offset_pt']).'pt; bottom:'.htmlspecialchars($css_bottom_bd + $bd['small_light_top_offset_pt']).'pt; width:'.htmlspecialchars((float)$bd['small_light_width_pt']).'pt; height:'.htmlspecialchars((float)$bd['small_light_height_pt']).'pt; background:'.htmlspecialchars($bd['light_color']).';"></div>';
$cover .= '<div style="position:fixed; left:'.htmlspecialchars($css_left_bd + $bd['small_dark_left_offset_pt']).'pt; bottom:'.htmlspecialchars($css_bottom_bd + $bd['small_dark_top_offset_pt']).'pt; width:'.htmlspecialchars((float)$bd['small_dark_size_pt']).'pt; height:'.htmlspecialchars((float)$bd['small_dark_size_pt']).'pt; background:'.htmlspecialchars($bd['dark_color']).';"></div>';

$cover .= '<pagebreak />';

$rapport = $cover;

$rapport .= '<div class="header-client">';
$rapport .= '<div class="header-title">'.htmlspecialchars($clientName).'</div>';
$rapport .= '<img src="'.htmlspecialchars($logo_data).'" class="client-logo" alt="Logo" />';
$rapport .= '<div class="header-subtitle">Rapport d\'audit de sécurité</div>';
$rapport .= '<div class="audit-date-chip">'.htmlspecialchars($date_today).'</div>';
$rapport .= '</div>';

$rapport .= $victime_html;

$rapport .= '<div class="desc-box">Ce rapport présente les informations-clés collectées lors de la dernière analyse de sécurité, par OSINT et reconnaissance technique. Il permet d’obtenir une vision synthétique, exhaustive et professionnelle de la surface exposée sur Internet, pour le client <b>'.htmlspecialchars($clientName).'</b>. Les tableaux sont conçus pour fournir un accès rapide à chaque type de donnée critique, utile pour la gestion des risques et la remédiation.
</div>';

$toc_header = "<div class='toc-hd'>Sommaire</div>";
$rapport .= '<pagebreak />';
$rapport .= '<tocpagebreak toc-preHTML="'.htmlspecialchars($toc_header, ENT_QUOTES).'" toc-bookmark-level="0" links="1" />';

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

    $rapport .= '<bookmark content="'.htmlspecialchars($title).'" name="'.htmlspecialchars($safeId).'" />';
    $rapport .= '<a id="'.htmlspecialchars($safeId).'"></a>';
    $rapport .= '<tocentry content="'.htmlspecialchars($title).'" level="1" id="'.htmlspecialchars($safeId).'" />';

    $rapport .= '<div class="chapter-title">'.htmlspecialchars($title).'</div>';
    if ($subtitle) {
        $rapport .= '<div class="chapter-sub">'.htmlspecialchars($subtitle).'</div>';
    }

    if ($section['id'] === 'whois') {
        $rapport .= '<div class="desc-box">Ce tableau regroupe les informations d’enregistrement des domaines identifiés, issues des bases WHOIS : registraire, propriétaire, pays, dates importantes, et serveurs DNS associés.</div>';
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
        $rapport .= '<div class="desc-box">Ports ouverts et services détectés par Nmap.</div>';
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
            if (mb_strlen($domain_raw, 'UTF-8') > 150) {
                $short = mb_substr($domain_raw, 0, 150, 'UTF-8') . '...';
                $display = htmlspecialchars($short);
            }
            $href = $domain_raw;
            if ($href !== '' && !preg_match('/^https?:\\/\\//i', $href)) {
                $href = 'http://' . $href;
            }
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

$pdfname = "rapport_client{$client_id}_$date.pdf";
$mpdf->Output($pdfname, \Mpdf\Output\Destination::DOWNLOAD);
exit;
?>
