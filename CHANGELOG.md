# Changelog

## [0.2.6] - 2026-09-14

- Setup no longer fails as a blank HTTP 500: php.ini last-wins overrides, site session dir, display_errors on setup, `/health.php`, `Repair-BackAisle-Iis.ps1`

## [0.2.5] - 2026-09-14

- Collector watch task uses a 3650-day repetition (not TimeSpan.MaxValue, which Task Scheduler rejects as P99999999DT23H59M59S)

## [0.2.4] - 2026-09-14

- NTFS grants use `IIS APPPOOL\BackAisle` (not the bare pool name). icacls stderr no longer aborts the install.

## [0.2.3] - 2026-09-14

- Ignore the Microsoft Store `WindowsApps\python.exe` stub; install CPython 3.12 from python.org when no real interpreter is present

## [0.2.2] - 2026-09-14

- PHP download no longer trusts a HEAD 302 on windows.php.net (8.3.32 left /releases/ and 404s). Tries the 8.3 latest zip, archives, then releases.json, and only accepts a real ZIP.

## [0.2.1] - 2026-09-14

- Installer is ASCII + UTF-8 BOM so Windows PowerShell 5.1 does not treat em-dashes as `"` and fail to parse
- Downloads retry with curl `--ssl-no-revoke` (CRYPT_E_NO_REVOCATION_CHECK on locked-down networks)
- Zip fetch tries `codeload.github.com` before `github.com`

## [0.2.0] - 2026-09-14

- Windows installer (`Install-BackAisle.ps1`) modeled on ColdAisle: IIS roles, VC++, PHP, ODBC 18, URL Rewrite, Python, own site on :8080
- Web setup wizard (`/setup.php`): SQLite or SQL Server, first admin, **restore site backup**, **PowerPanel profile.zip import**
- SQL Server schema (`sql/schema.sql`) and collector ODBC support via `config/collector.json`

## [0.1.0] - 2026-09-14

First public release of BackAisle, an IDF infrastructure product (network racks, UPS SNMPv3 monitoring, closet climate). Independent of ColdAisle.

- Dashboard with campus-wide power, temperature, and humidity charts and an IDF list
- EIA-310 rack elevations; UPS, switch, and patch-panel placement on U units
- Device templates (apply manufacturer, model, U height, ports)
- SNMPv3 authPriv collector with a worker pool so slow cards do not stall the fleet
- Organization tree, SNMPv3 credential profiles, PowerPanel profile import
- Fleet config/firmware writer (lab-gated until AllowMultiWrite)
- Admin GitHub updates from sabap/BackAisle: check, full site backup (`backaisle-site_…`) plus application-files zip, overlay, schema ensure, restore
