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

FTP GET of `YYYY_MM_DD_HHMM.txt` is the 1.4.0+ path. The name is the card's current local minute, and the card builds the file for that request. A different minute waits until the FTP timeout. The writer tries the card clock, then UTC, then this server's clock. NLST is empty. Login as the RMCARD **web** admin, not the SNMPv3 user. An SCP download of that remote name is refused during key exchange. SCP remains the upload (`user@host:`). This read does not use the website. A failed read records every minute it tried, whether the card clock was read, and where the transfer stopped. A closed connection, a timeout, an FTP reply, and a line that is not an FTP reply stay distinct. The password and any address in that line are left out. A blank line before the greeting is skipped. When no greeting follows, the login is sent anyway. The other file names are not tried until a file transfer has been attempted. The read still stops at 30 seconds and is not tried again.

Apply config profile with the factory login checked is the new-card path. Assign a Web/SSH/FTP login that is not the factory account. BackAisle signs in with the factory account. If SSH is closed, telnet enables it with `console ssh enable`, the session signs out, and SSH is tried again. If the shell asks `New User Name:`, the answer is the saved username, then `New Password:` and `Confirm Password:` both get the saved password. Each question is answered only after the card asks it. If the card says the confirm password was entered incorrectly and asks for `New Password:` again, that password and the confirm are answered once more. A second rejection stops. If the shell opens at `CyberPower >`, `admin name` and `admin passwd` set the same login, and `input admin password :` gets the current password. Then `ftp access enable` and `exit`, and FTP may take up to 3 minutes. If the factory password no longer works, the saved login enables FTP. A failed FTP wait is not tried again. The config read uses the new login when the account changed. The card keeps its own IP address, gateway, mask, hostname, and MAC address. This path does not update firmware and does not set the battery date. Those settings are on Power → Vendors and run only for Add default UPS. Turn the factory login off when the card already has its own login. That path does not change the FTP service or the administrator.

### Push / restore

1. **Web Restore** on About: `POST /about.cgi` multipart field `upfile` (filename must look like `YYYY_MM_DD_HHMM.txt`). Card is expected to reboot.
2. **SCP put** of the `.txt` to `user@ip:` (colon required), classic SCP (`scp -O`), not the SFTP subsystem. Some cards offer **aes128-cbc** and **keyboard-interactive** auth.
3. FTP STOR of the `.txt` then QUIT.

Optional: SNMP SET of `sysLocation` is allowed for identity. Do not retry restore in a loop.

## Firmware

Store `cpsrm2scfw_XXX.bin` then `cpsrm2scdata_XXX.bin` under `C:\ProgramData\BackAisle\firmware\`. Default job is **simulate**. Live flash: type `PUSH FIRMWARE`. Do not power off the UPS. Writer never cancels mid-STOR.
