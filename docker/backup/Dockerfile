FROM alpine:latest

# Install required packages
RUN apk add --no-cache \
    bash \
    curl \
    mysql-client \
    redis \
    aws-cli \
    gnupg \
    tar \
    gzip \
    coreutils \
    dcron \
    rsync \
    openssh-client \
    && rm -rf /var/cache/apk/*

# Create backup user
RUN addgroup -g 1001 backup && \
    adduser -D -u 1001 -G backup backup

# Create directories
RUN mkdir -p /backup/scripts /backup/temp /backup/keys /var/log/backup

# Copy backup scripts
COPY scripts/ /backup/scripts/
COPY entrypoint.sh /entrypoint.sh

# Set permissions
RUN chmod +x /backup/scripts/*.sh /entrypoint.sh && \
    chown -R backup:backup /backup /var/log/backup

# Switch to backup user
USER backup

# Set working directory
WORKDIR /backup

# Health check
HEALTHCHECK --interval=24h --timeout=30s --start-period=10s --retries=3 \
    CMD /backup/scripts/health-check.sh

ENTRYPOINT ["/entrypoint.sh"]