#!/bin/sh
set -eu
if [ "${RENEWED_LINEAGE:-}" = /etc/letsencrypt/live/badger.mogwai.mira-gpt.org ]; then
    systemctl reload apache2
fi
