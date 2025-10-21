#!/usr/bin/env python3
import sys
import subprocess
import psycopg2
import re

def main():
    if len(sys.argv) < 3:
        print("Usage: amass_to_pg.py <domain> <scanid>")
        sys.exit(1)
    domain = sys.argv[1]
    scanid = int(sys.argv[2])

    # Configure database
    conn = psycopg2.connect(
        dbname="osintapp",
        user="thomas",
        password="thomas",
        host="localhost",
        port=5432
    )
    cur = conn.cursor()

    # Run Amass
    try:
        proc = subprocess.run(
            ["/opt/go/bin/amass", "enum", "-passive", "-d", domain],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=500,
            universal_newlines=True
        )
        lines = proc.stdout.splitlines()
    except Exception as e:
        print(f"Amass failed: {e}", file=sys.stderr)
        sys.exit(2)

    amass_line_re = re.compile(r'^([^\ ]+)\ \(([^\)]+)\)\ -->\ ([^\ ]+)\ -->\ (.+)\ \(([^\)]+)\)$')

    for line in lines:
        line = line.strip()
        if not line:
            continue
        m = amass_line_re.match(line)
        if m:
            src, src_type, rel, dst, dst_type = m.groups()
            subdomain = src if src_type == "FQDN" else ""
            record_type = rel
            value = dst
        else:
            subdomain = ""
            record_type = ""
            value = ""
        try:
            cur.execute("""
                INSERT INTO amass_results (scan_id, client_id, domain, subdomain, record_type, value, raw_output)
                VALUES (%s, (SELECT client_id FROM scans WHERE id=%s), %s, %s, %s, %s, %s)
                """,
                (scanid, scanid, domain, subdomain, record_type, value, line)
            )
        except Exception as ex:
            print(f"Failed to insert line: {line}\nError: {ex}", file=sys.stderr)
            conn.rollback()
            continue

    conn.commit()
    cur.close()
    conn.close()

if __name__ == "__main__":
    main()
