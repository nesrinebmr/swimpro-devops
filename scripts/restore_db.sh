#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ $# -ne 1 ]; then
    echo "Utilisation : ./scripts/restore_db.sh fichier.sql"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Erreur : fichier SQL introuvable."
    exit 1
fi

echo "Attention : la restauration peut remplacer les données."
read -r -p "Tapez RESTAURER pour confirmer : " CONFIRMATION

if [ "$CONFIRMATION" != "RESTAURER" ]; then
    echo "Restauration annulée."
    exit 1
fi

docker compose exec -T db sh -c '
    export MYSQL_PWD="$MYSQL_PASSWORD"
    exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"
' < "$BACKUP_FILE"

echo "Restauration terminée."