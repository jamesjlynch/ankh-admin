#!/usr/bin/env bash
# Publish ONLY the new public customer portal to the cPanel subdomain's document root.
# Does not publish or modify ANKH Admin, its configuration or its live orders DB.
set -Eeuo pipefail
umask 077
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
"$php" -l customer-portal/lib.php
"$php" -l customer-portal/index.php
"$php" -l scripts/customer-portal-accounts.php
"$php" tests/customer-portal.php
for file in index.php lib.php view.php preview.php style.css icon.svg .htaccess; do
  source="customer-portal/$file"
  [[ -f "$source" ]] || { echo "Missing $source" >&2; exit 1; }
  tmp="$target/.$file.deploy.$$"
  install -m 644 "$source" "$tmp"
  mv -f "$tmp" "$target/$file"
done
printf 'Published ANKH Customer Portal to %s. Admin unchanged.\n' "$target"
