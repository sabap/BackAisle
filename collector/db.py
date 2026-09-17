from __future__ import annotations

import json
import re
import sqlite3
from pathlib import Path

DB_PATH = Path(r"C:\inetpub\BackAisle\data\backaisle.db")
CONFIG_JSON = Path(r"C:\inetpub\BackAisle\config\collector.json")


def load_app_config() -> dict:
    if CONFIG_JSON.is_file():
        try:
            return json.loads(CONFIG_JSON.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {"driver": "sqlite", "path": str(DB_PATH)}


def is_sqlsrv() -> bool:
    return (load_app_config().get("driver") or "sqlite").lower() in ("sqlsrv", "sqlserver")


def adapt_sql(sql: str) -> str:
    if not is_sqlsrv():
        return sql
    sql = sql.replace("datetime('now')", "SYSUTCDATETIME()")
    sql = sql.replace("IFNULL(", "ISNULL(")
    sql = sql.replace("INSERT OR IGNORE INTO", "INSERT INTO")
    sql = sql.replace("INSERT OR REPLACE INTO", "INSERT INTO")
    m = re.search(r"^(.*)\s+LIMIT\s+(\d+)\s*$", sql, re.I | re.S)
    if m and re.match(r"\s*SELECT\s", m.group(1), re.I) and "SELECT TOP " not in m.group(1).upper():
        sql = re.sub(r"^\s*SELECT\s+", f"SELECT TOP {int(m.group(2))} ", m.group(1), count=1, flags=re.I)
    return sql

SCHEMA = """
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  username TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('viewer','admin')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS devices (
  id INTEGER PRIMARY KEY,
  ip TEXT NOT NULL,
  hostname TEXT,
  mac TEXT,
  model TEXT,
  serial TEXT,
  firmware TEXT,
  snmp_name TEXT,
  site TEXT,
  building TEXT,
  idf_closet TEXT,
  rack TEXT,
  circuit TEXT,
  load_notes TEXT,
  install_date TEXT,
  last_battery_replacement TEXT,
  warranty_replace_by TEXT,
  sensor_expected INTEGER NOT NULL DEFAULT 1,
  sensor_present INTEGER,
  is_simulated INTEGER NOT NULL DEFAULT 0,
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS poll_state (
  device_id INTEGER PRIMARY KEY,
  last_success TEXT,
  last_attempt TEXT,
  last_trap TEXT,
  consecutive_failures INTEGER NOT NULL DEFAULT 0,
  last_error TEXT,
  comm_state TEXT NOT NULL DEFAULT 'unknown',
  FOREIGN KEY (device_id) REFERENCES devices(id)
);
CREATE TABLE IF NOT EXISTS samples (
  id INTEGER PRIMARY KEY,
  device_id INTEGER NOT NULL,
  ts TEXT NOT NULL,
  output_status INTEGER,
  battery_status INTEGER,
  capacity_pct REAL,
  runtime_min REAL,
  load_pct REAL,
  input_voltage REAL,
  output_voltage REAL,
  temp_f REAL,
  humidity_pct REAL,
  on_battery INTEGER,
  sensor_present INTEGER,
  replace_battery INTEGER,
  FOREIGN KEY (device_id) REFERENCES devices(id)
);
CREATE INDEX IF NOT EXISTS ix_samples_dev_ts ON samples(device_id, ts);
CREATE TABLE IF NOT EXISTS samples_hourly (
  device_id INTEGER NOT NULL,
  hour_ts TEXT NOT NULL,
  capacity_avg REAL,
  runtime_avg REAL,
  load_avg REAL,
  input_voltage_avg REAL,
  temp_f_avg REAL,
  humidity_avg REAL,
  PRIMARY KEY (device_id, hour_ts)
);
CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY,
  device_id INTEGER,
  ts TEXT NOT NULL DEFAULT (datetime('now')),
  severity TEXT NOT NULL,
  code TEXT NOT NULL,
  message TEXT NOT NULL,
  details TEXT
);
CREATE INDEX IF NOT EXISTS ix_events_ts ON events(ts);
CREATE TABLE IF NOT EXISTS thresholds (
  id INTEGER PRIMARY KEY,
  scope TEXT NOT NULL,
  site TEXT,
  idf_closet TEXT,
  device_id INTEGER,
  on_battery_minutes INTEGER NOT NULL DEFAULT 5,
  capacity_low INTEGER NOT NULL DEFAULT 30,
  runtime_low_min INTEGER NOT NULL DEFAULT 15,
  temp_high_f REAL NOT NULL DEFAULT 85,
  temp_low_f REAL NOT NULL DEFAULT 50,
  humidity_high INTEGER NOT NULL DEFAULT 70,
  humidity_low INTEGER NOT NULL DEFAULT 20,
  poll_fail_count INTEGER NOT NULL DEFAULT 3
);
CREATE TABLE IF NOT EXISTS alerts (
  id INTEGER PRIMARY KEY,
  device_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  severity TEXT NOT NULL,
  status TEXT NOT NULL,
  message TEXT NOT NULL,
  opened_at TEXT NOT NULL,
  acked_at TEXT,
  cleared_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_alerts_open ON alerts(device_id, code, status);
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  ts TEXT NOT NULL DEFAULT (datetime('now')),
  username TEXT,
  action TEXT NOT NULL,
  entity TEXT,
  entity_id TEXT,
  details TEXT
);
CREATE TABLE IF NOT EXISTS config_templates (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  source_device_id INTEGER,
  source_ip TEXT,
  path TEXT NOT NULL,
  pulled_at TEXT NOT NULL,
  notes TEXT
);
CREATE TABLE IF NOT EXISTS firmware_images (
  id INTEGER PRIMARY KEY,
  version TEXT NOT NULL,
  fw_path TEXT NOT NULL,
  data_path TEXT NOT NULL,
  uploaded_at TEXT NOT NULL,
  uploaded_by TEXT
);
CREATE TABLE IF NOT EXISTS write_jobs (
  id INTEGER PRIMARY KEY,
  kind TEXT NOT NULL,
  status TEXT NOT NULL,
  simulate INTEGER NOT NULL DEFAULT 0,
  stop_on_error INTEGER NOT NULL DEFAULT 1,
  confirm_phrase TEXT,
  created_by TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  started_at TEXT,
  ended_at TEXT,
  template_id INTEGER,
  firmware_id INTEGER,
  payload_json TEXT,
  error TEXT
);
CREATE TABLE IF NOT EXISTS write_job_targets (
  id INTEGER PRIMARY KEY,
  job_id INTEGER NOT NULL,
  device_id INTEGER NOT NULL,
  ip TEXT NOT NULL,
  hostname TEXT,
  status TEXT NOT NULL DEFAULT 'queued',
  step TEXT,
  started_at TEXT,
  ended_at TEXT,
  error TEXT,
  post_model TEXT,
  post_firmware TEXT,
  post_name TEXT,
  post_location TEXT,
  FOREIGN KEY (job_id) REFERENCES write_jobs(id)
);
CREATE TABLE IF NOT EXISTS write_job_steps (
  id INTEGER PRIMARY KEY,
  target_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  name TEXT NOT NULL,
  status TEXT NOT NULL,
  detail TEXT,
  ts TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (target_id) REFERENCES write_job_targets(id)
);
CREATE TABLE IF NOT EXISTS groups (
  id INTEGER PRIMARY KEY,
  parent_id INTEGER,
  name TEXT NOT NULL,
  alert_hold_sec INTEGER NOT NULL DEFAULT 180,
  notes TEXT,
  FOREIGN KEY (parent_id) REFERENCES groups(id)
);
CREATE TABLE IF NOT EXISTS racks (
  id INTEGER PRIMARY KEY,
  group_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  u_height INTEGER NOT NULL DEFAULT 42,
  sort_order INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (group_id) REFERENCES groups(id)
);
CREATE INDEX IF NOT EXISTS ix_racks_group ON racks(group_id);
CREATE TABLE IF NOT EXISTS device_templates (
  id INTEGER PRIMARY KEY,
  manufacturer TEXT,
  model TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'other',
  u_height INTEGER NOT NULL DEFAULT 1,
  face TEXT NOT NULL DEFAULT 'both',
  port_count INTEGER,
  va_rating REAL,
  watts REAL,
  weight_kg REAL,
  notes TEXT,
  front_picture TEXT,
  rear_picture TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS snmp_profiles (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  username TEXT NOT NULL,
  auth_proto TEXT NOT NULL DEFAULT 'SHA',
  priv_proto TEXT NOT NULL DEFAULT 'AES',
  web_user TEXT,
  is_default INTEGER NOT NULL DEFAULT 0,
  notes TEXT
);
CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT
);
CREATE TABLE IF NOT EXISTS ldap_role_maps (
  id INTEGER PRIMARY KEY,
  group_token TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('viewer','admin'))
);
CREATE TABLE IF NOT EXISTS pending_alerts (
  device_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  first_seen TEXT NOT NULL,
  mailed_at TEXT,
  PRIMARY KEY (device_id, code)
);
CREATE TABLE IF NOT EXISTS group_alerts (
  id INTEGER PRIMARY KEY,
  group_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  status TEXT NOT NULL,
  message TEXT NOT NULL,
  opened_at TEXT NOT NULL,
  cleared_at TEXT
);
CREATE TABLE IF NOT EXISTS certs (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  cn TEXT,
  key_path TEXT,
  csr_path TEXT,
  cert_path TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  notes TEXT
);
"""


class _Row(dict):
    def __getitem__(self, key):
        if isinstance(key, int):
            return list(self.values())[key]
        return super().__getitem__(key)


class SqlSrvConn:
    def __init__(self, raw):
        self.raw = raw

    def execute(self, sql, params=()):
        cur = self.raw.cursor()
        cur.execute(adapt_sql(sql), params or ())
        return SqlSrvCursor(cur)

    def executescript(self, sql):
        return self

    def commit(self):
        self.raw.commit()

    def close(self):
        self.raw.close()


class SqlSrvCursor:
    def __init__(self, cur):
        self.cur = cur

    def fetchone(self):
        row = self.cur.fetchone()
        if row is None:
            return None
        cols = [c[0] for c in (self.cur.description or [])]
        return _Row(zip(cols, row))

    def fetchall(self):
        cols = [c[0] for c in (self.cur.description or [])]
        return [_Row(zip(cols, r)) for r in self.cur.fetchall()]

    def fetchcolumn(self):
        row = self.fetchone()
        if row is None:
            return None
        return list(row.values())[0]


def last_id(con) -> int:
    if is_sqlsrv():
        row = con.execute("SELECT SCOPE_IDENTITY()").fetchone()
        return int(list(row.values())[0] if isinstance(row, dict) else row[0])
    return int(con.execute("SELECT last_insert_rowid()").fetchone()[0])


def _odbc_brace(value: str) -> str:
    """ODBC connection-string literal; allows ; { } and other punctuation in passwords."""
    return "{" + str(value).replace("}", "}}") + "}"


def connect(path: Path | None = None):
    cfg = load_app_config()
    if (cfg.get("driver") or "sqlite").lower() in ("sqlsrv", "sqlserver"):
        import pyodbc
        enc = "yes" if cfg.get("encrypt") else "no"
        trust = "yes" if cfg.get("trust_server_certificate", True) else "no"
        drv = cfg.get("odbc_driver") or "ODBC Driver 18 for SQL Server"
        server = cfg.get("host") or "localhost"
        port = int(cfg.get("port") or 1433)
        if port != 1433:
            server = f"{server},{port}"
        dbn = cfg.get("database") or "BackAisle"
        user = str(cfg.get("username") or "")
        pwd = str(cfg.get("password") or "")
        parts = [
            f"DRIVER={_odbc_brace(drv)}",
            f"SERVER={_odbc_brace(server)}",
            f"DATABASE={_odbc_brace(dbn)}",
            f"Encrypt={enc}",
            f"TrustServerCertificate={trust}",
        ]
        if user:
            parts.append(f"UID={_odbc_brace(user)}")
            parts.append(f"PWD={_odbc_brace(pwd)}")
        else:
            parts.append("Trusted_Connection=yes")
        dsn = ";".join(parts)
        try:
            raw = pyodbc.connect(dsn, timeout=30, autocommit=True)
        except Exception as e:
            drivers = []
            try:
                drivers = list(pyodbc.drivers())
            except Exception:
                pass
            raise RuntimeError(
                "SQL Server (pyodbc) connect failed: %s. Drivers installed: %s. "
                "Setup import now uses PHP/PDO; collector still needs this connection as SYSTEM."
                % (e, drivers)
            ) from e
        return SqlSrvConn(raw)
    p = Path(cfg.get("path") or path or DB_PATH)
    p.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(str(p), timeout=30)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA journal_mode=WAL")
    con.execute("PRAGMA foreign_keys=ON")
    return con


def _add_col(con: sqlite3.Connection, table: str, col: str, spec: str) -> None:
    have = {r[1] for r in con.execute(f"PRAGMA table_info({table})")}
    if col not in have:
        con.execute(f"ALTER TABLE {table} ADD COLUMN {col} {spec}")


def init_db(con=None):
    own = con is None
    con = con or connect()
    if is_sqlsrv():
        if own:
            return con
        return con
    con.executescript(SCHEMA)
    _add_col(con, "devices", "group_id", "INTEGER")
    _add_col(con, "devices", "snmp_profile_id", "INTEGER")
    _add_col(con, "devices", "va_rating", "REAL")
    _add_col(con, "devices", "kind", "TEXT NOT NULL DEFAULT 'ups'")
    _add_col(con, "devices", "rack_id", "INTEGER")
    _add_col(con, "devices", "position_u", "INTEGER")
    _add_col(con, "devices", "u_height", "INTEGER NOT NULL DEFAULT 1")
    _add_col(con, "devices", "face", "TEXT NOT NULL DEFAULT 'both'")
    _add_col(con, "devices", "port_count", "INTEGER")
    _add_col(con, "devices", "manufacturer", "TEXT")
    _add_col(con, "devices", "template_id", "INTEGER")
    _add_col(con, "samples", "power_w", "REAL")
    _add_col(con, "samples_hourly", "power_avg", "REAL")
    _add_col(con, "users", "source", "TEXT NOT NULL DEFAULT 'local'")
    _add_col(con, "users", "display_name", "TEXT")
    _add_col(con, "config_templates", "is_default", "INTEGER NOT NULL DEFAULT 0")
    row = con.execute("SELECT COUNT(*) AS n FROM thresholds").fetchone()
    if row["n"] == 0:
        con.execute(
            """INSERT INTO thresholds (scope, on_battery_minutes, capacity_low, runtime_low_min,
               temp_high_f, temp_low_f, humidity_high, humidity_low, poll_fail_count)
               VALUES ('global', 5, 30, 15, 85, 50, 70, 20, 3)"""
        )
    if con.execute("SELECT COUNT(*) n FROM settings WHERE k='alert_hold_sec'").fetchone()["n"] == 0:
        con.execute("INSERT INTO settings (k,v) VALUES ('alert_hold_sec','180')")
    if con.execute("SELECT COUNT(*) n FROM snmp_profiles").fetchone()["n"] == 0:
        con.execute(
            "INSERT INTO snmp_profiles (name, username, auth_proto, priv_proto, web_user, is_default, notes) "
            "VALUES ('Default SNMPv3','snmpv3user','SHA','AES','',1,'from secrets.env')"
        )
        con.execute(
            "INSERT INTO snmp_profiles (name, username, auth_proto, priv_proto, web_user, is_default, notes) "
            "VALUES ('Factory default','cyber','SHA','AES','cyber',0,'cyber/cyber web + SNMPv3')"
        )
    if con.execute("SELECT COUNT(*) n FROM groups").fetchone()["n"] == 0:
        con.execute("INSERT INTO groups (parent_id, name) VALUES (NULL,'Hospital')")
        hid = last_id(con)
        for (b, c) in con.execute("SELECT DISTINCT building, idf_closet FROM devices WHERE building IS NOT NULL").fetchall():
            if not c:
                continue
            exist = con.execute("SELECT id FROM groups WHERE parent_id=? AND name=?", (hid, b or "Campus")).fetchone()
            if exist:
                bid = exist["id"]
            else:
                con.execute("INSERT INTO groups (parent_id, name) VALUES (?,?)", (hid, b or "Campus"))
                bid = last_id(con)
            closet = con.execute("SELECT id FROM groups WHERE parent_id=? AND name=?", (bid, c)).fetchone()
            if closet:
                gid = closet["id"]
            else:
                con.execute("INSERT INTO groups (parent_id, name) VALUES (?,?)", (bid, c))
                gid = last_id(con)
            con.execute("UPDATE devices SET group_id=? WHERE building=? AND idf_closet=?", (gid, b, c))
    con.commit()
    if own:
        return con
    return con
