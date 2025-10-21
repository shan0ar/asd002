#!/usr/bin/env python3
import re
import json
import os
import sys
import subprocess
from urllib.parse import urlparse

# -------------------- WhatWeb Parsing (from detect_technos3.py) -------------------

TECHNO_JSON = '/var/www/html/asd002/technologies.json'
MIN_VERSION_LEN = 2

def collect_technos_from_json(json_path):
    with open(json_path, "r") as f:
        obj = json.load(f)
    found = set()
    def walk(o):
        if isinstance(o, dict):
            for k, v in o.items():
                found.add(str(k).lower())
                if k.lower() == 'name' and isinstance(v, str):
                    found.add(v.lower())
                walk(v)
        elif isinstance(o, list):
            for i in o:
                walk(i)
    walk(obj)
    return found

def extract_main_values(values):
    result = []
    for v in values:
        if v.strip().startswith(('features:', 'settings:', 'google_font-enabled', 'font_display-swap', 'additional_custom_breakpoints', 'e_element_cache')):
            continue
        result.append(v)
    return result

def parse_whatweb_reports(content):
    # Découpe chaque bloc "WhatWeb report for ..."
    blocks = re.split(r"(?:^|\n)WhatWeb report for ", content)
    results = []
    for block in blocks[1:]:
        # Récupère le domaine/URL du bloc
        url = block.splitlines()[0].strip()
        # Ne prend que la partie du bloc jusqu'au prochain bloc ou fin
        # Parse le bloc Detected Plugins
        plugin_sections = re.split(r"Detected Plugins:", block)
        if len(plugin_sections) < 2:
            continue
        section = plugin_sections[1]
        blocks_plugins = re.split(r"\n\s*\[ ([^]]+) \]", section)
        for i in range(1, len(blocks_plugins), 2):
            tech = blocks_plugins[i].strip()
            plugin_block = blocks_plugins[i+1]
            string_m = re.search(r"^\s*String\s*:\s*(.+)$", plugin_block, re.MULTILINE)
            version_m = re.search(r"^\s*Version\s*:\s*(.+)$", plugin_block, re.MULTILINE)
            version_val = version_m.group(1).strip() if version_m else ""
            raw_values = string_m.group(1).strip() if string_m else ""
            values = []
            if raw_values:
                for part in re.split(r'[;,]', raw_values):
                    part = part.strip()
                    if part:
                        values.append(part)
            else:
                values = []
            # Pour MetaGenerator, filtre
            if tech == "MetaGenerator":
                values = extract_main_values(values)
            if version_val and len(version_val) >= MIN_VERSION_LEN:
                found = False
                for v in values:
                    m = re.match(r"(.+?)\s+([0-9]+\.[0-9][0-9a-zA-Z\.]*)", v)
                    if m and m.group(2) == version_val:
                        value_main = m.group(1)
                        results.append({
                            "domaine": url,
                            "tech": tech,
                            "value": value_main,
                            "version": version_val
                        })
                        found = True
                if not found:
                    results.append({
                        "domaine": url,
                        "tech": tech,
                        "value": "",
                        "version": version_val
                    })
            elif values:
                for v in values:
                    m = re.match(r"(.+?)\s+([0-9]+\.[0-9][0-9a-zA-Z\.]*)$", v)
                    if m:
                        value_main = m.group(1)
                        version_found = m.group(2)
                        results.append({
                            "domaine": url,
                            "tech": tech,
                            "value": value_main,
                            "version": version_found
                        })
                    else:
                        results.append({
                            "domaine": url,
                            "tech": tech,
                            "value": v,
                            "version": ""
                        })
            else:
                results.append({
                    "domaine": url,
                    "tech": tech,
                    "value": "",
                    "version": ""
                })
    return results

# -------------------- Nuclei Parsing (from detect_technos_nuclei.py) -------------------

def extract_domain(url):
    parsed = urlparse(url)
    if parsed.scheme and parsed.netloc:
        return f"{parsed.scheme}://{parsed.netloc}"
    return url

def parse_metatag_values(values):
    results = []
    for val in re.findall(r'"([^"]+)"', values):
        m = re.match(r"^(.+?)\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)(.*)$", val)
        if m:
            name = m.group(1)
            version = m.group(2)
            results.append((name, version))
        else:
            results.append((val, ""))
    return results

def clean_version(version):
    m = re.match(r"^([0-9]+\.[0-9]+(?:\.[0-9]+)?)", version)
    if m:
        return m.group(1)
    return version

def parse_nuclei_output(lines):
    results = []
    regex_wp_detected = re.compile(
        r'^\[wordpress-([a-z0-9\-]+):detected_version\]\s+\[http\]\s+\[info\]\s+(\S+) \["([^"]+)"\](?: \[last_version="([^"]+)"\])?'
    )
    regex_plugin_detect = re.compile(
        r'\[wordpress-plugin-detect:([a-z0-9\-]+)\]\s+\[http\]\s+\[info\]\s+(\S+)'
    )
    regex_tech_detect = re.compile(
        r'\[tech-detect:([a-z0-9\-\.]+)\]\s+\[http\]\s+\[info\]\s+(\S+)'
    )
    regex_metatag_cms = re.compile(
        r'\[metatag-cms\]\s+\[http\]\s+\[info\]\s+(\S+)\s+\[(.+)\]'
    )
    regex_php_detect = re.compile(
        r'\[php-detect\]\s+\[http\]\s+\[info\]\s+(\S+)\s+\["([^"]+)"\]'
    )
    regex_generic = re.compile(
        r'\[([a-z0-9\-:.]+)\]\s+\[http\]\s+\[info\]\s+(\S+)'
    )

    for line in lines:
        line = line.strip()
        m = regex_wp_detected.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            version = m.group(3)
            results.append({
                "domaine": extract_domain(url),
                "tech": tech,
                "value": "",
                "version": version
            })
            continue

        m = regex_plugin_detect.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            results.append({
                "domaine": extract_domain(url),
                "tech": tech,
                "value": "",
                "version": ""
            })
            continue

        m = regex_tech_detect.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            results.append({
                "domaine": extract_domain(url),
                "tech": tech,
                "value": "",
                "version": ""
            })
            continue

        m = regex_metatag_cms.match(line)
        if m:
            url = m.group(1)
            values = m.group(2)
            for name, version in parse_metatag_values(values):
                results.append({
                    "domaine": extract_domain(url),
                    "tech": "metatag-cms",
                    "value": name,
                    "version": version
                })
            continue

        m = regex_php_detect.match(line)
        if m:
            url = m.group(1)
            version = m.group(2)
            results.append({
                "domaine": extract_domain(url),
                "tech": "php",
                "value": "",
                "version": version
            })
            continue

        m = regex_generic.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            if tech.startswith("wordpress-") or tech.startswith("tech-detect") or tech.startswith("php-detect") or tech.startswith("metatag"):
                continue
            if ":detected_version" in tech:
                continue
            if "plugin-detect" in tech:
                continue
            results.append({
                "domaine": extract_domain(url),
                "tech": tech,
                "value": "",
                "version": ""
            })
            continue

    return results

# -------------------- Output Table (same as detect_technos3.py, 4 columns) -------------------

def print_table(rows):
    headers = ["Domaine", "Technologie", "Valeur", "Version"]
    width = [len(h) for h in headers]
    # Compute max width
    for r in rows:
        for i, key in enumerate(["domaine", "tech", "value", "version"]):
            val = r.get(key, "")
            width[i] = max(width[i], len(str(val)))
    width = [w+2 for w in width]
    fmt = "".join([f"{{:<{w}}}" for w in width])
    print(fmt.format(*headers))
    print("-" * (sum(width)))
    for row in rows:
        vals = [row.get("domaine", ""), row.get("tech", ""), row.get("value", ""), row.get("version", "")]
        print(fmt.format(*vals))

# -------------------- MAIN -------------------

def main():
    if len(sys.argv) != 2:
        print(f"Usage: python3 {sys.argv[0]} <domaine>")
        sys.exit(1)
    domaine = sys.argv[1]

    if not os.path.exists(TECHNO_JSON):
        print(f"Technologies file not found: {TECHNO_JSON}")
        return

    # 1. WhatWeb
    try:
        ww_output = subprocess.check_output(
            ["whatweb", domaine, "-a", "3", "-v", "--color=never"],
            stderr=subprocess.STDOUT,
            universal_newlines=True,
            encoding="utf-8"
        )
    except subprocess.CalledProcessError as e:
        print(f"Erreur lors de l'exécution de WhatWeb:\n{e.output}")
        sys.exit(2)

    technos_json = collect_technos_from_json(TECHNO_JSON)
    plugins = parse_whatweb_reports(ww_output)
    seen_ww = set()
    whatweb_rows = []
    for plugin in plugins:
        tech = plugin['tech']
        if tech.lower() in technos_json:
            val = plugin['value']
            version = plugin['version']
            domaine_val = plugin['domaine']
            key = (domaine_val.lower(), tech.lower(), val.lower())
            if key in seen_ww:
                continue
            seen_ww.add(key)
            whatweb_rows.append({
                "domaine": domaine_val,
                "tech": tech,
                "value": val,
                "version": version
            })

    # 2. Nuclei
    nuclei_cmd = [
        "nuclei", "-u", domaine, "-t", "technologies", "-c", "5", "-rl", "1", "-pc", "5", "-prc", "5", "--no-color"
    ]
    process = subprocess.Popen(nuclei_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    nuclei_lines = []
    try:
        for line in process.stdout:
            nuclei_lines.append(line)
    except KeyboardInterrupt:
        process.kill()
        sys.exit(1)
    process.wait()
    nuclei_rows = parse_nuclei_output(nuclei_lines)

    # Affichage : whatweb d'abord, puis nuclei (pour l'ordre demandé)
    print_table(whatweb_rows + nuclei_rows)

if __name__ == "__main__":
    main()
