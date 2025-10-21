#!/usr/bin/env python3
import re
import json
import os
import sys
import subprocess
from urllib.parse import urlparse
from datetime import datetime

TECHNO_JSON = '/var/www/html/asd002/technologies.json'
MIN_VERSION_LEN = 2
DEBUG_FILE = '/opt/asd002-logs/debug_nuclei_python.txt'

def debug(msg):
    with open(DEBUG_FILE, "a") as f:
        f.write(f"[{datetime.now().isoformat()}] {msg}\n")

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

def clean_value(val):
    cleaned = re.sub(r'\s*\(from [^)]+\)', '', val, flags=re.IGNORECASE)
    return cleaned.strip()

def parse_whatweb_reports(content):
    blocks = re.split(r"(?:^|\n)WhatWeb report for ", content)
    results = []
    for block in blocks[1:]:
        url = block.splitlines()[0].strip()
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
                            "value": clean_value(value_main),
                            "version": version_val,
                            "source": "whatweb"
                        })
                        found = True
                if not found:
                    results.append({
                        "domaine": url,
                        "tech": tech,
                        "value": "",
                        "version": version_val,
                        "source": "whatweb"
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
                            "value": clean_value(value_main),
                            "version": version_found,
                            "source": "whatweb"
                        })
                    else:
                        results.append({
                            "domaine": url,
                            "tech": tech,
                            "value": clean_value(v),
                            "version": "",
                            "source": "whatweb"
                        })
            else:
                results.append({
                    "domaine": url,
                    "tech": tech,
                    "value": "",
                    "version": "",
                    "source": "whatweb"
                })
    return results

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
                "version": version,
                "source": "nuclei"
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
                "version": "",
                "source": "nuclei"
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
                "version": "",
                "source": "nuclei"
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
                    "value": clean_value(name),
                    "version": version,
                    "source": "nuclei"
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
                "version": version,
                "source": "nuclei"
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
                "version": "",
                "source": "nuclei"
            })
            continue

    return results

def print_csv(rows):
    import csv
    import sys
    writer = csv.writer(sys.stdout)
    writer.writerow(["domaine","tech","value","version","source"])
    for row in rows:
        writer.writerow([
            row.get("domaine",""),
            row.get("tech",""),
            row.get("value",""),
            row.get("version",""),
            row.get("source","")
        ])

def main():
    if len(sys.argv) != 2:
        print(f"Usage: python3 {sys.argv[0]} <domaine>")
        sys.exit(1)
    domaine = sys.argv[1]

    if not os.path.exists(TECHNO_JSON):
        print(f"Technologies file not found: {TECHNO_JSON}")
        return

    try:
        debug(f"Launching whatweb against {domaine}")
        ww_output = subprocess.check_output(
            ["whatweb", domaine, "-a", "3", "-v", "--color=never"],
            stderr=subprocess.STDOUT,
            universal_newlines=True,
            encoding="utf-8"
        )
        debug("Whatweb finished successfully")
    except subprocess.CalledProcessError as e:
        debug(f"Erreur lors de l'exécution de WhatWeb: {e.output}")
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
                "version": version,
                "source": "whatweb"
            })

    # DEBUG: Check nuclei binary
    nuclei_path = subprocess.getoutput("which nuclei")
    debug(f"Using nuclei binary at: {nuclei_path}")

    nuclei_cmd = [
        nuclei_path, "-u", domaine, "-t", "/opt/nuclei-templates/http/technologies", "-c", "7", "-rl", "2", "-pc", "7", "-prc", "7", "--no-color"
    ]
    debug(f"Running nuclei command: {' '.join(nuclei_cmd)}")
    try:
        process = subprocess.Popen(nuclei_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1)
    except Exception as e:
        debug(f"Erreur d'appel nuclei: {e}")
        nuclei_lines = []
        nuclei_stderr = ""
        return

    nuclei_lines = []
    try:
        for line in process.stdout:
            nuclei_lines.append(line)
    except KeyboardInterrupt:
        debug("Nuclei interrupted by keyboard")
        process.kill()
        sys.exit(1)
    process.wait()

    nuclei_stderr = process.stderr.read()
    debug(f"Nuclei exited with code {process.returncode}")
    debug(f"Nuclei STDOUT lines: {len(nuclei_lines)}")
    debug(f"Nuclei STDERR: {nuclei_stderr.strip()[:500]}")  # Limite à 500 chars

    nuclei_rows = parse_nuclei_output(nuclei_lines)

    # Ajoute la source 'nuclei' explicitement à chaque ligne nuclei
    for row in nuclei_rows:
        row["source"] = "nuclei"

    debug(f"Total whatweb rows: {len(whatweb_rows)} ; nuclei rows: {len(nuclei_rows)}")

    # Affichage CSV complet des deux sources
    print_csv(whatweb_rows + nuclei_rows)

if __name__ == "__main__":
    main()
