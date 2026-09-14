# BackAisle

Campus IDF infrastructure: network racks, UPS monitoring, and closet climate. **Not ColdAisle** (data center DCIM) and **not PowerPanel**.

BackAisle is a separate IIS site, app pool, and folder. It does not load ColdAisle files or schema.

## What it does

- Network racks per closet, with EIA-310 U-unit elevations (U1 at the bottom)
- Place UPS units, switches, and patch panels on rack U positions
- Device templates for common IDF gear
- Battery health, UPS state, load, input/output voltage (SNMPv3 poller)
- Closet temp/humidity when an ENVIROSENSOR is attached (never fabricated)
- Fleet views, alerts, 90-day samples + ~1 year hourly downsample
- In-app updates from GitHub, with a full site backup before apply

No VM/OS shutdown. No SNMPv1. No SET/write to the UPS except optional admin battery self-test / fleet-write jobs.

## Layout

| Item | Value |
|---|---|
| IIS site | **BackAisle** (own site + app pool) |
| Files | `C:\inetpub\BackAisle` |
| Secrets | `C:\ProgramData\BackAisle\secrets.env` (see `secrets.env.example`) |
| Database | SQLite `C:\inetpub\BackAisle\data\backaisle.db` (WAL) |
| Backups | `C:\inetpub\BackAisle\storage\backups\` |

## SNMPv3

- authPriv, SHA + AES-128
- Credentials only in `secrets.env` (never git, never shown in the UI after save)
- Restrict the card’s allowed SNMP client to the collector host
- Default poll: 60 s status, 5 min climate; 8–16 concurrent SNMP workers

## Install

1. Copy this tree to `C:\inetpub\BackAisle`.
2. Copy `secrets.env.example` to `C:\ProgramData\BackAisle\secrets.env` and fill credentials. Set `APP_ADMIN_PASS`.
3. Point FastCGI at `C:\inetpub\BackAisle\php.ini` (enable pdo_sqlite / sqlite3). Do not reuse another site’s php.ini.
4. Run `Install-BackAisle.ps1` (creates the BackAisle site/pool on port 8080; does not touch Default Web Site).
5. `python collector\seed.py` then start `collector\collector.py` and `collector\writer.py`.

## Updates

Admin → **Updates** checks [sabap/BackAisle](https://github.com/sabap/BackAisle). Applying an update:

1. Writes a full **site package** (`backaisle-site_…zip` — database, secrets, pictures)
2. Writes an **application-files** zip (`backup_…zip`)
3. Downloads the GitHub zipball, overlays files (preserves `data/`, `logs/`, `secrets.env`, `php.ini`, `storage/`)
4. Applies SQLite schema changes

Restore a site package from the same Admin page. Encrypted packages use AES-256-GCM (`.baisle`).

## Fleet writes

`/writes` is admin-only. Default target is `UPS_HOST` until `AllowMultiWrite=true`. Config files live under `C:\ProgramData\BackAisle\configs` (never served by IIS).
