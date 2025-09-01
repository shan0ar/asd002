#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
AMASS_PARSER="/var/www/html/asd002/scripts/amass_to_pg3.py"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_amass_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

python3 "$AMASS_PARSER" "${ASSET#*//}" "$SCANID" > "${LOGDIR}/amass_raw_${SCANID}.txt" 2>>"$LOGFILE"
