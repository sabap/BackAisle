"""Parse, redact, and rewrite RMCARD YYYY_MM_DD_HHMM.txt config files."""
from __future__ import annotations

import re
from dataclasses import dataclass, field

IDENTITY_KEY_RE = re.compile(
    r"(ip\s*address|ipaddress|ipv4|gateway|subnet|netmask|submask|hostname|host\s*name|"
    r"device\s*name|sysname|system_name|system_host_name|system_ip|"
    r"mac_address|mac\s*address|macaddress|"
    r"snmp\s*client|allowed\s*client|nsmclient|"
    r"snmpv3_access_ipaddr|snmpv3_access_username|usm\s*user)",
    re.I,
)
SECRET_KEY_RE = re.compile(
    r"(password|passwd|passphrase|auth\s*pass|priv\s*pass|secret|community|"
    r"radius.*secret|ldap.*pass)",
    re.I,
)
POLICY_KEEP_RE = re.compile(
    r"(threshold|temp|humid|ntp|smtp|trap\s*receiver|trap\s*ip|syslog|"
    r"email|envir|sensor|event)",
    re.I,
)


@dataclass
class ConfigDoc:
    raw: str
    lines: list[str] = field(default_factory=list)
    pairs: list[tuple[int, str, str, str]] = field(default_factory=list)
    # (lineno, original_line, key, value)

    def identity_fields(self) -> list[dict]:
        out = []
        for i, orig, key, val in self.pairs:
            if IDENTITY_KEY_RE.search(key):
                out.append({"line": i + 1, "key": key, "value": val, "kind": "identity"})
        return out

    def redacted_text(self) -> str:
        out = []
        for line in self.lines:
            key, val, sep = _split_kv(line)
            if key and SECRET_KEY_RE.search(key) and val:
                out.append(key + sep + "********")
            else:
                out.append(line)
        return "\n".join(out)

    def rewrite(self, overlays: dict[str, str], strip_identity: bool = True,
                identity_map: dict[str, str] | None = None) -> str:
        """overlays match keys case-insensitively. identity_map replaces identity keys."""
        identity_map = identity_map or {}
        out = []
        for line in self.lines:
            key, val, sep = _split_kv(line)
            if not key:
                out.append(line)
                continue
            lk = key.strip().lower()
            replaced = False
            for ok, ov in overlays.items():
                if ok.strip().lower() == lk:
                    out.append(key + sep + ov)
                    replaced = True
                    break
            if replaced:
                continue
            if strip_identity and IDENTITY_KEY_RE.search(key):
                mapped = None
                for ik, iv in identity_map.items():
                    if ik.strip().lower() == lk or ik.strip().lower() in lk:
                        mapped = iv
                        break
                if mapped is not None:
                    out.append(key + sep + mapped)
                else:
                    out.append(line)  # keep original identity unless mapped
                continue
            out.append(line)
        return "\n".join(out)


def parse_config(text: str) -> ConfigDoc:
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    lines = text.split("\n")
    pairs = []
    for i, line in enumerate(lines):
        key, val, sep = _split_kv(line)
        if key:
            pairs.append((i, line, key, val))
    return ConfigDoc(raw=text, lines=lines, pairs=pairs)


def looks_like_rmcard_config(text: str) -> bool:
    if not text or len(text) < 40:
        return False
    if text.startswith("MZ") or text.startswith("\x7f"):
        return False
    low = text.lstrip().lower()
    if low.startswith("<!doctype") or low.startswith("<html") or "<html" in low[:200]:
        return False
    if low.startswith("cyberpowersystems") and "rmcard" in low[:80]:
        return True
    if re.search(r"\d{4}_\d{2}_\d{2}_\d{4}\.txt", text):
        return True
    keys = ("ipaddress", "ip address", "hostname", "snmp", "location", "ntp", "smtp", "trap")
    hits = sum(1 for k in keys if k in low)
    kv = len(re.findall(r"^[A-Za-z0-9_.\s-]{2,40}\s*[=:,]", text, re.M))
    return hits >= 3 and kv >= 8


def _split_kv(line: str) -> tuple[str | None, str, str]:
    s = line.rstrip("\n")
    if not s.strip() or s.lstrip().startswith(("#", ";", "//")):
        return None, "", ""
    for sep in (", ", " = ", "=", ": ", ":"):
        if sep in s:
            k, v = s.split(sep, 1)
            if k.strip() and re.match(r"^[A-Za-z][A-Za-z0-9_ ./-]{0,60}$", k.strip()):
                return k, v, sep
    return None, "", ""
