#!/bin/sh

set -eu

cd /var/www/html

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# composer install تم بدون scripts أثناء بناء الصورة.
# لذلك ننفذ اكتشاف الحزم عند بدء الحاوية.
if [ ! -f bootstrap/cache/packages.php ]; then
    php artisan package:discover --ansi
fi

exec "$@"
