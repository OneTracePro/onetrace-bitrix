#!/usr/bin/env bash
# Integration tests on a real 1C-Bitrix "Small business" (the trial distribution, the latest version) in Docker:
# the distribution is downloaded once into .cache, the installation wizard is passed without a browser (wizard.py),
# the module is installed from the working copy, PHPUnit runs inside the container.
#   composer install && tests/env/run.sh              — PHP 8.3
#   PHP_VERSION=8.2 tests/env/run.sh --filter Orders  — another PHP (docker compose down -v first to switch)
set -euo pipefail
cd "$(dirname "$0")"
export PHP_VERSION="${PHP_VERSION:-8.3}" BITRIX_PORT="${BITRIX_PORT:-8895}"
DIST=.cache/small_business_encode.tar.gz

if [ ! -s "$DIST" ]; then
    mkdir -p .cache
    # ~375 MB: a broken transfer resumes where it stopped.
    curl -fsSL --retry 5 --retry-all-errors --retry-delay 10 -C - -A 'Mozilla/5.0' -o "$DIST.part" https://www.1c-bitrix.ru/download/small_business_encode.tar.gz
    gzip -t "$DIST.part" && mv "$DIST.part" "$DIST"
fi

# Docker Hub sometimes times out on CI runners: the images are pulled with retries before the start.
for try in 1 2 3 4 5; do
    docker compose pull -q db && docker compose build -q web && break
    [ "$try" = 5 ] && exit 1
    sleep $((try * 15))
done
docker compose up -d --wait >/dev/null

if ! docker compose exec -T web test -f /var/www/html/bitrix/php_interface/dbconn.php; then
    docker compose cp "$DIST" web:/tmp/distrib.tar.gz >/dev/null
    # The module directory is mounted into local/: the archive must not touch it, nor its owner.
    docker compose exec -T web sh -c 'tar xzf /tmp/distrib.tar.gz -C /var/www/html --exclude=./local && rm /tmp/distrib.tar.gz \
        && find /var/www/html -path /var/www/html/local -prune -o -exec chown www-data:www-data {} +'
    python3 wizard.py --url "http://localhost:$BITRIX_PORT" --db-host db --db-name bitrix --db-user bitrix --db-password bitrix \
        --admin-password "$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')Aa1!" --skip-updates --solution eshop --demo yes
fi

docker compose exec -T web php /var/www/html/local/modules/onetrace.connector/tests/env/install-module.php
docker compose exec -T -w /var/www/html/local/modules/onetrace.connector web php vendor/bin/phpunit "$@"
