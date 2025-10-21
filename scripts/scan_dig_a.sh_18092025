#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_diga_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

DIG_A_RAW="${LOGDIR}/dig_a_${SCANID}.txt"
dig "${ASSET#*//}" A +noall +answer > "$DIG_A_RAW" 2>&1 || true
if grep -q '^;;' "$DIG_A_RAW"; then
    exit 0
else
    A_IP=$(awk '{print $5}' "$DIG_A_RAW" | head -1)
    A_TTL=$(awk '{print $2}' "$DIG_A_RAW" | head -1)
    RAW_A=$(cat "$DIG_A_RAW" | sed "s/'/''/g")
    if [[ -n "$A_IP" && "$A_TTL" =~ ^[0-9]+$ ]]; then
        $PSQL_CMD -c "INSERT INTO dig_a (scan_id, domain, ip, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$A_IP', '$A_TTL', \$\$${RAW_A}\$\$);" 2>>"$LOGFILE"
    fi
fi
