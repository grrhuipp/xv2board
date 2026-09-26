#!/bin/bash

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

git config --global --add safe.directory $(pwd)
git fetch --all && git reset --hard origin/master && git pull origin master
rm -rf composer.lock composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar update -vvv

php_main_version=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1)
if [ $php_main_version -ge 8 ]; then
    php composer.phar require joanhey/adapterman
    if ! php -m | grep -q "pcntl"; then
        echo "Adding pcntl extension to cli-php.ini"
        sed -i '/extension=redis.so/a extension=pcntl.so' cli-php.ini
    fi
    php -c cli-php.ini webman.php stop
    echo "Webman stopped.Please restart it by yourself."
fi

php artisan v2board:update

if [ -f "/etc/init.d/bt" ]; then
  chown -R www $(pwd);
elif [ -f "scripts/fix-permissions.sh" ]; then
  # 非宝塔环境：git 拉取的新文件属主是执行者（常为 root），而 PHP-FPM
  # 多以别的用户运行。config/ 与 storage/ 需要被 PHP 进程写入
  # （后台保存配置会直接改写 config/v2board.php），属主不对会导致
  # 保存配置报 file_put_contents ... Permission denied 且配置存不进去。
  # 自动探测 PHP 运行用户并修正属主，省掉每次升级手工 chown。
  bash scripts/fix-permissions.sh || true
fi
