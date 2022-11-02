#!/bin/bash

set -ex
sleep 5 # Waiting for mysql being started.


##########################################################################
# Get App Files
##########################################################################
# If host machine has not files, give it files.
if [ ! -d "/app/app" ];then
    echo "Copying files from /app_src to /app"
    yes|cp -rf /app_src/. /app/
fi


##########################################################################
# Configuration
##########################################################################
function mod_env(){
    sed -i "s/^.\?$1\s\?=.*$/$1=${2//\//\\\/}/" $3
}
mod_env "APP_DEBUG"         ${APP_DEBUG:-false}                 .env
mod_env "HREF_FORCE_HTTPS"  ${HREF_FORCE_HTTPS:-false}          .env
mod_env "QUEUE_CONNECTION"  ${QUEUE_CONNECTION:-sync}           .env
mod_env "DB_HOST"           ${MYSQL_HOST:-host.docker.internal} .env
mod_env "DB_PORT"           ${MYSQL_PORT:-3306} .env
mod_env "DB_DATABASE"       ${MYSQL_DATABASE}   .env
mod_env "DB_USERNAME"       ${MYSQL_USER}       .env
mod_env "DB_PASSWORD"       ${MYSQL_PASSWORD}   .env
mod_env "REDIS_HOST"        ${REDIS_HOST:-host.docker.internal} .env
mod_env "REDIS_PORT"        ${REDIS_PORT:-6379} .env
mod_env "REDIS_PASSWORD"    ${REDIS_PASSWORD}   .env

## config php, php-fpm
# open php extension
sed -i "/^;extension=gettext.*/i extension=gd"    /etc/php/7.2/fpm/php.ini
sed -i "/^;extension=gettext.*/i extension=curl"  /etc/php/7.2/fpm/php.ini
sed -i "/^;extension=gettext.*/i extension=zip"   /etc/php/7.2/fpm/php.ini
sed -i "/^;extension=gettext.*/i extension=redis" /etc/php/7.2/fpm/php.ini

# file size
mod_env "post_max_size"        ${php_post_max_size:-64M}       /etc/php/7.2/fpm/php.ini
mod_env "upload_max_filesize"  ${php_upload_max_filesize:-64M} /etc/php/7.2/fpm/php.ini

# default php-fpm `pm` for server with 32GB max memory.
mod_env "pm"                   ${fpm_pm:-dynamic}                /etc/php/7.2/fpm/pool.d/www.conf
mod_env "pm.max_children"      ${fpm_pm_max_children:-1024}      /etc/php/7.2/fpm/pool.d/www.conf
mod_env "pm.max_requests"      ${fpm_pm_max_requests:-1000}      /etc/php/7.2/fpm/pool.d/www.conf
# The following item is avaliable only if pm=dynamic.
mod_env "pm.start_servers"     ${fpm_pm_start_servers:-8}        /etc/php/7.2/fpm/pool.d/www.conf
mod_env "pm.min_spare_servers" ${fpm_pm_min_spare_servers:-2}    /etc/php/7.2/fpm/pool.d/www.conf
mod_env "pm.max_spare_servers" ${fpm_pm_max_spare_servers:-1024} /etc/php/7.2/fpm/pool.d/www.conf

## nginx config
sed -i "s/worker_connections [0-9]*;$/worker_connections 51200;/" /etc/nginx/nginx.conf


##########################################################################
# Start Server
##########################################################################
# start nginx server
service nginx start

# Start php-fpm server
service php7.2-fpm start


##########################################################################
# Initialize laravel app.
##########################################################################
php artisan storage:link
php artisan optimize
if [[ "`cat .env|grep ^APP_KEY=$`" != "" ]]; then  # Lack of APP_KEY
    php artisan key:generate --force
fi
php artisan migrate --force
php artisan optimize
php artisan lduoj:init

# Change storage folders owner.
chown www-data:www-data -R storage bootstrap/cache


##########################################################################
# Background running
##########################################################################
bash storage/scripts/auto-clear-log.sh 2>&1 &


##########################################################################
# Start laravel-queue. Although there are more than one queue they still execute one by one
##########################################################################
php artisan queue:work --queue=default,CorrectSubmittedCount

# Sleep forever to keep container alives.
sleep infinity