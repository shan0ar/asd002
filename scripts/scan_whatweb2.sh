#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PYTHON_SCRIPT="/var/www/html/asd002/scripts/detect_technos_web.py"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_whatweb_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")
if [[ -z "$client_id" ]]; then
    echo "ERROR: client_id introuvable pour scan $SCANID"
    exit 1
fi

PYTHON_OUT="${LOGDIR}/parsed_technos_${SCANID}.csv"
python3 "$PYTHON_SCRIPT" "$ASSET" > "$PYTHON_OUT" 2>>"$LOGFILE" || {
    echo "ERROR: Problème python detect_technos_web.py pour $ASSET"
    exit 1
}

tail -n +2 "$PYTHON_OUT" | while IFS=',' read -r domaine technologie valeur version source; do
    [[ -z "$domaine" ]] && continue
    valeur=$(echo "$valeur" | sed 's/ *$//')
    version=$(echo "$version" | sed 's/ *$//')
    source=$(echo "$source" | sed 's/ *$//;s/\r$//')
    raw_output="${domaine},${technologie},${valeur},${version},${source}"
    $PSQL_CMD -c "INSERT INTO whatweb (client_id, scan_id, domain_ip, port, raw_output, scan_date, technologie, version, valeur, domain, source) VALUES ($client_id, $SCANID, '$domaine', 80, \$\$${raw_output}\$\$, now(), '$technologie', '$version', '$valeur', '${ASSET#*//}', '$source');" 2>>"$LOGFILE"
done
