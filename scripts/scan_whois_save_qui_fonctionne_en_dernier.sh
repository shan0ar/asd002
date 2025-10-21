#!/bin/bash
set -u

ASSET="$1"
SCANID="$2"
LOGDIR="/opt/asd002-logs"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"
export PGPASSWORD="thomas"
LOGFILE="${LOGDIR}/scan_whois_${SCANID}.log"
DEBUGLOG="/tmp/debug_whois_global.log"

mkdir -p "$LOGDIR"
exec > >(tee -a "$LOGFILE") 2>&1

echo "[$(date)] Début du script. ASSET=$ASSET SCANID=$SCANID" | tee -a "$DEBUGLOG"

client_id=$($PSQL_CMD -tAc "SELECT client_id FROM scans WHERE id=$SCANID")
echo "[$(date)] Résultat client_id: '$client_id'" | tee -a "$DEBUGLOG"
if [[ -z "$client_id" ]]; then
    echo "ERROR: client_id introuvable pour scan $SCANID" | tee -a "$DEBUGLOG"
    exit 1
fi

WHOIS_TXT="${LOGDIR}/whois_${SCANID}.txt"
ASSET_CLEAN="${ASSET#*//}"
echo "[$(date)] Appel de whois sur $ASSET_CLEAN" | tee -a "$DEBUGLOG"
timeout 120 whois "$ASSET_CLEAN" > "$WHOIS_TXT" 2>>"$LOGFILE"
whois_ret=$?
if [[ $whois_ret -ne 0 ]]; then
    echo "WARNING: whois timeout ou erreur pour $ASSET (return $whois_ret)" | tee -a "$DEBUGLOG"
fi

WHOIS_RAW=$(cat "$WHOIS_TXT" | sed "s/'/''/g")
WHOIS_DOMAIN=$(grep -i 'Domain Name:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_REGISTRAR=$(grep -i 'Registrar:' "$WHOIS_TXT" | grep -v Whois | head -1 | cut -d: -f2- | xargs)
WHOIS_CREATION=$(grep -Ei 'Creation Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_EXPIRY=$(grep -Ei 'Expiry Date:|Expiration Date:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_NS1=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')
WHOIS_NS2=$(grep -Ei 'Name Server:' "$WHOIS_TXT" | sed -n '2p' | awk '{print $NF}')
WHOIS_DNSSEC=$(grep -i 'DNSSEC:' "$WHOIS_TXT" | head -1 | awk '{print $NF}')

echo "[$(date)] Extraction whois:" | tee -a "$DEBUGLOG"
echo "  DOMAIN=$WHOIS_DOMAIN" | tee -a "$DEBUGLOG"
echo "  REGISTRAR=$WHOIS_REGISTRAR" | tee -a "$DEBUGLOG"
echo "  CREATION=$WHOIS_CREATION" | tee -a "$DEBUGLOG"
echo "  EXPIRY=$WHOIS_EXPIRY" | tee -a "$DEBUGLOG"
echo "  NS1=$WHOIS_NS1" | tee -a "$DEBUGLOG"
echo "  NS2=$WHOIS_NS2" | tee -a "$DEBUGLOG"
echo "  DNSSEC=$WHOIS_DNSSEC" | tee -a "$DEBUGLOG"

[[ -z "$WHOIS_CREATION" ]] && WHOIS_CREATION=NULL || WHOIS_CREATION="'$WHOIS_CREATION'"
[[ -z "$WHOIS_EXPIRY" ]] && WHOIS_EXPIRY=NULL || WHOIS_EXPIRY="'$WHOIS_EXPIRY'"

insert_sql="INSERT INTO whois_data (scan_id, domain, registrar, creation_date, expiry_date, name_server_1, name_server_2, dnssec, raw_output) VALUES ($SCANID, '$WHOIS_DOMAIN', '$WHOIS_REGISTRAR', $WHOIS_CREATION, $WHOIS_EXPIRY, '$WHOIS_NS1', '$WHOIS_NS2', '$WHOIS_DNSSEC', \$\$${WHOIS_RAW}\$\$);"

echo "[$(date)] Envoi dans la base : $insert_sql" | tee -a "$DEBUGLOG"
$PSQL_CMD -c "$insert_sql" 2>>"$LOGFILE"
psql_ret=$?
if [[ $psql_ret -ne 0 ]]; then
    echo "[$(date)] ERREUR: insertion SQL a échoué (code $psql_ret)" | tee -a "$DEBUGLOG"
    exit 3
else
    echo "[$(date)] Insertion SQL OK." | tee -a "$DEBUGLOG"
fi

echo "[$(date)] Fin du script scan_whois.sh" | tee -a "$DEBUGLOG"
exit 0
