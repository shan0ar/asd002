#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
SUBDOMAIN_WORDLIST="/opt/subdomains.txt"
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
while read -r sub; do
    sub=$(echo "$sub" | tr -d '\r')
    [[ -z "$sub" ]] && continue
    fqdn="${sub}.${ASSET#*//}"
    ip=$(dig +short "$fqdn" | head -1)
    if [[ -n "$ip" ]]; then
        RAW_BF=$(dig "$fqdn" A | sed "s/'/''/g" | head -50)
        echo "$fqdn $ip" >> "$DIG_BF_RAW"
        $PSQL_CMD -c "INSERT INTO dig_bruteforce (scan_id, subdomain, ip, raw_output) VALUES ($SCANID, '$fqdn', '$ip', \$\$${RAW_BF}\$\$);" 2>>"$LOGFILE"
    fi
done < "$WORDLIST_DYNAMIC"
