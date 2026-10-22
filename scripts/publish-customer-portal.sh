#!/usr/bin/env bash
# Publish ONLY the new public customer portal to the cPanel subdomain's document root.
# Does not publish or modify ANKH Admin, its configuration or its live orders DB.
set -Eeuo pipefail
umask 077
trap 'code=$?; printf "PORTAL PUBLISH FAILED at line %s (exit %s). No success has been confirmed.\\n" "$LINENO" "$code" >&2; exit "$code"' ERR
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
root="${ANKH_CUSTOMER_PORTAL_ROOT:-}"
php="${ANKH_DEPLOY_PHP:-php}"
if [[ -z "$root" || "$root" != /* ]]; then
  echo "Set ANKH_CUSTOMER_PORTAL_ROOT to the ABSOLUTE cPanel subdomain document root." >&2
  exit 2
fi
if [[ ! -d "$root" || -L "$root" ]]; then
  echo "Subdomain root must already exist as a real directory. Create it in cPanel first." >&2
  exit 2
fi
target="$(cd -- "$root" && pwd -P)"
if [[ "$target" == "/" || "$target" == */ankh-admin || -f "$target/auth-users.php" || "$target" != *"/ankh-order" ]]; then
  echo "Refusing unsafe destination: expected a dedicated /ankh-order subdomain root." >&2
  exit 2
fi
config="${ANKH_PORTAL_CONFIG:-$(dirname "$(dirname "$target")")/ankh-customer-portal-config.php}"
if [[ ! -f "$config" ]]; then
  echo "Private portal config missing. Deployment blocked." >&2
  exit 2
fi
cd "$repo"
files=(customer-catalogue.php lib.php live-catalogue.php catalogue-demo.php catalogue-view.php customer-experience.php style.css account.css account.js icon.svg .htaccess view.php index.php preview.php preview-live.php)
source_for() { if [[ "$1" == "customer-catalogue.php" ]]; then printf "%s" "customer-catalogue.php"; else printf "customer-portal/%s" "$1"; fi; }
echo "STEP 1/4: Checking complete portal release"
for file in "${files[@]}"; do
  [[ -f "$(source_for "$file")" ]] || { echo "Missing customer-portal/$file" >&2; exit 1; }
done
echo "STEP 2/4: Running portal checks"
"$php" -l customer-portal/lib.php
"$php" -l customer-portal/index.php
"$php" -l customer-portal/view.php
"$php" -l customer-portal/customer-experience.php
"$php" -l customer-portal/catalogue-demo.php
"$php" -l customer-portal/catalogue-view.php
"$php" -l customer-portal/live-catalogue.php
"$php" -l customer-catalogue.php
"$php" -l customer-portal/preview-live.php
"$php" -l customer-portal/preview.php
"$php" -l scripts/configure-customer-preview.php
"$php" -l scripts/customer-portal-accounts.php
"$php" tests/customer-portal.php
"$php" tests/customer-portal-render.php
"$php" tests/customer-portal-catalogue.php
"$php" tests/customer-catalogue-visibility.php
for tab in home orders vault support account catalogue basket checkout test-orders; do "$php" tests/customer-portal-navigation.php "$tab"; done
"$php" tests/customer-portal-navigation.php orders 104
"$php" tests/customer-portal-navigation.php vault 103
echo "STEP 3/4: Backing up current portal code"
backup="$(dirname "$config")/ankh-customer-portal-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$backup"
chmod 700 "$(dirname "$backup")" "$backup"
for file in "${files[@]}"; do
  if [[ -f "$target/$file" ]]; then
    cp -p -- "$target/$file" "$backup/$file"
    chmod 600 "$backup/$file"
  fi
done
echo "STEP 4/4: Installing and verifying portal files"
for file in "${files[@]}"; do
  source="$(source_for "$file")"
  tmp="$target/.$file.deploy.$$"
  install -m 644 "$source" "$tmp"
  mv -f "$tmp" "$target/$file"
  cmp -s "$source" "$target/$file"
  printf 'VERIFIED: %s\n' "$file"
done
printf 'SUCCESS: All 15 customer portal files match the tested release.\n'
printf 'Destination: %s\nPrivate code backup: %s\n' "$target" "$backup"
printf 'This confirms files on disk. Check the web preview to confirm the served page.\n'

