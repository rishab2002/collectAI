#!/bin/sh
# Container entrypoint for the production image. Takes a role:
#
#   web    — migrate, warm the caches, then nginx + php-fpm under supervisord
#   worker — queue:work, the process without which every capture silently
#            skips OCR
#
# Configuration arrives as a .env file mounted at /var/www/html/.env, not as
# process environment. That is the same rule docker-compose.yml documents for
# development, and it holds for the same reason: Laravel's Dotenv reads $_SERVER
# ahead of everything else, so values injected into the process environment
# become impossible to override from the file. Reading .env off disk keeps one
# source of truth.
#
# The one exception is ORIGIN_MODE=railway (README §8.6). Railway has no file
# to mount: configuration *is* the service's variables, so there the process
# environment is the single source of truth and no .env exists to disagree
# with it. That mode also needs ORIGIN_SECRET, the header value Cloudflare adds
# to every request — see docker/nginx/origin-mode-railway.conf.

set -eu

role="${1:-web}"

log() {
    echo "[entrypoint] $*"
}

origin_mode="${ORIGIN_MODE:-direct}"

require_env_file() {
    case "$origin_mode" in
        direct | railway) ;;
        *)
            log "FATAL: unknown ORIGIN_MODE '${origin_mode}' (direct or railway)"
            exit 1
            ;;
    esac
    if [ "$origin_mode" = railway ]; then
        if [ -f /var/www/html/.env ]; then
            log "FATAL: ORIGIN_MODE=railway takes configuration from Railway's"
            log "variables, but a .env file is also present. Remove one."
            exit 1
        fi
        return
    fi
    if [ ! -f /var/www/html/.env ]; then
        log "FATAL: no .env mounted at /var/www/html/.env"
        log "Copy .env.production.example, fill it in, and mount it. See docs/deploy.md."
        exit 1
    fi
}

# Opening a PDO connection through Laravel's own config waits on exactly the
# database the app will use, rather than a host re-derived from .env by this
# script.
#
# NOT `db:show`, which looks like the obvious choice and is a trap: it connects,
# prints the tables, and then exits 1 anyway because its summary calls
# Number::format, which throws without the intl extension. The probe would never
# succeed and every boot would time out claiming the database was unreachable.
# laravel/tinker is in `require`, not `require-dev`, so --execute is available
# in this image.
wait_for_database() {
    attempt=0
    until php artisan tinker --execute="DB::connection()->getPdo();" >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            log "FATAL: database not reachable after 2 minutes"
            php artisan tinker --execute="DB::connection()->getPdo();" || true
            exit 1
        fi
        log "waiting for database (attempt ${attempt})..."
        sleep 2
    done
    log "database is up"
}

# Caching happens here rather than in the Dockerfile: `config:cache` freezes
# whatever environment it is run in, and at build time that is the builder's,
# not the box's.
warm_caches() {
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
}

# This entrypoint runs as root, so everything warm_caches just wrote is
# root-owned — but php-fpm serves as www-data. Precompiling every view is not
# enough on its own: Laravel still compiles lazily for anything view:cache did
# not reach (vendor views, the error pages), and that write would fail with a
# permission error at exactly the moment something has already gone wrong.
fix_permissions() {
    chown -R www-data:www-data storage bootstrap/cache
}

# Railway mode: install the nginx origin mode that admits only requests
# carrying Cloudflare's secret header. A short or odd secret is refused rather
# than written: it is pasted into an nginx string, and a guessable one is no
# gate at all. `openssl rand -hex 32` makes a good one.
configure_origin_mode() {
    case "$origin_mode" in
        direct) ;;
        railway)
            if ! printf '%s' "${ORIGIN_SECRET:-}" | grep -Eq '^[A-Za-z0-9]{32,100}$'; then
                log "FATAL: ORIGIN_MODE=railway needs ORIGIN_SECRET: 32 to 100 letters"
                log "and digits (openssl rand -hex 32), the same value as Cloudflare's"
                log "X-Origin-Auth transform rule. See README section 8.6."
                exit 1
            fi
            sed "s/__ORIGIN_SECRET__/${ORIGIN_SECRET}/" \
                /etc/nginx/origin-mode-railway.conf > /etc/nginx/origin-mode.conf
            log "origin mode: railway (requests need Cloudflare's X-Origin-Auth header)"
            ;;
    esac
}

# nginx.conf includes /etc/nginx/ssl.conf rather than naming a certificate
# directly, because ./certs is mounted read-only and so cannot receive a
# generated fallback. This writes that include to point wherever a usable pair
# actually is.
ensure_certificate() {
    if [ -f /etc/nginx/certs/origin.pem ] && [ -f /etc/nginx/certs/origin.key ]; then
        log "using the mounted origin certificate"
        cat > /etc/nginx/ssl.conf <<'EOF'
ssl_certificate     /etc/nginx/certs/origin.pem;
ssl_certificate_key /etc/nginx/certs/origin.key;
EOF
        return
    fi

    if [ "$origin_mode" = railway ]; then
        # Railway's edge terminates TLS and forwards to port 80; the 443
        # server only needs *a* certificate to start, and nothing connects to it.
        log "railway mode: TLS terminates at Railway's edge, port 443 unused"
    else
        log "WARNING: no origin certificate at /etc/nginx/certs/origin.{pem,key}"
        log "WARNING: generating a self-signed pair so the container can boot."
        log "WARNING: Cloudflare Full (strict) REJECTS this with error 526."
        log "WARNING: For the real deployment, mount a Cloudflare Origin CA"
        log "WARNING: certificate instead. See docs/deploy.md."
    fi

    mkdir -p /etc/nginx/certs-selfsigned
    openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
        -keyout /etc/nginx/certs-selfsigned/origin.key \
        -out /etc/nginx/certs-selfsigned/origin.pem \
        -subj "/CN=collectai.club" >/dev/null 2>&1

    cat > /etc/nginx/ssl.conf <<'EOF'
ssl_certificate     /etc/nginx/certs-selfsigned/origin.pem;
ssl_certificate_key /etc/nginx/certs-selfsigned/origin.key;
EOF
}

require_env_file

case "$role" in
    web)
        configure_origin_mode
        wait_for_database
        log "running migrations"
        php artisan migrate --force
        warm_caches
        fix_permissions
        ensure_certificate
        log "starting nginx + php-fpm"
        exec supervisord -c /etc/supervisord.conf
        ;;

    worker)
        wait_for_database
        warm_caches
        # --timeout must stay BELOW the queue connection's retry_after
        # (REDIS_QUEUE_RETRY_AFTER, set to 360 in .env.production.example) or
        # Redis makes the job visible again while it is still running and a
        # second worker picks it up — one capture, two billed vision calls.
        #
        # 300s rather than Laravel's default 60s because ReadSubmissionPhotos
        # makes an Opus 5 vision call with thinking enabled over up to four
        # photos, and the job class declares no $timeout of its own. At 60s the
        # first real OCR run would be killed mid-call, retried three times and
        # then marked failed — which looks exactly like a broken agent.
        #
        # --max-time recycles the process hourly; the restart policy brings it
        # straight back with a clean heap.
        log "starting queue worker"
        exec php artisan queue:work \
            --tries=3 \
            --timeout=300 \
            --max-time=3600 \
            --sleep=3
        ;;

    scheduler)
        # Laravel's scheduler (routes/console.php): the daily price update,
        # the nightly recognition index export and the hourly scan prune. One
        # long-running process instead of a host cron, so it ships with the
        # stack and restarts with it.
        wait_for_database
        warm_caches
        log "starting scheduler"
        exec php artisan schedule:work
        ;;

    *)
        # Anything else is run verbatim, so `docker compose run --rm web php
        # artisan catalog:import-hot-wheels --year=2012` works.
        exec "$@"
        ;;
esac
