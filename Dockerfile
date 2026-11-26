FROM php:8.2-apache

# Installer l'extension PHP pour la connexion à MySQL
RUN docker-php-ext-install pdo_mysql

# Activer le module rewrite d'Apache
RUN a2enmod rewrite

# Définir le dossier du projet
WORKDIR /var/www/html

# Copier les fichiers du projet
COPY . /var/www/html

# Donner les permissions nécessaires à Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80