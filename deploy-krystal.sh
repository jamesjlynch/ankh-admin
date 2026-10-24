#!/usr/bin/env bash
# ANKH Admin: fetch latest main and publish automatically (e.g. every 5 minutes).
set -Eeuo pipefail
umask 077
# Deploy from the account running this checkout, not the retired account.
home="${ANKH_DEPLOY_HOME:-${HOME:-}}"
if [[ -z "$home" || "$home" != /* || ! -d "$home" ]]; then
  echo "ANKH: Cannot determine a valid hosting account home" >&2
  exit 1
fi
# Preserve the explicit freeze on the original server.
if [[ "$home" == "/home/lynchde1" ]]; then
  echo "ANKH: old hosting account remains frozen." >&2
  exit 0
fi
log="$home/ankh-admin-deploy.log"
exec >> "$log" 2>&1
trap 'code=$?; echo "[ERROR] Automatic deploy failed (exit $code) at line $LINENO" >&2' ERR

exec 9>"$home/.ankh-admin-git.lock"
if ! flock -n 9; then exit 0; fi
# Locate the checked-out repository from this script to support server migrations.
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$repo"
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "Cannot auto-deploy: repository is not on main"
  exit 1
fi
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Cannot auto-deploy: local repository changes need resolving"
  git status --short
  exit 1
fi

git fetch origin main
git merge --ff-only origin/main
ANKH_DEPLOY_HOME="$home" /bin/bash scripts/publish-live.sh
