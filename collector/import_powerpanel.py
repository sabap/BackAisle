"""Import a PowerPanel Business profile.zip (DbGroup / DbDevice / DbSNMPSetting)."""
from __future__ import annotations

import json
import zipfile
from pathlib import Path

from db import init_db
from profiles import set_secret

AUTH_MAP = {"0": "NONE", "1": "MD5", "2": "SHA", "None": "SHA"}
PRIV_MAP = {"0": "NONE", "1": "DES", "2": "AES", "None": "AES"}


def import_zip(zip_path: str | Path) -> dict:
    zip_path = Path(zip_path)
    with zipfile.ZipFile(zip_path) as zf:
        name = next((n for n in zf.namelist() if n.lower().endswith("profile.json")), None)
        if not name:
            raise RuntimeError("profile.json not found in zip")
        data = json.loads(zf.read(name).decode("utf-8"))
    con = init_db()
    stats = {"groups": 0, "devices": 0, "snmp": 0, "skipped": 0}

    snmp_map = {}  # old id -> new id
    for s in data.get("DbSNMPSetting") or []:
        if str(s.get("snmpType")) != "1":
            continue
        name = (s.get("profileName") or "imported").strip()
        user = s.get("userName") or "cyber"
        if user in ("None", ""):
            user = "cyber"
        auth = AUTH_MAP.get(str(s.get("authProtocol")), "SHA")
        priv = PRIV_MAP.get(str(s.get("privacyProtocol")), "AES")
        exist = con.execute("SELECT id FROM snmp_profiles WHERE name=?", (name,)).fetchone()
        if exist:
            nid = exist["id"]
        else:
            con.execute(
                "INSERT INTO snmp_profiles (name, username, auth_proto, priv_proto, notes) VALUES (?,?,?,?,?)",
                (name, user, auth, priv, "imported from PowerPanel"),
            )
            nid = con.execute("SELECT last_insert_rowid()").fetchone()[0]
        auth_key = s.get("authKey") or ""
        priv_key = s.get("privacyKey") or ""
        if auth_key and auth_key != "None" and len(auth_key) < 80:
            set_secret(nid, auth_key, priv_key if priv_key != "None" else auth_key)
        snmp_map[str(s.get("id"))] = nid
        stats["snmp"] += 1

    group_map = {}
    groups = list(data.get("DbGroup") or [])
    pending = groups[:]
    guard = 0
    while pending and guard < 10000:
        guard += 1
        g = pending.pop(0)
        oid = str(g.get("id"))
        parent_old = str(g.get("parentId"))
        if parent_old not in ("-1", "None", "none", "") and parent_old not in group_map:
            pending.append(g)
            continue
        parent_new = None if parent_old in ("-1", "None", "none", "") else group_map[parent_old]
        name = (g.get("name") or f"group-{oid}").strip()
        exist = con.execute(
            "SELECT id FROM groups WHERE name=? AND IFNULL(parent_id,-1)=IFNULL(?, -1)",
            (name, parent_new),
        ).fetchone()
        if exist:
            nid = exist["id"]
        else:
            con.execute("INSERT INTO groups (parent_id, name, notes) VALUES (?,?,?)", (parent_new, name, "PowerPanel import"))
            nid = con.execute("SELECT last_insert_rowid()").fetchone()[0]
            stats["groups"] += 1
        group_map[oid] = nid

    for d in data.get("DbDevice") or []:
        ip = (d.get("address") or "").strip()
        if not ip:
            stats["skipped"] += 1
            continue
        host = (d.get("name") or "").strip()
        loc = (d.get("location") or "").strip()
        gid = group_map.get(str(d.get("belongGroupId")))
        sid = snmp_map.get(str(d.get("snmpId")))
        exist = con.execute("SELECT id FROM devices WHERE ip=?", (ip,)).fetchone()
        if exist:
            con.execute(
                "UPDATE devices SET hostname=COALESCE(NULLIF(hostname,''), ?), group_id=COALESCE(group_id, ?), "
                "snmp_profile_id=COALESCE(snmp_profile_id, ?), load_notes=COALESCE(load_notes, ?) WHERE id=?",
                (host, gid, sid, loc, exist["id"]),
            )
        else:
            con.execute(
                """INSERT INTO devices (ip, hostname, site, building, idf_closet, load_notes, group_id, snmp_profile_id,
                   sensor_expected, is_simulated, enabled, va_rating)
                   VALUES (?,?,?,?,?,?,?,?,1,0,1,2000)""",
                (ip, host, "Imported", loc, host, loc, gid, sid),
            )
            stats["devices"] += 1
    con.commit()
    con.close()
    return stats
