#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_digmx_${SCANID}.log"
EXCEPTIONS_FILE="/var/www/html/asd002/exceptions.txt"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")

parse_exceptions() {
    whitelist=()
    blacklist=()
    if [[ -f "$EXCEPTIONS_FILE" ]]; then
        while IFS= read -r line; do
            line="${line%%#*}"
            [[ -z "$line" ]] && continue
            if [[ "$line" =~ ^whitelist[[:space:]]*:[[:space:]]*(.+) ]]; then
                whitelist+=("${BASH_REMATCH[1]}")
            elif [[ "$line" =~ ^blacklist[[:space:]]*:[[:space:]]*(.+) ]]; then
                blacklist+=("${BASH_REMATCH[1]}")
            fi
        done < "$EXCEPTIONS_FILE"
    fi
}

is_allowed() {
    local domain="$1"
    for pattern in "${whitelist[@]}"; do
        pat="^${pattern//\*/.*}$"
        if [[ "$domain" =~ $pat ]]; then
            return 0
        fi
    done
    for pattern in "${blacklist[@]}"; do
        pat="^${pattern//\*/.*}$"
        if [[ "$domain" =~ $pat ]]; then
            return 1
        fi
    done
    return 0
}

insert_asset_discovered() {
    local asset="$1"
    local src="$2"
    local now
    now=$(date +"%Y-%m-%d %H:%M:%S")
    $PSQL_CMD -c "
    INSERT INTO assets_discovered (scan_id, asset, source, detected_at, client_id, last_seen)
    VALUES ($SCANID, '$asset', '$src', '$now', $client_id, '$now')
    ON CONFLICT (asset, client_id)
    DO UPDATE SET
      last_seen = EXCLUDED.last_seen,
      source = CASE
        WHEN assets_discovered.source IS NULL OR assets_discovered.source = '' THEN EXCLUDED.source
        WHEN assets_discovered.source LIKE '%' || EXCLUDED.source || '%' THEN assets_discovered.source
        ELSE assets_discovered.source || ' & ' || EXCLUDED.source
      END;
    "
}

parse_exceptions

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
    # Enlève le point final éventuellement présent à la fin du sous-domaine
    MX_EXCH_STRIPPED="${MX_EXCH%.}"
    RAW_MX=$(cat "$DIG_MX_RAW" | sed "s/'/''/g")
    if [[ -n "$MX_EXCH_STRIPPED" && "$MX_TTL" =~ ^[0-9]+$ && "$MX_PREF" =~ ^[0-9]+$ ]]; then
        $PSQL_CMD -c "INSERT INTO dig_mx (scan_id, domain, preference, exchange, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$MX_PREF', '$MX_EXCH_STRIPPED', '$MX_TTL', \$\$${RAW_MX}\$\$);" 2>>"$LOGFILE"
        if is_allowed "$MX_EXCH_STRIPPED"; then
            insert_asset_discovered "$MX_EXCH_STRIPPED" "dig_mx"
        fi
    fi
done < "$DIG_MX_RAW"
