import sys
import json
import re

def collect_technos(obj, found=None):
    if found is None:
        found = set()
    if isinstance(obj, dict):
        for k, v in obj.items():
            found.add(str(k).lower())
            if k.lower() == 'name' and isinstance(v, str):
                found.add(v.lower())
            collect_technos(v, found)
    elif isinstance(obj, list):
        for item in obj:
            collect_technos(item, found)
    return found

def read_exceptions(path):
    try:
        with open(path, "r") as f:
            return [e.strip().lower() for e in f if e.strip()]
    except Exception:
        return []

if len(sys.argv) < 4:
    print("Usage: parse_whatweb.py tech.json whatweb.txt exceptions.txt")
    sys.exit(1)

json_path = sys.argv[1]
whatweb_file = sys.argv[2]
exceptions_file = sys.argv[3]

with open(json_path, "r") as f:
    techs_json = json.load(f)
techno_names = collect_technos(techs_json)
exceptions = read_exceptions(exceptions_file)
ansi_escape = re.compile(r'\x1B\[[0-?]*[ -/]*[@-~]')

detected = set()

def print_result(name, value):
    key = (name.strip(), value.strip())
    if key not in detected:
        if value:
            print(f"{name}\t{value}")
        else:
            print(f"{name}")
        detected.add(key)

with open(whatweb_file, "r") as f:
    for line in f:
        line = ansi_escape.sub('', line.strip())
        if not line:
            continue
        match = re.search(r'\] (.*)$', line)
        data = match.group(1) if match else line
        tokens = [t.strip() for t in data.split(', ') if t.strip()]
        for token in tokens:
            m = re.match(r'^([A-Za-z0-9\.\+\-]+)(?:\[(.*?)\])?$', token)
            if m:
                name = m.group(1)
                bracket = m.group(2) if m.group(2) else ""
                # Cas normal JSON ou pas de crochets
                if name.lower() in techno_names or not bracket:
                    print_result(name, bracket)
                    continue
                # Explose toutes les sous-valeurs du champ entre crochets sur ; ou ,
                bracket_parts = re.split(r'[;,]', bracket)
                for part in bracket_parts:
                    part = part.strip()
                    if not part:
                        continue
                    # Exception ?
                    if any(exc in part.lower() for exc in exceptions):
                        print_result(name, part)
                        continue
                    # Version (= au moins un chiffre)
                    if re.search(r'\d', part):
                        print_result(name, part)

# Pour debug, tu veux voir si la ligne MetaGenerator a bien été traitée ?
# Ajoute ce print juste après le "for token in tokens":
# print('TOKEN:', token)
