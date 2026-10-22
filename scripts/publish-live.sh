#!/usr/bin/env bash
# ANKH Admin: one publishing path for scheduled deploys and cPanel Git.
# Deliberately does not open, move or back up the live SQLite database.
set -Eeuo pipefail
umask 077

home=${ANKH_DEPLOY_HOME:-/home/lynchde1}
# Keep the original hosting account frozen during the new-server authentication migration.
if [[ "$home" == "/home/lynchde1" ]]; then
  echo "ANKH: old hosting account deployment intentionally disabled; original installation unchanged." >&2
  exit 0
fi
log="$home/ankh-admin-deploy.log"
exec >> "$log" 2>&1
trap 'code=$?; echo "[ERROR] Publish failed (exit $code) at line $LINENO" >&2' ERR

repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo"
exec 9>"$home/.ankh-admin-publish.lock"
if ! flock -n 9; then
  echo "Publish already in progress; skipping."
  exit 0
fi

release="$(git rev-parse HEAD)"
target="$home/public_html/ankh-admin"
marker="$home/.ankh-admin-last-deployed-sha"
php=${ANKH_DEPLOY_PHP:-/opt/cpanel/ea-php82/root/usr/bin/php}
files=(.htaccess business-alerts.php auth-users.php customer-catalogue.php index.php supplier-centre.php shipping-intelligence.php setup.php style.css style.min.css style.core.min.css manifest.webmanifest service-worker.js offline.html icon.svg splash.svg)

# A successful commit is not a successful deployment; compare the actual live files.
if [[ -f "$marker" && "$(cat "$marker")" == "$release" ]]; then
  current=1
  for file in "${files[@]}"; do
    if ! cmp -s "$file" "$target/$file"; then current=0; break; fi
  done
  if ((current)); then exit 0; fi
fi

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Publishing ${release:0:12}"
for file in "${files[@]}"; do
  [[ -f "$file" ]] || { echo "Missing required file: $file" >&2; exit 1; }
done
"$php" -l business-alerts.php
"$php" -l auth-users.php
"$php" -l index.php
"$php" -l customer-catalogue.php
"$php" tests/customer-catalogue-visibility.php
"$php" tests/delivery-queue.php
"$php" tests/business-alerts.php
"$php" -l setup.php
"$php" -l supplier-centre.php
"$php" -l shipping-intelligence.php
"$php" -r 'if (PHP_VERSION_ID < 80100 || !extension_loaded("pdo_sqlite")) { fwrite(STDERR, "PHP 8.1+ with PDO SQLite required\\n"); exit(1); }'
mkdir -p "$target"
[[ ! -L "$target" ]] || { echo "Refusing symlinked live directory" >&2; exit 1; }

# Replace each static/PHP asset atomically. Customer data lives elsewhere.
for file in "${files[@]}"; do
  stage="$target/.$file.deploy.$$"
  install -m 644 "$file" "$stage"
  mv -f "$stage" "$target/$file"
done

printf '%s\n' "$release" > "$marker.tmp"
chmod 600 "$marker.tmp"
mv -f "$marker.tmp" "$marker"
echo "[OK] Published ${release:0:12} at $(date -u +%Y-%m-%dT%H:%M:%SZ)"
