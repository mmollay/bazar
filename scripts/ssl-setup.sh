#!/bin/bash

# SSL/TLS Certificate Management Script for Bazar Marketplace
# Supports Let's Encrypt, self-signed certificates, and custom certificates

set -euo pipefail

# Configuration
DOMAIN=${DOMAIN:-"bazar.com"}
EMAIL=${LETSENCRYPT_EMAIL:-"admin@bazar.com"}
SSL_DIR="/etc/nginx/ssl"
LOG_FILE="/var/log/ssl-setup.log"
CERTBOT_DIR="/etc/letsencrypt"
RENEWAL_DAYS=${RENEWAL_DAYS:-30}

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Logging functions
log() {
    echo -e "$(date '+%Y-%m-%d %H:%M:%S') $*" | tee -a "$LOG_FILE"
}

log_info() {
    log "${GREEN}[INFO]${NC} $*"
}

log_warn() {
    log "${YELLOW}[WARN]${NC} $*"
}

log_error() {
    log "${RED}[ERROR]${NC} $*"
}

log_debug() {
    if [ "${DEBUG:-0}" = "1" ]; then
        log "${BLUE}[DEBUG]${NC} $*"
    fi
}

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_error "This script must be run as root"
        exit 1
    fi
}

# Install required packages
install_dependencies() {
    log_info "Installing SSL management dependencies..."
    
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update
        apt-get install -y certbot openssl curl cron
    elif command -v yum >/dev/null 2>&1; then
        yum install -y certbot openssl curl cronie
    elif command -v apk >/dev/null 2>&1; then
        apk add --no-cache certbot openssl curl dcron
    else
        log_error "Unsupported package manager. Please install certbot, openssl, and curl manually."
        exit 1
    fi
}

# Generate self-signed certificate
generate_self_signed() {
    local domain=$1
    local ssl_dir=$2
    
    log_info "Generating self-signed certificate for $domain..."
    
    mkdir -p "$ssl_dir"
    
    # Generate private key
    openssl genrsa -out "$ssl_dir/$domain.key" 2048
    
    # Generate certificate signing request
    openssl req -new -key "$ssl_dir/$domain.key" -out "$ssl_dir/$domain.csr" \
        -subj "/C=US/ST=State/L=City/O=Organization/OU=IT/CN=$domain"
    
    # Generate self-signed certificate
    openssl x509 -req -days 365 -in "$ssl_dir/$domain.csr" \
        -signkey "$ssl_dir/$domain.key" -out "$ssl_dir/$domain.crt" \
        -extensions v3_req -extfile <(
        cat << EOF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = US
ST = State
L = City
O = Organization
OU = IT
CN = $domain

[v3_req]
basicConstraints = CA:FALSE
keyUsage = nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names

[alt_names]
DNS.1 = $domain
DNS.2 = www.$domain
DNS.3 = api.$domain
DNS.4 = admin.$domain
EOF
    )
    
    # Create combined certificate file for HAProxy
    cat "$ssl_dir/$domain.crt" "$ssl_dir/$domain.key" > "$ssl_dir/$domain.pem"
    
    # Set proper permissions
    chmod 600 "$ssl_dir"/*.key "$ssl_dir"/*.pem
    chmod 644 "$ssl_dir"/*.crt
    
    # Clean up CSR
    rm "$ssl_dir/$domain.csr"
    
    log_info "Self-signed certificate generated successfully"
}

# Request Let's Encrypt certificate
request_letsencrypt() {
    local domain=$1
    local email=$2
    local ssl_dir=$3
    
    log_info "Requesting Let's Encrypt certificate for $domain..."
    
    # Stop nginx temporarily for standalone authentication
    if systemctl is-active --quiet nginx; then
        log_info "Stopping nginx for certificate validation..."
        systemctl stop nginx
        local restart_nginx=true
    fi
    
    # Request certificate using standalone authenticator
    if certbot certonly --standalone \
        --agree-tos \
        --no-eff-email \
        --email "$email" \
        -d "$domain" \
        -d "www.$domain" \
        -d "api.$domain" \
        -d "admin.$domain" \
        --non-interactive; then
        
        log_info "Let's Encrypt certificate obtained successfully"
        
        # Copy certificates to nginx SSL directory
        cp "$CERTBOT_DIR/live/$domain/fullchain.pem" "$ssl_dir/$domain.crt"
        cp "$CERTBOT_DIR/live/$domain/privkey.pem" "$ssl_dir/$domain.key"
        
        # Create combined certificate file for HAProxy
        cat "$CERTBOT_DIR/live/$domain/fullchain.pem" \
            "$CERTBOT_DIR/live/$domain/privkey.pem" > "$ssl_dir/$domain.pem"
        
        # Set proper permissions
        chmod 600 "$ssl_dir"/*.key "$ssl_dir"/*.pem
        chmod 644 "$ssl_dir"/*.crt
        
        # Setup automatic renewal
        setup_auto_renewal
        
    else
        log_error "Failed to obtain Let's Encrypt certificate"
        
        # Generate self-signed as fallback
        log_warn "Generating self-signed certificate as fallback..."
        generate_self_signed "$domain" "$ssl_dir"
    fi
    
    # Restart nginx if it was running
    if [ "${restart_nginx:-false}" = "true" ]; then
        log_info "Restarting nginx..."
        systemctl start nginx
    fi
}

# Setup automatic certificate renewal
setup_auto_renewal() {
    log_info "Setting up automatic certificate renewal..."
    
    # Create renewal script
    cat > /usr/local/bin/renew-certificates.sh << 'EOF'
#!/bin/bash

# Certificate renewal script
LOG_FILE="/var/log/ssl-renewal.log"
DATE=$(date '+%Y-%m-%d %H:%M:%S')

log() {
    echo "$DATE $*" >> "$LOG_FILE"
}

log "Starting certificate renewal check..."

# Renew certificates
if certbot renew --quiet --deploy-hook "systemctl reload nginx"; then
    log "Certificate renewal check completed successfully"
else
    log "Certificate renewal failed"
    # Send alert (implement notification mechanism)
fi

# Update HAProxy certificates
for cert_dir in /etc/letsencrypt/live/*/; do
    domain=$(basename "$cert_dir")
    if [ -f "$cert_dir/fullchain.pem" ] && [ -f "$cert_dir/privkey.pem" ]; then
        cat "$cert_dir/fullchain.pem" "$cert_dir/privkey.pem" > "/etc/nginx/ssl/$domain.pem"
        chmod 600 "/etc/nginx/ssl/$domain.pem"
    fi
done

# Reload HAProxy if running in Docker
if docker ps | grep -q haproxy; then
    docker exec bazar_haproxy haproxy -c -f /usr/local/etc/haproxy/haproxy.cfg
    if [ $? -eq 0 ]; then
        docker kill -s HUP bazar_haproxy
        log "HAProxy configuration reloaded"
    fi
fi

log "Certificate renewal process completed"
EOF

    chmod +x /usr/local/bin/renew-certificates.sh
    
    # Add cron job for automatic renewal (daily check)
    (crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/renew-certificates.sh") | crontab -
    
    log_info "Automatic renewal configured (daily at 2 AM)"
}

# Check certificate expiration
check_certificate() {
    local cert_file=$1
    
    if [ ! -f "$cert_file" ]; then
        log_error "Certificate file not found: $cert_file"
        return 1
    fi
    
    local expiry_date
    expiry_date=$(openssl x509 -enddate -noout -in "$cert_file" | cut -d= -f2)
    local expiry_epoch
    expiry_epoch=$(date -d "$expiry_date" +%s)
    local current_epoch
    current_epoch=$(date +%s)
    local days_until_expiry
    days_until_expiry=$(( (expiry_epoch - current_epoch) / 86400 ))
    
    log_info "Certificate expires in $days_until_expiry days ($expiry_date)"
    
    if [ $days_until_expiry -lt $RENEWAL_DAYS ]; then
        log_warn "Certificate expires soon! Consider renewal."
        return 1
    fi
    
    return 0
}

# Validate certificate configuration
validate_certificate() {
    local domain=$1
    local ssl_dir=$2
    
    log_info "Validating certificate configuration for $domain..."
    
    # Check if certificate files exist
    if [ ! -f "$ssl_dir/$domain.crt" ] || [ ! -f "$ssl_dir/$domain.key" ]; then
        log_error "Certificate files missing for $domain"
        return 1
    fi
    
    # Validate certificate and key match
    local cert_md5
    cert_md5=$(openssl x509 -noout -modulus -in "$ssl_dir/$domain.crt" | openssl md5)
    local key_md5
    key_md5=$(openssl rsa -noout -modulus -in "$ssl_dir/$domain.key" | openssl md5)
    
    if [ "$cert_md5" != "$key_md5" ]; then
        log_error "Certificate and private key do not match!"
        return 1
    fi
    
    # Check certificate expiration
    check_certificate "$ssl_dir/$domain.crt"
    
    log_info "Certificate validation completed successfully"
}

# Test SSL configuration
test_ssl_config() {
    local domain=$1
    
    log_info "Testing SSL configuration for $domain..."
    
    # Test with curl
    if curl -Is "https://$domain/health" | head -n 1 | grep -q "200 OK"; then
        log_info "SSL endpoint is responding correctly"
    else
        log_warn "SSL endpoint test failed or health endpoint not available"
    fi
    
    # Test SSL certificate with openssl
    if echo | openssl s_client -servername "$domain" -connect "$domain:443" 2>/dev/null | \
       openssl x509 -noout -dates; then
        log_info "SSL certificate is valid and accessible"
    else
        log_warn "SSL certificate test failed"
    fi
}

# Create security-related directories
setup_security_dirs() {
    log_info "Setting up security directories..."
    
    # Create SSL directory
    mkdir -p "$SSL_DIR"
    chmod 755 "$SSL_DIR"
    
    # Create log directory
    mkdir -p "$(dirname "$LOG_FILE")"
    
    # Create certbot directory
    mkdir -p "$CERTBOT_DIR"
}

# Generate Diffie-Hellman parameters
generate_dhparam() {
    local dh_file="$SSL_DIR/dhparam.pem"
    
    if [ ! -f "$dh_file" ]; then
        log_info "Generating Diffie-Hellman parameters (this may take a while)..."
        openssl dhparam -out "$dh_file" 2048
        chmod 644 "$dh_file"
        log_info "Diffie-Hellman parameters generated"
    else
        log_info "Diffie-Hellman parameters already exist"
    fi
}

# Security hardening
apply_security_hardening() {
    log_info "Applying security hardening..."
    
    # Set secure file permissions
    find "$SSL_DIR" -name "*.key" -exec chmod 600 {} \;
    find "$SSL_DIR" -name "*.pem" -exec chmod 600 {} \;
    find "$SSL_DIR" -name "*.crt" -exec chmod 644 {} \;
    
    # Create security configuration for nginx
    cat > /etc/nginx/conf.d/security.conf << 'EOF'
# Security headers and configurations

# Hide nginx version
server_tokens off;

# Security headers
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self';" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

# Rate limiting
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
limit_req_zone $binary_remote_addr zone=api:10m rate=100r/m;
limit_req_zone $binary_remote_addr zone=general:10m rate=1000r/m;

# Connection limiting
limit_conn_zone $binary_remote_addr zone=perip:10m;
limit_conn_zone $server_name zone=perserver:10m;
EOF
    
    log_info "Security hardening completed"
}

# Main function
main() {
    local action=${1:-"setup"}
    
    log_info "Starting SSL management script: $action"
    
    case $action in
        "setup")
            check_root
            install_dependencies
            setup_security_dirs
            generate_dhparam
            
            if [ "${USE_LETSENCRYPT:-true}" = "true" ] && [ -n "${DOMAIN:-}" ]; then
                request_letsencrypt "$DOMAIN" "$EMAIL" "$SSL_DIR"
            else
                generate_self_signed "$DOMAIN" "$SSL_DIR"
            fi
            
            validate_certificate "$DOMAIN" "$SSL_DIR"
            apply_security_hardening
            ;;
            
        "renew")
            check_root
            log_info "Renewing certificates..."
            certbot renew --quiet
            systemctl reload nginx || docker exec bazar_nginx nginx -s reload
            ;;
            
        "check")
            validate_certificate "$DOMAIN" "$SSL_DIR"
            test_ssl_config "$DOMAIN"
            ;;
            
        "self-signed")
            check_root
            setup_security_dirs
            generate_dhparam
            generate_self_signed "$DOMAIN" "$SSL_DIR"
            validate_certificate "$DOMAIN" "$SSL_DIR"
            apply_security_hardening
            ;;
            
        *)
            echo "Usage: $0 {setup|renew|check|self-signed}"
            echo "  setup       - Complete SSL setup (Let's Encrypt or self-signed)"
            echo "  renew       - Renew existing certificates"
            echo "  check       - Validate current certificate configuration"
            echo "  self-signed - Generate self-signed certificates"
            exit 1
            ;;
    esac
    
    log_info "SSL management script completed: $action"
}

# Initialize logging
mkdir -p "$(dirname "$LOG_FILE")"
log_info "=== SSL Setup started at $(date) ==="

# Run main function
main "$@"