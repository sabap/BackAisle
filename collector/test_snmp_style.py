"""Username-and-password profiles must not borrow UPS environment secrets."""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from profiles import secret_view  # noqa: E402
from snmp_client import HOST_KEYS, OIDS  # noqa: E402


def test_password_uses_one_secret() -> None:
    env = {
        "SNMPV3_USER": "envuser",
        "SNMPV3_AUTH_PASS": "env-auth",
        "SNMPV3_PRIV_PASS": "env-priv",
        "SNMPV3_AUTH_PROTO": "MD5",
        "SNMPV3_PRIV_PROTO": "DES",
        "UPS_WEB_USER": "envweb",
        "UPS_WEB_PASS": "envwebpass",
    }
    out = secret_view({"style": "password", "auth_pass": "one-secret", "user": "udm"}, env)
    assert out["style"] == "password"
    assert out["user"] == "udm"
    assert out["auth_pass"] == "one-secret"
    assert out["priv_pass"] == "one-secret"
    assert out["auth_proto"] == "SHA"
    assert out["priv_proto"] == "AES"
    assert out["web_user"] == ""
    assert out["web_pass"] == ""
    assert "env-auth" not in out.values()
    assert "env-priv" not in out.values()


def test_password_empty_stays_empty() -> None:
    env = {
        "SNMPV3_USER": "envuser",
        "SNMPV3_AUTH_PASS": "env-auth",
        "SNMPV3_PRIV_PASS": "env-priv",
    }
    out = secret_view({"style": "password"}, env)
    assert out["auth_pass"] == ""
    assert out["priv_pass"] == ""
    assert out["user"] == ""


def test_password_copies_priv_when_auth_missing() -> None:
    out = secret_view({"style": "password", "priv_pass": "only-priv"}, {})
    assert out["auth_pass"] == "only-priv"
    assert out["priv_pass"] == "only-priv"


def test_authpriv_keeps_env_fallback() -> None:
    env = {
        "SNMPV3_USER": "envuser",
        "SNMPV3_AUTH_PASS": "env-auth",
        "SNMPV3_PRIV_PASS": "env-priv",
    }
    out = secret_view({}, env)
    assert out["style"] == "authpriv"
    assert out["user"] == "envuser"
    assert out["auth_pass"] == "env-auth"
    assert out["priv_pass"] == "env-priv"


def test_authpriv_stored_wins() -> None:
    env = {
        "SNMPV3_USER": "envuser",
        "SNMPV3_AUTH_PASS": "env-auth",
        "SNMPV3_PRIV_PASS": "env-priv",
    }
    out = secret_view({"auth_pass": "stored-a", "priv_pass": "stored-p", "user": "card"}, env)
    assert out["auth_pass"] == "stored-a"
    assert out["priv_pass"] == "stored-p"
    assert out["user"] == "card"
    assert out["style"] == "authpriv"


def test_host_oids() -> None:
    missing = [name for name in HOST_KEYS if name not in OIDS]
    assert missing == []
    assert OIDS["sysDescr"] == (1, 3, 6, 1, 2, 1, 1, 1, 0)
    assert OIDS["sysName"] == (1, 3, 6, 1, 2, 1, 1, 5, 0)


if __name__ == "__main__":
    test_password_uses_one_secret()
    test_password_empty_stays_empty()
    test_password_copies_priv_when_auth_missing()
    test_authpriv_keeps_env_fallback()
    test_authpriv_stored_wins()
    test_host_oids()
    print("SNMP_STYLE_OK")
