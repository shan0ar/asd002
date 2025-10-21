import re
import json
import os

TECHNO_JSON = '/var/www/html/asd002/technologies.json'
WHATWEB_FILE = '/tmp/www.txt'
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
    """
    Pour les valeurs MetaGenerator, ne prendre que les couples (nom, version) ou les valeurs principales (ex: Elementor, Site Kit by Google, etc).
    On ignore les settings, features, etc. sauf si c'est la seule valeur.
    """
    result = []
    for v in values:
        # On ne prend pas ce qui commence par "features:" ou "settings:" ou "google_font-enabled" etc.
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
            # Split par , ou ; pour toutes les valeurs, ou garde la string entière si rien à splitter
            values = []
            if raw_values:
                for part in re.split(r'[;,]', raw_values):
                    part = part.strip()
                    if part:
                        values.append(part)
            else:
                values = []

            # Pour MetaGenerator, ne prendre que les principaux (ignore les settings/features etc)
            if tech == "MetaGenerator":
                values = extract_main_values(values)

            # Si Version: existe et >= MIN_VERSION_LEN
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
                    # Si aucun match précis, affiche la version à côté de la techno
                    results.append({
                        "tech": tech,
                        "value": "",
                        "version": version_val
                    })
            elif values:
                any_version = False
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
                        any_version = True
                    else:
                        # On prend la valeur principale si pas de version, mais que ce n'est pas un sous-setting
                        results.append({
                            "tech": tech,
                            "value": v,
                            "version": ""
                        })
            else:
                # Pas de string ni version
                results.append({
                    "tech": tech,
                    "value": "",
                    "version": ""
                })
    return results, all_detected_techs

def main():
    if not os.path.exists(TECHNO_JSON):
        print(f"Technologies file not found: {TECHNO_JSON}")
        return
    if not os.path.exists(WHATWEB_FILE):
        print(f"WhatWeb file not found: {WHATWEB_FILE}")
        return

    technos_json = collect_technos_from_json(TECHNO_JSON)
    with open(WHATWEB_FILE, "r", encoding="utf-8") as f:
        content = f.read()
    plugins, all_detected_techs = parse_detected_plugins_blocks(content)

    # Affichage
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
