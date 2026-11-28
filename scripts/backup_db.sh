#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p backups

DATE=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="backups/swimpro_$DATE.sql"

echo "Sauvegarde de la base SwimPro..."

docker compose exec -T db sh -c '
    export MYSQL_PWD="$MYSQL_PASSWORD"
    exec mysqldump --single-transaction --no-tablespaces \
        -u"$MYSQL_USER" "$MYSQL_DATABASE"
' > "$BACKUP_FILE"

if [ ! -s "$BACKUP_FILE" ]; then
    echo "Erreur : sauvegarde vide."
    rm -f "$BACKUP_FILE"
    exit 1
fi

echo "Sauvegarde réussie : $BACKUP_FILE"