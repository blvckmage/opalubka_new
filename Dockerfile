FROM php:8.2-apache

# Включаем mod_rewrite для Apache (если нужно для роутинга)
RUN a2enmod rewrite

# Устанавливаем SQLite и PostgreSQL зависимости
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    libpq-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql

# Копируем файлы проекта
COPY . /var/www/html/

# Создаем папки для данных и загрузок, и даем права веб-серверу
RUN mkdir -p /var/www/html/data /var/www/html/uploads/orders && \
    chown -R www-data:www-data /var/www/html/data /var/www/html/uploads && \
    chmod -R 777 /var/www/html/data /var/www/html/uploads

# Указываем порт (по умолчанию для apache - 80)
EXPOSE 80
