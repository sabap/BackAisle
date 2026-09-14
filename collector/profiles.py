"""SNMPv3 / web credential secrets outside the web root."""
from __future__ import annotations

import json
from pathlib import Path

from secrets import load_secrets

PATH = Path(r"C:\ProgramData\BackAisle\snmp_profiles.json")


def _load() -> dict:
    if PATH.is_file():
        return json.loads(PATH.read_text(encoding="utf-8"))
    return {}


def _save(data: dict) -> None:
    PATH.parent.mkdir(parents=True, exist_ok=True)
    PATH.write_text(json.dumps(data, indent=2), encoding="utf-8")


def set_secret(profile_id: int, auth_pass: str, priv_pass: str, web_pass: str | None = None) -> None:
    data = _load()
    rec = data.get(str(profile_id), {})
    if auth_pass:
        rec["auth_pass"] = auth_pass
    if priv_pass:
        rec["priv_pass"] = priv_pass
    if web_pass is not None and web_pass != "":
        rec["web_pass"] = web_pass
    data[str(profile_id)] = rec
    _save(data)


def get_secret(profile_id: int | None) -> dict:
    secrets = load_secrets()
    data = _load()
    rec = data.get(str(profile_id or ""), {})
    return {
        "user": rec.get("user") or secrets.get("SNMPV3_USER") or "",
        "auth_pass": rec.get("auth_pass") or secrets.get("SNMPV3_AUTH_PASS") or "",
        "priv_pass": rec.get("priv_pass") or secrets.get("SNMPV3_PRIV_PASS") or "",
        "web_user": rec.get("web_user") or secrets.get("UPS_WEB_USER") or "",
        "web_pass": rec.get("web_pass") or secrets.get("UPS_WEB_PASS") or "",
        "auth_proto": rec.get("auth_proto") or secrets.get("SNMPV3_AUTH_PROTO") or "SHA",
        "priv_proto": rec.get("priv_proto") or secrets.get("SNMPV3_PRIV_PROTO") or "AES",
    }


def seed_from_env() -> None:
    secrets = load_secrets()
    data = _load()
    if "1" not in data:
        data["1"] = {
            "auth_pass": secrets.get("SNMPV3_AUTH_PASS", ""),
            "priv_pass": secrets.get("SNMPV3_PRIV_PASS", ""),
            "web_pass": secrets.get("UPS_WEB_PASS", ""),
        }
    if "2" not in data:
        data["2"] = {"auth_pass": "cyber", "priv_pass": "cyber", "web_pass": "cyber"}
    _save(data)
