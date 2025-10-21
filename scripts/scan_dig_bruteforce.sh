#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
SUBDOMAIN_WORDLIST="/opt/subdomains.txt"
EXCEPTIONS_FILE="/var/www/html/asd002/exceptions.txt"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_digbruteforce_${SCANID}.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")
BRUTE_COUNT=$($PSQL_CMD -tAc "SELECT brute_count FROM information WHERE client_id=$client_id" | xargs)
if [[ -z "$BRUTE_COUNT" ]]; then
    BRUTE_COUNT=50
fi

WORDLIST_DYNAMIC="${LOGDIR}/top_bruteforce_${SCANID}.txt"
head -n "$BRUTE_COUNT" "$SUBDOMAIN_WORDLIST" > "$WORDLIST_DYNAMIC"
ACTUAL_ATTEMPTS=$(wc -l < "$WORDLIST_DYNAMIC")
$PSQL_CMD -c "UPDATE scans SET bruteforce_attempts=$ACTUAL_ATTEMPTS WHERE id=$SCANID;"

DIG_BF_RAW="${LOGDIR}/dig_bruteforce_${SCANID}.txt"
> "$DIG_BF_RAW"

# Exceptions logic
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
    # UPSERT: met à jour last_seen et source si l'asset existe déjà pour ce client
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

while read -r sub; do
    sub=$(echo "$sub" | tr -d '\r')
    [[ -z "$sub" ]] && continue
    fqdn="${sub}.${ASSET#*//}"
    ip=$(dig +short "$fqdn" | head -1)
    if [[ -n "$ip" ]]; then
        RAW_BF=$(dig "$fqdn" A | sed "s/'/''/g" | head -50)
        echo "$fqdn $ip" >> "$DIG_BF_RAW"
        $PSQL_CMD -c "INSERT INTO dig_bruteforce (scan_id, subdomain, ip, raw_output) VALUES ($SCANID, '$fqdn', '$ip', \$\$${RAW_BF}\$\$);" 2>>"$LOGFILE"
        if is_allowed "$fqdn"; then
            insert_asset_discovered "$fqdn" "dig_bruteforce"
        fi
    fi
done < "$WORDLIST_DYNAMIC"
