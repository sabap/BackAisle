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

Elevated PowerShell. **Do not run from `C:\Windows\system32`.** Some networks intercept `raw.githubusercontent.com` and save an HTML page instead of the script (first line will be `<!DOCTYPE html>` instead of `#Requires`). Use `curl.exe --fail` and confirm the file before running:

```powershell
$dir = Join-Path $env:TEMP 'BackAisle-install'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Set-Location $dir
$out = Join-Path $dir 'Install-BackAisle.ps1'
$urls = @(
  'https://github.com/sabap/BackAisle/releases/latest/download/Install-BackAisle.ps1',
  'https://cdn.jsdelivr.net/gh/sabap/BackAisle@main/Install-BackAisle.ps1',
  'https://raw.githubusercontent.com/sabap/BackAisle/main/Install-BackAisle.ps1'
)
$ok = $false
foreach ($u in $urls) {
  Write-Host "Trying $u"
  & curl.exe -fsSL --max-time 60 -o $out $u
  if ($LASTEXITCODE -ne 0) { continue }
  $head = Get-Content -LiteralPath $out -TotalCount 1
  if ($head -match 'Requires') { $ok = $true; break }
  Write-Host "Not a script (got: $head)"
}
if (-not $ok) { throw 'Could not download Install-BackAisle.ps1. Clone https://github.com/sabap/BackAisle instead.' }
Get-Content $out -TotalCount 3
Set-ExecutionPolicy Bypass -Scope Process -Force
.\Install-BackAisle.ps1 -OpenSetup -RegisterCollectorTask
```

Or clone the repo and run the same script from disk:

```powershell
git clone https://github.com/sabap/BackAisle.git C:\TEMP\BackAisle-src
Set-ExecutionPolicy Bypass -Scope Process -Force
& C:\TEMP\BackAisle-src\Install-BackAisle.ps1 -OpenSetup -RegisterCollectorTask
```

The installer:

1. Enables IIS FastCGI role services (does **not** change Default Web Site or port 80)
2. Installs VC++ Redistributable, PHP NTS, ODBC Driver 18, URL Rewrite, Python 3.12+ and pip packages
3. Creates IIS site **BackAisle** on **:8080** with a site-local `php.ini`
4. Opens **http://localhost:8080/setup.php**

In the wizard, pick **SQLite** (no extra engine) or **SQL Server** (existing instance — this script does not install SQL Server), then:

- Fresh install (admin account), or
- Restore a `backaisle-site_*.zip` / `.baisle` package, or
- Import a PowerPanel `profile.zip`

Manual copy is still supported: place the tree under `C:\inetpub\BackAisle` and run `scripts\Install-BackAisle-Prereqs.ps1`.

## Updates

Admin → **Updates** checks [sabap/BackAisle](https://github.com/sabap/BackAisle). Applying an update:

1. Writes a full **site package** (`backaisle-site_…zip` — database, secrets, pictures)
2. Writes an **application-files** zip (`backup_…zip`)
3. Downloads the GitHub zipball, overlays files (preserves `data/`, `logs/`, `secrets.env`, `php.ini`, `storage/`)
4. Applies SQLite schema changes

Restore a site package from the same Admin page. Encrypted packages use AES-256-GCM (`.baisle`).

## Fleet writes

`/writes` is admin-only. Default target is `UPS_HOST` until `AllowMultiWrite=true`. Config files live under `C:\ProgramData\BackAisle\configs` (never served by IIS).
