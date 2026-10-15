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
        used = set()
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
                    used.add(ok.strip().lower())
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
                    out.append(line)
                continue
            out.append(line)
        for ok, ov in overlays.items():
            if ok.strip().lower() not in used:
                out.append(ok + "=" + ov)
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


def _snmpv3_slot_index(lk: str) -> int | None:
    """Do not use the '3' in 'snmpv3'. Prefer a trailing 1-4 after username/ip/pass."""
    rest = re.sub(r"^(snmpv3|usm)", "", lk)
    m = re.search(r"(\d+)", rest)
    if not m:
        return None
    idx = int(m.group(1))
    if 1 <= idx <= 4:
        return idx
    return None


def _retarget_key(key: str, from_idx: int, to_idx: int) -> str:
    rev = key[::-1]
    old = str(from_idx)[::-1]
    new = str(to_idx)[::-1]
    if old in rev:
        return rev.replace(old, new, 1)[::-1]
    return key


def parse_snmpv3_slots(doc: ConfigDoc) -> list[dict]:
    """RMCARD has 4 SNMPv3 access-control slots. Map config keys onto index 1-4."""
    slots = {
        i: {
            "index": i,
            "username": "",
            "ip": "",
            "auth_proto": "",
            "priv_proto": "",
            "status": "",
            "keys": {},
        }
        for i in range(1, 5)
    }
    for _i, _orig, key, val in doc.pairs:
        lk = re.sub(r"\s+", "", key.strip().lower())
        if "snmpv3" not in lk and "usm" not in lk:
            continue
        idx = _snmpv3_slot_index(lk)
        if idx is None:
            continue
        val = (val or "").strip()
        if any(x in lk for x in ("username", "user")) and "auth" not in lk and "priv" not in lk:
            slots[idx]["username"] = val
            slots[idx]["keys"]["username"] = key
        elif "name" in lk and "auth" not in lk and "priv" not in lk:
            slots[idx]["username"] = val
            slots[idx]["keys"]["username"] = key
        elif any(x in lk for x in ("ipaddr", "ipaddress")) or (lk.endswith("ip") or "ip" + str(idx) in lk):
            slots[idx]["ip"] = val
            slots[idx]["keys"]["ip"] = key
        elif "auth" in lk and ("proto" in lk or "type" in lk or "protocol" in lk):
            slots[idx]["auth_proto"] = val
            slots[idx]["keys"]["auth_proto"] = key
        elif "priv" in lk and ("proto" in lk or "type" in lk or "protocol" in lk):
            slots[idx]["priv_proto"] = val
            slots[idx]["keys"]["priv_proto"] = key
        elif "auth" in lk and ("pass" in lk or "key" in lk or "pwd" in lk):
            slots[idx]["keys"]["auth_pass"] = key
        elif "priv" in lk and ("pass" in lk or "key" in lk or "pwd" in lk):
            slots[idx]["keys"]["priv_pass"] = key
        elif "status" in lk or "enable" in lk:
            slots[idx]["status"] = val
            slots[idx]["keys"]["status"] = key
    donor = None
    for i in range(1, 5):
        if slots[i]["keys"].get("username"):
            donor = i
            break
    if donor:
        for i in range(1, 5):
            if slots[i]["keys"].get("username"):
                continue
            cloned = {}
            for field, src in slots[donor]["keys"].items():
                cloned[field] = _retarget_key(src, donor, i)
            slots[i]["keys"] = cloned
    return [slots[i] for i in range(1, 5)]


def is_placeholder_snmpv3_user(name: str) -> bool:
    """CyberPower factory labels: 'cyber snmpv3 user1' .. user4. Treat as empty."""
    n = re.sub(r"\s+", " ", (name or "").strip().lower())
    if n == "":
        return True
    if re.match(r"^cyber snmpv3 user\s*[1-4]$", n):
        return True
    if re.match(r"^snmpv3 user\s*[1-4]$", n):
        return True
    return False


def pick_snmpv3_slot(slots: list[dict], username: str) -> tuple[int, str]:
    """Match existing real username, else first empty/placeholder. Never overwrite a different real user."""
    want = (username or "").strip().lower()
    if want and not is_placeholder_snmpv3_user(want):
        for s in slots:
            have = (s.get("username") or "").strip().lower()
            if have == want:
                return int(s["index"]), "existing_user"
    for s in slots:
        if is_placeholder_snmpv3_user(s.get("username") or ""):
            return int(s["index"]), "empty_slot"
    occupied = ", ".join(f"{s['index']}={(s.get('username') or 'occupied')}" for s in slots)
    raise RuntimeError(
        "All 4 SNMPv3 slots are occupied (" + occupied + "). "
        "Refusing to overwrite. Free a slot on the card or use a username that already exists."
    )


def rmcard_snmpv3_auth_code(value: str) -> str:
    """RMCARD AUTHTYPE is 0=none 1=MD5 2=SHA. Do not write the word SHA."""
    v = (value or "").strip()
    if v in ("0", "1", "2"):
        return v
    return {"none": "0", "md5": "1", "sha": "2", "sha1": "2"}.get(v.lower(), "2")


def rmcard_snmpv3_priv_code(value: str) -> str:
    """RMCARD PRIVTYPE is 0=none 1=DES 2=AES. Do not write the word AES."""
    v = (value or "").strip()
    if v in ("0", "1", "2"):
        return v
    return {"none": "0", "des": "1", "aes": "2", "aes128": "2", "aes-128": "2"}.get(v.lower(), "2")


def snmpv3_overlays_for_slot(
    slots: list[dict],
    index: int,
    username: str,
    auth_proto: str,
    priv_proto: str,
    auth_pass: str,
    priv_pass: str,
    acl_ip: str | None,
) -> dict[str, str]:
    slot = next(s for s in slots if int(s["index"]) == int(index))
    keys = slot.get("keys") or {}
    out: dict[str, str] = {}
    if keys.get("username"):
        out[keys["username"]] = username
    if keys.get("auth_proto") and auth_proto:
        out[keys["auth_proto"]] = rmcard_snmpv3_auth_code(auth_proto)
    if keys.get("priv_proto") and priv_proto:
        out[keys["priv_proto"]] = rmcard_snmpv3_priv_code(priv_proto)
    if keys.get("auth_pass") and auth_pass:
        out[keys["auth_pass"]] = auth_pass
    if keys.get("priv_pass") and priv_pass:
        out[keys["priv_pass"]] = priv_pass
    if keys.get("status"):
        out[keys["status"]] = "1"
    if acl_ip is not None and keys.get("ip"):
        out[keys["ip"]] = acl_ip
    if not keys.get("username"):
        raise RuntimeError(
            f"Could not find SNMPv3 username key for slot {index} in the RMCARD config. "
            "Pull a config in Fleet writes and inspect snmpv3_* keys."
        )
    return out


def canonical_ipv4(value: str) -> str:
    text = (value or "").strip()
    parts = text.split(".")
    if len(parts) != 4:
        raise RuntimeError("Trap destination must be an IPv4 address")
    nums = []
    for part in parts:
        if not part.isdigit():
            raise RuntimeError("Trap destination must be an IPv4 address")
        num = int(part)
        if num > 255:
            raise RuntimeError("Trap destination must be an IPv4 address")
        nums.append(str(num))
    return ".".join(nums)


def clean_trap_receiver_name(value: str) -> str:
    name = re.sub(r"\s+", " ", (value or "").strip())
    if not name or len(name) > 15 or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9 ._-]{0,14}", name):
        raise RuntimeError("Trap receiver name must be 1-15 letters, digits, spaces, or . _ -")
    return name


def _config_pair_map(doc: ConfigDoc) -> dict[str, tuple[str, str]]:
    found: dict[str, tuple[str, str]] = {}
    for _i, _orig, key, val in doc.pairs:
        found[key.strip().lower()] = (key, (val or "").strip())
    return found


def _require_pair(found: dict[str, tuple[str, str]], name: str) -> tuple[str, str]:
    row = found.get(name.lower())
    if row is None:
        raise RuntimeError("This card config has no 10-receiver trap table (" + name + ")")
    return row


def _ten_digits(value: str, label: str) -> str:
    text = (value or "").strip()
    if not re.fullmatch(r"[0-9]{10}", text):
        raise RuntimeError(label + " must be 10 digits")
    return text


def _replace_digit(value: str, index0: int, digit: str, label: str) -> str:
    text = _ten_digits(value, label)
    if index0 < 0 or index0 > 9 or digit not in "0123456789":
        raise RuntimeError(label + " index is out of range")
    chars = list(text)
    chars[index0] = digit
    return "".join(chars)


def _blank_trap_ip(ip: str) -> bool:
    text = (ip or "").strip()
    if text == "":
        return True
    try:
        return canonical_ipv4(text) == "0.0.0.0"
    except RuntimeError:
        return False


def parse_trap_receivers(doc: ConfigDoc) -> dict:
    """Ten RMCARD trap receivers.

    TRAP_ENABLE_TYPE is one digit per receiver: 3 is SNMPv3, 1 is SNMPv1, 0 is unused.
    TRAP_V3_USER_INDEX is one digit per receiver and is 0-based, so user slot 1 is the character 0.
    """
    found = _config_pair_map(doc)
    type_key, type_val = _require_pair(found, "TRAP_ENABLE_TYPE")
    user_key, user_val = _require_pair(found, "TRAP_V3_USER_INDEX")
    enable_key, enable_val = _require_pair(found, "SNMP_TRAP_ENABLE")
    type_val = _ten_digits(type_val, "TRAP_ENABLE_TYPE")
    user_val = _ten_digits(user_val, "TRAP_V3_USER_INDEX")
    receivers = []
    enabled_word = "Enabled"
    for n in range(1, 11):
        name_key, name_val = _require_pair(found, f"TRAP_NAME{n}")
        ip_key, ip_val = _require_pair(found, f"TRAP_IP_ADDRESS{n}")
        access_key, access_val = _require_pair(found, f"TRAP_ENABLE_ACCESS{n}")
        if access_val.lower().startswith("enable"):
            enabled_word = access_val
        version = type_val[n - 1]
        user_digit = user_val[n - 1]
        user_slot = int(user_digit) + 1 if version == "3" and user_digit in "0123" else None
        receivers.append({
            "index": n,
            "name": name_val,
            "name_key": name_key,
            "ip": ip_val,
            "ip_key": ip_key,
            "access": access_val,
            "access_key": access_key,
            "version": version,
            "user_slot": user_slot,
        })
    return {
        "enable_key": enable_key,
        "enable": enable_val,
        "type_key": type_key,
        "type_value": type_val,
        "user_key": user_key,
        "user_value": user_val,
        "enabled_word": enabled_word,
        "receivers": receivers,
    }


def _trap_slot_for_user(slots: list[dict], username: str) -> dict:
    want = (username or "").strip()
    if not want or is_placeholder_snmpv3_user(want):
        raise RuntimeError("SNMPv3 user is not on this card. Write the SNMPv3 slot first.")
    matches = [s for s in slots if (s.get("username") or "").strip().lower() == want.lower()]
    if not matches:
        raise RuntimeError(
            "SNMPv3 user " + want + " is not on this card. Write the SNMPv3 slot first."
        )
    slot = matches[0]
    status = (slot.get("status") or "").strip().lower()
    if status in ("0", "disable", "disabled", "forbidden"):
        raise RuntimeError("SNMPv3 user " + want + " is disabled on the card.")
    auth = (slot.get("auth_proto") or "").strip().lower()
    priv = (slot.get("priv_proto") or "").strip().lower()
    if auth not in ("2", "sha", "sha1") or priv not in ("2", "aes", "aes128", "aes-128"):
        raise RuntimeError(
            "SNMPv3 user " + want + " is not SHA and AES. The trap listener only accepts those."
        )
    return slot


def trap_receiver_overlays(
    doc: ConfigDoc,
    slots: list[dict],
    username: str,
    dest_ip: str,
    receiver_name: str,
) -> tuple[dict[str, str], str]:
    """Overlay one SNMPv3 trap receiver. Empty overlays means the card already sends it."""
    name = clean_trap_receiver_name(receiver_name)
    ip = canonical_ipv4(dest_ip)
    if ip == "0.0.0.0":
        raise RuntimeError("Trap destination must be an IPv4 address")
    slot = _trap_slot_for_user(slots, username)
    slot_index = int(slot["index"])
    table = parse_trap_receivers(doc)
    receivers = table["receivers"]

    def same_ip(receiver: dict) -> bool:
        if _blank_trap_ip(receiver["ip"]):
            return False
        try:
            return canonical_ipv4(receiver["ip"]) == ip
        except RuntimeError:
            return False

    matches = [r for r in receivers if same_ip(r)]
    if matches:
        target = matches[0]
        action = "update"
    else:
        empties = [r for r in receivers if _blank_trap_ip(r["ip"]) and r["version"] == "0"]
        if not empties:
            raise RuntimeError(
                "All 10 trap receivers are in use. Refusing to overwrite another destination."
            )
        target = empties[0]
        action = "add"
    if target["name"].strip() != name:
        for other in receivers:
            if other["index"] == target["index"]:
                continue
            if other["name"].strip().lower() == name.lower() and not _blank_trap_ip(other["ip"]):
                raise RuntimeError("Trap name " + name + " is already used for " + other["ip"])
    already = (
        action == "update"
        and target["version"] == "3"
        and target["user_slot"] == slot_index
        and target["access"].lower().startswith("enable")
        and target["name"].strip() == name
        and table["enable"].strip() == "1"
    )
    if already:
        return {}, (
            "already sending SNMPv3 traps to " + ip + " as " + username.strip()
            + " (receiver " + str(target["index"]) + ")"
        )
    access_word = table["enabled_word"]
    if not access_word.lower().startswith("enable"):
        access_word = "Enabled"
    overlays = {
        target["name_key"]: name,
        target["ip_key"]: ip,
        target["access_key"]: access_word,
        table["type_key"]: _replace_digit(table["type_value"], target["index"] - 1, "3", "TRAP_ENABLE_TYPE"),
        table["user_key"]: _replace_digit(
            table["user_value"], target["index"] - 1, str(slot_index - 1), "TRAP_V3_USER_INDEX"
        ),
    }
    if table["enable"].strip() != "1":
        overlays[table["enable_key"]] = "1"
    summary = (
        action + " receiver " + str(target["index"])
        + " name=" + name + " ip=" + ip
        + " user=" + username.strip() + " slot=" + str(slot_index) + " version=v3"
    )
    return overlays, summary


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
