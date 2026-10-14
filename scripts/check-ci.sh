#!/bin/sh
set -eu
vendor/bin/phpstan analyse --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff
composer check-licenses
composer fixtures:scan
vendor/bin/phpunit "$@"
