#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
AMASS_PARSER="/var/www/html/asd002/scripts/amass_to_pg3.py"
UPDATE_LAST_SEEN="/var/www/html/asd002/scripts/update_last_seen_amass.py"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
export HOME=/var/www
LOGFILE="${LOGDIR}/scan_amass_${SCANID}.log"
AMASS_RAW="${LOGDIR}/amass_raw_${SCANID}.txt"

mkdir -p "$LOGDIR"

# Debug pour traçabilité de l'appel depuis l'interface web
echo "=== Lancement scan_amass.sh ===" >> /opt/asd002-logs/DEBUG_CALLS.txt
date >> /opt/asd002-logs/DEBUG_CALLS.txt
echo "ARGS: $@" >> /opt/asd002-logs/DEBUG_CALLS.txt
whoami >> /opt/asd002-logs/DEBUG_CALLS.txt
env >> /opt/asd002-logs/DEBUG_CALLS.txt

exec > >(tee -a "$LOGFILE") 2>&1

# On récupère le client_id
client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")

# Exécution du parsing Amass (sortie brute dans AMASS_RAW)
python3 "$AMASS_PARSER" "${ASSET#*//}" "$SCANID" > "$AMASS_RAW" 2>>"$LOGFILE"

# Extraction et log des sous-domaines détectés dans AMASS_RAW
echo "==== SOUS-DOMAINES DÉTECTÉS ====" >> "$AMASS_RAW"
grep ' (FQDN)' "$AMASS_RAW" | awk -F' (FQDN)' '{print $1}' | sort | uniq >> "$AMASS_RAW"

# Mise à jour du last_seen pour chaque sous-domaine détecté
python3 "$UPDATE_LAST_SEEN" "$SCANID" "$client_id" "$AMASS_RAW" >> "$LOGFILE" 2>&1
