#!/bin/sh
# Copia consistente del database SQLite (anche con l'app in uso) con rotazione.
set -e
DB="${DB_PATH:-/data/postezone.db}"
DIR="$(dirname "$DB")/backups"
KEEP="${BACKUP_KEEP:-14}"

mkdir -p "$DIR"
OUT="$DIR/postezone-$(date +%Y%m%d-%H%M%S).db"
sqlite3 "$DB" ".backup '$OUT'"
gzip "$OUT"
echo "[PosteZone] Backup creato: $OUT.gz"

# Conserva solo gli ultimi $KEEP backup
ls -1t "$DIR"/postezone-*.db.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do rm -f "$old"; done
