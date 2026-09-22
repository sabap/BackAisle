# Changelog

## [0.5.42] - 2026-09-22

- Dashboard temperature and humidity averages no longer treat a missing reading as 0. SQL Server was storing a PHP null as 0 in the sample row, and the hourly average included those zeros. The graph and the hottest-IDF list skip null and 0. New polls store a real null when the probe did not answer.

## [0.5.41] - 2026-09-22

- About half the EnviroSensor readings were still missing. A timed-out group request was abandoned, so a slow card never got a temperature-only retry. Temperature, unit, and humidity are read first and retried one OID at a time. Optional name and contact OIDs stay on the 5-minute climate pass and cannot drop a reading already in hand. The collector uses up to 24 workers, which covers about 150 UPS on a 60-second status interval.

## [0.5.40] - 2026-09-22

- Climate showed one UPS because a CyberPower multi-get that hits an unsupported OID returns `noSuchName` and blank values for the temperature OID in that same request. The collector treated that as success and never read the probe. It now retries each sensor OID on its own. Temperature is requested before the optional name and contact OIDs.

## [0.5.39] - 2026-09-22

- Climate readings were kept for only the fastest UPS. The enviro GET used a 1 second timeout, and the next UPS poll stored a blank sample that hid the last real temperature. Sensor reads now get up to 4 seconds on the climate pass, a failed sensor GET does not erase the last reading, and the climate page shows the latest sample that actually has a temperature.

## [0.5.38] - 2026-09-22

- Climate is one row per IDF, from the UPS that has the ENVIROSENSOR. Other UPS in that closet are not listed. A probe counts as attached when temperature or humidity is read, not only when the name OID is present. A poll that never reaches the sensor OIDs no longer marks the closet absent. EnviroSensor timeouts do not fail the UPS poll.

## [0.5.37] - 2026-09-22

- SNMP Last OK / State: SQL Server was returning `is_simulated` and device ids as the text `"0"`. Python treated that as simulated, the fake poll crashed, and every unit stayed Degraded with no Last OK. Numbers are coerced, a real success is stored before the sample insert, and the poll toast reports collector ok/fail. The row shows the last error when state is not ok.

## [0.5.36] - 2026-09-21

- Job page can cancel a queued or running write (type STOP JOB). Remaining queued/running units become cancelled; ok and fail stay. Writer 0.5.36 stops taking new units when the job is cancelled. `scripts/Cancel-StuckWriteJobs.ps1` stops the writer process and marks those jobs cancelled.

## [0.5.35] - 2026-09-21

- Fleet config and SNMPv3 writes run several cards at once (default 4, `WRITE_WORKERS`, max 8). One card is never written by two workers. Transient login/connect/SCP/FTP failures retry (default 2 extra tries, `WRITE_RETRIES`). Firmware stays one card at a time.

## [0.5.34] - 2026-09-21

- Job pull step records `writer=VERSION` so an old in-memory writer is obvious. In-app Update sets `restart_writer.flag`; watchdog recycles writer.py when the file is newer than the process. `scripts/Restart-BackAisleWriter.ps1` copies collector files from jsDelivr and restarts the task.

## [0.5.33] - 2026-09-18

- RMCARD allows one web login. SNMPv3 push keeps that session from pull through HTTP restore (no second login on error.html). Logout after the target. Busy-page error tells you to close the UPS browser tab.

## [0.5.32] - 2026-09-18

- Lab-tested on 10.202.7.24: pull, SNMPv3 slot overlay, FTP restore, re-pull. Slot 1 left intact; empty slot 2 took the new user.
- Web session uses HTTPS when 443 is open, HTTP when 443 is refused (WinError 10061).
- SNMPv3 AUTHTYPE/PRIVTYPE/STATUS write numeric codes (2/2/1), not SHA/AES/enable.
- Login finishes the full auth-counter dance (early stop landed on error.html).
- SCP dest is `user@host:` (local file named `YYYY_MM_DD_HHMM.txt`). A remote filename makes the card drop the session. CTR and CBC ciphers, ed25519/ssh-rsa. Restore falls back SCP → FTP → HTTP.

## [0.5.31] - 2026-09-18

- RMCARD SCP uses aes128-ctr/aes256-ctr. After restore, wait for HTTPS not FTP (FTP often stays off). Pull retries 3 times.

## [0.5.30] - 2026-09-18

- RMCARD SCP allows ssh-rsa host keys (OpenSSH vs CyberPower). HTTP restore if SCP fails. stop_on_error=0 is not treated as true.

## [0.5.29] - 2026-09-18

- Simulate flag: SQL Server '0' is not treated as true. RMCARD web session always logs out after pull (one login at a time).

## [0.5.28] - 2026-09-18

- Write job header status counts per-target rows (SQL Server COUNT alias was marking all-ok SNMPv3 jobs as fail)

## [0.5.27] - 2026-09-18

- Treat CyberPower factory SNMPv3 names (cyber snmpv3 user1–4) as empty slots. Real users (opmanager, sgmc-infrastructure, backaisle, …) are never overwritten.

## [0.5.26] - 2026-09-18

- SNMPv3 slot parse: do not treat the 3 in snmpv3 as the slot index. Empty slots get cloned key names from an occupied slot.

## [0.5.25] - 2026-09-18

- SNMPv3 card write uses profile/web login or factory cyber/cyber (no KeyError UPS_WEB_USER). One UPS failure does not leave the rest queued.

## [0.5.24] - 2026-09-18

- Write job page auto-refreshes while queued/running. SNMP toast links to that page (no second toast when the writer finishes).

## [0.5.23] - 2026-09-18

- SQL Server: never call PDO lastInsertId() (ODBC IM001). Use @@IDENTITY via ba_last_id().

## [0.5.22] - 2026-09-18

- SNMPv3 RMCARD slot write is not lab-gated (PUSH SNMPV3 / Simulate). Config and firmware writes still require AllowMultiWrite.

## [0.5.21] - 2026-09-18

- Write SNMPv3 onto CyberPower RMCARD: 4 slots, never overwrite a different username; empty slot or matching user only; ACL keep vs NMS IP; simulate first

## [0.5.20] - 2026-09-18

- SNMP page: bulk assign SNMPv3 profile to selected UPS, schedule, all UPS, or IDF group
- Check for updates: jsDelivr LATEST first, GitHub 3s timeout (no 45s hang)

## [0.5.19] - 2026-09-18

- PHP SQL bridge: do not fetch rows after DELETE/INSERT (ODBC invalid cursor). SQL Server upserts instead of SQLite ON CONFLICT.

## [0.5.18] - 2026-09-18

- Collector SQL: use PHP PDO (same as the website) via sql_bridge.php when pyodbc returns empty HY000 under IIS

## [0.5.17] - 2026-09-18

- SNMP page reads only the last 32 KB of collector.log (a full-file read exhausted 256 MB PHP memory)

## [0.5.16] - 2026-09-18

- SNMP page is a standalone entry (like the dashboard) so IIS cannot hide the error behind a blank 500. Device list no longer joins samples.

## [0.5.15] - 2026-09-18

- SNMP page: do not exec tasklist/schtasks from IIS (FastCGI 500). SQL Server last-sample uses OUTER APPLY. Cell values accept SQL datetime objects.

## [0.5.14] - 2026-09-18

- TLS for updates matches ColdAisle: search php.ini / PHP extras / config/cacert.pem, auto-download Mozilla CA on first SSL failure, Install CA certificates writes config/cacert.pem with verify off only for that bootstrap URL.

## [0.5.13] - 2026-09-18

- Apply does not abort if the application-files zip is empty (IIS-locked files). ZipArchive falls back to addFromString.

## [0.5.12] - 2026-09-18

- Apply/backup: do not run SQLite `PRAGMA wal_checkpoint(TRUNCATE)` against SQL Server (ODBC 156)

## [0.5.11] - 2026-09-18

- PHP Check uses Windows native CA store (CURLSSLOPT_NATIVE_CA). OpenSSL does not see OS roots on this host; that was HTTP 0 / issuer certificate (20).

## [0.5.10] - 2026-09-18

- Check/Apply fall back to `curl.exe --ssl-no-revoke` when the PHP curl extension is missing or fails (IIS php.ini vs CLI php.ini)

## [0.5.9] - 2026-09-18

- Check for updates takes the newest of GitHub releases, GitHub tags, jsDelivr catalog, VERSION, and LATEST. It no longer stops at the first source (a stale GitHub latest or lagging catalog hid 0.5.8).

## [0.5.8] - 2026-09-17

- SNMP page: do not write Task Scheduler stderr into IIS FastCGI (empty HTTP 500). SQL Server uses TOP instead of LIMIT for last sample.

## [0.5.7] - 2026-09-17

- Collector SQL connect: use the same config.php credentials PHP uses (writable runtime JSON), ODBC after connect not autocommit-in-connect, brace PWD, try SqlPassword and the legacy SQL Server driver. Connect before loading pysnmp/cryptography.

## [0.5.6] - 2026-09-17

- Check for updates uses GitHub releases/tags first (same as ColdAisle). jsDelivr is only a fallback if GitHub fails.

## [0.5.5] - 2026-09-17

- Admin Check for updates: remove the sticky nav overlay toast. In-page banner matches ColdAisle (transparent green when current, transparent blue when an update is available, transparent red on error)

## [0.5.4] - 2026-09-17

- Check for updates no longer stops at the first missing jsDelivr patch tag (a 404 on 0.5.2 hid 0.5.3)

## [0.5.3] - 2026-09-17

- Admin Update matches ColdAisle: Check then Apply from the page. jsDelivr first (GitHub zip is often a proxy page here). IIS file replace uses the same locked-file staging as ColdAisle. Apply fails closed if VERSION on disk does not match the target.

## [0.5.2] - 2026-09-17

- Collector SQL Server (pyodbc) matches PHP/PDO: do not brace SERVER, retry Encrypt/Trust and TCP, refresh collector.json before a poll from Admin

## [0.5.1] - 2026-09-17

- Admin Check for updates probes jsDelivr `VERSION` at newer git tags (does not stop at a stale jsDelivr catalog, cached `@main`, or GitHub latest alone)
- Overlay tries newest jsDelivr version first (no longer applies 0.4.9 when a newer tag exists)

## [0.5.0] - 2026-09-17

- SNMP page: poller status, Windows tasks, scheduled devices, poll one / poll selected / poll all, add to schedule

## [0.4.9] - 2026-09-17

- Overlay stops the IIS app pool, copies with [IO.File]::Copy, and verifies VERSION on disk (fixes silent no-op when PHP files were in use)

## [0.4.8] - 2026-09-17

- Overlay refuses a jsDelivr tree older than 0.4.8 (stops @main from leaving production on 0.4.3)

## [0.4.7] - 2026-09-17

- Production overlay uses the latest jsDelivr **version tag** (not cached @main). Admin check shows a yellow toast and a flash banner.

## [0.4.6] - 2026-09-17

- Production overlay: `scripts/Update-BackAisle.ps1` pulls application files from jsDelivr without wiping the database, config, or php.ini

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
