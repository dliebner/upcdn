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

# general dependencies
sudo apt update
sudo apt install apt-transport-https ca-certificates curl gnupg lsb-release perl libnet-ssleay-perl openssl libauthen-pam-perl libpam-runtime libio-pty-perl apt-show-versions python unzip ufw zip g++ make cmake git

# webmin repo + key
echo "deb http://download.webmin.com/download/repository sarge contrib" | sudo tee /etc/apt/sources.list.d/webmin.list > /dev/null
wget -q -O- http://www.webmin.com/jcameron-key.asc | sudo apt-key add

#install webmin
sudo apt update && sudo apt install webmin

# docker repo + key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# install docker
sudo apt update && sudo apt install docker-ce docker-ce-cli containerd.io

# pull docker transcoding image
docker pull dliebner/ffmpeg-entrydefault

# install LAMP
sudo apt install apache2 php7.4 php-cli php-dev libapache2-mod-fcgid php-fpm htop php-zip php-gd php-mbstring php-curl php-xml php-pear php-bcmath php-json php-gmp php-common certbot python3-certbot-apache

# Collect installation details
read -p "CDN server hostname: " UPCDN_HOSTNAME
read -sp "MySQL root password (keep a copy handy): " UPCDN_MYSQL_ROOT_PASS
echo

# Set mysql root credentials for root user
echo "[client]
user = \"root\"
password = \"${UPCDN_MYSQL_ROOT_PASS}\"" | sudo tee /root/.my.cnf > /dev/null
sudo chmod 0600 /root/.my.cnf

# Set hostname
sudo hostnamectl set-hostname "${UPCDN_HOSTNAME}"

# Install mysql
echo "mysql-server mysql-server/root_password password $UPCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo "mysql-server mysql-server/root_password_again password $UPCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo 'Installing mysql ..'
sudo apt-get install mysql-server -y
sudo apt-get install mysql-client expect -y
echo "+-----------------------------+"

# secure MySQL installation
sudo mysql_secure_installation
sleep 1

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

# install postfix + dovecot
# ./install-postfix-dovecot.sh -p "${UPCDN_MYSQL_ROOT_PASS}" -d "${UPCDN_HOSTNAME}" -b "${MYSQL_UPCDN_PW}"

# ufw rules
sudo ufw allow ssh
sudo ufw allow 'Apache Full'
sudo ufw allow 10000

echo "y" | sudo ufw enable

sudo a2enmod rewrite proxy_fcgi setenvif expires headers
sudo a2enconf php7.4-fpm

# install phpMyAdmin
sudo apt install php-mysql phpmyadmin

# force SSL on phpMyAdmin
PMAACONF=/etc/phpmyadmin/apache.conf
sudo cp $PMAACONF ${PMAACONF}.original
sudo sed -i '/<Directory \/usr\/share\/phpmyadmin>/a\
\
    # upcdn config - force SSL\
    RewriteEngine On\
    RewriteCond %{HTTPS} !=on\
    RewriteCond %{REQUEST_URI} ^/phpmyadmin\
    RewriteRule ^/?(.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\
' /etc/phpmyadmin/apache.conf

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

# install redis
sudo apt install redis-server
sudo sed -i 's/^supervised no$/supervised systemd/g' /etc/redis/redis.conf

# install php-redis
pecl install redis
echo "extension=redis.so" | sudo tee /etc/php/7.4/mods-available/redis.ini > /dev/null
sudo ln -s /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/fpm/conf.d/20-redis.ini
sudo ln -s /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/cli/conf.d/20-redis.ini

# switch to mpm_worker
sudo a2dismod php7.4 mpm_prefork
sudo a2enmod mpm_worker

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
