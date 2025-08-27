#!/bin/bash

# Pre-deployment backup script for Bazar Marketplace

set -e

# Configuration
BACKUP_DIR="/opt/backups/bazar"
DATE=$(date +"%Y%m%d_%H%M%S")
BACKUP_NAME="pre_deploy_${DATE}"
RETENTION_DAYS=7
DB_CONTAINER="bazar_mysql"
APP_DIR="/opt/bazar-production"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
    exit 1
}

# Create backup directory
mkdir -p "$BACKUP_DIR"

log "Starting pre-deployment backup: $BACKUP_NAME"

# Database backup
log "Backing up database..."
if docker ps | grep -q $DB_CONTAINER; then
    docker exec $DB_CONTAINER mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" \
        --single-transaction --routines --triggers \
        bazar_marketplace > "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
    
    # Compress database backup
    gzip "$BACKUP_DIR/${BACKUP_NAME}_database.sql"
    log "Database backup completed: ${BACKUP_NAME}_database.sql.gz"
else
    error "Database container not found or not running"
fi

# Application files backup
log "Backing up application files..."
tar -czf "$BACKUP_DIR/${BACKUP_NAME}_app_files.tar.gz" \
    -C "$APP_DIR" \
    --exclude='logs/*' \
    --exclude='cache/*' \
    --exclude='node_modules' \
    --exclude='.git' \
    --exclude='vendor' \
    .

log "Application files backup completed: ${BACKUP_NAME}_app_files.tar.gz"

# Upload files backup
log "Backing up upload files..."
if [ -d "$APP_DIR/uploads" ]; then
    tar -czf "$BACKUP_DIR/${BACKUP_NAME}_uploads.tar.gz" \
        -C "$APP_DIR" uploads/
    log "Upload files backup completed: ${BACKUP_NAME}_uploads.tar.gz"
else
    warn "No uploads directory found"
fi

# Docker images backup
log "Backing up current Docker images..."
docker images --format "table {{.Repository}}:{{.Tag}}\t{{.ID}}" | \
    grep bazar > "$BACKUP_DIR/${BACKUP_NAME}_docker_images.txt" || true

# Configuration backup
log "Backing up Docker Compose and configuration files..."
tar -czf "$BACKUP_DIR/${BACKUP_NAME}_config.tar.gz" \
    -C "$APP_DIR" \
    docker-compose*.yml \
    .env* \
    docker/ \
    scripts/ || true

# Create backup manifest
log "Creating backup manifest..."
cat > "$BACKUP_DIR/${BACKUP_NAME}_manifest.txt" << EOF
Backup Name: $BACKUP_NAME
Date: $(date)
Type: Pre-deployment backup
Database: ${BACKUP_NAME}_database.sql.gz
App Files: ${BACKUP_NAME}_app_files.tar.gz
Uploads: ${BACKUP_NAME}_uploads.tar.gz
Config: ${BACKUP_NAME}_config.tar.gz
Docker Images: ${BACKUP_NAME}_docker_images.txt
Git Commit: $(cd $APP_DIR && git rev-parse HEAD)
Git Branch: $(cd $APP_DIR && git branch --show-current)
Size: $(du -sh $BACKUP_DIR/${BACKUP_NAME}* | awk '{print $1}' | paste -sd+ | bc)MB
EOF

# Verify backups
log "Verifying backups..."
for file in "${BACKUP_NAME}_database.sql.gz" "${BACKUP_NAME}_app_files.tar.gz" "${BACKUP_NAME}_config.tar.gz"; do
    if [ -f "$BACKUP_DIR/$file" ]; then
        log "✓ $file - $(du -h $BACKUP_DIR/$file | cut -f1)"
    else
        error "✗ Missing: $file"
    fi
done

# Clean up old backups
log "Cleaning up old backups (older than $RETENTION_DAYS days)..."
find "$BACKUP_DIR" -name "pre_deploy_*" -type f -mtime +$RETENTION_DAYS -delete

# Calculate total backup size
total_size=$(du -sh "$BACKUP_DIR" | cut -f1)
log "Backup completed successfully!"
log "Backup location: $BACKUP_DIR"
log "Backup size: $total_size"
log "Backup name: $BACKUP_NAME"

# Optional: Upload to S3 or remote storage
if [ ! -z "$AWS_S3_BUCKET" ] && command -v aws &> /dev/null; then
    log "Uploading backup to S3..."
    aws s3 cp "$BACKUP_DIR/${BACKUP_NAME}"* "s3://$AWS_S3_BUCKET/backups/bazar/" --recursive
    log "Backup uploaded to S3: s3://$AWS_S3_BUCKET/backups/bazar/"
fi

log "Pre-deployment backup completed: $BACKUP_NAME"