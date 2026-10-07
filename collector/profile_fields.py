"""Read model-profile fields that are not already on the device summary."""
from __future__ import annotations

import json
from typing import Any

from db import connect, is_sqlsrv
from snmp_client import snmp_get_oid


def _int(v, default: int = 0) -> int:
    try:
        return int(v)
    except (TypeError, ValueError):
        return default


def _as_map(row) -> dict:
    if isinstance(row, dict):
        return {str(k).lower(): v for k, v in row.items()}
    return {str(k).lower(): row[k] for k in row.keys()}


def _rows(con, sql: str, args: tuple = ()):
    try:
        return [_as_map(row) for row in con.execute(sql, args).fetchall()]
    except Exception:
        return []


def resolve_profile_id(con, device: dict) -> int:
    pid = _int(device.get("model_profile_id") or 0)
    if pid > 0:
        return pid
    model = str(device.get("model") or "").lower()
    vendor = str(device.get("manufacturer") or "").lower()
    profiles = _rows(con, "SELECT id, vendor, match_model FROM model_profiles WHERE is_active=1 ORDER BY id")
    best_id = 0
    best_len = 0
    for row in profiles:
        mm = str(row.get("match_model") or "").strip().lower()
        if mm and model and mm in model and len(mm) > best_len:
            best_id = _int(row.get("id"))
            best_len = len(mm)
    if best_id:
        return best_id
    for row in profiles:
        mv = str(row.get("vendor") or "").strip().lower()
        if mv and ((vendor and vendor == mv) or (model and mv in model)):
            return _int(row.get("id"))
    if len(profiles) == 1:
        return _int(profiles[0].get("id"))
    return 0


def load_extra_fields(con, device: dict) -> list[dict]:
    pid = resolve_profile_id(con, device)
    if pid < 1:
        return []
    out = []
    for row in _rows(
        con,
        """SELECT field_key, oid, scale, enum_json FROM model_profile_fields
           WHERE profile_id=? AND show_on_device=1
             AND (builtin_key IS NULL OR builtin_key='')
           ORDER BY sort_order, id""",
        (pid,),
    ):
        oid = str(row.get("oid") or "").strip()
        if oid.count(".") < 3:
            continue
        out.append(row)
        if len(out) >= 16:
            break
    return out


def _apply_scale(raw: str, scale: float, enum_json: str) -> dict[str, Any] | None:
    text = raw.strip()
    if text == "" or text.lower() in ("nosuchobject", "nosuchinstance", "endofmibview"):
        return {"clear": True}
    enum = {}
    if enum_json:
        try:
            parsed = json.loads(enum_json)
            if isinstance(parsed, dict):
                enum = {str(k): str(v) for k, v in parsed.items()}
        except json.JSONDecodeError:
            enum = {}
    try:
        num = float(text)
    except ValueError:
        return {"clear": False, "value_num": None, "value_text": text[:200]}
    if num == -1:
        return {"clear": True}
    if str(int(num)) in enum and abs(num - int(num)) < 0.001:
        return {"clear": False, "value_num": num, "value_text": enum[str(int(num))][:200]}
    scaled = num * scale
    return {"clear": False, "value_num": scaled, "value_text": None}


async def read_profile_fields(device: dict, secrets: dict, engine) -> list[dict]:
    if engine is None:
        return []
    con = connect()
    try:
        fields = load_extra_fields(con, device)
    finally:
        con.close()
    if not fields:
        return []
    from profiles import get_secret

    cred = get_secret(device.get("snmp_profile_id"))
    user = device.get("snmp_username") or cred["user"] or secrets.get("SNMPV3_USER") or ""
    auth = cred["auth_pass"] or secrets.get("SNMPV3_AUTH_PASS") or ""
    priv = cred["priv_pass"] or secrets.get("SNMPV3_PRIV_PASS") or ""
    host = str(device.get("ip") or "")
    if not host or not user:
        return []
    found = []
    for field in fields:
        parts = []
        for piece in str(field.get("oid") or "").split("."):
            if piece.isdigit():
                parts.append(int(piece))
        if len(parts) < 4:
            continue
        try:
            scale = float(field.get("scale") or 1)
        except (TypeError, ValueError):
            scale = 1.0
        if scale == 0:
            scale = 1.0
        try:
            raw = await snmp_get_oid(engine, host, user, auth, priv, tuple(parts), timeout=2.0)
        except Exception:
            break
        if raw is None:
            break
        shaped = _apply_scale(str(raw), scale, str(field.get("enum_json") or ""))
        if shaped is None:
            continue
        shaped["field_key"] = str(field.get("field_key") or "")
        found.append(shaped)
    return found


def store_profile_fields(con, device_id: int, rows: list[dict]) -> None:
    device_id = _int(device_id)
    for row in rows:
        key = str(row.get("field_key") or "")
        if not key:
            continue
        if row.get("clear"):
            con.execute("DELETE FROM device_metrics WHERE device_id=? AND field_key=?", (device_id, key))
            continue
        num = row.get("value_num")
        text = row.get("value_text")
        if is_sqlsrv():
            con.execute(
                "UPDATE device_metrics SET value_num=?, value_text=?, polled_at=SYSUTCDATETIME() WHERE device_id=? AND field_key=?",
                (num, text, device_id, key),
            )
            con.execute(
                """INSERT INTO device_metrics (device_id, field_key, value_num, value_text, polled_at)
                   SELECT ?, ?, ?, ?, SYSUTCDATETIME()
                   WHERE NOT EXISTS (SELECT 1 FROM device_metrics WHERE device_id=? AND field_key=?)""",
                (device_id, key, num, text, device_id, key),
            )
        else:
            con.execute(
                """INSERT INTO device_metrics (device_id, field_key, value_num, value_text, polled_at)
                   VALUES (?,?,?,?,datetime('now'))
                   ON CONFLICT(device_id, field_key) DO UPDATE SET
                     value_num=excluded.value_num, value_text=excluded.value_text, polled_at=excluded.polled_at""",
                (device_id, key, num, text),
            )
