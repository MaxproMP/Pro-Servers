FROM php:8.2-fpm

# 1. Instalar dependencias del sistema, Nginx y requerimientos para MongoDB
RUN apt-get update && apt-get install -y \
    nginx git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    libssl-dev pkg-config

# 2. Limpiar caché de apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. Instalar la extensión de MongoDB (Vital para tus clientes)
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# 4. Instalar extensiones de PHP (MySQL, GD, etc)
RUN docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd

# 5. Traer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. Directorio de trabajo
WORKDIR /var/www/html

# 7. Copiar todo el proyecto Laravel
COPY . .

# 8. Instalar dependencias de Laravel sin interacciones
RUN composer install --optimize-autoloader --no-dev --no-interaction

# 9. Configurar Nginx con el archivo híbrido que armamos
COPY nginx.conf /etc/nginx/sites-enabled/default

# 10. Permisos de carpetas críticas para Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 11. Exponer el puerto 80 al mundo
EXPOSE 80

# 12. Iniciar Nginx y el motor PHP juntos
CMD service nginx start && php-fpm