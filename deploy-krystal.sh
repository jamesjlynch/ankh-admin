#!/bin/bash
set -euo pipefail
exec 9>/home/lynchde1/.ankh-admin-deploy.lock
flock -n 9 || exit 0
cd /home/lynchde1/repositories/ankh-admin
test -z "$(git status --porcelain)" || { echo 'Working tree has local changes; deployment stopped.'; exit 1; }
git fetch origin main
git merge --ff-only origin/main
/opt/cpanel/ea-php82/root/usr/bin/php -l index.php
/opt/cpanel/ea-php82/root/usr/bin/php -l setup.php
/opt/cpanel/ea-php82/root/usr/bin/php -r 'if (PHP_VERSION_ID < 80100 || !extension_loaded("pdo_sqlite")) { fwrite(STDERR, "PHP 8.1+ and PDO SQLite required\n"); exit(1); }'
target=/home/lynchde1/public_html/ankh-admin
mkdir -p "$target"
for file in .htaccess style.css setup.php index.php manifest.webmanifest service-worker.js offline.html icon.svg splash.svg; do
  install -m 644 "$file" "$target/.$file.deploy"
  mv "$target/.$file.deploy" "$target/$file"
done
echo "Published $(git rev-parse --short HEAD)"
