#!/bin/sh
set -e

# Default to 10000 (Render default port) if PORT is not set
export PORT="${PORT:-10000}"

echo "=========================================="
echo " Starting ViLoCare on Port: ${PORT}"
echo " Environment: ${APP_ENV:-production}"
echo "=========================================="

# Substitute PORT in Nginx configuration template
if [ -f /etc/nginx/templates/default.conf.template ]; then
    echo "Configuring Nginx with port ${PORT}..."
    envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf
fi

# Ensure storage and bootstrap cache directories exist and have proper permissions
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if missing
if [ ! -L /var/www/html/public/storage ]; then
    echo "Creating storage symlink..."
    php artisan storage:link --force || true
fi

# Run database migrations if enabled via environment variable
if [ "${RUN_MIGRATIONS:-false}" = "true" ] || [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "Running database migrations..."
    php artisan migrate --force || echo "Migration encountered an issue, proceeding..."
fi

# Run database seeders if enabled via environment variable (useful on initial setup)
if [ "${RUN_SEEDING:-false}" = "true" ] || [ "${RUN_SEEDING:-0}" = "1" ]; then
    echo "Running database seeders..."
    php artisan db:seed --force || echo "Seeding encountered an issue, proceeding..."
fi

# Optimize Laravel cache for production if APP_KEY is set
if [ -n "${APP_KEY}" ]; then
    echo "Caching Laravel configuration, routes, and views..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
else
    echo "WARNING: APP_KEY is not set. Skipping config/route caching."
fi

# Execute supervisor or passed command
if [ $# -eq 0 ]; then
    echo "Starting Supervisord (PHP-FPM + Nginx)..."
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    exec "$@"
fi
