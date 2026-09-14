# Phase 4 — Fleet config and RMCARD firmware

Lab first. No silent production blasts. Poller stays on SNMPv3; this document is write territory.

## Roles

- Viewer never sees `/writes`.
- Admin only.
- `AllowMultiWrite=false` in `secrets.env` until an operator turns it on. Default target is `UPS_HOST` only.

## Config file

Source of truth is the RMCARD **System → About** save/restore file.

- Filename: `YYYY_MM_DD_HHMM.txt`
- Contains usernames and passwords. **Never serve from IIS.**
- Store: `C:\ProgramData\BackAisle\configs\`
- UI shows a redacted copy only. Every pull/push is audit-logged.

### Pull

Web session (HTTPS, form login → `login_pass.cgi` → `login_counter.html?stap=` → `login.cgi?action=LOGIN`):

1. Open **System → About** (`about.html`).
2. **Save Configuration** is `GET /get_set.cgi?getset=Save`.
3. Response is `Content-Disposition: attachment; filename=YYYY_MM_DD_HHMM.txt` (`text/plain`).
4. File starts with `CyberPowerSystems, RMCARD205` then `KEY, value` lines (not `key=value`).

FTP GET of that filename is the 1.4.0+ alternative. Login as the RMCARD **web** admin, not the SNMPv3 user. NLST may be empty (you must know the name).

### Push / restore

1. **Web Restore** on About: `POST /about.cgi` multipart field `upfile` (filename must look like `YYYY_MM_DD_HHMM.txt`). Card is expected to reboot.
2. **SCP put** of the `.txt` to `user@ip:` (colon required), classic SCP (`scp -O`), not the SFTP subsystem. Some cards offer **aes128-cbc** and **keyboard-interactive** auth.
3. FTP STOR of the `.txt` then QUIT.

Optional: SNMP SET of `sysLocation` is allowed for identity. Do not retry restore in a loop.

## Firmware

Store `cpsrm2scfw_XXX.bin` then `cpsrm2scdata_XXX.bin` under `C:\ProgramData\BackAisle\firmware\`. Default job is **simulate**. Live flash: type `PUSH FIRMWARE`. Do not power off the UPS. Writer never cancels mid-STOR.
