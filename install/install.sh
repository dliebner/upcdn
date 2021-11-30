#!/bin/bash

# init local files
mkdir -p ../local
mkdir -p ../logs
echo "<?php\
\
if( !defined('IN_SCRIPT') ) die( \"Hacking attempt\" );\
" > ../local/constants.php

# chmod dirs
chmod 0777 ../logs

# chmod files
chmod +x *.sh
chmod +x ../scripts/*
chmod +x ../daemon/*
chmod +x ../cron/*.sh
chmod +x ../devel/cpp-redis-pipe/*.sh

# general dependencies
sudo apt update
sudo apt install apt-transport-https ca-certificates curl gnupg lsb-release perl libnet-ssleay-perl openssl libauthen-pam-perl libpam-runtime libio-pty-perl apt-show-versions python unzip ufw zip g++ make cmake git

# webmin repo + key
echo "deb http://download.webmin.com/download/repository sarge contrib" | sudo tee /etc/apt/sources.list.d/webmin.list > /dev/null
wget -q -O- http://www.webmin.com/jcameron-key.asc | sudo apt-key add

#install webmin
sudo apt install webmin

# docker repo + key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# install docker
sudo apt update && sudo apt install docker-ce docker-ce-cli containerd.io

# pull docker transcoding image
docker pull dliebner/ffmpeg-entrydefault

# install LAMP
sudo apt install apache2 php7.4 php-cli php-dev libapache2-mod-fcgid php-fpm htop php-zip php-gd php-mbstring php-curl php-xml php-pear php-bcmath php-json php-common certbot python3-certbot-apache

# Collect installation details
read -p "CDN server hostname: " BGCDN_HOSTNAME
read -sp "MySQL root password (keep a copy handy): " BGCDN_MYSQL_ROOT_PASS
echo

# Set hostname
sudo hostnamectl set-hostname "${BGCDN_HOSTNAME}"

# Install mysql
echo "mysql-server mysql-server/root_password password $BGCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo "mysql-server mysql-server/root_password_again password $BGCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo 'Installing mysql ..'
sudo apt-get install mysql-server -y > /dev/null 2>&1
sudo apt-get install mysql-client expect -y > /dev/null 2>&1
echo "+-----------------------------+"

# secure MySQL installation
sudo mysql_secure_installation

# add bgcdn mysql user
MYSQL_BGCDN_USER="bgcdn_user"
read -sp "Set MYSQL_BGCDN_USER password: " MYSQL_BGCDN_PW
echo
printf -v MYSQL_BGCDN_PW "%q" "$MYSQL_BGCDN_PW" # escape the password
sudo mysql --execute="USE mysql;\
CREATE USER '${MYSQL_BGCDN_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_BGCDN_PW}';\
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_BGCDN_USER}'@'localhost';\
FLUSH PRIVILEGES;"
echo "\
define('MYSQL_BGCDN_PW', '${MYSQL_BGCDN_PW}');\
" >> ../local/constants.php
unset MYSQL_BGCDN_PW
sudo service mysql restart

# Import db schema
sudo mysql < schema.sql

# install postfix + dovecot
# ./install-postfix-dovecot.sh -p "${BGCDN_MYSQL_ROOT_PASS}" -d "${BGCDN_HOSTNAME}" -b "${MYSQL_BGCDN_PW}"

sudo ufw allow 'Apache Full'
sudo ufw allow 10000

sudo a2enmod proxy_fcgi setenvif expires headers
sudo a2enconf php7.4-fpm

# install phpMyAdmin
sudo apt install php-mysql phpmyadmin

# force SSL on phpMyAdmin
PMAACONF=/etc/phpmyadmin/apache.conf
cp $PMAACONF ${PMAACONF}.original
sed -i '/<Directory \/usr\/share\/phpmyadmin>/a\
\
    # bgcdn config - force SSL\
    RewriteEngine On\
    RewriteCond %{HTTPS} !=on\
    RewriteCond %{REQUEST_URI} ^/phpmyadmin\
    RewriteRule ^/?(.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\
' /etc/phpmyadmin/apache.conf

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
sudo a2dismod mpm_prefork
sudo a2enmod mpm_worker

# create bgcdn user
sudo useradd -m -s /bin/bash bgcdn
# add bgcdn user to www-data group
sudo usermod -a -G www-data bgcdn

# install composer
./install-composer.sh

# install composer extensions
cd /home/bgcdn
composer require gabrielelana/byte-units
composer require guzzlehttp/guzzle:^7
#composer require obregonco/backblaze-b2
composer config repositories.backblaze-b2 vcs https://github.com/dliebner/backblaze-b2
composer require dliebner/backblaze-b2:dev-master

cd /home/bgcdn/install

# replace occurences of BGCDN_HOSTNAME in source files
sed -i "s/\$BGCDN_HOSTNAME/$BGCDN_HOSTNAME/" ../devel/cpp-redis-pipe/redis-pipe.cc

# build stuff
../devel/cpp-redis-pipe/build.sh

# copy files
sudo cp bgcdn-apache.conf /etc/apache2/sites-available/${BGCDN_HOSTNAME}.conf
sudo cp ../devel/cpp-redis-pipe/redis-pipe ../scripts/
sudo cp bgcdn-bw-redis-pipe.conf /etc/apache2/conf-available/
sudo cp bgcdn-sudoers /etc/sudoers.d/
sudo cp bgcdn-cron /etc/cron.d/
sudo cp bgcdn-docker-events.service /etc/systemd/system/

# replace occurences of BGCDN_HOSTNAME in copied conf files
sed -i "s/\$BGCDN_HOSTNAME/$BGCDN_HOSTNAME/" /etc/apache2/sites-available/${BGCDN_HOSTNAME}.conf
# replace occurences of BGCDN_HOSTNAME in copied scripts
sed -i "s/\$BGCDN_HOSTNAME/$BGCDN_HOSTNAME/" /home/bgcdn/scripts/redis-pipe.sh

# soft link
sudo ln -rs /etc/apache2/sites-available/${BGCDN_HOSTNAME}* /etc/apache2/sites-enabled/
sudo ln -rs /etc/apache2/conf-available/bgcdn* /etc/apache2/conf-enabled/

# chown files
chown -R bgcdn:bgcdn /home/bgcdn/

# restart apache
sudo service apache2 restart
sudo service php7.4-fpm restart

# start custom daemons
sudo systemctl enable bgcdn-docker-events.service
sudo systemctl start bgcdn-docker-events.service
