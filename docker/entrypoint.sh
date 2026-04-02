#!/usr/bin/env bash
set -euo pipefail

PHP_MEMORY_LIMIT_VALUE="${PHP_MEMORY_LIMIT:-1024M}"
printf "memory_limit=%s\n" "${PHP_MEMORY_LIMIT_VALUE}" > /usr/local/etc/php/conf.d/99-memory-limit.ini

for writable in /var/www/html/var /var/www/html/tile; do
  if [[ -e "$writable" ]]; then
    chmod -R a+rwx "$writable" || true
  fi
done

if [[ $# -gt 0 ]]; then
  exec "$@"
fi

exec apache2-foreground
