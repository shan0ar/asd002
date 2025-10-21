#!/usr/bin/env python3
import sys
import subprocess
import re

def run_nmap(target):
    try:
        result = subprocess.run(
            ["nmap", "-sV", "-T5", target, "-Pn"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True
        )
        return result.stdout
    except subprocess.CalledProcessError as e:
        print(f"Erreur lors de l'exécution de nmap : {e}")
        sys.exit(1)

def parse_nmap_output(output):
    lines = output.splitlines()
    domain_ip = ""
    parsing_ports = False
    results = []

    # Find the domain and IP
    for i, line in enumerate(lines):
        m = re.match(r"Nmap scan report for ([^\s]+) \(([\d\.]+)\)", line)
        if m:
            domain_ip = f"{m.group(1)}/{m.group(2)}"
        elif not domain_ip:
            # Try with only IP
            m = re.match(r"Nmap scan report for ([\d\.]+)", line)
            if m:
                domain_ip = m.group(1)

        # Detect PORT header
        if line.startswith("PORT"):
            parsing_ports = True
            port_line_idx = i
            continue

        # Parse ports
        if parsing_ports:
            if line.strip() == "" or line.startswith("Service detection"):
                break
            # Example: 80/tcp  open  http      OVHcloud
            port_match = re.match(r"(\d+\/\w+)\s+(\w+)\s+([^\s]+)\s+(.*)", line)
            if port_match:
                port = port_match.group(1)
                state = port_match.group(2).capitalize()  # e.g. Open
                service = port_match.group(3)
                version = port_match.group(4)
                results.append((domain_ip, port, state, service, version))
    return results

def print_results(results):
    # Print header
    print(f"{'Domaine/IP':<28} {'Port':<8} {'État':<10} {'Service':<25} {'Version'}")
    print("-" * 25 + "    " + "-" * 5 + "    " + "-" * 6 + " " + "-" * 28 + " " + "-" * 16)
    for row in results:
        print(f"{row[0]:<28} {row[1]:<8} {row[2]:<10} {row[3]:<25} {row[4]}")

def main():
    if len(sys.argv) != 2:
        print("Usage: python3 scripts/detect_ports.py <domaine|IP>")
        sys.exit(1)
    target = sys.argv[1]
    nmap_out = run_nmap(target)
    results = parse_nmap_output(nmap_out)
    print_results(results)

if __name__ == "__main__":
    main()
