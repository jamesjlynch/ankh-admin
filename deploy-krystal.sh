#!/usr/bin/env bash
# ANKH Admin: fetch latest main and publish automatically (e.g. every 5 minutes).
set -Eeuo pipefail
umask 077
home=/home/lynchde1
log="$home/ankh-admin-deploy.log"
exec >> "$log" 2>&1
trap 'code=$?; echo "[ERROR] Automatic deploy failed (exit $code) at line $LINENO" >&2' ERR

exec 9>"$home/.ankh-admin-git.lock"
if ! flock -n 9; then exit 0; fi
cd "$home/repositories/ankh-admin"
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
/bin/bash scripts/publish-live.sh
