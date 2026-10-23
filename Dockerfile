FROM php:8.5-fpm-alpine

# Install nginx (Alpine package)
RUN apk add --no-cache nginx

# Create non-root user for both nginx and php-fpm
RUN adduser -D -u 1000 -h /app phpuser

# ── Nginx configuration ─────────────────────────────────────
COPY config/nginx.conf /etc/nginx/http.d/default.conf

# Fix nginx user: Alpine nginx runs as 'nginx' user by default,
# switch to phpuser so file permissions align
RUN sed -i 's/^user nginx;/user phpuser;/' /etc/nginx/nginx.conf

# Create nginx runtime directories and set ownership
RUN mkdir -p /var/log/nginx /var/lib/nginx/tmp/client_body \
    && touch /var/log/nginx/access.log /var/log/nginx/error.log \
    && chown -R phpuser:phpuser /var/log/nginx /var/lib/nginx

# ── PHP-FPM configuration ───────────────────────────────────
COPY config/www.conf /usr/local/etc/php-fpm.d/www.conf

# ── Application ─────────────────────────────────────────────
WORKDIR /app
COPY webapp/ /app/
RUN chown -R phpuser:phpuser /app

# ── Entrypoint ──────────────────────────────────────────────
COPY config/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
