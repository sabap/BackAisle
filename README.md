# BackAisle

Campus IDF infrastructure: network racks, UPS monitoring, and closet climate. **Not ColdAisle** (data center DCIM) and **not PowerPanel**.

BackAisle installs as the IIS **Default Web Site** (ports 80 and 443) with its own app pool and folder (`C:\inetpub\BackAisle`). It does not load ColdAisle files or schema.

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
| IIS site | **Default Web Site** on **:80** and **:443** (app pool `BackAisle`) |
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

Elevated PowerShell. **Do not run from `C:\Windows\system32`.**

Some networks:

- Intercept `raw.githubusercontent.com` and save HTML (`<!DOCTYPE html>`) instead of the script
- Fail GitHub TLS revocation checks (`CRYPT_E_NO_REVOCATION_CHECK` / curl 35) -- retry with `--ssl-no-revoke`
- Use Windows PowerShell 5.1, which misparses UTF-8 em-dashes unless the file has a BOM

Use this paste in elevated PowerShell. It leaves System32, tries jsDelivr first (GitHub.com is often replaced with an OpenDNS HTML page), and **will not run** a download that is not a real script:

```powershell
if ((Get-Location).Path -match '\\[Ww]indows\\[Ss]ystem32$') { Set-Location $env:TEMP }
$dir = Join-Path $env:TEMP 'BackAisle-install'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Set-Location $dir
$out = Join-Path $dir 'Install-BackAisle.ps1'
$urls = @(
  'https://cdn.jsdelivr.net/gh/sabap/BackAisle@v0.4.9/Install-BackAisle.ps1',
  'https://cdn.jsdelivr.net/gh/sabap/BackAisle@main/Install-BackAisle.ps1'
)
$ok = $false
foreach ($u in $urls) {
  Write-Host "Trying $u"
  cmd /c "curl.exe -fsSL --ssl-no-revoke --max-time 60 -o `"$out`" $u"
  if ($LASTEXITCODE -ne 0) { continue }
  if (-not (Test-Path $out)) { continue }
  $b = [IO.File]::ReadAllBytes($out)
  if ($b.Length -lt 200 -or $b[0] -eq 0x3C) { Write-Host 'Not a script (HTML or empty).'; continue }
  $head = [Text.Encoding]::UTF8.GetString($b, 0, [Math]::Min(80, $b.Length))
  if ($head -notmatch 'Requires') { Write-Host "Not a script (got: $head)"; continue }
  $ok = $true
  break
}
if (-not $ok) { throw 'Could not download Install-BackAisle.ps1 (network returned a web page). Copy the BackAisle folder onto this server and run scripts\Install-BackAisle-Prereqs.ps1 from that folder.' }
$utf8bom = New-Object System.Text.UTF8Encoding $true
$skip = 0
if ($b.Length -ge 3 -and $b[0] -eq 239 -and $b[1] -eq 187 -and $b[2] -eq 191) { $skip = 3 }
$text = [Text.Encoding]::UTF8.GetString($b, $skip, $b.Length - $skip)
[IO.File]::WriteAllText($out, $text, $utf8bom)
Get-Content $out -TotalCount 1
Set-ExecutionPolicy Bypass -Scope Process -Force
& $out -OpenSetup -RegisterCollectorTask
```

Or clone the repo and run the same script from disk:

```powershell
git clone https://github.com/sabap/BackAisle.git C:\TEMP\BackAisle-src
Set-ExecutionPolicy Bypass -Scope Process -Force
& C:\TEMP\BackAisle-src\Install-BackAisle.ps1 -OpenSetup -RegisterCollectorTask
```

The installer (written for a **fresh IIS** server):

1. Enables IIS FastCGI role services
2. Installs VC++ Redistributable, PHP NTS, ODBC Driver 18, URL Rewrite, Python 3.12+ and pip packages
3. Points **Default Web Site** at `C:\inetpub\BackAisle\public`, pool **BackAisle**, HTTP **:80** and HTTPS **:443** (self-signed cert if none exists)
4. Opens **http://localhost/setup.php**

This does not assume another product is already using port 80. Files stay under `C:\inetpub\BackAisle` (not `wwwroot`). To skip TLS: `.\Install-BackAisle.ps1 -SkipHttps`.

In the wizard, pick **SQLite** (no extra engine) or **SQL Server** (existing instance — this script does not install SQL Server), then:

- Fresh install (admin account), or
- Restore a `backaisle-site_*.zip` / `.baisle` package, or
- Import a PowerPanel `profile.zip`

Manual copy is still supported: place the tree under `C:\inetpub\BackAisle` and run `scripts\Install-BackAisle-Prereqs.ps1`.

## Updates

Do **not** delete the IIS site to pick up application fixes. Overlay files on the existing install (keeps SQL/SQLite, `config.php`, `collector.json`, `php.ini`, secrets, logs). Elevated PowerShell, not from `C:\Windows\system32`:

```powershell
if ((Get-Location).Path -match '\\[Ww]indows\\[Ss]ystem32$') { Set-Location $env:TEMP }
$ErrorActionPreference = 'Stop'
$dir = Join-Path $env:TEMP 'BackAisle-update'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Set-Location $dir
$out = Join-Path $dir 'Update-BackAisle.ps1'
$urls = @(
  'https://cdn.jsdelivr.net/gh/sabap/BackAisle@v0.4.9/scripts/Update-BackAisle.ps1',
  'https://cdn.jsdelivr.net/gh/sabap/BackAisle@main/scripts/Update-BackAisle.ps1'
)
$ok = $false
foreach ($u in $urls) {
  Write-Host "Trying $u"
  cmd /c "curl.exe -fsSL --ssl-no-revoke --max-time 60 -o `"$out`" $u"
  if ($LASTEXITCODE -ne 0) { continue }
  if (-not (Test-Path $out)) { continue }
  $b = [IO.File]::ReadAllBytes($out)
  if ($b.Length -lt 200 -or ($b[0] -eq 0x3C -and $b[1] -ne 0x3F)) { Write-Host 'Not a script (HTML or empty).'; continue }
  $head = [Text.Encoding]::UTF8.GetString($b, 0, [Math]::Min(80, $b.Length))
  if ($head -notmatch 'Requires') { Write-Host "Not a script (got: $head)"; continue }
  $ok = $true
  break
}
if (-not $ok) { throw 'Could not download Update-BackAisle.ps1 (network returned a web page). Copy scripts\Update-BackAisle.ps1 onto this server and run it elevated.' }
$utf8bom = New-Object System.Text.UTF8Encoding $true
$skip = 0
if ($b.Length -ge 3 -and $b[0] -eq 239 -and $b[1] -eq 187 -and $b[2] -eq 191) { $skip = 3 }
$text = [Text.Encoding]::UTF8.GetString($b, $skip, $b.Length - $skip)
[IO.File]::WriteAllText($out, $text, $utf8bom)
Get-Content $out -TotalCount 1
Set-ExecutionPolicy Bypass -Scope Process -Force
& $out -SiteRoot 'C:\inetpub\BackAisle' -Ref v0.4.9
```

Admin → **Updates** can also check [sabap/BackAisle](https://github.com/sabap/BackAisle) from the browser (jsDelivr if GitHub is blocked). Applying an update:

1. Writes a full **site package** (`backaisle-site_…zip` — database, secrets, pictures)
2. Writes an **application-files** zip (`backup_…zip`)
3. Downloads the GitHub zipball, overlays files (preserves `data/`, `logs/`, `secrets.env`, `php.ini`, `storage/`)
4. Applies SQLite schema changes

Restore a site package from the same Admin page. Encrypted packages use AES-256-GCM (`.baisle`).

## Fleet writes

`/writes` is admin-only. Default target is `UPS_HOST` until `AllowMultiWrite=true`. Config files live under `C:\ProgramData\BackAisle\configs` (never served by IIS).
