"""Web/SSH/FTP login lookup. No card and no real secret file."""
import sqlite3
import tempfile
from pathlib import Path

import writer


def fail(msg):
    raise SystemExit(msg)


def main():
    con = sqlite3.connect(":memory:")
    con.row_factory = sqlite3.Row
    con.execute("CREATE TABLE devices (id INTEGER PRIMARY KEY, ip TEXT)")
    con.execute("INSERT INTO devices (id, ip) VALUES (1, '10.1.1.1')")
    if writer.assigned_card_login(con, 1) is not None:
        fail("missing column should fall back")
    con.execute("ALTER TABLE devices ADD COLUMN web_credential_id INTEGER")
    if writer.assigned_card_login(con, 1) is not None:
        fail("empty assignment should fall back")
    con.execute("CREATE TABLE web_credentials (id INTEGER PRIMARY KEY, username TEXT)")
    con.execute("INSERT INTO web_credentials (id, username) VALUES (7, 'ops')")
    con.execute("UPDATE devices SET web_credential_id=7 WHERE id=1")
    tmp = Path(tempfile.mkdtemp()) / "web_credentials.json"
    writer.WEB_CRED_PATH = tmp
    secret = "unit-test-secret"
    try:
        writer.assigned_card_login(con, 1)
        fail("missing password should raise")
    except RuntimeError as exc:
        if "Web/SSH/FTP credential has no password" not in str(exc):
            fail("unexpected missing-password error")
        if writer.is_retryable(exc):
            fail("missing password should not retry")
    tmp.write_text('{"7": {"password": "%s"}}' % secret, encoding="utf-8")
    got = writer.assigned_card_login(con, 1)
    if got != ("ops", secret):
        fail("assigned login mismatch")
    secrets = {}
    profile = {"web_user": "snmp-web", "username": "snmp-user"}
    sec = {"web_user": "", "web_pass": "snmp-web-pass", "user": "snmp-user"}
    prefer = writer.target_card_login(con, 1, secrets, profile, sec)
    if prefer != ("ops", secret):
        fail("assignment should win over the snmp web login")
    if prefer[0] == profile["username"]:
        fail("snmp username was used as the login")
    con.execute("UPDATE devices SET web_credential_id=NULL WHERE id=1")
    fallback = writer.target_card_login(con, 1, secrets, profile, sec)
    if fallback != ("snmp-web", "snmp-web-pass"):
        fail("snmp web login fallback mismatch")
    plain = writer.target_card_login(con, 1, secrets)
    if plain != writer.web_creds(secrets):
        fail("site fallback mismatch")
    if secret in plain[0] or secret in plain[1]:
        fail("secret leaked into fallback")
    tmp.unlink()
    print("web login ok")


if __name__ == "__main__":
    main()
