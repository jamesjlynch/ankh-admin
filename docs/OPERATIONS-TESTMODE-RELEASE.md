# ANKH Admin Operations Release

> **HISTORICAL DOCUMENT — PRE-MIGRATION DEPLOYMENT INFORMATION.** The `/home/lynchde1` paths, jobs and URLs below are retired and MUST NOT be run. The current verified paths and safeguards are in [CURRENT-PRODUCTION.md](CURRENT-PRODUCTION.md) and [AGENTS.md](../AGENTS.md). Do not infer live publication from GitHub alone.


This release keeps the existing test-access behaviour and preserves the current black-and-gold layout. It upgrades daily operations without enabling or deploying any account-enrolment changes.

## What is included
- Daily action worklist, preparation counts, delivery-ready counts and overdue indicators
- One-tap reconstitution and packaging via the existing server-side workflow
- Manually confirmed delivery action; planned dates never mark an order delivered automatically
- Activity history on each order and under More, recording new order, payment, preparation and delivery changes
- Isolated SQLite migrations: one new activity_events table, no deletion or rewriting of existing customers, orders, items, stock or payments
- Existing header, navigation, six top metric cards, notification centre and customer intelligence remain unchanged

## Deployment — make changes, publish changes

The same publisher is used by both existing deployment methods:

- **Automatic:** the Krystal scheduled task runs `/bin/bash /home/lynchde1/repositories/ankh-admin/deploy-krystal.sh`. It fetches and fast-forwards GitHub `main`, then invokes `scripts/publish-live.sh`.
- **Manual fallback:** cPanel Git's Deploy HEAD runs `.cpanel.yml`, which invokes the **same** `scripts/publish-live.sh`.

On a changed release, the publisher checks the required files, lints PHP, copies the web assets and writes `/home/lynchde1/.ankh-admin-last-deployed-sha`. The publisher logs output to `/home/lynchde1/ankh-admin-deploy.log` and skips copies when the deployed commit and all live assets match.

**Database backup is not a requirement for ordinary code publishing.** Neither deployment method reads, copies, migrates or overwrites the private SQLite database. App-level schema migrations, if any, execute separately at runtime, so take a verified database backup before intentional schema-changing releases.

These GitHub scripts **cannot create, repair or restart a missing/disabled Krystal cron job**. On the host, verify the above cron command exists and that the log records a successful publish. Also verify `git fetch` works from the hosting account.

## Database backups (independent of publishing)

Keep daily consistent SQLite backups in a separate, scheduled job using `scripts/backup-sqlite.php`. The backup script verifies database integrity, stores 0600 snapshots outside public_html and retains 30 snapshots.

If the scheduled backup job has not yet been configured, add a separate daily cron entry that runs the checked-in backup utility:

    15 3 * * * /usr/bin/env ANKH_ADMIN_CONFIG=/home/lynchde1/ankh-admin-config.php /opt/cpanel/ea-php82/root/usr/bin/php /home/lynchde1/repositories/ankh-admin/scripts/backup-sqlite.php >> /home/lynchde1/ankh-admin-backup.log 2>&1

Daily backup setup needs host access; GitHub merging cannot install a cron task. Periodically test an off-host recovery.

## Rollback
Use the privately saved code snapshots in /home/lynchde1/ankh-admin-releases. The private rollback-release.sh restores web code assets only and leaves the original SQLite database untouched. The new activity_events table can remain in the database after restoring older code.

Database restore is a separate, deliberately manual operation: restoring an old database snapshot can discard orders and payments recorded since that snapshot.

## QA coverage
PHP lint, a disposable real publishing simulation, and the current repository regression suite plus:
- tests/operations-testmode.php validates unchanged access and additive migration in an isolated fixture
- tests/backup-sqlite.php validates private consistent snapshot and file permissions
- tests/viewport-testmode.mjs exercises Chromium at 390, 768, 820, 1024, 1180 and 1440 pixels, plus real one-tap preparation and delivery actions and history views

Any live deployment still requires checking the Krystal release output and physically checking the live mobile/tablet view. Do not claim hosting cron or live verification completed solely from GitHub CI.
