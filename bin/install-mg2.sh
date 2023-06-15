#!/bin/bash

sudo pecl install -f xdebug-2.9.8

echo "Getting Magento 2.4.5..."
curl -LO https://github.com/magento/magento2/archive/refs/tags/2.4.3.zip
unzip -qq 2.4.3.zip
mv magento2-2.4.3 magento2

cd magento2

echo "Installing..."
composer update
composer install

bin/magento --version
sudo chmod -Rf 777 var/ pub/ generated/ app/etc/env.php
php -d memory_limit=5G bin/magento
bin/magento setup:upgrade
bin/magento module:enable --all --clear-static-content
php -d memory_limit=5G bin/magento setup:di:compile

rm -rf ../2.4.3.zip
