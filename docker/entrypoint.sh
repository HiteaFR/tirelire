#!/bin/sh
# Start of the Tirelire container: application key, SQLite database, migrations, caches, then the
# command received (FrankenPHP by default).
#
# All persistent state lives in $TIRELIRE_DATA_DIR (the volume):
#   app.key          key generated on first start when APP_KEY is not provided
#   database.sqlite  the database
#   .seeded          marker: the generic categories have been created
set -eu

data_dir="${TIRELIRE_DATA_DIR:-/var/lib/tirelire}"
export DB_DATABASE="${DB_DATABASE:-$data_dir/database.sqlite}"

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$data_dir/app.key" ]; then
        (umask 077 && php artisan key:generate --show --no-interaction > "$data_dir/app.key")
        echo "tirelire: application key generated in $data_dir/app.key"
    fi
    APP_KEY="$(cat "$data_dir/app.key")"
    export APP_KEY
fi

[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

php artisan migrate --force --no-interaction

# Generic categories and rules, once: seeding again on every start would recreate the categories
# deleted in the app. The marker is written only after a successful seed.
if [ ! -f "$data_dir/.seeded" ]; then
    php artisan db:seed --force --no-interaction
    touch "$data_dir/.seeded"
fi

php artisan optimize --no-interaction

exec "$@"
