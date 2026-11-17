#!/bin/sh
# Cache config, routes, views and events at startup, when the server's environment is available,
# rather than at build time, so no secrets end up in the image. Every container built from the
# app image (app, horizon, scheduler, reverb, migrate) runs this first.
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
