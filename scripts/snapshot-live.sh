#!/bin/bash
# Code rollback snapshot; the database is backed up separately.
set -euo pipefail
umask 077
home=/home/lynchde1
target="$home/public_html/ankh-admin"
releases="$home/ankh-admin-releases"
mkdir -p "$releases"
chmod 700 "$releases"
stamp="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"
dest="$releases/$stamp"
mkdir -m 700 "$dest"
for file in .htaccess index.php supplier-centre.php setup.php style.css style.min.css style.core.min.css service-worker.js manifest.webmanifest offline.html icon.svg splash.svg; do
 if [[ -f "$target/$file" ]]; then install -m 600 "$target/$file" "$dest/$file"; fi
done
printf '%s\n' "$stamp" > "$releases/latest-snapshot"
echo "Saved previous web release: $stamp"
