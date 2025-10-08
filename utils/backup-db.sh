#!/bin/bash
# Database backup script
# Usage: ./backup-db.sh [optional-backup-name]

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DB_DIR="$SCRIPT_DIR/../db"
DB_FILE="$DB_DIR/comments.db"
BACKUP_DIR="$SCRIPT_DIR/../backups"

# Create backups directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Generate backup filename
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
CUSTOM_NAME="${1:-}"

if [ -n "$CUSTOM_NAME" ]; then
    BACKUP_FILE="$BACKUP_DIR/comments-${CUSTOM_NAME}-${TIMESTAMP}.db"
else
    BACKUP_FILE="$BACKUP_DIR/comments-backup-${TIMESTAMP}.db"
fi

# Check if database exists
if [ ! -f "$DB_FILE" ]; then
    echo "Error: Database file not found at $DB_FILE"
    exit 1
fi

# Create backup
echo "Creating backup: $BACKUP_FILE"
cp "$DB_FILE" "$BACKUP_FILE"

if [ $? -eq 0 ]; then
    echo "✓ Backup created successfully!"
    echo "  Size: $(du -h "$BACKUP_FILE" | cut -f1)"
    echo "  Location: $BACKUP_FILE"

    # Show record counts
    echo ""
    echo "Database contents:"
    sqlite3 "$BACKUP_FILE" "SELECT 'Comments: ' || COUNT(*) FROM comments;"
    sqlite3 "$BACKUP_FILE" "SELECT 'Subscriptions: ' || COUNT(*) FROM subscriptions;"

    # List recent backups
    echo ""
    echo "Recent backups:"
    ls -lht "$BACKUP_DIR"/*.db | head -5
else
    echo "✗ Backup failed!"
    exit 1
fi
