#!/bin/sh
set -eu

MEDIA_DIR="/var/www/html/public/media"

mkdir -p "$MEDIA_DIR"

if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data "$MEDIA_DIR"
    chmod -R ug+rwX "$MEDIA_DIR"
fi
