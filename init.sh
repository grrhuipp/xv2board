#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
[[ -f composer.lock ]] || { echo 'Missing reviewed composer.lock.' >&2; exit 1; }
composer_path=${V2BOARD_COMPOSER:-}
if [[ -z "$composer_path" ]]; then
    if [[ -f composer.phar ]]; then composer_path="$(pwd)/composer.phar"
    else composer_path=$(command -v composer || true); fi
fi
[[ -f "$composer_path" ]] || { echo 'Set V2BOARD_COMPOSER to an installed Composer PHP/PHAR file.' >&2; exit 1; }
composer=(php "$composer_path")
composer_version=$("${composer[@]}" --version)
[[ "$composer_version" == 'Composer version '* ]] || { echo 'Composer must be a PHP/PHAR file, not a shell wrapper.' >&2; exit 1; }
"${composer[@]}" validate --no-check-publish
"${composer[@]}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"${composer[@]}" check-platform-reqs --no-dev
php artisan v2board:install
echo 'Installation finished. Configure Nginx + PHP-FPM and supervised Horizon (docs/upgrade.md).'
