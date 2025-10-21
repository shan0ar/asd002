#!/usr/bin/env python3
import sys
import re
import subprocess
from urllib.parse import urlparse

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
            results.append((extract_domain(url), tech, "", version))
            continue

        m = regex_plugin_detect.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            results.append((extract_domain(url), tech, "", ""))
            continue

        m = regex_tech_detect.match(line)
        if m:
            tech = m.group(1)
            url = m.group(2)
            results.append((extract_domain(url), tech, "", ""))
            continue

        m = regex_metatag_cms.match(line)
        if m:
            url = m.group(1)
            values = m.group(2)
            for name, version in parse_metatag_values(values):
                results.append((extract_domain(url), "metatag-cms", name, version))
            continue

        m = regex_php_detect.match(line)
        if m:
            url = m.group(1)
            version = m.group(2)
            results.append((extract_domain(url), "php", "", version))
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
            results.append((extract_domain(url), tech, "", ""))
            continue

    return results

def print_table(results):
    headers = ["Domaine", "Technologie", "Valeur", "Version"]

    width = [len(h) for h in headers]
    for r in results:
        for i, val in enumerate(r):
            if i == 3:
                val = clean_version(val)
            width[i] = max(width[i], len(str(val)))
    width = [w+2 for w in width]

    fmt = "".join([f"{{:<{w}}}" for w in width])
    print(fmt.format(*headers))
    print("-" * (sum(width)))

    for row in results:
        cleaned_row = list(row)
        cleaned_row[3] = clean_version(cleaned_row[3])
        print(fmt.format(*[str(v) for v in cleaned_row]))

def main():
    if len(sys.argv) != 2:
        print("Usage: python3 test4.py https://heliaq.fr")
        sys.exit(1)

    url = sys.argv[1]
    nuclei_cmd = [
        "nuclei", "-u", url, "-t", "technologies", "-c", "5", "-rl", "1", "-pc", "5", "-prc", "5", "--no-color"
    ]

    process = subprocess.Popen(nuclei_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    lines = []
    try:
        for line in process.stdout:
            lines.append(line)
    except KeyboardInterrupt:
        process.kill()
        sys.exit(1)
    process.wait()

    results = parse_nuclei_output(lines)
    print_table(results)

if __name__ == "__main__":
    main()
