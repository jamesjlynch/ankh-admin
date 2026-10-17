#!/bin/bash
# Automatic Krystal release: publish only after a verified private data snapshot.
set -Eeuo pipefail
umask 077
log=/home/lynchde1/ankh-admin-deploy.log
exec > >(tee -a "$log") 2>&1
trap 'rc=$?; echo "ANKH deployment FAILED (exit $rc) at line $LINENO; inspect $log" >&2' ERR
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Automatic deployment check"
exec 9>/home/lynchde1/.ankh-admin-deploy.lock
if ! flock -n 9; then echo "A previous deployment is still running; skipping this check."; exit 0; fi
cd /home/lynchde1/repositories/ankh-admin
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Deployment stopped: repository contains local changes (not overwritten):"
  git status --short
  exit 1
fi
git fetch origin main
git merge --ff-only origin/main
release="$(git rev-parse HEAD)"
target=/home/lynchde1/public_html/ankh-admin
marker=/home/lynchde1/.ankh-admin-last-deployed-sha
files=(.htaccess style.css style.min.css style.core.min.css setup.php index.php supplier-centre.php manifest.webmanifest service-worker.js offline.html icon.svg splash.svg)

# The recurring check must not create a database backup and code snapshot
# every five minutes when GitHub has not supplied a new release.
if [[ -f "$marker" && "$(cat "$marker")" == "$release" ]]; then
  all_match=1
  for file in "${files[@]}"; do
    if ! cmp -s "$file" "$target/$file"; then all_match=0; break; fi
  done
  if ((all_match)); then
    echo "Already published ${release:0:12}; no new snapshot necessary."
    exit 0
  fi
  echo "Release marker matches but live files differ; restoring verified release."
fi

/opt/cpanel/ea-php82/root/usr/bin/php -l index.php
/opt/cpanel/ea-php82/root/usr/bin/php -l setup.php
/opt/cpanel/ea-php82/root/usr/bin/php -l supplier-centre.php
/opt/cpanel/ea-php82/root/usr/bin/php -l scripts/backup-sqlite.php
/opt/cpanel/ea-php82/root/usr/bin/php -r 'if (PHP_VERSION_ID < 80100 || !extension_loaded("pdo_sqlite")) { fwrite(STDERR, "PHP 8.1+ and PDO SQLite required\n"); exit(1); }'
# Use the known private config path, not an environment-dependent HOME variable.
ANKH_ADMIN_CONFIG=/home/lynchde1/ankh-admin-config.php /opt/cpanel/ea-php82/root/usr/bin/php scripts/backup-sqlite.php
bash scripts/snapshot-live.sh
mkdir -p /home/lynchde1/ankh-admin-tools
chmod 700 /home/lynchde1/ankh-admin-tools
install -m 700 scripts/backup-sqlite.php /home/lynchde1/ankh-admin-tools/backup-sqlite.php
install -m 700 scripts/rollback-release.sh /home/lynchde1/ankh-admin-tools/rollback-release.sh
mkdir -p "$target"
for file in "${files[@]}"; do
  install -m 644 "$file" "$target/.$file.deploy"
  mv "$target/.$file.deploy" "$target/$file"
done
printf '%s\n' "$release" > "$marker.tmp"
chmod 600 "$marker.tmp"
mv "$marker.tmp" "$marker"
echo "Published ${release:0:12} successfully at $(date -u +%Y-%m-%dT%H:%M:%SZ)"
