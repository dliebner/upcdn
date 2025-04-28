#!/bin/bash

# -e: Exit immediately if any command exits with a non-zero status
# -x: Print each command before it's executed
set -ex

# init local files
mkdir -p ../local
mkdir -p ../logs
mkdir -p ../transcoding
echo "<?php

if( !defined('IN_SCRIPT') ) die('Hacking attempt');
" | sudo tee ../local/constants.php > /dev/null

# chmod dirs
chmod 0777 ../www/v ../logs ../transcoding

# chmod files
chmod +x *.sh
chmod +x ../scripts/*
chmod +x ../daemon/*
#chmod +x ../cron/*.sh
chmod +x ../devel/cpp-redis-pipe/*.sh

# general dependencies assumed to be installed
# webmin assumed to be installed
# docker assumed to be installed
# LAMP assumed to be installed

# Collect upcdn domain
read -p "UPCDN domain: " UPCDN_HOSTNAME
echo

# Mysql root credentials for root user assumed to be set
# Hostname assumed to be set
# Mysql assumed to be installed and secured

# add upcdn mysql user
MYSQL_UPCDN_USER="upcdn_user"
read -sp "Set MYSQL_UPCDN_USER password: " MYSQL_UPCDN_PW
echo
printf -v MYSQL_UPCDN_PW "%q" "$MYSQL_UPCDN_PW" # escape the password
sudo mysql --execute="USE mysql;\
CREATE USER '${MYSQL_UPCDN_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_UPCDN_PW}';\
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_UPCDN_USER}'@'localhost';\
FLUSH PRIVILEGES;"

# Import db schema
sudo mysql < schema.sql

# set upcdn_user pw in constants.php
echo "
define('MYSQL_UPCDN_PW', '${MYSQL_UPCDN_PW}');
" | sudo tee -a ../local/constants.php > /dev/null
unset MYSQL_UPCDN_PW
sudo service mysql restart

# ufw rules assumed to be set up
# apache mods and php7.4-fpm conf assumed to be set up
# phpMyAdmin assumed to be installed and set up

# copy apache files
sudo cp upcdn-apache.conf /etc/apache2/sites-available/${UPCDN_HOSTNAME}.conf
sudo cp upcdn-apache-ssl.conf /etc/apache2/sites-available/${UPCDN_HOSTNAME}-ssl.conf
sudo cp upcdn-bw-redis-pipe.conf /etc/apache2/conf-available/

# replace occurences of UPCDN_HOSTNAME in copied conf files
sed -i "s/\$UPCDN_HOSTNAME/$UPCDN_HOSTNAME/" /etc/apache2/sites-available/${UPCDN_HOSTNAME}.conf
sed -i "s/\$UPCDN_HOSTNAME/$UPCDN_HOSTNAME/" /etc/apache2/sites-available/${UPCDN_HOSTNAME}-ssl.conf

# soft link
sudo ln -rs /etc/apache2/sites-available/${UPCDN_HOSTNAME}* /etc/apache2/sites-enabled/
sudo ln -rs /etc/apache2/conf-available/upcdn* /etc/apache2/conf-enabled/

# replace occurences of UPCDN_HOSTNAME in source files
sed -i "s/\$UPCDN_HOSTNAME/$UPCDN_HOSTNAME/" ../devel/cpp-redis-pipe/redis-pipe.cc

# build stuff
cd /home/upcdn/devel/cpp-redis-pipe/
./build.sh
cd /home/upcdn/install

# copy files
sudo cp ../devel/cpp-redis-pipe/redis-pipe ../scripts/

sudo service apache2 restart

# install SSL certificate
sudo certbot --apache
sleep 1
sudo systemctl status certbot.timer

sudo service apache2 restart

# redis assumed to be installed and configured
# php-redis assumed to be installed
# apache assumed to be using mpm_worker

# create upcdn user
sudo useradd -m -s /bin/bash upcdn
# add upcdn user to www-data group
sudo usermod -a -G www-data upcdn

# install composer
./install-composer.sh

# chown files
chown -R upcdn:upcdn /home/upcdn

# install composer extensions
cd /home/upcdn
su upcdn -c "composer require gabrielelana/byte-units guzzlehttp/guzzle:^6.5"
# flexihash/flexihash is used on the hub server, don't think we need it here
# obregonco/backblaze-b2 - the original b2 repo we forked from
su upcdn -c "composer config repositories.backblaze-b2 vcs https://github.com/dliebner/backblaze-b2"
su upcdn -c "composer require dliebner/backblaze-b2:dev-master"
cd /home/upcdn/install

# copy files
sudo cp upcdn-sudoers /etc/sudoers.d/
sudo cp upcdn-cron /etc/cron.d/
sudo cp upcdn-docker-events.service /etc/systemd/system/

# chown files again
chown -R upcdn:upcdn /home/upcdn/

# restart apache
sudo service apache2 restart
sudo service php7.4-fpm restart

# start custom daemons
sudo systemctl enable upcdn-docker-events.service
sudo systemctl start upcdn-docker-events.service
