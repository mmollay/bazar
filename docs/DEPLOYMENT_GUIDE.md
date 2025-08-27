# Bazar Marketplace - Production Deployment Guide

This comprehensive guide covers the complete production deployment of the Bazar Marketplace application with high availability, security, and scalability.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Infrastructure Setup](#infrastructure-setup)
3. [Security Hardening](#security-hardening)
4. [Application Deployment](#application-deployment)
5. [Monitoring & Logging](#monitoring--logging)
6. [Backup & Recovery](#backup--recovery)
7. [Scaling & Load Balancing](#scaling--load-balancing)
8. [Maintenance & Operations](#maintenance--operations)
9. [Troubleshooting](#troubleshooting)
10. [Security Checklist](#security-checklist)

## Prerequisites

### System Requirements

**Minimum Production Environment:**
- CPU: 4 cores
- RAM: 8GB
- Storage: 100GB SSD
- Network: 1Gbps
- OS: Ubuntu 20.04 LTS or CentOS 8

**Recommended Production Environment:**
- CPU: 8 cores
- RAM: 16GB
- Storage: 500GB NVMe SSD
- Network: 10Gbps
- OS: Ubuntu 22.04 LTS

### Software Dependencies

- Docker 24.0+
- Docker Compose 2.0+
- Git
- OpenSSL
- Certbot (for Let's Encrypt)
- AWS CLI (for S3 backups)

### Domain and SSL Requirements

- Registered domain name
- DNS configuration access
- SSL certificate (Let's Encrypt recommended)

## Infrastructure Setup

### 1. Server Preparation

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y curl wget git unzip software-properties-common

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

### 2. Clone Repository

```bash
# Clone the repository
git clone https://github.com/your-org/bazar-marketplace.git /opt/bazar-production
cd /opt/bazar-production

# Set proper permissions
sudo chown -R $USER:$USER /opt/bazar-production
```

### 3. Environment Configuration

```bash
# Create production environment file
cp .env.example .env.production

# Edit environment variables
nano .env.production
```

**Required Environment Variables:**

```bash
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
JWT_SECRET=your-jwt-secret-key

# Database
DB_HOST=mysql-master
DB_NAME=bazar_marketplace
DB_USER=bazar_user
DB_PASS=secure-password
MYSQL_ROOT_PASSWORD=root-password
REPLICATION_PASSWORD=replication-password

# Redis
REDIS_HOST=redis-cluster
REDIS_PORT=6379

# Mail
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USER=your-email@domain.com
MAIL_PASS=your-app-password

# Backup
S3_BUCKET=your-backup-bucket
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
BACKUP_ENCRYPTION_KEY=backup-encryption-key

# Monitoring
GRAFANA_PASSWORD=secure-grafana-password
SLACK_WEBHOOK=your-slack-webhook-url
ALERT_EMAIL=admin@yourdomain.com

# SSL
DOMAIN=yourdomain.com
LETSENCRYPT_EMAIL=admin@yourdomain.com
```

## Security Hardening

### 1. Run Security Hardening Script

```bash
# Make scripts executable
chmod +x scripts/*.sh

# Run comprehensive security hardening
sudo ./scripts/security-hardening.sh all

# Review generated security report
cat /var/log/bazar/security-report-*.txt
```

### 2. Configure SSL Certificates

```bash
# Setup SSL certificates (Let's Encrypt)
sudo ./scripts/ssl-setup.sh setup

# Or generate self-signed certificates for testing
sudo ./scripts/ssl-setup.sh self-signed
```

### 3. Firewall Configuration

```bash
# Configure UFW firewall
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
```

## Application Deployment

### 1. Production Deployment

```bash
# Build and start all services
docker-compose -f docker-compose.yml --env-file .env.production up -d

# Wait for services to be ready
sleep 60

# Run database migrations
docker-compose exec app php backend/cli/migrate.php

# Verify deployment
docker-compose ps
curl -I https://yourdomain.com/health
```

### 2. Scaling Deployment

For high-traffic environments:

```bash
# Use scaling configuration
docker-compose -f docker-compose.yml -f docker-compose.scaling.yml --env-file .env.production up -d

# Scale application instances
docker-compose up -d --scale app=3 --scale queue-worker=2
```

### 3. Load Balancer Setup

```bash
# Start HAProxy load balancer
docker-compose -f docker-compose.scaling.yml up -d haproxy

# Check HAProxy stats
curl http://admin:password@localhost:8404/stats
```

## Monitoring & Logging

### 1. Start Monitoring Stack

```bash
# Start monitoring services
docker-compose --profile monitoring up -d

# Access Grafana dashboard
echo "Grafana: https://yourdomain.com:3000"
echo "Username: admin"
echo "Password: $GRAFANA_PASSWORD"

# Access Prometheus
echo "Prometheus: https://yourdomain.com:9090"
```

### 2. Configure Logging

```bash
# Start logging stack
docker-compose --profile logging up -d

# Access Kibana dashboard
echo "Kibana: https://yourdomain.com:5601"
```

### 3. Set Up Alerts

```bash
# Configure alert rules
cp docker/prometheus/alerts/bazar-alerts.yml docker/prometheus/alerts/

# Restart Prometheus
docker-compose restart prometheus
```

## Backup & Recovery

### 1. Configure Automated Backups

```bash
# Start backup service
docker-compose up -d backup

# Test backup manually
docker-compose exec backup /backup/scripts/automated-backup.sh

# Verify backup files
aws s3 ls s3://your-backup-bucket/backups/bazar/
```

### 2. Restore from Backup

```bash
# Download backup files
aws s3 cp s3://your-backup-bucket/backups/bazar/ /tmp/restore/ --recursive

# Restore database
gunzip /tmp/restore/bazar_*_database.sql.gz
docker-compose exec mysql mysql -u root -p$MYSQL_ROOT_PASSWORD $DB_NAME < /tmp/restore/bazar_*_database.sql

# Restore files
tar -xzf /tmp/restore/bazar_*_files.tar.gz -C /opt/bazar-production/
```

## Scaling & Load Balancing

### 1. Horizontal Scaling

```bash
# Scale web application
docker-compose up -d --scale app=5

# Scale queue workers
docker-compose up -d --scale queue-worker=3

# Verify scaling
docker-compose ps app
```

### 2. Database Scaling

```bash
# Start MySQL master-slave replication
docker-compose -f docker-compose.scaling.yml up -d mysql-master mysql-slave

# Configure read/write split in application
# Update database configuration to use mysql-slave for read operations
```

### 3. Auto-scaling

```bash
# Start auto-scaler service
docker-compose -f docker-compose.scaling.yml up -d autoscaler

# Monitor auto-scaling logs
docker-compose logs -f autoscaler
```

## Maintenance & Operations

### 1. Health Checks

```bash
#!/bin/bash
# Health check script

# Application health
curl -f https://yourdomain.com/health || echo "Application down!"

# Database health
docker-compose exec mysql mysqladmin ping -u root -p$MYSQL_ROOT_PASSWORD || echo "Database down!"

# Redis health
docker-compose exec redis redis-cli ping || echo "Redis down!"
```

### 2. Log Rotation

```bash
# Configure logrotate
sudo tee /etc/logrotate.d/bazar << 'EOF'
/opt/bazar-production/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF
```

### 3. Regular Maintenance

```bash
# Create maintenance script
cat > /usr/local/bin/bazar-maintenance.sh << 'EOF'
#!/bin/bash
# Regular maintenance tasks

cd /opt/bazar-production

# Clean up old Docker images
docker system prune -f

# Update SSL certificates
./scripts/ssl-setup.sh renew

# Database optimization
docker-compose exec mysql mysqlcheck --optimize --all-databases -u root -p$MYSQL_ROOT_PASSWORD

# Clear application cache
docker-compose exec app php backend/cli/clear-cache.php

echo "Maintenance completed at $(date)"
EOF

chmod +x /usr/local/bin/bazar-maintenance.sh

# Schedule weekly maintenance
(crontab -l; echo "0 2 * * 0 /usr/local/bin/bazar-maintenance.sh") | crontab -
```

## Troubleshooting

### Common Issues

#### 1. Application Not Responding

```bash
# Check container status
docker-compose ps

# Check container logs
docker-compose logs app

# Check resource usage
docker stats

# Restart application
docker-compose restart app
```

#### 2. Database Connection Issues

```bash
# Check MySQL status
docker-compose exec mysql mysqladmin ping -u root -p$MYSQL_ROOT_PASSWORD

# Check MySQL logs
docker-compose logs mysql

# Check database connections
docker-compose exec mysql mysql -u root -p$MYSQL_ROOT_PASSWORD -e "SHOW PROCESSLIST;"
```

#### 3. SSL Certificate Issues

```bash
# Check certificate validity
openssl x509 -in /etc/nginx/ssl/yourdomain.com.crt -text -noout

# Test SSL configuration
curl -vI https://yourdomain.com

# Renew Let's Encrypt certificate
sudo certbot renew
```

#### 4. Performance Issues

```bash
# Check system resources
htop
iostat 1
netstat -tulnp

# Check application metrics
curl https://yourdomain.com/metrics

# Scale up if needed
docker-compose up -d --scale app=5
```

### Log Analysis

```bash
# Application errors
docker-compose logs app | grep ERROR

# Nginx access logs
docker-compose exec nginx tail -f /var/log/nginx/access.log

# Database slow queries
docker-compose exec mysql tail -f /var/log/mysql/slow.log

# System logs
sudo journalctl -u docker -f
```

## Security Checklist

### Pre-deployment Security

- [ ] Server hardening completed
- [ ] Firewall configured
- [ ] SSH hardened (key-based auth, non-standard port)
- [ ] SSL certificates installed and valid
- [ ] Environment variables secured
- [ ] Database credentials secured
- [ ] File permissions set correctly

### Runtime Security

- [ ] fail2ban configured and running
- [ ] Log monitoring active
- [ ] Intrusion detection (AIDE) configured
- [ ] Security monitoring script scheduled
- [ ] Regular security updates automated
- [ ] Backup encryption enabled

### Application Security

- [ ] Security headers configured
- [ ] Rate limiting enabled
- [ ] Input validation implemented
- [ ] Session security configured
- [ ] CORS policies set
- [ ] CSP headers configured

## Performance Optimization

### 1. Database Optimization

```sql
-- Enable query cache (MySQL 5.7)
SET GLOBAL query_cache_type = ON;
SET GLOBAL query_cache_size = 268435456;

-- Optimize tables
OPTIMIZE TABLE articles, users, categories;

-- Add indexes for frequently queried columns
CREATE INDEX idx_articles_created_at ON articles(created_at);
CREATE INDEX idx_users_email ON users(email);
```

### 2. Caching Strategy

```bash
# Configure Redis for caching
docker-compose exec redis redis-cli CONFIG SET maxmemory-policy allkeys-lru

# Enable OPcache
docker-compose exec app php -m | grep -i opcache
```

### 3. CDN Configuration

```nginx
# Configure CloudFlare or AWS CloudFront
location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header Vary Accept-Encoding;
}
```

## Disaster Recovery

### 1. Backup Strategy

- Daily automated backups to S3
- Weekly full system snapshots
- Monthly backup verification
- Cross-region backup replication

### 2. Recovery Procedures

```bash
# Complete system recovery
# 1. Provision new infrastructure
# 2. Restore latest backup
# 3. Update DNS records
# 4. Verify functionality

# RTO (Recovery Time Objective): 4 hours
# RPO (Recovery Point Objective): 24 hours
```

## Support and Maintenance

### Regular Tasks

- **Daily**: Monitor alerts, check system health
- **Weekly**: Review logs, update dependencies
- **Monthly**: Security updates, backup verification
- **Quarterly**: Performance review, capacity planning

### Emergency Contacts

- System Administrator: admin@yourdomain.com
- DevOps Engineer: devops@yourdomain.com
- Security Team: security@yourdomain.com

### Documentation Updates

This documentation should be updated whenever:
- New services are added
- Configuration changes are made
- Security procedures are updated
- Performance optimizations are implemented

---

**Last Updated**: 2024-01-01
**Version**: 1.0
**Maintained by**: DevOps Team