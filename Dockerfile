# Lembretes — imagem pronta (Apache + PHP 8.3)
# Build:  docker build -t lembretes .
# Uso:    docker run -d -p 8080:80 -v $PWD/config.php:/var/www/html/config.php lembretes
FROM php:8.3-apache

# Extensões que o sistema usa: PDO MySQL (banco), GD (cartão da previsão e ícones),
# mbstring já vem embutida; cURL também.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# .htaccess precisa valer dentro do container
RUN printf '<Directory /var/www/html>\n  AllowOverride All\n  Require all granted\n</Directory>\n' \
      > /etc/apache2/conf-available/lembretes.conf \
 && a2enconf lembretes

WORKDIR /var/www/html
COPY . /var/www/html/

# uploads/ precisa ser gravável; config.php entra por volume (nunca vai na imagem)
RUN rm -f config.php \
 && mkdir -p uploads/clima \
 && chown -R www-data:www-data /var/www/html \
 && printf 'date.timezone=America/Maceio\nupload_max_filesize=16M\npost_max_size=20M\n' \
      > /usr/local/etc/php/conf.d/lembretes.ini

EXPOSE 80

# O cron dos envios roda fora do container, por exemplo:
#   docker exec lembretes php /var/www/html/cron.php
#   docker exec lembretes php /var/www/html/alertachuva.php
