# Changelog

## [0.5.67] - 2026-10-09

- The mark is a gold arch, the entrance to the aisle. Sign-in is a centered card with that mark, the name, IDFM, and "The aisle behind the racks."
- Add device can start from a template. The template fills the kind, the model, the height, the outlets, the credentials, and the model profile. A credential chosen on that form overrides the template.
- A device entered by hand can be saved as a template for the next one. Not now keeps the device. Creating a model profile is optional. The match is the full model name. A profile that already uses that name is reused. A new profile has no extra fields yet, and a UPS with a blank model no longer uses the only profile on the site.
- A template can store a model profile. Applying it sets the profile and the credentials when the template has them, and leaves the device's current assignment alone when those links are empty. Adding a device from a rack copies those links and does not move the U you placed.
- Create model profile, on the device page, makes an empty profile from that unit, assigns it, and opens Models so the OIDs can be added.

## [0.5.66] - 2026-10-07

- Models, under Power, is the field list for one kind of device. The first profile is CyberPower UPS and keeps the readings already on the device page. While it is the only profile, every UPS uses it. Assign a profile on the device when a second kind of equipment is added.
- Upload a vendor MIB to name OIDs. Search that list, type an OID, or walk one live unit and check the objects it returned. A walk stays on the branch you type and stops after 40 answers. The MIB is a dictionary, not the poll list.
- An extra field is read on the climate poll and shows on that device only after the card returns a value. Input frequency is the first extra field. Screens that used to say SNMPv3 profile now say SNMPv3 credentials. Config profiles are unchanged.
- Open Models once after updating so the CyberPower profile is created. Restart the collector if it is already running. Poll all scheduled starts its own poll and uses this version without that restart.

## [0.5.65] - 2026-10-03

- Poll all scheduled polls every UPS on the schedule in one click. A long list runs in the background, 24 at a time, and the page comes back right away. Refresh the SNMP page for the collector log. A second click while that poll is running waits. Battery replacement dates fill in as each card answers.
- The Batteries list marks a UPS with no battery replacement date as Not set, and counts how many still need that date on the card. Set the date on the card, then poll, and the date shows in the list.

## [0.5.64] - 2026-10-03

- Battery last-replaced and replace-by come from the CyberPower card. Last replaced is the battery replacement date set in the RMCARD web interface. Replace-by is that date plus the card's recommended battery life. A blank reply does not clear a date already saved. Install stays as you typed it. Restart the collector so the next climate poll fills these in.

## [0.5.63] - 2026-10-03

- BackAisle is branded as IDF Management (IDFM). The header, sign-in page, and setup wizard show a rack mark with a bronze backslash, the aisle behind the equipment. The browser tab uses the same mark.

## [0.5.62] - 2026-10-03

- The Organization IDF tree lists each location on its own row. Edit opens the name, the parent, and the alert hold, and each control says what it does. Remove is a text action inside Edit and asks before it removes a location. A location that still has locations or racks under it stays put.

## [0.5.61] - 2026-10-03

- Pages use the same cards as Users. Each section has a bronze header. Short actions, such as Add device, New group, and Place existing UPS, are buttons on that header and open a dialog. Wider editors stay in the card.
- Organization no longer has an LDAPS tab. LDAPS stays under Admin → Users. Alert hold is a button on the Groups card. The IDF tree shows each location name on one line.

## [0.5.60] - 2026-10-03

- Inventory has a Select all checkbox beside Assign department, and the same checkbox in the table header. It selects every device in the current list. A department filter or Show decommissioned changes that list, and Select all follows it.

## [0.5.59] - 2026-10-03

- A device's department owns it. IDFM Admin and Global Admin assign that department from the device page or from Inventory. Department Admin can add, edit, and decommission only the devices their department owns. A device with no department stays with IDFM Admin and Global Admin.
- Decommission stops polling and keeps the device and its history. Inventory hides those devices until Show decommissioned. Return to service brings one back.
- A Department Admin sees, acknowledges, and clears alerts only for devices their department owns. Alert mail also goes to that department's contact and to active users in the department who have an email, along with the site notification address. Restart the collector so those extra addresses are used.

## [0.5.58] - 2026-10-03

- The Users page is cards with a bronze header. Add department, Add user, Add role mapping, and Add department mapping are buttons on those headers and open a dialog. Edit department and edit user open the same way.
- LDAPS authentication spans the page. Connection, the enterprise CA, and the connection test sit in columns so the form is shorter.

## [0.5.57] - 2026-10-03

- Platform role names are editable. They start as Global Admin, IDFM Admin, Department Admin, and View Only. Save roles keeps a name you change. Restore defaults puts those four names and their original permissions back. The Area column is no longer covered by the table header.

## [0.5.56] - 2026-10-03

- LDAPS can trust an enterprise certificate authority. Upload the root, and an intermediate if you have one, exported from AD Certificate Services as PEM, Base-64 .CER, or DER. The file stays in the site data folder, outside the web site. After it is saved, turn certificate checks back on.
- Default Role applies only when a new directory user signs in and “Require a mapped security group” is off, or no role maps exist yet. A matching security group still sets the role. Someone who already has an account keeps their current role when no group matches.
- Test connection checks the directory with the values on the form. It does not create a user. A newly chosen certificate is used after Save.

## [0.5.55] - 2026-10-03

- Admin has a Users page for LDAPS sign-in, departments, local users, and platform roles. Active Directory security groups can set a person's role and department at sign-in. Global Admin still has the whole site. Data Center Admin can change racks, inventory, SNMP, and fleet writes. Department Admin can change devices in their own department. Viewer stays read-only.

## [0.5.54] - 2026-09-24

- UPS polls were calling cards unreachable when PowerPanel still got a reply. The status request waited 1 second and did not retry, and a slow sensor read could cancel a poll that already had battery and output data. Status now waits 3 seconds, retries once, and keeps that card's SNMP session. Temperature is read on the 5-minute pass and cannot by itself raise an unreachable alert. A card that truly does not answer still alerts after three failed polls.

## [0.5.53] - 2026-09-24

- Alerts has Ack all and Clear all. Ack all marks every open alert acknowledged. Clear all removes every open and acknowledged alert from the list. Already cleared alerts are left alone.

## [0.5.52] - 2026-09-24

- The dashboard has a Tech Mode switch for a tablet layout. Admin and Templates leave the navigation. An IDF shows a larger Add rack form, and a rack has a tappable U grid for placing a UPS, switch, or patch panel.

## [0.5.51] - 2026-09-24

- UPS templates now have a plug type, a count of output outlets, data ports, and environmental ports. The outlet count adds one line per output, numbered from 01, and each line has its own plug type and label. Those fields are stored when the template is saved. Open Templates once after updating so the new columns are added.

## [0.5.50] - 2026-09-24

- Clicking an empty rack U went to `/rack?...`, which IIS has no rewrite for, so it 404s. Those links now go to `rack.php`, the same way the rest of the site does.

## [0.5.49] - 2026-09-24

- PowerPanel re-import no longer fails on SQL Server with "text is incompatible with int". Group and device ids are bound as integers. A missing group does not clear one that is already set.

## [0.5.48] - 2026-09-24

- Org is where the IDF tree is added, renamed, moved, and removed. The IDFs page only displays that tree. Group parent ids from SQL Server are read as numbers, so closets show under their campus. Uploading the PowerPanel profile.zip again rewrites each IDF's parent and points each UPS at that IDF.

## [0.5.47] - 2026-09-24

- Hottest IDFs and Highest power IDFs stayed on "No readings yet" because those queries required a group id and a SQL Server LIMIT that did not survive translation. They are now built from the same polled UPS rows as the IDF table, including closets stored only as a UPS hostname.

## [0.5.46] - 2026-09-23

- Climate rows were still one per UPS. SQL Server column names did not match, so the IDF id was ignored, and `idf_closet` is the UPS hostname (`DMC-UPS-01`). Rows now group by that hostname stem (`DMC`). If any UPS in the stem is flagged or has a real reading, the others drop off. A stored 0 is shown as no reading, not a bare °F.

## [0.5.45] - 2026-09-23

- Climate no longer shows "attached" with a bare °F or % when the stored value is 0 or missing. A UPS counts as the IDF sensor only when it has a real temperature or humidity. Sibling UPS stay listed until that happens, then drop off. A sensor name or table-size counter alone does not mark the probe attached.

## [0.5.44] - 2026-09-23

- Climate was one row per UPS hostname, so the other units in an IDF never dropped off after a sensor reading. Rows now group by the PowerPanel IDF. When any UPS in that IDF has a temperature or humidity, the rest of that IDF is hidden. Legacy EnviroSensor scalars and table index 2 are polled too, which is what PowerPanel still shows on older cards.

## [0.5.43] - 2026-09-22

- One-device poll on SQL Server logged `julianday is not a recognized built-in function` from alert housekeeping and pasted that into the poll toast. Alert age now uses DATEDIFF on SQL Server. The toast shows the device poll failure, not the housekeeping query.

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
