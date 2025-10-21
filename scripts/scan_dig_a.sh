#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_diga_${SCANID}.log"
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

DIG_A_RAW="${LOGDIR}/dig_a_${SCANID}.txt"
dig "${ASSET#*//}" A +noall +answer > "$DIG_A_RAW" 2>&1 || true
if grep -q '^;;' "$DIG_A_RAW"; then
    exit 0
else
    RAW_A=$(cat "$DIG_A_RAW" | sed "s/'/''/g")
    while read -r line; do
        [ -z "$line" ] && continue
        # ignore comment lines
        [[ "$line" == \;\;* ]] && continue
        TYPE=$(echo "$line" | awk '{print $4}')
        DOMAIN_RAW=$(echo "$line" | awk '{print $1}')
        DOMAIN="${DOMAIN_RAW%.}"
        TTL=$(echo "$line" | awk '{print $2}')
        if [[ "$TYPE" == "A" ]]; then
            IP=$(echo "$line" | awk '{print $5}')
            if [[ -n "$IP" && "$TTL" =~ ^[0-9]+$ ]]; then
                $PSQL_CMD -c "INSERT INTO dig_a (scan_id, domain, ip, ttl, raw_output) VALUES ($SCANID, '$DOMAIN', '$IP', '$TTL', \$\$${RAW_A}\$\$);" 2>>"$LOGFILE"
                if is_allowed "$DOMAIN"; then
                    insert_asset_discovered "$DOMAIN" "dig_a"
                fi
            fi
        elif [[ "$TYPE" == "CNAME" ]]; then
            CNAME=$(echo "$line" | awk '{print $5}')
            CNAME_STRIPPED="${CNAME%.}"
            # On l'insère en tant qu'asset
            if [[ -n "$CNAME_STRIPPED" && "$TTL" =~ ^[0-9]+$ ]]; then
                if is_allowed "$CNAME_STRIPPED"; then
                    insert_asset_discovered "$CNAME_STRIPPED" "dig_a"
                fi
            fi
        fi
    done < "$DIG_A_RAW"
fi
