# ANKH production environment — mandatory agent preflight

**This repository's former production address and filesystem were retired after the October 2026 migration.** Read this file and [docs/CURRENT-PRODUCTION.md](docs/CURRENT-PRODUCTION.md) before suggesting URLs, issuing terminal commands, changing publishing scripts, or claiming any release is live.

## Verified current environment (migration records, 22–23 October 2026)

- Krystal/cPanel account: `devcouk`; account home: `/home/devcouk`.
- Git checkout: `/home/devcouk/repositories/ankh-admin-git`.
- Order Admin deployed files: `/home/devcouk/public_html/ankh-admin`.
- Customer portal deployed files: `/home/devcouk/public_html/ankh-order`.
- Confirmed migrated customer portal URL: `https://order.ankhpeptides.com`.
- Private config: `/home/devcouk/ankh-admin-config.php`.
- Admin source: `jamesjlynch/ankh-admin` on `main`. Portal work may have its own deployment flow/branch; do not conflate the app directories.
- **Do not guess an Admin public hostname.** An older request mentioned `admin.ankhpeptides.co.uk`, but its live DNS/current configuration must be confirmed before publishing a link.

## Strict prohibitions

1. Do not link, deploy, troubleshoot or publish to `lynchdev.co.uk` or `/home/lynchde1`. These are **legacy, not production**. References elsewhere in old README/docs are historical only.
2. Do not use `$HOME/repositories/ankh-admin` as the assumed Git checkout; the verified name is `ankh-admin-git`.
3. Do not equate a GitHub commit/PR merge, a passing CI check, or a successful simulated publisher test with successful live deployment.
4. Do not silently substitute Order Admin (`ankh-admin`) code for the separate customer-portal (`ankh-order`) code when the task specifically concerns the portal.
5. Do not overwrite live SQLite data/configuration or automatically infer that a deployment script matches the active cPanel checkout.

## Before every change or deployment

1. Identify whether the user means **Admin** or **Customer portal**, and match the correct deployed app directory.
2. Check the currently configured account/repository/branch and whether any migration replaced earlier data.
3. For live actions, verify the destination directory, Git HEAD, actual deploy method (Actions vs cron vs manual), required runtime files, deployment logs, and the current hostname.
4. Use the current full pathname in commands. Treat any stale historical path in files as a warning, not a source of truth.
5. Test the change against the targeted app, and explicitly distinguish **code updated**, **deployed**, and **verified live** in the response.
6. If the server or browser isn't accessible, say that clearly instead of presenting a guessed live URL or claiming completion.

All new migration updates should go into `docs/CURRENT-PRODUCTION.md`; update this file alongside it.
