# Bazar Marketplace - Operations Runbook

This runbook provides step-by-step procedures for common operational tasks, incident response, and troubleshooting.

## Table of Contents

1. [System Overview](#system-overview)
2. [Emergency Procedures](#emergency-procedures)
3. [Incident Response](#incident-response)
4. [Monitoring & Alerting](#monitoring--alerting)
5. [Backup & Recovery](#backup--recovery)
6. [Deployment Procedures](#deployment-procedures)
7. [Maintenance Tasks](#maintenance-tasks)
8. [Performance Optimization](#performance-optimization)
9. [Security Operations](#security-operations)
10. [Contact Information](#contact-information)

## System Overview

### Architecture Components

- **Load Balancer**: HAProxy (port 80/443)
- **Web Application**: PHP-FPM + Nginx (3 instances)
- **Database**: MySQL 8.0 (Master-Slave)
- **Cache**: Redis Cluster
- **Search**: Elasticsearch Cluster
- **Queue**: Redis-based job queue
- **Monitoring**: Prometheus + Grafana
- **Logging**: ELK Stack
- **Backup**: Automated S3 backups

### Service Dependencies

```
HAProxy → Application Servers → MySQL Master/Slave
                            ↓
                         Redis Cluster
                            ↓
                      Elasticsearch
```

## Emergency Procedures

### 🚨 Complete System Outage

**Symptoms**: Website completely inaccessible, all health checks failing

**Immediate Actions**:

1. **Assess Scope**:
   ```bash
   # Check if it's a network issue
   ping yourdomain.com
   nslookup yourdomain.com
   
   # Check server accessibility
   ssh user@server-ip
   ```

2. **Check System Status**:
   ```bash
   # Check Docker services
   docker-compose ps
   
   # Check system resources
   df -h
   free -h
   uptime
   ```

3. **Quick Recovery**:
   ```bash
   # Restart all services
   cd /opt/bazar-production
   docker-compose restart
   
   # If that fails, full restart
   docker-compose down
   docker-compose up -d
   ```

4. **Verify Recovery**:
   ```bash
   # Wait for services to start
   sleep 60
   
   # Test health endpoint
   curl -I https://yourdomain.com/health
   
   # Check all services
   docker-compose ps
   ```

**Escalation**: If system doesn't recover within 15 minutes, initiate disaster recovery

### 🚨 Database Outage

**Symptoms**: Database connection errors, data not loading

**Immediate Actions**:

1. **Check Database Status**:
   ```bash
   docker-compose ps mysql
   docker-compose logs mysql | tail -50
   ```

2. **Check Disk Space**:
   ```bash
   df -h /var/lib/docker
   docker system df
   ```

3. **Restart Database**:
   ```bash
   # Graceful restart
   docker-compose restart mysql
   
   # If fails, force restart
   docker-compose stop mysql
   docker-compose up -d mysql
   ```

4. **Verify Database**:
   ```bash
   # Test connection
   docker-compose exec mysql mysql -u root -p$MYSQL_ROOT_PASSWORD -e "SELECT 1"
   
   # Check replication status
   docker-compose exec mysql-slave mysql -u root -p$MYSQL_ROOT_PASSWORD -e "SHOW SLAVE STATUS\G"
   ```

**Failover to Slave**: If master is down

```bash
# Promote slave to master
docker-compose exec mysql-slave mysql -u root -p$MYSQL_ROOT_PASSWORD -e "STOP SLAVE; RESET MASTER;"

# Update application configuration
# Point DB_HOST to mysql-slave temporarily
```

### 🚨 High Traffic / DDoS Attack

**Symptoms**: Slow response times, high CPU/memory usage, unusual traffic patterns

**Immediate Actions**:

1. **Analyze Traffic**:
   ```bash
   # Check current connections
   netstat -an | grep :80 | wc -l
   netstat -an | grep :443 | wc -l
   
   # Check top IPs in access logs
   docker-compose exec nginx tail -1000 /var/log/nginx/access.log | awk '{print $1}' | sort | uniq -c | sort -nr | head -20
   ```

2. **Enable Rate Limiting**:
   ```bash
   # Update HAProxy configuration
   # Add aggressive rate limiting rules
   
   # Restart HAProxy
   docker-compose restart haproxy
   ```

3. **Scale Application**:
   ```bash
   # Scale up application instances
   docker-compose up -d --scale app=6
   
   # Scale up queue workers
   docker-compose up -d --scale queue-worker=4
   ```

4. **Enable CloudFlare DDoS Protection** (if configured)

### 🚨 SSL Certificate Expiry

**Symptoms**: SSL certificate warnings, HTTPS not working

**Immediate Actions**:

1. **Check Certificate Status**:
   ```bash
   openssl x509 -in /etc/nginx/ssl/yourdomain.com.crt -enddate -noout
   echo | openssl s_client -servername yourdomain.com -connect yourdomain.com:443 2>/dev/null | openssl x509 -noout -dates
   ```

2. **Renew Certificate**:
   ```bash
   sudo ./scripts/ssl-setup.sh renew
   
   # Or force renewal
   sudo certbot renew --force-renewal
   ```

3. **Update Docker Containers**:
   ```bash
   # Restart nginx to pick up new certificates
   docker-compose restart nginx
   docker-compose restart haproxy
   ```

## Incident Response

### Incident Classification

- **P1 (Critical)**: Complete system outage, security breach
- **P2 (High)**: Partial outage, performance degradation
- **P3 (Medium)**: Minor issues, non-critical features affected
- **P4 (Low)**: Cosmetic issues, maintenance items

### Response Procedures

#### P1 - Critical Incident

1. **Immediate Response** (0-15 minutes):
   - Assess impact and scope
   - Implement immediate fixes
   - Notify stakeholders
   - Start incident log

2. **Investigation** (15-60 minutes):
   - Identify root cause
   - Implement temporary fix
   - Monitor system stability

3. **Resolution** (1-4 hours):
   - Implement permanent fix
   - Verify system recovery
   - Update documentation

4. **Post-Incident** (24-48 hours):
   - Conduct post-mortem
   - Update procedures
   - Implement preventive measures

### Incident Communication

```bash
# Slack notification template
curl -X POST -H 'Content-type: application/json' \
    --data '{
        "text": "🚨 INCIDENT: [P1] - Complete system outage",
        "attachments": [{
            "color": "danger",
            "fields": [
                {"title": "Status", "value": "Investigating", "short": true},
                {"title": "ETA", "value": "30 minutes", "short": true},
                {"title": "Impact", "value": "All users affected"}
            ]
        }]
    }' \
    $SLACK_WEBHOOK
```

## Monitoring & Alerting

### Critical Metrics

#### Application Metrics
- Response time (95th percentile < 2s)
- Error rate (< 1%)
- Throughput (requests/minute)
- Active users

#### Infrastructure Metrics
- CPU usage (< 80%)
- Memory usage (< 90%)
- Disk usage (< 85%)
- Network I/O

#### Database Metrics
- Connection count (< 80% of max)
- Query response time
- Replication lag (< 1 second)
- Slow queries

### Alert Response

#### High CPU Usage Alert

```bash
# Investigation steps
1. Check processes: htop
2. Check Docker stats: docker stats
3. Check application logs: docker-compose logs app
4. Scale if needed: docker-compose up -d --scale app=5
```

#### High Memory Usage Alert

```bash
# Investigation steps
1. Check memory usage: free -h
2. Check Docker memory: docker system df
3. Restart services: docker-compose restart
4. Clear caches: docker-compose exec redis redis-cli FLUSHALL
```

#### Database Connection Alert

```bash
# Investigation steps
1. Check connections: docker-compose exec mysql mysql -e "SHOW PROCESSLIST"
2. Check slow queries: docker-compose exec mysql mysql -e "SHOW FULL PROCESSLIST"
3. Restart connections: docker-compose restart app
4. Scale database: Consider read replicas
```

## Backup & Recovery

### Daily Backup Verification

```bash
#!/bin/bash
# Daily backup check script

BACKUP_DATE=$(date +"%Y%m%d")
S3_BUCKET="your-backup-bucket"

# Check if today's backup exists
if aws s3 ls s3://$S3_BUCKET/backups/bazar/ | grep $BACKUP_DATE; then
    echo "✅ Backup found for $BACKUP_DATE"
else
    echo "❌ No backup found for $BACKUP_DATE"
    # Send alert
fi

# Verify backup integrity
aws s3 cp s3://$S3_BUCKET/backups/bazar/bazar_${BACKUP_DATE}_manifest.json /tmp/
if [ -f "/tmp/bazar_${BACKUP_DATE}_manifest.json" ]; then
    echo "✅ Backup manifest verified"
else
    echo "❌ Backup manifest missing"
fi
```

### Recovery Procedures

#### Point-in-Time Recovery

```bash
# Stop application
docker-compose down

# Restore database from backup
TARGET_DATE="20240101_020000"
aws s3 cp s3://your-backup-bucket/backups/bazar/bazar_${TARGET_DATE}_database.sql.gz /tmp/
gunzip /tmp/bazar_${TARGET_DATE}_database.sql.gz

# Restore to database
docker-compose up -d mysql
sleep 30
docker-compose exec mysql mysql -u root -p$MYSQL_ROOT_PASSWORD $DB_NAME < /tmp/bazar_${TARGET_DATE}_database.sql

# Restore files
aws s3 cp s3://your-backup-bucket/backups/bazar/bazar_${TARGET_DATE}_files.tar.gz /tmp/
tar -xzf /tmp/bazar_${TARGET_DATE}_files.tar.gz -C /opt/bazar-production/

# Restart application
docker-compose up -d
```

## Deployment Procedures

### Blue-Green Deployment

```bash
#!/bin/bash
# Blue-green deployment script

set -e

echo "Starting blue-green deployment..."

# Pre-deployment backup
./scripts/backup-before-deploy.sh

# Run blue-green deployment
./scripts/blue-green-deploy.sh

# Post-deployment verification
echo "Verifying deployment..."
sleep 30

if curl -f https://yourdomain.com/health; then
    echo "✅ Deployment successful"
else
    echo "❌ Deployment failed, initiating rollback"
    ./scripts/rollback.sh
fi
```

### Rollback Procedures

```bash
#!/bin/bash
# Rollback script

echo "Initiating rollback..."

# Get previous deployment info
PREV_TAG=$(docker images --format "table {{.Repository}}:{{.Tag}}" | grep bazar | head -2 | tail -1 | cut -d: -f2)

echo "Rolling back to: $PREV_TAG"

# Update docker-compose to use previous image
sed -i "s/image: .*/image: bazar:$PREV_TAG/g" docker-compose.yml

# Restart services
docker-compose down
docker-compose up -d

# Wait and verify
sleep 60
if curl -f https://yourdomain.com/health; then
    echo "✅ Rollback successful"
else
    echo "❌ Rollback failed - manual intervention required"
fi
```

## Maintenance Tasks

### Weekly Maintenance Checklist

- [ ] Review monitoring alerts
- [ ] Check system resource usage
- [ ] Verify backup integrity
- [ ] Update security patches
- [ ] Review error logs
- [ ] Performance optimization review
- [ ] SSL certificate expiry check
- [ ] Database optimization

### Monthly Maintenance

```bash
#!/bin/bash
# Monthly maintenance script

echo "Starting monthly maintenance..."

# System updates
sudo apt update && sudo apt upgrade -y

# Docker cleanup
docker system prune -f
docker image prune -f

# Database optimization
docker-compose exec mysql mysqlcheck --optimize --all-databases -u root -p$MYSQL_ROOT_PASSWORD

# Log rotation
logrotate -f /etc/logrotate.conf

# Security scan
rkhunter --check --skip-keypress

# Backup verification
./scripts/verify-backups.sh

echo "Monthly maintenance completed"
```

## Performance Optimization

### Database Performance Tuning

```sql
-- Analyze slow queries
SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;

-- Check table sizes
SELECT 
    table_name AS "Table",
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS "Size (MB)"
FROM information_schema.tables 
WHERE table_schema = 'bazar_marketplace'
ORDER BY (data_length + index_length) DESC;

-- Optimize tables
OPTIMIZE TABLE articles, users, categories;
```

### Application Performance Monitoring

```bash
# Monitor application metrics
curl -s https://yourdomain.com/metrics | grep -E '(http_requests|response_time|memory_usage)'

# Check PHP-FPM status
curl -s https://yourdomain.com/fpm-status

# Monitor Redis performance
docker-compose exec redis redis-cli INFO stats
```

## Security Operations

### Security Monitoring

```bash
#!/bin/bash
# Daily security check

# Check failed login attempts
echo "Failed SSH attempts:"
grep "Failed password" /var/log/auth.log | wc -l

# Check fail2ban status
fail2ban-client status

# Check for suspicious processes
ps aux --sort=-%cpu | head -10

# Check open ports
nmap -sT -O localhost

# Check file integrity (if AIDE is installed)
aide --check
```

### Incident Response - Security Breach

1. **Immediate Actions**:
   - Isolate affected systems
   - Change all passwords
   - Revoke API keys
   - Enable additional logging

2. **Investigation**:
   - Analyze log files
   - Check for data exfiltration
   - Identify attack vector
   - Document findings

3. **Recovery**:
   - Patch vulnerabilities
   - Restore from clean backup
   - Update security measures
   - Notify stakeholders

## Contact Information

### Emergency Contacts

- **On-Call Engineer**: +1-XXX-XXX-XXXX
- **System Administrator**: admin@yourdomain.com
- **Security Team**: security@yourdomain.com
- **DevOps Team**: devops@yourdomain.com

### Escalation Matrix

| Severity | Primary Contact | Secondary Contact | Manager Contact |
|----------|----------------|-------------------|------------------|
| P1       | On-Call Engineer | System Admin | Engineering Manager |
| P2       | System Admin | DevOps Team | Technical Lead |
| P3       | DevOps Team | Development Team | Product Manager |
| P4       | Development Team | QA Team | Project Manager |

### External Vendors

- **Cloud Provider**: AWS Support
- **CDN Provider**: CloudFlare Support
- **Monitoring**: New Relic Support
- **Security**: Security Vendor Support

---

**Last Updated**: 2024-01-01  
**Version**: 1.0  
**Review Schedule**: Monthly  
**Owner**: DevOps Team