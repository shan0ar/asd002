#!/bin/bash
set -Euo pipefail

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PYTHON_SCRIPT="/var/www/html/asd002/scripts/detect_technos_web.py"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
SUBDOMAIN_WORDLIST="/var/www/html/asd002/subdomains.txt"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_runner_${SCANID}.log"
DEBUGLOG="${LOGDIR}/scan_runner_debug.log"

# --- NUCLEI TMPDIR PATCH ---
export HOME=/opt/nuclei-config
mkdir -p "$HOME"
chown www-data:www-data "$HOME"

export TMPDIR=/opt/nuclei-tmp
mkdir -p "$TMPDIR"
chown www-data:www-data "$TMPDIR"

set -x
exec > >(tee -a "$LOGFILE") 2>&1
mkdir -p "$LOGDIR"

log() {
    echo "[$(date '+%F %T')] $*" >> "$DEBUGLOG"
}

if [[ -z "$ASSET" || -z "$SCANID" ]]; then
    log "ERROR: Asset or ScanID missing"
    exit 1
fi

log "scan_runner.sh lancé pour asset=$ASSET scanid=$SCANID"

scan_exists=$($PSQL_CMD -tAc "SELECT 1 FROM scans WHERE id=$SCANID")
if [[ -z "$scan_exists" ]]; then
    log "ERROR: Scan $SCANID n'existe pas"
    exit 1
fi

$PSQL_CMD -c "UPDATE scans SET status='running' WHERE id=$SCANID;" || {
    log "ERROR: Impossible de mettre à jour le statut running pour scan $SCANID"
    exit 1
}

finish_scan() {
    log "Fin de scan $SCANID"
}
trap finish_scan EXIT

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")
if [[ -z "$client_id" ]]; then
    log "ERROR: client_id introuvable pour scan $SCANID"
    exit 1
fi

# --- Correction passage ASSET: toujours avec schéma ---
if [[ ! "$ASSET" =~ ^http ]]; then
    ASSET="https://$ASSET"
fi

echo "DEBUG: Python args: $PYTHON_SCRIPT $ASSET" >&2
whoami >&2
env >&2
which nuclei >&2
nuclei --version >&2

# --- WHATWEB/NUCLEI TECHNOS ---
if [[ ! -f "$PYTHON_SCRIPT" ]]; then
    log "ERROR: Script python $PYTHON_SCRIPT non trouvé"
    exit 1
fi

PYTHON_OUT="${LOGDIR}/parsed_technos_${SCANID}.csv"
python3 "$PYTHON_SCRIPT" "$ASSET" > "$PYTHON_OUT" 2>>"$LOGFILE" || {
    log "ERROR: Problème python detect_technos_web.py pour $ASSET"
    exit 1
}
echo "DEBUG: PYTHON OUTPUT BELOW:" >&2
cat "$PYTHON_OUT" >&2

tail -n +2 "$PYTHON_OUT" | while IFS=',' read -r domaine technologie valeur version source; do
    [[ -z "$domaine" ]] && continue
    valeur=$(echo "$valeur" | sed 's/ *$//')
    version=$(echo "$version" | sed 's/ *$//')
    source=$(echo "$source" | sed 's/ *$//;s/\r$//')
    raw_output="${domaine},${technologie},${valeur},${version},${source}"
    echo "INSERTING: $raw_output" >&2
    log "INSERT whatweb : client_id=$client_id scanid=$SCANID domain_ip=$domaine techno=$technologie valeur=\"$valeur\" version=\"$version\" source=\"$source\""
    $PSQL_CMD -c "INSERT INTO whatweb (client_id, scan_id, domain_ip, port, raw_output, scan_date, technologie, version, valeur, domain, source) VALUES ($client_id, $SCANID, '$domaine', 80, \$\$${raw_output}\$\$, now(), '$technologie', '$version', '$valeur', '${ASSET#*//}', '$source');" 2>>"$LOGFILE"
done

# --- DIG A record ---
DIG_A_RAW="${LOGDIR}/dig_a_${SCANID}.txt"
dig "${ASSET#*//}" A +noall +answer > "$DIG_A_RAW" 2>&1 || true
A_IP=$(awk '{print $5}' "$DIG_A_RAW" | head -1)
A_TTL=$(awk '{print $2}' "$DIG_A_RAW" | head -1)
RAW_A=$(cat "$DIG_A_RAW" | sed "s/'/''/g")
$PSQL_CMD -c "INSERT INTO dig_a (scan_id, domain, ip, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$A_IP', '${A_TTL:-0}', \$\$${RAW_A}\$\$);" 2>>"$LOGFILE"

# --- DIG NS record ---
DIG_NS_RAW="${LOGDIR}/dig_ns_${SCANID}.txt"
dig "${ASSET#*//}" NS +noall +answer > "$DIG_NS_RAW" 2>&1 || true
while read -r line; do
    [ -z "$line" ] && continue
    NS_DOMAIN=$(echo "$line" | awk '{print $1}')
    NS_TTL=$(echo "$line" | awk '{print $2}')
    NS_VAL=$(echo "$line" | awk '{print $5}')
    RAW_NS=$(cat "$DIG_NS_RAW" | sed "s/'/''/g")
    $PSQL_CMD -c "INSERT INTO dig_ns (scan_id, domain, ns, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$NS_VAL', '${NS_TTL:-0}', \$\$${RAW_NS}\$\$);" 2>>"$LOGFILE"
done < "$DIG_NS_RAW"

# --- DIG MX record ---
DIG_MX_RAW="${LOGDIR}/dig_mx_${SCANID}.txt"
dig "${ASSET#*//}" MX +noall +answer > "$DIG_MX_RAW" 2>&1 || true
while read -r line; do
    [ -z "$line" ] && continue
    MX_DOMAIN=$(echo "$line" | awk '{print $1}')
    MX_TTL=$(echo "$line" | awk '{print $2}')
    MX_PREF=$(echo "$line" | awk '{print $5}')
    MX_EXCH=$(echo "$line" | awk '{print $6}')
    RAW_MX=$(cat "$DIG_MX_RAW" | sed "s/'/''/g")
    $PSQL_CMD -c "INSERT INTO dig_mx (scan_id, domain, preference, exchange, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '${MX_PREF:-0}', '$MX_EXCH', '${MX_TTL:-0}', \$\$${RAW_MX}\$\$);" 2>>"$LOGFILE"
done < "$DIG_MX_RAW"

# --- DIG TXT record ---
DIG_TXT_RAW="${LOGDIR}/dig_txt_${SCANID}.txt"
dig "${ASSET#*//}" TXT +noall +answer > "$DIG_TXT_RAW" 2>&1 || true
while read -r line; do
    [ -z "$line" ] && continue
    TXT_DOMAIN=$(echo "$line" | awk '{print $1}')
    TXT_TTL=$(echo "$line" | awk '{print $2}')
    TXT_VAL=$(echo "$line" | cut -d\" -f2 | sed "s/'/''/g")
    RAW_TXT=$(cat "$DIG_TXT_RAW" | sed "s/'/''/g")
    $PSQL_CMD -c "INSERT INTO dig_txt (scan_id, domain, txt, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$TXT_VAL', '${TXT_TTL:-0}', \$\$${RAW_TXT}\$\$);" 2>>"$LOGFILE"
done < "$DIG_TXT_RAW"

# --- DIG BRUTEFORCE (top 50, simple +short, insert si réponse) ---
DIG_BF_RAW="${LOGDIR}/dig_bruteforce_${SCANID}.txt"
WORDLIST="/opt/asd002-logs/top50_subdomains.txt"
if [[ ! -f "$WORDLIST" ]]; then
    head -50 "$SUBDOMAIN_WORDLIST" > "$WORDLIST"
fi

> "$DIG_BF_RAW"
while read -r sub; do
    sub=$(echo "$sub" | tr -d '\r')
    [[ -z "$sub" ]] && continue
    fqdn="${sub}.${ASSET#*//}"
    ip=$(dig +short "$fqdn" | head -1)
    if [[ -n "$ip" ]]; then
        echo "$fqdn $ip" >> "$DIG_BF_RAW"
        RAW_BF=$(dig "$fqdn" A | sed "s/'/''/g" | head -50)
        log "Bruteforce found: $fqdn -> $ip"
        $PSQL_CMD -c "INSERT INTO dig_bruteforce (scan_id, subdomain, ip, raw_output) VALUES ($SCANID, '$fqdn', '$ip', \$\$${RAW_BF}\$\$);" 2>>"$LOGFILE"
    fi
done < <(head -50 "$WORDLIST")

# --- WHOIS ---
WHOIS_TXT="${LOGDIR}/whois_${SCANID}.txt"
timeout 120 whois "${ASSET#*//}" > "$WHOIS_TXT" 2>>"$LOGFILE" || log "WARNING: whois timeout ou erreur pour $ASSET"
WHOIS_RAW=$(cat "$WHOIS_TXT" | sed "s/'/''/g")
WHOIS_DOMAIN=$(grep -i 'Domain Name:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_REGISTRAR=$(grep -i 'Registrar:' "$WHOIS_TXT" | grep -v Whois | head -1 | cut -d: -f2- | xargs)
WHOIS_CREATION=$(grep -Ei 'Creation Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_EXPIRY=$(grep -Ei 'Expiry Date:|Expiration Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_NS1=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_NS2=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | sed -n '2p' | awk '{print $NF}')
WHOIS_DNSSEC=$(grep -i 'DNSSEC:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')

$PSQL_CMD -c "INSERT INTO whois_data (scan_id, domain, registrar, creation_date, expiry_date, name_server_1, name_server_2, dnssec, raw_output) VALUES ($SCANID, '$WHOIS_DOMAIN', '$WHOIS_REGISTRAR', '$WHOIS_CREATION', '$WHOIS_EXPIRY', '$WHOIS_NS1', '$WHOIS_NS2', '$WHOIS_DNSSEC', \$\$${WHOIS_RAW}\$\$);" 2>>"$LOGFILE"

log "Scan $SCANID terminé avec succès"
echo "End of the detection" >> "$LOGFILE"
exit 0
