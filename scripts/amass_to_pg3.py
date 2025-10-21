#!/usr/bin/env python3
import sys
import os
import subprocess
import psycopg2
import re
import threading
import traceback
import datetime
import fnmatch

TIMEOUT = 1000  # seconds
EXCEPTIONS_FILE = "/var/www/html/asd002/exceptions.txt"

def debug(msg):
    print(f"[DEBUG] {msg}", file=sys.stderr, flush=True)

def parse_exceptions(filename):
    whitelist = []
    blacklist = []
    try:
        with open(filename, "r") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if line.startswith("whitelist :"):
                    val = line.split("whitelist :",1)[1].strip()
                    whitelist.append(val)
                elif line.startswith("blacklist :"):
                    val = line.split("blacklist :",1)[1].strip()
                    blacklist.append(val)
    except Exception as e:
        debug(f"Impossible de lire {filename}: {e}")
    return whitelist, blacklist

def is_allowed(domain, whitelist, blacklist):
    # Whitelist prioritaire
    for pattern in whitelist:
        if fnmatch.fnmatch(domain, pattern):
            return True
    for pattern in blacklist:
        if fnmatch.fnmatch(domain, pattern):
            return False
    return True

def insert_asset_discovered(cur, conn, scanid, client_id, asset, source):
    try:
        # PATCH : ignore case, strip spaces
        asset_clean = asset.strip().lower()
        cur.execute("""
            SELECT id, source FROM assets_discovered
            WHERE LOWER(TRIM(asset))=%s AND client_id=%s
        """, (asset_clean, client_id))
        row = cur.fetchone()
        now = datetime.datetime.now()
        debug(f"Insert/update asset: asset={asset_clean}, client_id={client_id}, found={bool(row)}")
        if row:
            old_source = row[1] if row[1] else ""
            if "amass" not in old_source:
                new_source = (old_source + " & amass") if old_source else "amass"
                cur.execute("""
                    UPDATE assets_discovered SET source=%s, last_seen=%s WHERE id=%s
                """, (new_source, now, row[0]))
                debug(f"UPDATE source+last_seen id={row[0]} : {new_source} {now}")
            else:
                cur.execute("""
                    UPDATE assets_discovered SET last_seen=%s WHERE id=%s
                """, (now, row[0]))
                debug(f"UPDATE last_seen id={row[0]} : {now}")
        else:
            cur.execute("""
                INSERT INTO assets_discovered (scan_id, asset, source, detected_at, client_id, last_seen)
                VALUES (%s, %s, %s, %s, %s, %s)
            """, (scanid, asset_clean, source, now, client_id, now))
            debug(f"INSERT asset: {asset_clean} {now}")
        conn.commit()
    except Exception as e:
        debug(f"Erreur insert/update assets_discovered: {e}")
        conn.rollback()

def db_insert(cur, conn, scanid, client_id, domain, line, whitelist, blacklist):
    # PATCH : parsing FQDN robuste, ignore espaces
    fqdn_match = re.match(r'^([^\s]+)\s+\(FQDN\)\s+-->\s+([^\s]+)\s+-->\s+(.+)', line)
    if fqdn_match:
        subdomain = fqdn_match.group(1).strip().lower()
        record_type = fqdn_match.group(2).strip()
        value = fqdn_match.group(3).strip()
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

    # PATCH : asset detection et update last_seen fiable
    if subdomain and subdomain.endswith(domain):
        if is_allowed(subdomain, whitelist, blacklist):
            insert_asset_discovered(cur, conn, scanid, client_id, subdomain, "amass")
        else:
            debug(f"Exclu par exception.txt : {subdomain}")

def main():
    debug("=== Debut amass_to_pg3.py ===")
    debug(f"USER: {os.getenv('USER','?')}, UID: {os.getuid()}")
    debug(f"ARGV: {sys.argv}")

    if len(sys.argv) < 3:
        debug("Usage: amass_to_pg3.py <domain> <scanid>")
        sys.exit(1)
    domain = sys.argv[1].strip().lower()
    scanid = int(sys.argv[2])

    whitelist, blacklist = parse_exceptions(EXCEPTIONS_FILE)
    debug(f"Règles whitelist: {whitelist}")
    debug(f"Règles blacklist: {blacklist}")

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
                print(line, flush=True)
                debug(f"Amass output: {line}")
                db_insert(cur, conn, scanid, client_id, domain, line, whitelist, blacklist)
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
