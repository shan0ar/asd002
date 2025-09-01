#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_digmx_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

DIG_MX_RAW="${LOGDIR}/dig_mx_${SCANID}.txt"
dig "${ASSET#*//}" MX +noall +answer > "$DIG_MX_RAW" 2>&1 || true
while read -r line; do
    [ -z "$line" ] && continue
    if [[ "$line" == \;\;* ]]; then
        continue
    fi
    MX_DOMAIN=$(echo "$line" | awk '{print $1}')
    MX_TTL=$(echo "$line" | awk '{print $2}')
    MX_PREF=$(echo "$line" | awk '{print $5}')
    MX_EXCH=$(echo "$line" | awk '{print $6}')
    RAW_MX=$(cat "$DIG_MX_RAW" | sed "s/'/''/g")
    if [[ -n "$MX_EXCH" && "$MX_TTL" =~ ^[0-9]+$ && "$MX_PREF" =~ ^[0-9]+$ ]]; then
        $PSQL_CMD -c "INSERT INTO dig_mx (scan_id, domain, preference, exchange, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$MX_PREF', '$MX_EXCH', '$MX_TTL', \$\$${RAW_MX}\$\$);" 2>>"$LOGFILE"
    fi
done < "$DIG_MX_RAW"
