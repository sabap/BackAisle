"""SNMPv3 / web credential secrets outside the web root."""
from __future__ import annotations

import json
from pathlib import Path

from rmcard_defaults import RMCARD_FACTORY_PASS
from secrets import load_secrets

PATH = Path(r"C:\ProgramData\BackAisle\snmp_profiles.json")


def _load() -> dict:
    if PATH.is_file():
        return json.loads(PATH.read_text(encoding="utf-8"))
    return {}


def _save(data: dict) -> None:
    PATH.parent.mkdir(parents=True, exist_ok=True)
    PATH.write_text(json.dumps(data, indent=2), encoding="utf-8")


def set_secret(
    profile_id: int,
    auth_pass: str,
    priv_pass: str,
    web_pass: str | None = None,
    style: str | None = None,
) -> None:
    data = _load()
    rec = data.get(str(profile_id), {})
    if style == "password":
        pw = auth_pass or priv_pass
        if pw:
            rec["auth_pass"] = pw
            rec["priv_pass"] = pw
        elif rec.get("auth_pass") and not rec.get("priv_pass"):
            rec["priv_pass"] = rec["auth_pass"]
        elif rec.get("priv_pass") and not rec.get("auth_pass"):
            rec["auth_pass"] = rec["priv_pass"]
        rec["style"] = "password"
        rec["auth_proto"] = "SHA"
        rec["priv_proto"] = "AES"
    else:
        if auth_pass:
            rec["auth_pass"] = auth_pass
        if priv_pass:
            rec["priv_pass"] = priv_pass
        if style == "authpriv":
            rec["style"] = "authpriv"
    if web_pass is not None and web_pass != "":
        rec["web_pass"] = web_pass
    data[str(profile_id)] = rec
    _save(data)


def secret_view(rec: dict, secrets: dict) -> dict:
    """Map one stored profile to the keys a poll uses.

    A password profile is the Ubiquiti form: one password. The poll still
    speaks SNMPv3 authPriv, with that password as both the SHA key and the
    AES key. It must not pick up the UPS secrets from the environment.
    """
    if str(rec.get("style") or "") == "password":
        pw = rec.get("auth_pass") or rec.get("priv_pass") or ""
        return {
            "user": rec.get("user") or "",
            "auth_pass": pw,
            "priv_pass": pw,
            "web_user": "",
            "web_pass": "",
            "auth_proto": "SHA",
            "priv_proto": "AES",
            "style": "password",
        }
    return {
        "user": rec.get("user") or secrets.get("SNMPV3_USER") or "",
        "auth_pass": rec.get("auth_pass") or secrets.get("SNMPV3_AUTH_PASS") or "",
        "priv_pass": rec.get("priv_pass") or secrets.get("SNMPV3_PRIV_PASS") or "",
        "web_user": rec.get("web_user") or secrets.get("UPS_WEB_USER") or "",
        "web_pass": rec.get("web_pass") or secrets.get("UPS_WEB_PASS") or "",
        "auth_proto": rec.get("auth_proto") or secrets.get("SNMPV3_AUTH_PROTO") or "SHA",
        "priv_proto": rec.get("priv_proto") or secrets.get("SNMPV3_PRIV_PROTO") or "AES",
        "style": "authpriv",
    }


def get_secret(profile_id: int | None) -> dict:
    secrets = load_secrets()
    data = _load()
    rec = data.get(str(profile_id or ""), {})
    return secret_view(rec if isinstance(rec, dict) else {}, secrets)


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
        # auth_pass and priv_pass belong to the factory SNMPv3 profile.
        # web_pass is the factory SSH/web login, stored on its own key.
        data["2"] = {"auth_pass": "cyber", "priv_pass": "cyber", "web_pass": RMCARD_FACTORY_PASS}
    _save(data)
