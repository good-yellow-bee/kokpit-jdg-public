#!/bin/sh
set -eu
config=$(php-fpm -tt 2>&1)
value() {
    printf '%s\n' "$config" | sed -n "s/.*NOTICE:.*[[:space:]]$1 = //p"
}
[ "$(value listen)" = '127.0.0.1:9000' ]
[ "$(value listen.allowed_clients)" = '127.0.0.1' ]
[ "$(value access.log)" = '/dev/null' ]
[ "$(value access.format)" = '%m %s' ]
printf '%s\n' 'FPM OK: listen=127.0.0.1:9000; allowed_clients=127.0.0.1; access.log=/dev/null; access.format=%m %s'
