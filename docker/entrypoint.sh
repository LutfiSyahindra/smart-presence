#!/bin/sh
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY belum diisi. Jalankan scripts/docker-setup.php.' >&2
    exit 1
fi

if [ "${1:-}" = 'init' ]; then
    if [ "$(id -u)" != '0' ]; then
        echo 'Service init harus berjalan sebagai root untuk menyiapkan volume.' >&2
        exit 1
    fi

    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs
    chown -R www-data:www-data storage
    gosu www-data php artisan migrate --force --no-interaction
    exec gosu www-data php artisan view:cache --no-interaction
fi

# bootstrap/cache belongs to this container, so replicas never overwrite each
# other's cached environment. The shared storage volume only contains runtime data.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
