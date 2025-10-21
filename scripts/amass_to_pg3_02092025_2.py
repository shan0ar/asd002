#!/usr/bin/env python3
import sys
import os
import subprocess
import psycopg2
import re
import threading
import traceback

TIMEOUT = 500  # seconds

def debug(msg):
    print(f"[DEBUG] {msg}", file=sys.stderr, flush=True)

def db_insert(cur, conn, scanid, client_id, domain, line, regex):
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
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (scanid, client_id, domain, subdomain, record_type, value, line)
        )
        conn.commit()
    except Exception as ex:
        debug(f"Failed to insert line: {line}\nError: {ex}")
        conn.rollback()

def main():
    debug("=== Debut amass_to_pg3.py ===")
    debug(f"USER: {os.getenv('USER','?')}, UID: {os.getuid()}")
    debug(f"ARGV: {sys.argv}")

    if len(sys.argv) < 3:
        debug("Usage: amass_to_pg3.py <domain> <scanid>")
        sys.exit(1)
    domain = sys.argv[1]
    scanid = int(sys.argv[2])

    # Database connection
    try:
        conn = psycopg2.connect(
            dbname="osintapp",
            user="thomas",
            password="thomas",
            host="localhost",
            port=5432
        )
        cur = conn.cursor()
        debug("Connexion BDD OK")
    except Exception as e:
        debug(f"Connexion BDD échouée: {e}")
        sys.exit(2)

    # Get client_id securely
    try:
        cur.execute("SELECT client_id FROM scans WHERE id = %s", (scanid,))
        row = cur.fetchone()
        if not row or row[0] is None:
            debug(f"ERREUR: scan {scanid} introuvable ou client_id NULL")
            sys.exit(3)
        client_id = row[0]
        debug(f"client_id trouvé: {client_id}")
    except Exception as e:
        debug(f"Erreur lors du SELECT client_id: {e}")
        sys.exit(4)

    amass_cmd = ["/opt/amass_installation/amass_Linux_amd64/amass", "enum", "-passive", "-d", domain]
    amass_line_re = re.compile(r'^([^\ ]+)\ \(([^\)]+)\)\ -->\ ([^\ ]+)\ -->\ (.+)\ \(([^\)]+)\)$')

    debug(f"Lancement Amass: {amass_cmd}")

    try:
        proc = subprocess.Popen(
            amass_cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True,
            bufsize=1,
            env=dict(os.environ, HOME=os.environ.get("HOME", "/var/www"))
        )
    except Exception as e:
        debug(f"Erreur lancement Amass: {e}")
        traceback.print_exc(file=sys.stderr)
        sys.exit(5)

    stop_flag = threading.Event()

    def reader():
        try:
            for line in proc.stdout:
                if stop_flag.is_set():
                    break
                line = line.strip()
                if not line:
                    continue
                print(line, flush=True)  # Pour la sortie brute dans le .txt
                debug(f"Amass output: {line}")
                db_insert(cur, conn, scanid, client_id, domain, line, amass_line_re)
        except Exception as e:
            debug(f"Erreur dans thread reader: {e}")
            traceback.print_exc(file=sys.stderr)

    thread = threading.Thread(target=reader)
    thread.start()

    try:
        thread.join(TIMEOUT)
        if thread.is_alive():
            stop_flag.set()
            debug(f"Timeout reached ({TIMEOUT}s), terminating Amass process.")
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
            thread.join()
        # Affiche aussi les erreurs stderr d'Amass
        if proc.stderr:
            for errline in proc.stderr:
                debug(f"Amass STDERR: {errline.strip()}")
    finally:
        try:
            cur.close()
            conn.close()
        except Exception:
            pass
        try:
            if proc.stdout:
                proc.stdout.close()
        except Exception:
            pass
        try:
            if proc.stderr:
                proc.stderr.close()
        except Exception:
            pass
        debug("=== Fin amass_to_pg3.py ===")

if __name__ == "__main__":
    main()
