#!/bin/sh
set -eu

if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

exec docker-php-entrypoint "$@"
