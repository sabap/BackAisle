"""Event rows record poll or trap. No card and no site database."""
import sqlite3

import collector


def fail(msg):
    raise SystemExit(msg)


def main():
    con = sqlite3.connect(":memory:")
    con.row_factory = sqlite3.Row
    con.execute(
        """CREATE TABLE alerts (
            id INTEGER PRIMARY KEY,
            device_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            severity TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT NOT NULL,
            opened_at TEXT NOT NULL,
            acked_at TEXT,
            cleared_at TEXT
        )"""
    )
    con.execute(
        """CREATE TABLE events (
            id INTEGER PRIMARY KEY,
            device_id INTEGER,
            ts TEXT,
            severity TEXT,
            code TEXT,
            message TEXT,
            details TEXT,
            source TEXT
        )"""
    )
    con.execute(
        """CREATE TABLE pending_alerts (
            device_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            first_seen TEXT NOT NULL,
            PRIMARY KEY (device_id, code)
        )"""
    )
    if not collector.set_alert(con, 1, "on_battery", "crit", "On battery (10 min remaining)"):
        fail("poll open should insert")
    if not collector.set_alert(con, 1, "overload", "crit", "The UPS is overloaded", source="trap"):
        fail("trap open should insert")
    if collector.set_alert(con, 1, "overload", "crit", "The UPS is overloaded", source="trap"):
        fail("repeat trap should not open a second alert")
    collector.emit_event(con, 1, "crit", "snmp_trap", "The UPS is overloaded", source="trap")
    if not collector.clear_alert(con, 1, "on_battery"):
        fail("poll clear should record")
    if not collector.clear_alert(con, 1, "overload", source="trap"):
        fail("trap clear should record")
    if collector._event_source("nope") != "poll":
        fail("unknown source should be poll")
    rows = [(r["code"], r["source"]) for r in con.execute("SELECT code, source FROM events ORDER BY id")]
    expect = [
        ("on_battery", "poll"),
        ("overload", "trap"),
        ("snmp_trap", "trap"),
        ("on_battery.cleared", "poll"),
        ("overload.cleared", "trap"),
    ]
    if rows != expect:
        fail("sources mismatch")
    print("event source ok")


if __name__ == "__main__":
    main()
