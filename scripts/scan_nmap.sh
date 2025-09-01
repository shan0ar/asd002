#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_nmap_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

NMAP_RAW="${LOGDIR}/nmap_${SCANID}.txt"
NMAP_TARGET="${ASSET#*//}"
nmap -sV -T5 "$NMAP_TARGET" -Pn > "$NMAP_RAW" 2>&1

awk '/^PORT[ ]+STATE[ ]+SERVICE[ ]+VERSION$/{p=1;next} /^[0-9]+\/tcp/{if(p){print;next}} /^[A-Z]/{p=0}' "$NMAP_RAW" | \
while read -r line; do
    port=$(echo "$line" | awk '{print $1}')
    state=$(echo "$line" | awk '{print $2}')
    service=$(echo "$line" | awk '{print $3}')
    version=$(echo "$line" | cut -d' ' -f4-)
    version=$(echo "$version" | sed "s/'/''/g")
    $PSQL_CMD -c "INSERT INTO nmap_results (scan_id, asset, port, state, service, version) VALUES ($SCANID, '${ASSET#*//}', '$port', '$state', '$service', '$version');"
done
