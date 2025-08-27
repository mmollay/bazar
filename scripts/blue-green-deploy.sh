#!/bin/bash

# Blue-Green Deployment Script for Bazar Marketplace
# This script implements zero-downtime deployment

set -e

# Configuration
BLUE_COMPOSE_FILE="docker-compose.blue.yml"
GREEN_COMPOSE_FILE="docker-compose.green.yml"
PROD_COMPOSE_FILE="docker-compose.yml"
HEALTH_CHECK_URL="https://bazar.com/health"
SLEEP_TIME=30
MAX_RETRIES=10

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

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

check_health() {
    local url=$1
    local retries=$2
    
    for i in $(seq 1 $retries); do
        if curl -f -s "$url" > /dev/null; then
            log "Health check passed for $url"
            return 0
        fi
        log "Health check failed for $url (attempt $i/$retries)"
        sleep 5
    done
    return 1
}

get_current_deployment() {
    if docker-compose -f $BLUE_COMPOSE_FILE ps -q | grep -q .; then
        echo "blue"
    elif docker-compose -f $GREEN_COMPOSE_FILE ps -q | grep -q .; then
        echo "green"
    else
        echo "none"
    fi
}

switch_traffic() {
    local target=$1
    
    if [ "$target" = "blue" ]; then
        log "Switching traffic to blue deployment"
        cp $BLUE_COMPOSE_FILE $PROD_COMPOSE_FILE
    else
        log "Switching traffic to green deployment"
        cp $GREEN_COMPOSE_FILE $PROD_COMPOSE_FILE
    fi
    
    # Reload nginx configuration
    docker-compose exec nginx nginx -s reload
}

cleanup_old_deployment() {
    local old_deployment=$1
    
    if [ "$old_deployment" = "blue" ]; then
        log "Cleaning up blue deployment"
        docker-compose -f $BLUE_COMPOSE_FILE down --remove-orphans
    elif [ "$old_deployment" = "green" ]; then
        log "Cleaning up green deployment"
        docker-compose -f $GREEN_COMPOSE_FILE down --remove-orphans
    fi
}

main() {
    log "Starting blue-green deployment"
    
    # Determine current deployment
    current_deployment=$(get_current_deployment)
    log "Current deployment: $current_deployment"
    
    # Determine target deployment
    if [ "$current_deployment" = "blue" ] || [ "$current_deployment" = "none" ]; then
        target_deployment="green"
        target_compose_file=$GREEN_COMPOSE_FILE
    else
        target_deployment="blue"
        target_compose_file=$BLUE_COMPOSE_FILE
    fi
    
    log "Target deployment: $target_deployment"
    
    # Pull latest images
    log "Pulling latest Docker images"
    docker-compose -f $target_compose_file pull
    
    # Start target deployment
    log "Starting $target_deployment deployment"
    docker-compose -f $target_compose_file up -d --remove-orphans
    
    # Wait for services to be ready
    log "Waiting for services to be ready..."
    sleep $SLEEP_TIME
    
    # Health check on new deployment
    log "Performing health check on new deployment"
    if ! check_health "http://localhost:8080/health" $MAX_RETRIES; then
        error "Health check failed for new deployment. Rolling back..."
        docker-compose -f $target_compose_file down
        exit 1
    fi
    
    # Switch traffic to new deployment
    switch_traffic $target_deployment
    
    # Final health check on live traffic
    log "Performing final health check with live traffic"
    if ! check_health "$HEALTH_CHECK_URL" 5; then
        warn "Final health check failed. Consider manual verification."
    fi
    
    # Wait before cleanup to ensure stability
    log "Waiting for deployment stabilization..."
    sleep 60
    
    # Clean up old deployment
    if [ "$current_deployment" != "none" ]; then
        cleanup_old_deployment $current_deployment
    fi
    
    # Clean up unused images and containers
    log "Cleaning up unused Docker resources"
    docker system prune -f
    
    log "Blue-green deployment completed successfully!"
    log "Active deployment: $target_deployment"
}

# Trap to handle cleanup on script exit
trap 'error "Deployment script interrupted"' INT TERM

# Run main function
main "$@"