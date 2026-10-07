"""Bounded SNMPv3 walk of one device. Prints JSON to stdout. Does not poll the fleet."""
from __future__ import annotations

import argparse
import asyncio
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from db import connect  # noqa: E402
from profiles import get_secret  # noqa: E402
from secrets import load_secrets  # noqa: E402


def _oid_text(identity) -> str:
    text = str(identity).strip().lstrip(".")
    if text.upper().startswith("SNMPv2-SMI::"):
        text = text.split("::", 1)[-1]
    parts = []
    for piece in text.replace(" ", "").split("."):
        if piece.isdigit():
            parts.append(piece)
        else:
            return ""
    return ".".join(parts)


async def walk(host: str, user: str, auth: str, priv: str, root: str, limit: int) -> list[dict]:
    # Import after SQL connect. pysnmp's cryptography import can break msodbcsql TLS.
    from snmp_client import close_engine, new_engine
    from pysnmp.hlapi.v3arch.asyncio import (
        ContextData, ObjectIdentity, ObjectType, UdpTransportTarget, UsmUserData,
        next_cmd, usmAesCfb128Protocol, usmHMACSHAAuthProtocol,
    )

    engine = new_engine()
    rows = []
    try:
        creds = UsmUserData(user, auth, priv, authProtocol=usmHMACSHAAuthProtocol, privProtocol=usmAesCfb128Protocol)
        target = await UdpTransportTarget.create((host, 161), timeout=2.0, retries=0)
        ctx = ContextData()
        current = tuple(int(p) for p in root.split(".") if p.isdigit())
        prefix = root.strip(".") + "."
        for _ in range(limit):
            err_ind, err_stat, _err_idx, var_binds = await next_cmd(
                engine, creds, target, ctx, ObjectType(ObjectIdentity(current)), lookupMib=False,
            )
            if err_ind or not var_binds:
                break
            oid = _oid_text(var_binds[0][0])
            if not oid or not (oid == root or oid.startswith(prefix)):
                break
            val = var_binds[0][1]
            pretty = val.prettyPrint()
            name = val.__class__.__name__
            if name not in ("Null", "NoSuchObject", "NoSuchInstance", "EndOfMibView") and pretty not in (
                "", "noSuchObject", "noSuchInstance", "endOfMibView",
            ):
                rows.append({"oid": oid, "value": pretty[:120]})
            current = tuple(int(p) for p in oid.split("."))
        return rows
    finally:
        close_engine(engine)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--id", type=int, required=True)
    parser.add_argument("--root", required=True)
    parser.add_argument("--max", type=int, default=40)
    args = parser.parse_args()
    limit = max(1, min(40, args.max))
    root = args.root.strip().strip(".")
    con = connect()
    try:
        device = con.execute("SELECT * FROM devices WHERE id=?", (args.id,)).fetchone()
    finally:
        con.close()
    if not device:
        print(json.dumps({"error": "Device not found", "rows": []}))
        return
    secrets = load_secrets()
    cred = get_secret(device["snmp_profile_id"] if "snmp_profile_id" in device.keys() else None)
    user = (device["snmp_username"] if "snmp_username" in device.keys() else None) or cred["user"] or secrets.get("SNMPV3_USER") or ""
    # devices select has no snmp username join. Username lives on the profile secret and the profile row.
    if not user:
        con = connect()
        try:
            prof = con.execute("SELECT username FROM snmp_profiles WHERE id=?", (device["snmp_profile_id"],)).fetchone()
        finally:
            con.close()
        if prof:
            user = prof["username"] or ""
    auth = cred["auth_pass"] or secrets.get("SNMPV3_AUTH_PASS") or ""
    priv = cred["priv_pass"] or secrets.get("SNMPV3_PRIV_PASS") or ""
    host = str(device["ip"] or "")
    if not host or not user or not auth:
        print(json.dumps({"error": "This device has no SNMPv3 credentials", "rows": []}))
        return
    try:
        rows = asyncio.run(walk(host, user, auth, priv, root, limit))
    except Exception as exc:
        print(json.dumps({"error": str(exc)[:300], "rows": []}))
        return
    print(json.dumps({"rows": rows}))


if __name__ == "__main__":
    main()
