# ANKH Admin Operations Release

This release keeps the existing test-access behaviour and preserves the current black-and-gold layout. It upgrades daily operations without enabling or deploying any account-enrolment changes.

## What is included
- Daily action worklist, preparation counts, delivery-ready counts and overdue indicators
- One-tap reconstitution and packaging via the existing server-side workflow
- Manually confirmed delivery action; planned dates never mark an order delivered automatically
- Activity history on each order and under More, recording new order, payment, preparation and delivery changes
- Isolated SQLite migrations: one new activity_events table, no deletion or rewriting of existing customers, orders, items, stock or payments
- Existing header, navigation, six top metric cards, notification centre and customer intelligence remain unchanged

## Deployment
Both supported Krystal deploy paths lint the PHP, take a consistent, verified SQLite backup with VACUUM INTO, create a private code snapshot, then copy the revised web files. If backup verification or the deployment checks fail, the release aborts before live files are replaced.

The backup tool creates 0600 files outside public_html, keeps the latest 30 snapshots and verifies database integrity and counts. Daily backups after deployment require adding a scheduled cron job on the host; a GitHub merge cannot install cron automatically. Suggested daily task:

    15 3 * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/lynchde1/ankh-admin-tools/backup-sqlite.php >> /home/lynchde1/ankh-admin-backup.log 2>&1

Run and verify the command manually before scheduling. For resilience, make periodic off-host copies and test a restore in a separate database.

## Rollback
Use the privately saved code snapshots in /home/lynchde1/ankh-admin-releases. The private rollback-release.sh restores web code assets only and leaves the original SQLite database untouched. The new activity_events table can remain in the database after restoring older code.

Database restore is a separate, deliberately manual operation: restoring an old database snapshot can discard orders and payments recorded since that snapshot.

## QA coverage
PHP lint and current repository regression suite plus:
- tests/operations-testmode.php validates unchanged access and additive migration in an isolated fixture
- tests/backup-sqlite.php validates private consistent snapshot and file permissions
- tests/viewport-testmode.mjs exercises Chromium at 390, 768, 820, 1024, 1180 and 1440 pixels, plus real one-tap preparation and delivery actions and history views

Any live deployment still requires checking the Krystal release output and physically checking the live mobile/tablet view. Do not claim hosting cron or live verification completed solely from GitHub CI.
