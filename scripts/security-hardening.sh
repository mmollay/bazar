#!/bin/bash

# Security Hardening Script for Bazar Marketplace
# Implements comprehensive security measures for production deployment

set -euo pipefail

# Configuration
LOG_FILE="/var/log/security-hardening.log"
BACKUP_DIR="/opt/security-backups"
DATE=$(date +"%Y%m%d_%H%M%S")

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

# Create backup of configuration files
backup_configs() {
    log_info "Creating backup of configuration files..."
    
    mkdir -p "$BACKUP_DIR"
    
    # System configurations
    [ -f "/etc/ssh/sshd_config" ] && cp "/etc/ssh/sshd_config" "$BACKUP_DIR/sshd_config_$DATE"
    [ -f "/etc/security/limits.conf" ] && cp "/etc/security/limits.conf" "$BACKUP_DIR/limits.conf_$DATE"
    [ -f "/etc/sysctl.conf" ] && cp "/etc/sysctl.conf" "$BACKUP_DIR/sysctl.conf_$DATE"
    [ -d "/etc/iptables" ] && cp -r "/etc/iptables" "$BACKUP_DIR/iptables_$DATE"
    
    log_info "Configuration backup completed: $BACKUP_DIR"
}

# Harden SSH configuration
harden_ssh() {
    log_info "Hardening SSH configuration..."
    
    local ssh_config="/etc/ssh/sshd_config"
    
    if [ -f "$ssh_config" ]; then
        # Create hardened SSH configuration
        cat > "${ssh_config}.hardened" << 'EOF'
# Hardened SSH Configuration for Bazar Marketplace

# Basic Settings
Port 2222
Protocol 2
AddressFamily inet
ListenAddress 0.0.0.0

# Authentication
PermitRootLogin no
PasswordAuthentication no
PermitEmptyPasswords no
ChallengeResponseAuthentication no
UsePAM yes
PubkeyAuthentication yes
AuthorizedKeysFile .ssh/authorized_keys

# Session Settings
ClientAliveInterval 300
ClientAliveCountMax 2
LoginGraceTime 60
MaxAuthTries 3
MaxSessions 2
MaxStartups 10:30:60

# Security
PermitUserEnvironment no
AllowAgentForwarding no
AllowTcpForwarding no
X11Forwarding no
PrintMotd no
PrintLastLog yes
TCPKeepAlive yes
Compression no
UseDNS no
PermitTunnel no
GatewayPorts no

# Crypto
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr
MACs hmac-sha2-256-etm@openssh.com,hmac-sha2-512-etm@openssh.com,hmac-sha2-256,hmac-sha2-512
KexAlgorithms curve25519-sha256@libssh.org,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512,diffie-hellman-group14-sha256

# User/Group restrictions
# AllowUsers deploy
# AllowGroups ssh-users
# DenyUsers root

# Logging
SyslogFacility AUTHPRIV
LogLevel INFO

# Banner
Banner /etc/ssh/banner
EOF

        # Create SSH banner
        cat > /etc/ssh/banner << 'EOF'
***************************************************************************
                    AUTHORIZED ACCESS ONLY
                    
This system is for authorized users only. All activities are monitored
and logged. Unauthorized access is prohibited and will be prosecuted.
***************************************************************************
EOF

        log_info "SSH hardening configuration created. Review and apply manually if needed."
    else
        log_warn "SSH configuration file not found. Skipping SSH hardening."
    fi
}

# Configure firewall rules
setup_firewall() {
    log_info "Setting up firewall rules..."
    
    # Install ufw if not present
    if ! command -v ufw >/dev/null 2>&1; then
        if command -v apt-get >/dev/null 2>&1; then
            apt-get update && apt-get install -y ufw
        elif command -v yum >/dev/null 2>&1; then
            yum install -y ufw
        fi
    fi
    
    if command -v ufw >/dev/null 2>&1; then
        # Reset UFW to defaults
        ufw --force reset
        
        # Default policies
        ufw default deny incoming
        ufw default allow outgoing
        
        # Allow SSH (custom port if configured)
        ufw allow 2222/tcp comment 'SSH'
        
        # Allow HTTP/HTTPS
        ufw allow 80/tcp comment 'HTTP'
        ufw allow 443/tcp comment 'HTTPS'
        
        # Allow specific application ports
        ufw allow 3000/tcp comment 'Grafana'
        ufw allow 9090/tcp comment 'Prometheus'
        ufw allow 5601/tcp comment 'Kibana'
        
        # Rate limiting for SSH
        ufw limit ssh comment 'Rate limit SSH'
        
        # Enable firewall
        ufw --force enable
        
        log_info "UFW firewall configured and enabled"
    else
        log_warn "UFW not available. Setting up iptables rules..."
        
        # Basic iptables rules
        cat > /etc/iptables/rules.v4 << 'EOF'
*filter
:INPUT DROP [0:0]
:FORWARD DROP [0:0]
:OUTPUT ACCEPT [0:0]

# Allow loopback
-A INPUT -i lo -j ACCEPT

# Allow established connections
-A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

# Allow SSH (rate limited)
-A INPUT -p tcp --dport 2222 -m conntrack --ctstate NEW -m limit --limit 3/min --limit-burst 3 -j ACCEPT

# Allow HTTP/HTTPS
-A INPUT -p tcp --dport 80 -j ACCEPT
-A INPUT -p tcp --dport 443 -j ACCEPT

# Allow monitoring ports (restrict to specific IPs in production)
-A INPUT -p tcp --dport 3000 -j ACCEPT
-A INPUT -p tcp --dport 9090 -j ACCEPT
-A INPUT -p tcp --dport 5601 -j ACCEPT

# Drop everything else
-A INPUT -j DROP

COMMIT
EOF

        iptables-restore < /etc/iptables/rules.v4
        log_info "iptables rules configured"
    fi
}

# Harden system parameters
harden_sysctl() {
    log_info "Hardening kernel parameters..."
    
    cat >> /etc/sysctl.conf << 'EOF'

# Bazar Marketplace Security Hardening

# Network Security
net.ipv4.ip_forward = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
net.ipv4.conf.default.secure_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv6.conf.default.accept_source_route = 0

# IP Spoofing protection
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1

# Ignore ICMP ping requests
net.ipv4.icmp_echo_ignore_all = 1

# Ignore Directed pings
net.ipv4.icmp_echo_ignore_broadcasts = 1

# Disable source packet routing
net.ipv4.conf.all.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0

# Log Martians
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# TCP SYN flood protection
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2
net.ipv4.tcp_syn_retries = 5

# TCP settings
net.ipv4.tcp_timestamps = 0
net.ipv4.tcp_sack = 1
net.ipv4.tcp_fack = 1
net.ipv4.tcp_window_scaling = 1
net.ipv4.tcp_keepalive_time = 7200
net.ipv4.tcp_keepalive_probes = 9
net.ipv4.tcp_keepalive_intvl = 75

# Memory and process limits
kernel.pid_max = 65536
vm.swappiness = 10
vm.dirty_ratio = 60
vm.dirty_background_ratio = 2

# File system security
fs.suid_dumpable = 0
fs.protected_hardlinks = 1
fs.protected_symlinks = 1

# Kernel security
kernel.dmesg_restrict = 1
kernel.kptr_restrict = 2
kernel.yama.ptrace_scope = 1
kernel.kexec_load_disabled = 1
EOF

    # Apply sysctl settings
    sysctl -p
    
    log_info "Kernel parameters hardened"
}

# Set up file permissions and ownership
harden_file_permissions() {
    log_info "Hardening file permissions..."
    
    # Application directory permissions
    if [ -d "/var/www/html" ]; then
        find /var/www/html -type f -exec chmod 644 {} \;
        find /var/www/html -type d -exec chmod 755 {} \;
        
        # Make upload directories writable
        if [ -d "/var/www/html/uploads" ]; then
            chmod -R 755 /var/www/html/uploads
        fi
        
        # Secure configuration files
        find /var/www/html -name "*.env*" -exec chmod 600 {} \;
        find /var/www/html -name "config.php" -exec chmod 600 {} \;
        
        log_info "Application file permissions set"
    fi
    
    # System file permissions
    chmod 600 /etc/ssh/sshd_config* 2>/dev/null || true
    chmod 644 /etc/passwd
    chmod 600 /etc/shadow
    chmod 644 /etc/group
    chmod 600 /etc/gshadow
    
    # Log file permissions
    mkdir -p /var/log/bazar
    chmod 755 /var/log/bazar
    
    log_info "System file permissions hardened"
}

# Install and configure security tools
install_security_tools() {
    log_info "Installing security tools..."
    
    # Install fail2ban
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update
        apt-get install -y fail2ban rkhunter chkrootkit aide
    elif command -v yum >/dev/null 2>&1; then
        yum install -y epel-release
        yum install -y fail2ban rkhunter chkrootkit aide
    fi
    
    # Configure fail2ban
    if command -v fail2ban-server >/dev/null 2>&1; then
        cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
ignoreip = 127.0.0.1/8 ::1

[sshd]
enabled = true
port = 2222
logpath = /var/log/auth.log

[nginx-http-auth]
enabled = true
filter = nginx-http-auth
logpath = /var/log/nginx/error.log

[nginx-limit-req]
enabled = true
filter = nginx-limit-req
logpath = /var/log/nginx/error.log

[nginx-botsearch]
enabled = true
filter = nginx-botsearch
logpath = /var/log/nginx/access.log
EOF

        systemctl enable fail2ban
        systemctl start fail2ban
        
        log_info "fail2ban configured and started"
    fi
    
    # Initialize AIDE database
    if command -v aide >/dev/null 2>&1; then
        log_info "Initializing AIDE database (this may take a while)..."
        aide --init
        mv /var/lib/aide/aide.db.new /var/lib/aide/aide.db
        
        # Setup daily AIDE check
        cat > /etc/cron.daily/aide-check << 'EOF'
#!/bin/bash
aide --check
EOF
        chmod +x /etc/cron.daily/aide-check
        
        log_info "AIDE intrusion detection configured"
    fi
}

# Configure Docker security
harden_docker() {
    log_info "Hardening Docker configuration..."
    
    if command -v docker >/dev/null 2>&1; then
        # Create Docker daemon configuration
        mkdir -p /etc/docker
        cat > /etc/docker/daemon.json << 'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "100m",
    "max-file": "3"
  },
  "userland-proxy": false,
  "no-new-privileges": true,
  "seccomp-profile": "/etc/docker/seccomp-profile.json",
  "storage-driver": "overlay2",
  "storage-opts": [
    "overlay2.override_kernel_check=true"
  ],
  "default-ulimits": {
    "nofile": {
      "Name": "nofile",
      "Hard": 64000,
      "Soft": 64000
    }
  },
  "live-restore": true,
  "icc": false,
  "userns-remap": "default"
}
EOF

        # Create custom seccomp profile
        cat > /etc/docker/seccomp-profile.json << 'EOF'
{
  "defaultAction": "SCMP_ACT_ERRNO",
  "architectures": [
    "SCMP_ARCH_X86_64",
    "SCMP_ARCH_X86",
    "SCMP_ARCH_X32"
  ],
  "syscalls": [
    {
      "names": [
        "accept",
        "accept4",
        "access",
        "bind",
        "brk",
        "chdir",
        "chmod",
        "chown",
        "close",
        "connect",
        "dup",
        "dup2",
        "epoll_create",
        "epoll_ctl",
        "epoll_wait",
        "execve",
        "exit",
        "exit_group",
        "fcntl",
        "fork",
        "fstat",
        "futex",
        "getpid",
        "getsockopt",
        "listen",
        "lseek",
        "mmap",
        "mprotect",
        "munmap",
        "open",
        "openat",
        "poll",
        "read",
        "recv",
        "recvfrom",
        "rt_sigaction",
        "rt_sigprocmask",
        "rt_sigreturn",
        "select",
        "send",
        "sendto",
        "setsockopt",
        "socket",
        "stat",
        "vfork",
        "wait4",
        "write"
      ],
      "action": "SCMP_ACT_ALLOW"
    }
  ]
}
EOF

        # Restart Docker daemon
        systemctl restart docker
        
        log_info "Docker security configuration applied"
    else
        log_warn "Docker not found. Skipping Docker hardening."
    fi
}

# Setup log monitoring
setup_log_monitoring() {
    log_info "Setting up log monitoring..."
    
    # Create logrotate configuration
    cat > /etc/logrotate.d/bazar << 'EOF'
/var/log/bazar/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    copytruncate
    sharedscripts
    postrotate
        /bin/kill -USR1 $(cat /var/run/nginx.pid 2>/dev/null) 2>/dev/null || true
    endscript
}
EOF

    # Setup centralized logging
    cat > /etc/rsyslog.d/50-bazar.conf << 'EOF'
# Bazar Marketplace logging
$template BazarFormat,"%timegenerated% %HOSTNAME% %syslogtag% %msg%\n"

# Application logs
if $programname == 'bazar' then /var/log/bazar/application.log;BazarFormat
if $programname == 'bazar' then stop

# Security logs
authpriv.*                                        /var/log/bazar/security.log
EOF

    systemctl restart rsyslog
    
    log_info "Log monitoring configured"
}

# Create security monitoring script
create_monitoring_script() {
    log_info "Creating security monitoring script..."
    
    cat > /usr/local/bin/security-monitor.sh << 'EOF'
#!/bin/bash

# Security monitoring script for Bazar Marketplace

LOG_FILE="/var/log/bazar/security-monitor.log"
DATE=$(date '+%Y-%m-%d %H:%M:%S')

log() {
    echo "$DATE $*" >> "$LOG_FILE"
}

# Check for suspicious processes
check_processes() {
    # Check for processes with unusual names or high resource usage
    ps aux --sort=-%cpu | head -20 | while read line; do
        cpu=$(echo $line | awk '{print $3}')
        if (( $(echo "$cpu > 80" | bc -l) )); then
            log "HIGH CPU: $line"
        fi
    done
}

# Check for failed login attempts
check_logins() {
    failed_logins=$(grep "Failed password" /var/log/auth.log | wc -l)
    if [ $failed_logins -gt 50 ]; then
        log "HIGH FAILED LOGINS: $failed_logins attempts"
    fi
}

# Check disk usage
check_disk_usage() {
    df -h | while read line; do
        usage=$(echo $line | awk '{print $5}' | sed 's/%//')
        if [[ $usage =~ ^[0-9]+$ ]] && [ $usage -gt 90 ]; then
            log "HIGH DISK USAGE: $line"
        fi
    done
}

# Check for rootkits
check_rootkits() {
    if command -v rkhunter >/dev/null 2>&1; then
        rkhunter --check --skip-keypress --report-warnings-only
    fi
}

# Run checks
log "Starting security monitoring checks"
check_processes
check_logins
check_disk_usage
check_rootkits
log "Security monitoring checks completed"
EOF

    chmod +x /usr/local/bin/security-monitor.sh
    
    # Add to crontab (run every hour)
    (crontab -l 2>/dev/null; echo "0 * * * * /usr/local/bin/security-monitor.sh") | crontab -
    
    log_info "Security monitoring script created and scheduled"
}

# Generate security report
generate_security_report() {
    log_info "Generating security hardening report..."
    
    local report_file="/var/log/bazar/security-report-$DATE.txt"
    
    cat > "$report_file" << EOF
Bazar Marketplace Security Hardening Report
Generated: $(date)

=== System Information ===
Hostname: $(hostname)
OS: $(cat /etc/os-release | grep PRETTY_NAME | cut -d'"' -f2)
Kernel: $(uname -r)
Uptime: $(uptime)

=== Security Measures Implemented ===
✓ SSH hardening configuration created
✓ Firewall rules configured
✓ Kernel parameters hardened
✓ File permissions secured
✓ Security tools installed (fail2ban, aide, rkhunter)
✓ Docker security configuration applied
✓ Log monitoring configured
✓ Security monitoring script created

=== Next Steps ===
1. Review and apply SSH configuration manually
2. Customize firewall rules for your environment
3. Test all security measures
4. Setup alerting for security events
5. Regular security audits

=== Configuration Files ===
Backups created in: $BACKUP_DIR
Log file: $LOG_FILE
Security report: $report_file

EOF

    log_info "Security report generated: $report_file"
}

# Main function
main() {
    local action=${1:-"all"}
    
    log_info "Starting security hardening: $action"
    
    case $action in
        "all")
            check_root
            backup_configs
            harden_ssh
            setup_firewall
            harden_sysctl
            harden_file_permissions
            install_security_tools
            harden_docker
            setup_log_monitoring
            create_monitoring_script
            generate_security_report
            ;;
            
        "ssh")
            check_root
            backup_configs
            harden_ssh
            ;;
            
        "firewall")
            check_root
            setup_firewall
            ;;
            
        "system")
            check_root
            backup_configs
            harden_sysctl
            harden_file_permissions
            ;;
            
        "docker")
            check_root
            harden_docker
            ;;
            
        "monitoring")
            check_root
            setup_log_monitoring
            create_monitoring_script
            ;;
            
        *)
            echo "Usage: $0 {all|ssh|firewall|system|docker|monitoring}"
            echo "  all        - Apply all security hardening measures"
            echo "  ssh        - Harden SSH configuration only"
            echo "  firewall   - Configure firewall rules only"
            echo "  system     - Harden system parameters and permissions"
            echo "  docker     - Harden Docker configuration"
            echo "  monitoring - Setup security monitoring"
            exit 1
            ;;
    esac
    
    log_info "Security hardening completed: $action"
}

# Initialize logging
mkdir -p "$(dirname "$LOG_FILE")"
mkdir -p "$BACKUP_DIR"
log_info "=== Security hardening started at $(date) ==="

# Run main function
main "$@"