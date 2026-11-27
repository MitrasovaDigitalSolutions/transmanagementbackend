#!/bin/sh
set -e

# Public symlink for vehicle thumbnails (documents use signed URLs, not this symlink)
php artisan storage:link || true

# Cache config + routes from the injected env before serving traffic
php artisan config:cache
php artisan route:cache

# Apply pending migrations before serving traffic
php artisan migrate --force

exec "$@"
