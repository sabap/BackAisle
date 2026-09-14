from __future__ import annotations

import hashlib
import secrets as pysecrets
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from db import init_db
from secrets import load_secrets


def php_password_hash(password: str) -> str:
    """PHP will re-hash on first login if this is a placeholder marker."""
    return "sha256:" + hashlib.sha256(password.encode("utf-8")).hexdigest()


def main():
    con = init_db()
    sec = load_secrets()
    admin_user = sec.get("APP_ADMIN_USER") or "admin"
    admin_pass = sec.get("APP_ADMIN_PASS") or ""
    if con.execute("SELECT COUNT(*) n FROM users").fetchone()["n"] == 0:
        if not admin_pass:
            raise SystemExit("Set APP_ADMIN_PASS in secrets.env before first seed.")
        con.execute(
            "INSERT INTO users (username, password_hash, role) VALUES (?,?,?)",
            (admin_user, php_password_hash(admin_pass), "admin"),
        )
        viewer_pass = sec.get("APP_VIEWER_PASS") or "viewer"
        con.execute(
            "INSERT INTO users (username, password_hash, role) VALUES (?,?,?)",
            ("viewer", php_password_hash(viewer_pass), "viewer"),
        )

    host = (sec.get("UPS_HOST") or "").strip()
    if host and not con.execute("SELECT id FROM devices WHERE ip=?", (host,)).fetchone():
        con.execute(
            """INSERT INTO devices (ip, hostname, site, building, idf_closet, sensor_expected, is_simulated, enabled, kind)
               VALUES (?,?,?,?,?,?,0,1,'ups')""",
            (host, host, "Campus", "Main", "IDF-Lab", 1),
        )
        print(f"seeded UPS_HOST {host}")

    con.commit()
    total = con.execute("SELECT COUNT(*) n FROM devices").fetchone()["n"]
    print(f"devices={total}")
    con.close()


if __name__ == "__main__":
    main()
