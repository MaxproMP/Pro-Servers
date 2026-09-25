FROM php:8.4-fpm

# 1. Instalar dependencias
RUN apt-get update && apt-get install -y \
    nginx git curl ca-certificates libpng-dev libonig-dev libxml2-dev zip unzip \
    libssl-dev pkg-config gnupg2 apt-transport-https

# CA oficial para validar certificados de Amazon RDS sin confiar ciegamente.
RUN curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
    -o /usr/local/share/ca-certificates/rds-global-bundle.crt \
    && update-ca-certificates

# 2. Agregar repositorio de Microsoft
RUN curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list | tee /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update

# 3. Instalar drivers nativos de SQL Server
RUN ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

# 4. Instalar extensiones PHP
RUN pecl install mongodb sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable mongodb sqlsrv pdo_sqlsrv \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd

# 5. Limpiar caché
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 6. Traer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --optimize-autoloader --no-dev --no-interaction

# 7. Crear la configuración Nginx interna para Laravel (SIN USAR COPY)
RUN echo 'server { listen 80; root /var/www/html/public; index index.php; location / { try_files $uri $uri/ /index.php?$query_string; } location ~ \.php$ { include fastcgi_params; fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; } }' > /etc/nginx/sites-available/default && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# 8. Permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

EXPOSE 80

# 9. Iniciar servicios
CMD service nginx start && php-fpm