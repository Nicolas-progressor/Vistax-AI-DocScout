#!/bin/sh

# Устанавливаем правильные права на storage и cache
# Это нужно потому что volumes из хоста перекрывают права контейнера
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Раскомментируем listen в www.conf
sed -i 's/^;listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' /usr/local/etc/php-fpm.d/www.conf

# Генерируем ключ если не существует
if [ ! -f .env ] || [ -z "$APP_KEY" ]; then
    echo "APP_KEY не найден, генерируем..."
    php artisan key:generate --force
fi

# Запускаем миграции если БД доступна
echo "Проверка подключения к БД..."
php artisan migrate --force || true

# Оптимизация кэша (не критично если упадет)
php artisan config:cache || true
php artisan route:cache || true

# Запускаем PHP-FPM (основной процесс)
exec php-fpm
