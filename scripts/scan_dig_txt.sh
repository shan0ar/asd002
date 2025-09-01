#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_digtxt_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

DIG_TXT_RAW="${LOGDIR}/dig_txt_${SCANID}.txt"
dig "${ASSET#*//}" TXT +noall +answer > "$DIG_TXT_RAW" 2>&1 || true
while read -r line; do
    [ -z "$line" ] && continue
    if [[ "$line" == \;\;* ]]; then
        continue
    fi
    TXT_DOMAIN=$(echo "$line" | awk '{print $1}')
    TXT_TTL=$(echo "$line" | awk '{print $2}')
    TXT_VAL=$(echo "$line" | cut -d\" -f2 | sed "s/'/''/g")
    RAW_TXT=$(cat "$DIG_TXT_RAW" | sed "s/'/''/g")
    if [[ -n "$TXT_TTL" && "$TXT_TTL" =~ ^[0-9]+$ ]]; then
        $PSQL_CMD -c "INSERT INTO dig_txt (scan_id, domain, txt, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$TXT_VAL', '$TXT_TTL', \$\$${RAW_TXT}\$\$);" 2>>"$LOGFILE"
    fi
done < "$DIG_TXT_RAW"
