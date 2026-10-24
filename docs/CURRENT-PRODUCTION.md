# Current ANKH production targets (October 2026)

**Authority:** User-confirmed Krystal migration on 21–23 October 2026. Current-production reference, not a deployment credential file.

| Component | Production deployment target |
| --- | --- |
| Krystal account | `devcouk` |
| Home directory | `/home/devcouk` |
| Admin source checkout | `/home/devcouk/repositories/ankh-admin-git` |
| Admin deployed files | `/home/devcouk/public_html/ankh-admin` |
| Customer portal deployed files | `/home/devcouk/public_html/ankh-order` |
| Customer portal address | `https://order.ankhpeptides.com` |
| Private admin config | `/home/devcouk/ankh-admin-config.php` |
| Separate backups | `/home/devcouk/ankh-admin-backups` |
| Main GitHub repository | `jamesjlynch/ankh-admin` |

**Retired:** `https://lynchdev.co.uk/ankh-admin/`, `/home/lynchde1/`, and `/home/lynchde1/repositories/ankh-admin` must never be treated as active production. Do not paste legacy URLs into user replies, setup instructions, or test links.

The customer portal has a separate deployed directory from the admin. The original portal deployment used a feature branch named `feature/ankh-customer-portal` into `ankh-order`; confirm current branch flow before modifying it. Admin uses `main` source, but **verify how the new server publishes it**, not an old cron assumption. The earlier domain request `admin.ankhpeptides.co.uk` is not enough to prove that URL is live; do not invent a verified admin URL.

### Deployment preflight

Use the explicit new-server checkout, not the retired directory:

```bash
cd /home/devcouk/repositories/ankh-admin-git
pwd
git rev-parse --show-toplevel
git branch --show-current
git status --short
```

For deployment, first inspect the new host's actual Actions/cron/cPanel procedure and `/home/devcouk/public_html/` mapping. A successful merge does **not** prove files reached production. Compare the actual server release and HTTP response before claiming the change works, including on iPhone.

### Separation of responsibilities

- `ankh-admin`: operational order data, prices, physical stock, stock reservations, and admin dashboards.
- `ankh-order`: customer portal and customer-facing admin screens. Content management and public visibility may use shared product IDs/data, but never duplicate operational pricing/stock as a separate editable source.
- Changes to both applications require verifying both deploy paths and both UI screens.

Historical files in this repository may contain old absolute paths. They are not instructions for the current server.
