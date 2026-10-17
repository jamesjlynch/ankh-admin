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

# Keep the newest 30 private *code* rollback points, separate from SQLite backups.
# Before this change the five-minute auto-deploy check could accumulate one
# complete code snapshot on every run, even without a new GitHub revision.
mapfile -t old_releases < <(find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -E '^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{7,12}$' | sort -r || true)
for old in "${old_releases[@]:30}"; do
  [[ "$old" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{7,12}$ ]] || continue
  rm -rf -- "$releases/$old"
done
