FROM php:8-apache

RUN a2enmod rewrite
RUN service apache2 restart

RUN apt-get update
RUN apt-get install -y libonig-dev libpq-dev # need for mbstring extension

RUN docker-php-ext-install pdo pdo_mysql mbstring

#RUN pecl install xdebug && docker-php-ext-enable xdebug
