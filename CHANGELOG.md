# Changelog

## [0.4.5] - 2026-09-17

- Admin update check shows a result on the same page; if GitHub is blocked it uses jsDelivr for version and apply

## [0.4.4] - 2026-09-17

- Edit SNMPv3 profiles; bulk-assign a profile to a location (or all UPS); templates can carry an SNMPv3 profile
- PowerPanel import reads PascalCase JSON and nested groups (IDF closets)
- IDF category links use /idfs.php (no more 404)

## [0.4.3] - 2026-09-17

- Org tabs and other in-app links use `*.php` (PowerPanel import was 404 at `/org?tab=import`).
- SQL Server settings upsert (Admin no longer uses SQLite `ON CONFLICT`).

## [0.4.2] - 2026-09-17

- PowerPanel import uses PHP/PDO (no IIS pyodbc). SQL passwords may contain punctuation.
- If GitHub zip is blocked, installer fetches the tree from jsDelivr. `.grok/` is not in the repo.

## [0.4.1] - 2026-09-17

- Installer download fail-closed: refuse OpenDNS/HTML, jsDelivr first, zip magic-byte check. PowerShell_QC gate.

## [0.4.0] - 2026-09-17

- Installer targets a fresh IIS **Default Web Site** on HTTP :80 and HTTPS :443 (self-signed cert if needed). App pool remains `BackAisle`. Files stay in `C:\inetpub\BackAisle`. No assumption that another product owns port 80.

## [0.3.2] - 2026-09-14

- After login, redirect to /index.php (not /). Index errors are shown as text instead of a blank 500. Port 80 is optional via -HttpPort 80 when Default Web Site is unused.

## [0.3.1] - 2026-09-14

- Register the PHP FastCGI handler after writing web.config so replacing web.config cannot wipe *.php mappings (IIS 404 on login.php)

## [0.3.0] - 2026-09-14

- IIS routes are real `*.php` files (login.php, fleet.php, ...). URL Rewrite is no longer required, so /login is not a 404.

## [0.2.9] - 2026-09-14

- Restore front-controller rewrite (no handlers in web.config) so /login and /fleet are not 404
- Physical /login.php fallback; PowerPanel import uses real python.exe via proc_open and surfaces stderr

## [0.2.8] - 2026-09-14

- IIS 500.19 (0x80070021): site `web.config` no longer contains `<handlers>` or `<rewrite>` (locked/missing module). PHP handler is registered at the site; handlers section is unlocked.

## [0.2.7] - 2026-09-14

- IIS FastCGI 500 with empty body: duplicate `extension=` lines wrote "already loaded" to stderr; FastCGI treats stderr as 500. php.ini no longer reloads extensions; FastCGI stderrMode IgnoreAndReturn200.

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
