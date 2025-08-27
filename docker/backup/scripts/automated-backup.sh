#!/bin/bash

# Automated backup script for Bazar Marketplace
# Supports encryption, compression, and multiple storage backends

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="/backup"
TEMP_DIR="/backup/temp"
KEYS_DIR="/backup/keys"
LOG_FILE="/var/log/backup/backup.log"
DATE=$(date +"%Y%m%d_%H%M%S")
BACKUP_NAME="bazar_${DATE}"
RETENTION_DAYS=${RETENTION_DAYS:-30}
COMPRESSION_LEVEL=${COMPRESSION_LEVEL:-6}

# Database configuration
DB_HOST=${DB_HOST:-mysql}
DB_NAME=${DB_NAME:-bazar_marketplace}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS}

# Storage configuration
S3_BUCKET=${S3_BUCKET}
S3_REGION=${S3_REGION:-us-east-1}
ENCRYPTION_KEY=${ENCRYPTION_KEY}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Logging functions
log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${timestamp} [${level}] ${message}" | tee -a "$LOG_FILE"
}

log_info() {
    log "INFO" "${GREEN}$*${NC}"
}

log_warn() {
    log "WARN" "${YELLOW}$*${NC}"
}

log_error() {
    log "ERROR" "${RED}$*${NC}"
}

log_debug() {
    if [ "${DEBUG:-0}" = "1" ]; then
        log "DEBUG" "${BLUE}$*${NC}"
    fi
}

# Cleanup function
cleanup() {
    local exit_code=$?
    log_info "Cleaning up temporary files..."
    rm -rf "${TEMP_DIR:?}"/*
    if [ $exit_code -ne 0 ]; then
        log_error "Backup failed with exit code $exit_code"
        send_notification "failure" "Backup failed: $(tail -n 5 "$LOG_FILE")"
    fi
    exit $exit_code
}

# Set trap for cleanup
trap cleanup EXIT INT TERM

# Notification function
send_notification() {
    local status=$1
    local message=$2
    
    # Slack webhook (if configured)
    if [ -n "${SLACK_WEBHOOK:-}" ]; then
        local color="good"
        local icon=":white_check_mark:"
        
        if [ "$status" = "failure" ]; then
            color="danger"
            icon=":x:"
        fi
        
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"attachments\":[{\"color\":\"$color\",\"text\":\"$icon Bazar Backup $status: $message\"}]}" \
            "$SLACK_WEBHOOK" || true
    fi
    
    # Email notification (if configured)
    if [ -n "${SMTP_SERVER:-}" ] && [ -n "${ALERT_EMAIL:-}" ]; then
        echo "Subject: Bazar Backup $status" | \
        echo "$message" | \
        sendmail -S "$SMTP_SERVER" "$ALERT_EMAIL" || true
    fi
}

# Encryption functions
encrypt_file() {
    local input_file=$1
    local output_file="${input_file}.enc"
    
    if [ -n "$ENCRYPTION_KEY" ]; then
        log_info "Encrypting $input_file..."
        openssl enc -aes-256-cbc -salt -in "$input_file" -out "$output_file" -k "$ENCRYPTION_KEY"
        rm "$input_file"
        echo "$output_file"
    else
        echo "$input_file"
    fi
}

decrypt_file() {
    local input_file=$1
    local output_file=${input_file%.enc}
    
    if [[ "$input_file" == *.enc ]]; then
        log_info "Decrypting $input_file..."
        openssl enc -aes-256-cbc -d -in "$input_file" -out "$output_file" -k "$ENCRYPTION_KEY"
        echo "$output_file"
    else
        echo "$input_file"
    fi
}

# Database backup function
backup_database() {
    log_info "Starting database backup..."
    
    local dump_file="$TEMP_DIR/${BACKUP_NAME}_database.sql"
    
    # Wait for database to be ready
    local retries=0
    while ! mysqladmin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --silent; do
        if [ $retries -ge 30 ]; then
            log_error "Database is not responding after 30 attempts"
            return 1
        fi
        log_info "Waiting for database to be ready... ($retries/30)"
        sleep 10
        ((retries++))
    done
    
    # Create database dump
    mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --create-options \
        --disable-keys \
        --extended-insert \
        --quick \
        --lock-tables=false \
        "$DB_NAME" > "$dump_file"
    
    if [ $? -eq 0 ]; then
        local size=$(du -h "$dump_file" | cut -f1)
        log_info "Database backup completed: $size"
        
        # Compress the dump
        log_info "Compressing database backup..."
        gzip -"$COMPRESSION_LEVEL" "$dump_file"
        dump_file="${dump_file}.gz"
        
        # Encrypt if encryption key is provided
        dump_file=$(encrypt_file "$dump_file")
        
        echo "$dump_file"
    else
        log_error "Database backup failed"
        return 1
    fi
}

# Files backup function
backup_files() {
    log_info "Starting files backup..."
    
    local files_archive="$TEMP_DIR/${BACKUP_NAME}_files.tar"
    
    # Backup application files
    tar -cf "$files_archive" \
        --exclude='logs/*' \
        --exclude='cache/*' \
        --exclude='temp/*' \
        --exclude='node_modules' \
        --exclude='.git' \
        --exclude='vendor' \
        -C /var/www/html \
        .
    
    if [ $? -eq 0 ]; then
        # Compress the archive
        log_info "Compressing files backup..."
        gzip -"$COMPRESSION_LEVEL" "$files_archive"
        files_archive="${files_archive}.gz"
        
        local size=$(du -h "$files_archive" | cut -f1)
        log_info "Files backup completed: $size"
        
        # Encrypt if encryption key is provided
        files_archive=$(encrypt_file "$files_archive")
        
        echo "$files_archive"
    else
        log_error "Files backup failed"
        return 1
    fi
}

# Uploads backup function
backup_uploads() {
    log_info "Starting uploads backup..."
    
    if [ ! -d "/var/www/html/uploads" ]; then
        log_warn "No uploads directory found, skipping uploads backup"
        return 0
    fi
    
    local uploads_archive="$TEMP_DIR/${BACKUP_NAME}_uploads.tar"
    
    tar -cf "$uploads_archive" \
        -C /var/www/html \
        uploads/
    
    if [ $? -eq 0 ]; then
        # Compress the archive
        log_info "Compressing uploads backup..."
        gzip -"$COMPRESSION_LEVEL" "$uploads_archive"
        uploads_archive="${uploads_archive}.gz"
        
        local size=$(du -h "$uploads_archive" | cut -f1)
        log_info "Uploads backup completed: $size"
        
        # Encrypt if encryption key is provided
        uploads_archive=$(encrypt_file "$uploads_archive")
        
        echo "$uploads_archive"
    else
        log_error "Uploads backup failed"
        return 1
    fi
}

# Upload to S3 function
upload_to_s3() {
    local file_path=$1
    local s3_key="backups/bazar/$(basename "$file_path")"
    
    if [ -n "$S3_BUCKET" ]; then
        log_info "Uploading $(basename "$file_path") to S3..."
        
        if aws s3 cp "$file_path" "s3://$S3_BUCKET/$s3_key" --region "$S3_REGION"; then
            log_info "Upload to S3 completed: s3://$S3_BUCKET/$s3_key"
            
            # Set lifecycle policy for automatic cleanup
            aws s3api put-object-tagging \
                --bucket "$S3_BUCKET" \
                --key "$s3_key" \
                --tagging "TagSet=[{Key=backup-type,Value=automated},{Key=retention-days,Value=$RETENTION_DAYS}]" \
                --region "$S3_REGION" || log_warn "Failed to set S3 tags"
        else
            log_error "Failed to upload to S3: $(basename "$file_path")"
            return 1
        fi
    else
        log_warn "S3_BUCKET not configured, skipping S3 upload"
    fi
}

# Cleanup old backups
cleanup_old_backups() {
    log_info "Cleaning up backups older than $RETENTION_DAYS days..."
    
    # Local cleanup
    find "$BACKUP_DIR" -name "bazar_*" -type f -mtime +"$RETENTION_DAYS" -delete || true
    
    # S3 cleanup (if configured)
    if [ -n "$S3_BUCKET" ]; then
        local cutoff_date=$(date -d "$RETENTION_DAYS days ago" +"%Y-%m-%d")
        aws s3api list-objects-v2 \
            --bucket "$S3_BUCKET" \
            --prefix "backups/bazar/" \
            --query "Contents[?LastModified<='$cutoff_date'].Key" \
            --output text \
            --region "$S3_REGION" | \
        while read -r key; do
            if [ -n "$key" ] && [ "$key" != "None" ]; then
                log_info "Deleting old S3 backup: $key"
                aws s3 rm "s3://$S3_BUCKET/$key" --region "$S3_REGION" || true
            fi
        done
    fi
}

# Generate backup manifest
generate_manifest() {
    local manifest_file="$TEMP_DIR/${BACKUP_NAME}_manifest.json"
    
    cat > "$manifest_file" << EOF
{
  "backup_name": "$BACKUP_NAME",
  "timestamp": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "type": "automated",
  "application": "bazar-marketplace",
  "database": {
    "host": "$DB_HOST",
    "name": "$DB_NAME"
  },
  "encryption": $([ -n "$ENCRYPTION_KEY" ] && echo "true" || echo "false"),
  "compression_level": $COMPRESSION_LEVEL,
  "retention_days": $RETENTION_DAYS,
  "files": []
EOF

    echo "$manifest_file"
}

# Main backup function
main() {
    log_info "Starting automated backup: $BACKUP_NAME"
    
    # Check prerequisites
    if [ -z "$DB_PASS" ]; then
        log_error "DB_PASS environment variable is required"
        exit 1
    fi
    
    # Create temp directory
    mkdir -p "$TEMP_DIR"
    
    # Generate manifest
    local manifest_file
    manifest_file=$(generate_manifest)
    
    local backup_files=()
    local total_size=0
    
    # Perform backups
    if db_file=$(backup_database); then
        backup_files+=("$db_file")
        total_size=$((total_size + $(stat -c%s "$db_file")))
    fi
    
    if files_file=$(backup_files); then
        backup_files+=("$files_file")
        total_size=$((total_size + $(stat -c%s "$files_file")))
    fi
    
    if uploads_file=$(backup_uploads); then
        backup_files+=("$uploads_file")
        total_size=$((total_size + $(stat -c%s "$uploads_file")))
    fi
    
    # Update manifest with file list
    local files_json=""
    for file in "${backup_files[@]}"; do
        local filename=$(basename "$file")
        local size=$(stat -c%s "$file")
        local checksum=$(sha256sum "$file" | cut -d' ' -f1)
        
        if [ -n "$files_json" ]; then
            files_json="$files_json,"
        fi
        
        files_json="$files_json{\"name\":\"$filename\",\"size\":$size,\"sha256\":\"$checksum\"}"
    done
    
    # Finalize manifest
    sed -i "s/\"files\": \[\]/\"files\": [$files_json]/g" "$manifest_file"
    
    # Add manifest to backup files
    backup_files+=("$manifest_file")
    
    # Upload files
    for file in "${backup_files[@]}"; do
        upload_to_s3 "$file"
    done
    
    # Move files to backup directory
    for file in "${backup_files[@]}"; do
        mv "$file" "$BACKUP_DIR/"
    done
    
    # Cleanup old backups
    cleanup_old_backups
    
    # Log summary
    local human_size=$(echo "$total_size" | awk '{printf "%.2f %s", $1/1024/1024/1024, "GB"}')
    log_info "Backup completed successfully!"
    log_info "Total size: $human_size"
    log_info "Files created: ${#backup_files[@]}"
    
    # Send success notification
    send_notification "success" "Backup completed. Size: $human_size, Files: ${#backup_files[@]}"
}

# Initialize logging
mkdir -p "$(dirname "$LOG_FILE")"
log_info "=== Backup started at $(date) ==="

# Run main function
main "$@"