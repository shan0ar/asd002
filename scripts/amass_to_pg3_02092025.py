#!/usr/bin/env python3
import sys
import subprocess
import psycopg2
import re
import threading

TIMEOUT = 500  # seconds

def db_insert(cur, conn, scanid, domain, line, regex):
    m = regex.match(line)
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
        conn.commit()
    except Exception as ex:
        print(f"Failed to insert line: {line}\nError: {ex}", file=sys.stderr)
        conn.rollback()

def main():
    if len(sys.argv) < 3:
        print("Usage: amass_to_pg.py <domain> <scanid>", file=sys.stderr)
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

    amass_cmd = ["/opt/amass_installation/amass_Linux_amd64/amass", "enum", "-passive", "-d", domain]
    amass_line_re = re.compile(r'^([^\ ]+)\ \(([^\)]+)\)\ -->\ ([^\ ]+)\ -->\ (.+)\ \(([^\)]+)\)$')

    proc = subprocess.Popen(
        amass_cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        universal_newlines=True,
        bufsize=1
    )

    stop_flag = threading.Event()

    def reader():
        for line in proc.stdout:
            if stop_flag.is_set():
                break
            line = line.strip()
            if not line:
                continue
            db_insert(cur, conn, scanid, domain, line, amass_line_re)

    thread = threading.Thread(target=reader)
    thread.start()

    try:
        thread.join(TIMEOUT)  # Attend jusqu'à TIMEOUT pour la lecture
        if thread.is_alive():
            stop_flag.set()
            print(f"Timeout reached ({TIMEOUT}s), terminating Amass process.", file=sys.stderr)
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
            thread.join()
    finally:
        cur.close()
        conn.close()
        try:
            proc.stdout.close()
        except Exception:
            pass
        try:
            proc.stderr.close()
        except Exception:
            pass

if __name__ == "__main__":
    main()
