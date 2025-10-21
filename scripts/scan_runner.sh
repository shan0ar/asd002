#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PYTHON_SCRIPT="/var/www/html/asd002/scripts/detect_technos_web.py"
AMASS_PARSER="/var/www/html/asd002/scripts/amass_to_pg3.py"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
SUBDOMAIN_WORDLIST="/opt/subdomains.txt"
BLACKLIST_FILE="/opt/blacklist.txt"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_runner_${SCANID}_${ASSET}.log"
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

log() { echo "[$(date '+%F %T')] $*" >> "$DEBUGLOG"; }

finish_scan() {
    log "Fin de scan $SCANID ($ASSET)"
    $PSQL_CMD -c "UPDATE scans SET status='done' WHERE id=$SCANID;" >> "$LOGFILE" 2>&1
}
trap finish_scan EXIT

if [[ -z "$ASSET" || -z "$SCANID" ]]; then log "ERROR: Asset or ScanID missing"; exit 1; fi
log "scan_runner_modulaire lancé pour asset=$ASSET scanid=$SCANID"

scan_exists=$($PSQL_CMD -tAc "SELECT 1 FROM scans WHERE id=$SCANID")
if [[ -z "$scan_exists" ]]; then log "ERROR: Scan $SCANID n'existe pas"; exit 1; fi

$PSQL_CMD -c "UPDATE scans SET status='running' WHERE id=$SCANID;" || { log "ERROR: Impossible de mettre à jour le statut running pour scan $SCANID"; exit 1; }

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")
if [[ -z "$client_id" ]]; then log "ERROR: client_id introuvable pour scan $SCANID"; exit 1; fi

# Asset toujours avec schéma
if [[ ! "$ASSET" =~ ^http ]]; then ASSET="https://$ASSET"; fi

# Récupérer la liste des phases à exécuter pour cet asset/client
enabled_tools=$($PSQL_CMD -tAc "SELECT tool FROM asset_scan_settings WHERE client_id=$client_id AND asset='${ASSET#*//}' AND enabled")
phase_enabled() { [[ "$enabled_tools" == *"$1"* ]]; }

# (Fonctions parsing et update_asset_source inchangées, cf. ton dernier script)
# ... (copie/colle ici TOUTES tes fonctions extract_domains et update_asset_source, identiques à ton script précédent)

# ========== Lancement conditionnel de chaque phase ==========

# 1. Whois
if phase_enabled "whois"; then
    WHOIS_TXT="${LOGDIR}/whois_${SCANID}_${ASSET}.txt"
    timeout 120 whois "${ASSET#*//}" > "$WHOIS_TXT" 2>>"$LOGFILE" || log "WARNING: whois timeout ou erreur pour $ASSET"
    WHOIS_RAW=$(cat "$WHOIS_TXT" | sed "s/'/''/g")
    WHOIS_DOMAIN=$(grep -i 'Domain Name:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
    WHOIS_REGISTRAR=$(grep -i 'Registrar:' "$WHOIS_TXT" | grep -v Whois | head -1 | cut -d: -f2- | xargs)
    WHOIS_CREATION=$(grep -Ei 'Creation Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
    WHOIS_EXPIRY=$(grep -Ei 'Expiry Date:|Expiration Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
    WHOIS_NS1=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
    WHOIS_NS2=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | sed -n '2p' | awk '{print $NF}')
    WHOIS_DNSSEC=$(grep -i 'DNSSEC:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
    [[ -z "$WHOIS_CREATION" ]] && WHOIS_CREATION=NULL || WHOIS_CREATION="'$WHOIS_CREATION'"
    [[ -z "$WHOIS_EXPIRY" ]] && WHOIS_EXPIRY=NULL || WHOIS_EXPIRY="'$WHOIS_EXPIRY'"
    $PSQL_CMD -c "INSERT INTO whois_data (scan_id, domain, registrar, creation_date, expiry_date, name_server_1, name_server_2, dnssec, raw_output) VALUES ($SCANID, '$WHOIS_DOMAIN', '$WHOIS_REGISTRAR', $WHOIS_CREATION, $WHOIS_EXPIRY, '$WHOIS_NS1', '$WHOIS_NS2', '$WHOIS_DNSSEC', \$\$${WHOIS_RAW}\$\$);" 2>>"$LOGFILE"
    # Extraction pour assets_discovered
    extract_domains "$WHOIS_TXT" "whois"
fi

# 2. Amass
if phase_enabled "amass"; then
    AMASS_RAW="${LOGDIR}/amass_raw_${SCANID}_${ASSET}.txt"
    python3 "$AMASS_PARSER" "${ASSET#*//}" "$SCANID" > "$AMASS_RAW" 2>>"$LOGFILE"
    $PSQL_CMD -tAc "SELECT DISTINCT subdomain FROM amass_results WHERE scan_id=$SCANID AND subdomain IS NOT NULL AND subdomain <> ''" | while read -r dom; do
        update_asset_source "$dom" "amass"
    done
fi

# 3. dig_bruteforce
if phase_enabled "dig_bruteforce"; then
    DIG_BF_RAW="${LOGDIR}/dig_bruteforce_${SCANID}_${ASSET}.txt"
    BRUTE_COUNT=$($PSQL_CMD -tAc "SELECT brute_count FROM information WHERE client_id=$client_id" | xargs)
    [[ -z "$BRUTE_COUNT" ]] && BRUTE_COUNT=50
    WORDLIST_DYNAMIC="${LOGDIR}/top_bruteforce_${SCANID}_${ASSET}.txt"
    head -n "$BRUTE_COUNT" "$SUBDOMAIN_WORDLIST" > "$WORDLIST_DYNAMIC"
    ACTUAL_ATTEMPTS=$(wc -l < "$WORDLIST_DYNAMIC")
    $PSQL_CMD -c "UPDATE scans SET bruteforce_attempts=$ACTUAL_ATTEMPTS WHERE id=$SCANID;"
    > "$DIG_BF_RAW"
    while read -r sub; do
        sub=$(echo "$sub" | tr -d '\r')
        [[ -z "$sub" ]] && continue
        fqdn="${sub}.${ASSET#*//}"
        ip=$(dig +short "$fqdn" | head -1)
        if [[ -n "$ip" ]]; then
            RAW_BF=$(dig "$fqdn" A | sed "s/'/''/g" | head -50)
            echo "$fqdn $ip" >> "$DIG_BF_RAW"
            log "Bruteforce found: $fqdn -> $ip"
            $PSQL_CMD -c "INSERT INTO dig_bruteforce (scan_id, subdomain, ip, raw_output) VALUES ($SCANID, '$fqdn', '$ip', \$\$${RAW_BF}\$\$);" 2>>"$LOGFILE"
        fi
    done < "$WORDLIST_DYNAMIC"
    extract_domains "$DIG_BF_RAW" "dig_bruteforce"
fi

# 4. dig_mx
if phase_enabled "dig_mx"; then
    DIG_MX_RAW="${LOGDIR}/dig_mx_${SCANID}_${ASSET}.txt"
    dig "${ASSET#*//}" MX +noall +answer > "$DIG_MX_RAW" 2>&1 || true
    while read -r line; do
        [ -z "$line" ] && continue
        if [[ "$line" == \;\;* ]]; then
            log "DIG MX error: $line"
            continue
        fi
        MX_DOMAIN=$(echo "$line" | awk '{print $1}')
        MX_TTL=$(echo "$line" | awk '{print $2}')
        MX_PREF=$(echo "$line" | awk '{print $5}')
        MX_EXCH=$(echo "$line" | awk '{print $6}')
        RAW_MX=$(cat "$DIG_MX_RAW" | sed "s/'/''/g")
        if [[ -n "$MX_EXCH" && "$MX_TTL" =~ ^[0-9]+$ && "$MX_PREF" =~ ^[0-9]+$ ]]; then
            $PSQL_CMD -c "INSERT INTO dig_mx (scan_id, domain, preference, exchange, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$MX_PREF', '$MX_EXCH', '$MX_TTL', \$\$${RAW_MX}\$\$);" 2>>"$LOGFILE"
        else
            log "DIG MX: Résultat incohérent, non inséré ($MX_PREF/$MX_EXCH/$MX_TTL)."
        fi
    done < "$DIG_MX_RAW"
    extract_domains "$DIG_MX_RAW" "dig_mx"
fi

# 5. dig_txt
if phase_enabled "dig_txt"; then
    DIG_TXT_RAW="${LOGDIR}/dig_txt_${SCANID}_${ASSET}.txt"
    dig "${ASSET#*//}" TXT +noall +answer > "$DIG_TXT_RAW" 2>&1 || true
    while read -r line; do
        [ -z "$line" ] && continue
        if [[ "$line" == \;\;* ]]; then
            log "DIG TXT error: $line"
            continue
        fi
        TXT_DOMAIN=$(echo "$line" | awk '{print $1}')
        TXT_TTL=$(echo "$line" | awk '{print $2}')
        TXT_VAL=$(echo "$line" | cut -d\" -f2 | sed "s/'/''/g")
        RAW_TXT=$(cat "$DIG_TXT_RAW" | sed "s/'/''/g")
        if [[ -n "$TXT_TTL" && "$TXT_TTL" =~ ^[0-9]+$ ]]; then
            $PSQL_CMD -c "INSERT INTO dig_txt (scan_id, domain, txt, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$TXT_VAL', '$TXT_TTL', \$\$${RAW_TXT}\$\$);" 2>>"$LOGFILE"
        else
            log "DIG TXT: Résultat incohérent, non inséré ($TXT_VAL/$TXT_TTL)."
        fi
    done < "$DIG_TXT_RAW"
    extract_domains "$DIG_TXT_RAW" "dig_txt"
fi

# 6. dig_a
if phase_enabled "dig_a"; then
    DIG_A_RAW="${LOGDIR}/dig_a_${SCANID}_${ASSET}.txt"
    dig "${ASSET#*//}" A +noall +answer > "$DIG_A_RAW" 2>&1 || true
    if grep -q '^;;' "$DIG_A_RAW"; then
        log "DIG A error: $(cat "$DIG_A_RAW")"
    else
        A_IP=$(awk '{print $5}' "$DIG_A_RAW" | head -1)
        A_TTL=$(awk '{print $2}' "$DIG_A_RAW" | head -1)
        RAW_A=$(cat "$DIG_A_RAW" | sed "s/'/''/g")
        if [[ -n "$A_IP" && "$A_TTL" =~ ^[0-9]+$ ]]; then
            $PSQL_CMD -c "INSERT INTO dig_a (scan_id, domain, ip, ttl, raw_output) VALUES ($SCANID, '${ASSET#*//}', '$A_IP', '$A_TTL', \$\$${RAW_A}\$\$);" 2>>"$LOGFILE"
        else
            log "DIG A: Résultat incohérent, non inséré ($A_IP/$A_TTL)."
        fi
    fi
    extract_domains "$DIG_A_RAW" "dig_a"
fi

# 7. whatweb/nuclei
if phase_enabled "whatweb"; then
    if [[ ! -f "$PYTHON_SCRIPT" ]]; then log "ERROR: Script python $PYTHON_SCRIPT non trouvé"; exit 1; fi
    PYTHON_OUT="${LOGDIR}/parsed_technos_${SCANID}_${ASSET}.csv"
    python3 "$PYTHON_SCRIPT" "$ASSET" > "$PYTHON_OUT" 2>>"$LOGFILE" || { log "ERROR: Problème python detect_technos_web.py pour $ASSET"; exit 1; }
    tail -n +2 "$PYTHON_OUT" | while IFS=',' read -r domaine technologie valeur version source; do
        [[ -z "$domaine" ]] && continue
        valeur=$(echo "$valeur" | sed 's/ *$//')
        version=$(echo "$version" | sed 's/ *$//')
        source=$(echo "$source" | sed 's/ *$//;s/\r$//')
        raw_output="${domaine},${technologie},${valeur},${version},${source}"
        $PSQL_CMD -c "INSERT INTO whatweb (client_id, scan_id, domain_ip, port, raw_output, scan_date, technologie, version, valeur, domain, source) VALUES ($client_id, $SCANID, '$domaine', 80, \$\$${raw_output}\$\$, now(), '$technologie', '$version', '$valeur', '${ASSET#*//}', '$source');" 2>>"$LOGFILE"
    done
    extract_domains "$PYTHON_OUT" "whatweb"
fi

# 8. nmap
if phase_enabled "nmap"; then
    NMAP_RAW="${LOGDIR}/nmap_${SCANID}_${ASSET}.txt"
    NMAP_TARGET="${ASSET#*//}"
    nmap -sV -T5 "$NMAP_TARGET" -Pn > "$NMAP_RAW" 2>&1
    awk '/^PORT[ ]+STATE[ ]+SERVICE[ ]+VERSION$/{p=1;next} /^[0-9]+\/tcp/{if(p){print;next}} /^[A-Z]/{p=0}' "$NMAP_RAW" | \
    while read -r line; do
        port=$(echo "$line" | awk '{print $1}')
        state=$(echo "$line" | awk '{print $2}')
        service=$(echo "$line" | awk '{print $3}')
        version=$(echo "$line" | cut -d' ' -f4-)
        version=$(echo "$version" | sed "s/'/''/g")
        $PSQL_CMD -c "INSERT INTO nmap_results (scan_id, port, state, service, version) VALUES ($SCANID, '$port', '$state', '$service', '$version');"
    done
fi

log "Scan $SCANID terminé avec succès pour asset $ASSET"
echo "End of the detection" >> "$LOGFILE"
exit 0
