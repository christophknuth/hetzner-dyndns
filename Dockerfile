# PHP-FPM image for hetzner-dyndns, built by compose.yaml.
#
# The build context is this repository, so the image always matches the
# checked-out commit — no git clone, no CACHEBUST, no drift between the code
# Dockhand pulled and the code that ends up running.
#
# php:8.3-fpm-alpine already bundles ext-curl and ext-sqlite3, which is
# everything hetzner_dyndns.php needs. (It uses the SQLite3 class, not PDO.)
#
# Runtime expectations:
#   * /var/www/html/hetzner_dyndns.config.php  — bind-mounted, never baked in
#   * /var/www/data                            — named volume for the database
#
# The code directory is read-only for the PHP worker. So the stock config
# default (history_db next to the script) keeps working, that path is a symlink
# into /var/www/data; sqlite resolves it and puts the -wal/-shm files there too.
FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="hetzner-dyndns" \
      org.opencontainers.image.description="DynDNS endpoint for Hetzner DNS (PHP-FPM)" \
      org.opencontainers.image.source="https://github.com/christophknuth/hetzner-dyndns" \
      org.opencontainers.image.licenses="MIT"

# Production php.ini (display_errors=Off) plus an FPM ping endpoint for the
# healthcheck. Static, so this layer stays cached across code changes.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf '[www]\nping.path = /ping\nping.response = pong\n' > /usr/local/etc/php-fpm.d/zz-ping.conf \
 && apk add --no-cache fcgi \
 && install -d -o www-data -g www-data /var/www/data

# Application scripts only — never the config (it carries the API token) and
# never a stray sqlite file from a local checkout.
COPY hetzner_dyndns.php hetzner_dyndns_listhosts.php /var/www/html/

RUN chown -R root:www-data /var/www/html \
 && chmod -R go-w /var/www/html \
 && ln -sf /var/www/data/hetzner_dyndns.sqlite3 /var/www/html/hetzner_dyndns.sqlite3

WORKDIR /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD REQUEST_METHOD=GET SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
