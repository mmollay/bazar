FROM nginx:alpine

# Install additional packages
RUN apk add --no-cache \
    curl \
    openssl \
    certbot \
    certbot-nginx

# Copy custom nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY conf.d/ /etc/nginx/conf.d/

# Create SSL directory
RUN mkdir -p /etc/nginx/ssl

# Generate self-signed certificate for development (will be replaced in production)
RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/bazar.key \
    -out /etc/nginx/ssl/bazar.crt \
    -subj "/C=US/ST=State/L=City/O=Organization/OU=OrgUnit/CN=localhost"

# Copy entrypoint script
COPY entrypoint.sh /docker-entrypoint.d/
RUN chmod +x /docker-entrypoint.d/entrypoint.sh

EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]