#!/usr/bin/env bash
# Disposable end-to-end publisher test; never touches Krystal or production data.
set -Eeuo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
temp="$(mktemp -d)"
trap 'rm -rf -- "$temp"' EXIT
mkdir -p "$temp/repo/scripts" "$temp/repo/tests" "$temp/home" "$temp/home/public_html"
cp "$root/scripts/publish-live.sh" "$temp/repo/scripts/"
cd "$temp/repo"
git init -q
git config user.email "tests@ankh.invalid"
git config user.name "ANKH CI"
for file in .htaccess business-alerts.php auth-users.php customer-catalogue.php catalogue-admin.js index.php supplier-centre.php shipping-intelligence.php setup.php style.css style.min.css style.core.min.css manifest.webmanifest service-worker.js offline.html icon.svg splash.svg; do
  case "$file" in
    *.php) printf '%s\n' '<?php echo "ok";' > "$file" ;;
    *) printf '%s\n' "fixture $file" > "$file" ;;
  esac
done
for check in delivery-queue business-alerts customer-catalogue-visibility; do printf '%s\n' '<?php echo "fixture check passed";' > "tests/$check.php"; done
git add .
git commit -qm "baseline"
first="$(git rev-parse HEAD)"
export ANKH_DEPLOY_HOME="$temp/home"
export ANKH_DEPLOY_PHP="$(command -v php)"
bash scripts/publish-live.sh
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$first" ]]
cmp -s index.php "$temp/home/public_html/ankh-admin/index.php"
[[ ! -e "$temp/home/ankh-admin-backups" ]] || { echo 'Publisher unexpectedly created a database backup'; exit 1; }

# Repeat the same release: no needless deployment or extra backup.
bash scripts/publish-live.sh
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$first" ]]

# A bad PHP commit must be rejected before the live copy is changed.
printf '%s\n' '<?php syntax error' > index.php
git add index.php
git commit -qm "bad php fixture"
if bash scripts/publish-live.sh; then echo 'Broken PHP must not be published'; exit 1; fi
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$first" ]]
cmp -s <(printf '%s\n' '<?php echo "ok";') "$temp/home/public_html/ankh-admin/index.php"

# A subsequent valid commit must publish without touching SQLite.
printf '%s\n' '<?php echo "updated";' > index.php
git add index.php
git commit -qm "new fixture"
last="$(git rev-parse HEAD)"
bash scripts/publish-live.sh
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$last" ]]
cmp -s index.php "$temp/home/public_html/ankh-admin/index.php"
echo 'PASS: one-step publishing, repeat skip, PHP preflight and data-independent release'
