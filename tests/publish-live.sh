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
for file in .htaccess business-alerts.php auth-users.php customer-catalogue.php customer-cms.php customer-cms.css customer-cms.js public-catalogue-core.php public-catalogue.php catalogue-admin.js admin-visuals.js admin-visuals.css premium-v2.css product-v2.css product-v2.php packing-v2.css packing-v2.php packing-v2-support.php index.php supplier-centre.php shipping-intelligence.php setup.php style.css style.min.css style.core.min.css manifest.webmanifest service-worker.js offline.html icon.svg splash.svg; do
  case "$file" in
    *.php) printf '%s\n' '<?php echo "ok";' > "$file" ;;
    *) printf '%s\n' "fixture $file" > "$file" ;;
  esac
done
for check in delivery-queue business-alerts customer-catalogue-visibility customer-cms public-catalogue; do printf '%s\n' '<?php echo "fixture check passed";' > "tests/$check.php"; done
git add .
git commit -qm "baseline"
first="$(git rev-parse HEAD)"
export ANKH_DEPLOY_HOME="$temp/home"
export ANKH_DEPLOY_PHP="$(command -v php)"
bash scripts/publish-live.sh
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$first" ]]
cmp -s index.php "$temp/home/public_html/ankh-admin/index.php"
cmp -s admin-visuals.js "$temp/home/public_html/ankh-admin/admin-visuals.js"
cmp -s admin-visuals.css "$temp/home/public_html/ankh-admin/admin-visuals.css"
cmp -s premium-v2.css "$temp/home/public_html/ankh-admin/premium-v2.css"
cmp -s customer-cms.php "$temp/home/public_html/ankh-admin/customer-cms.php"
cmp -s customer-cms.css "$temp/home/public_html/ankh-admin/customer-cms.css"
cmp -s customer-cms.js "$temp/home/public_html/ankh-admin/customer-cms.js"
cmp -s product-v2.css "$temp/home/public_html/ankh-admin/product-v2.css"
cmp -s product-v2.php "$temp/home/public_html/ankh-admin/product-v2.php"
cmp -s packing-v2.php "$temp/home/public_html/ankh-admin/packing-v2.php"
cmp -s packing-v2-support.php "$temp/home/public_html/ankh-admin/packing-v2-support.php"
cmp -s packing-v2.css "$temp/home/public_html/ankh-admin/packing-v2.css"
[[ ! -e "$temp/home/ankh-admin-backups" ]] || { echo 'Publisher unexpectedly created a database backup'; exit 1; }

# Repeat the same release: no needless deployment or extra backup.
bash scripts/publish-live.sh
[[ "$(cat "$temp/home/.ankh-admin-last-deployed-sha")" == "$first" ]]

# The same SHA must heal missing or stale UI assets rather than skipping deployment.
rm "$temp/home/public_html/ankh-admin/admin-visuals.css"
bash scripts/publish-live.sh
cmp -s admin-visuals.css "$temp/home/public_html/ankh-admin/admin-visuals.css"
printf '%s\n' 'outdated' > "$temp/home/public_html/ankh-admin/admin-visuals.js"
bash scripts/publish-live.sh
cmp -s admin-visuals.js "$temp/home/public_html/ankh-admin/admin-visuals.js"

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
