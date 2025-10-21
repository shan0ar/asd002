#!/usr/bin/env python3
import psycopg2
import datetime
import sys

if len(sys.argv) < 4:
    print("Usage: update_last_seen_amass.py <scanid> <client_id> <amass_raw_file>")
    sys.exit(1)

scanid = int(sys.argv[1])
client_id = int(sys.argv[2])
raw_file = sys.argv[3]

assets = set()
with open(raw_file, "r") as f:
    for line in f:
        if "(FQDN)" in line and "-->" in line:
            domain = line.split(" (FQDN)")[0].strip().lower()
            assets.add(domain)

now = datetime.datetime.now()

conn = psycopg2.connect(
    dbname="osintapp",
    user="thomas",
    password="thomas",
    host="localhost",
    port=5432
)
cur = conn.cursor()

for asset in assets:
    cur.execute("""
        UPDATE assets_discovered
        SET last_seen=%s
        WHERE LOWER(TRIM(asset))=%s AND client_id=%s
    """, (now, asset, client_id))
    print(f"UPDATE last_seen for asset={asset} client_id={client_id}")
conn.commit()

cur.close()
conn.close()
