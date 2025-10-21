import re
import json
import os
import sys
import subprocess

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

def parse_detected_plugins_blocks(content):
    plugin_sections = re.split(r"Detected Plugins:", content)
    results = []
    all_detected_techs = set()
    for section in plugin_sections[1:]:
        blocks = re.split(r"\n\s*\[ ([^]]+) \]", section)
        for i in range(1, len(blocks), 2):
            tech = blocks[i].strip()
            all_detected_techs.add(tech)
            block = blocks[i+1]
            string_m = re.search(r"^\s*String\s*:\s*(.+)$", block, re.MULTILINE)
            version_m = re.search(r"^\s*Version\s*:\s*(.+)$", block, re.MULTILINE)
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
                            "tech": tech,
                            "value": value_main,
                            "version": version_val
                        })
                        found = True
                if not found:
                    results.append({
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
                            "tech": tech,
                            "value": value_main,
                            "version": version_found
                        })
                    else:
                        results.append({
                            "tech": tech,
                            "value": v,
                            "version": ""
                        })
            else:
                results.append({
                    "tech": tech,
                    "value": "",
                    "version": ""
                })
    return results, all_detected_techs

def main():
    if len(sys.argv) != 2:
        print(f"Usage: python3 {sys.argv[0]} <domaine>")
        sys.exit(1)
    domaine = sys.argv[1]

    if not os.path.exists(TECHNO_JSON):
        print(f"Technologies file not found: {TECHNO_JSON}")
        return

    # Exécute whatweb et récupère la sortie texte
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
    content = ww_output
    plugins, all_detected_techs = parse_detected_plugins_blocks(content)

    print(f"{'Technologie':<16} {'Valeur':<32} {'Version':<12}")
    print('-'*16 + ' ' + '-'*32 + ' ' + '-'*12)
    seen = set()
    for plugin in plugins:
        tech = plugin['tech']
        if tech.lower() in technos_json:
            val = plugin['value']
            version = plugin['version']
            key = (tech.lower(), val.lower())
            if key in seen:
                continue
            seen.add(key)
            print(f"{tech:<16} {val:<32} {version:<12}")

if __name__ == "__main__":
    main()
