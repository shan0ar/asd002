#!/bin/bash

ASSET="$1"
SCANID="$2"
LOGFILE="/opt/asd002-logs/scan_runner_${SCANID}.log"
PSQL_CMD="psql -h localhost -p 5432 -d osintapp -U thomas"

bash /var/www/html/asd002/scripts/scan_runner.sh "$ASSET" "$SCANID"

# Attendre la phrase "End of the detection"
while ! grep -q "End of the detection" "$LOGFILE"; do
    sleep 5
done

$PSQL_CMD -c "UPDATE scans SET status='done' WHERE id=$SCANID;"
