#!/bin/bash
# Restore saved web code only; never overwrite customer records or passwords.
set -euo pipefail
umask 077
if [[ $# -ne 1 || ! "$1" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{7,12}$ ]]; then
 echo "Usage: bash rollback-release.sh YYYYMMDDTHHMMSSZ-commit" >&2; exit 2
fi
base=/home/lynchde1/ankh-admin-releases
release="$base/$1"
target=/home/lynchde1/public_html/ankh-admin
[[ -d "$release" && ! -L "$release" ]] || { echo "No matching saved release." >&2; exit 1; }
# A previous ANKH version permitted public access. Never roll back to an unsafe snapshot.
[[ -f "$release/index.php" ]] || { echo "Snapshot has no index.php; refusing rollback." >&2; exit 1; }
if grep -Eq '\$testingNoAuth[[:space:]]*=[[:space:]]*true' "$release/index.php"; then
 echo "SECURITY BLOCK: this release enables public test mode. Rollback refused." >&2
 exit 1
fi
/opt/cpanel/ea-php82/root/usr/bin/php -l "$release/index.php" >/dev/null

for file in .htaccess index.php supplier-centre.php setup.php style.css style.min.css style.core.min.css service-worker.js manifest.webmanifest offline.html icon.svg splash.svg; do
 [[ -f "$release/$file" ]] || continue
 install -m 644 "$release/$file" "$target/.$file.rollback"
 mv "$target/.$file.rollback" "$target/$file"
done
echo "Restored code release $1. SQLite data left untouched."
