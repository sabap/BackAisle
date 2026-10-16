"""Ready-made RMCARD command lines. The writer sends only what this module builds."""
from __future__ import annotations

import re

RECIPES = ("identity", "utility", "restore", "sensor", "account")

_TEXT_BODY = r"[A-Za-z0-9][A-Za-z0-9 .,_/#()+\-]*"
_TEXT = re.compile(r"^" + _TEXT_BODY + r"$")
# A space is a second argument on the card. Quotes keep the text as one argument.
_ARG = r'(?:"' + _TEXT_BODY + r'"|' + _TEXT_BODY + r")"
# A sys show reply is kept this long so Name, Location, and Contact are not cut off.
IDENTITY_SHOW_LIMIT = 1200
_DELAYS = (0, 60, 120, 180, 300, 600, 1200, 1800, 3600)
_CAPS = (0, 15, 30, 45, 60, 75, 90)
_SENS = ("low", "medium", "high")

_FIELDS = {
    "identity": {"name", "location", "contact"},
    "utility": {"sensitivity", "high_volts", "low_volts", "output_volts"},
    "restore": {"recharge_delay", "recharge_capacity", "return_delay"},
    "sensor": {"index", "name", "location", "temp_high", "temp_low", "humid_high", "humid_low"},
    "account": {"role", "name", "password", "access"},
}
_SECRET = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.,_/#()+=!@%-]{3,62}$")
_PASSWD_LINE = re.compile(r"^((?:admin|device) passwd )\S+$")

_LINE = re.compile(
    r"^(?:"
    r"sys (?:name|location|contact) " + _ARG + r""
    r"|sys show"
    r"|upscfg sen (?:high|medium|low)"
    r"|upscfg (?:hvlimit|lvlimit|outpwr) \d+"
    r"|upscfg rechargedelay (?:0|60|120|180|300|600|1200|1800|3600)"
    r"|upscfg rechargecap (?:0|15|30|45|60|75|90)"
    r"|upscfg returndelay \d+"
    r"|upscfg show"
    r"|envcfg index [1-8] (?:name|location) " + _ARG + r""
    r"|envcfg index [1-8] (?:temphthres|templthres|humhthres|humlthres) -?\d+"
    r"|envcfg show"
    r"|(?:admin|device) name " + _ARG + r""
    r"|(?:admin|device) passwd [A-Za-z0-9][A-Za-z0-9.,_/#()+=!@%-]{3,62}"
    r"|device access (?:enable|disable)"
    r"|(?:admin|device) show"
    r")$"
)


def _text(value, limit: int, label: str) -> str:
    text = str(value if value is not None else "").strip()
    if not text or len(text) > limit or not _TEXT.match(text):
        raise ValueError(label + " can use letters, numbers, spaces, and . , _ - / # ( ) +")
    return text


def _arg(value, limit: int, label: str) -> str:
    """One command-line argument. A space is quoted so the card does not see a second word."""
    text = _text(value, limit, label)
    if " " in text:
        return '"' + text + '"'
    return text


def _unwrap(value: str) -> str:
    raw = str(value or "").strip()
    if len(raw) >= 2 and raw.startswith('"') and raw.endswith('"'):
        return raw[1:-1]
    return raw


def _whole(value, lo: int, hi: int, label: str) -> int:
    if isinstance(value, bool) or value is None:
        raise ValueError(label + " must be a whole number")
    raw = str(value).strip()
    if not re.fullmatch(r"-?\d+", raw):
        raise ValueError(label + " must be a whole number")
    number = int(raw)
    if number < lo or number > hi:
        raise ValueError(label + " must be between " + str(lo) + " and " + str(hi))
    return number


def _secret(value, limit: int, label: str) -> str:
    text = str(value if value is not None else "")
    if text != text.strip() or len(text) < 4 or len(text) > limit or not _SECRET.match(text):
        raise ValueError(
            label + " must be 4 to " + str(limit) + " characters: letters, numbers, and . , _ - / # ( ) + = ! @ %"
        )
    return text


def redact_card_text(text: str, secrets) -> str:
    """Hide login passwords in anything that is stored or shown."""
    raw = "" if text is None else str(text)
    parts = []
    for part in raw.split(" | "):
        if _PASSWD_LINE.match(part):
            part = _PASSWD_LINE.sub(r"\1********", part)
        parts.append(part)
    out = " | ".join(parts)
    for secret in secrets or []:
        if isinstance(secret, str) and secret:
            out = out.replace(secret, "********")
    return out


def _choice(value, allowed: tuple, label: str):
    number = _whole(value, min(allowed), max(allowed), label)
    if number not in allowed:
        raise ValueError(label + " is not one of the card choices")
    return number


def build_card_commands(recipe: str, fields: dict) -> list[str]:
    """Turn one setting group into command lines. Empty fields are omitted."""
    if recipe not in RECIPES:
        raise ValueError("unknown card setting")
    if not isinstance(fields, dict):
        raise ValueError("card settings need a field list")
    unknown = [key for key in fields if key not in _FIELDS[recipe]]
    if unknown:
        raise ValueError("unknown field")
    clean = {}
    for key, value in fields.items():
        if value is None:
            continue
        if isinstance(value, str):
            stripped = value.strip()
            # An empty box, or the word null, is not a value to send.
            if stripped == "" or (recipe == "identity" and stripped.lower() == "null"):
                continue
        clean[key] = value
    if recipe == "sensor":
        clean.pop("index", None)
        if not clean:
            raise ValueError("enter at least one value")
        clean["index"] = fields.get("index", 1)
    elif recipe == "account":
        role = str(fields.get("role") or "").strip()
        if role not in ("admin", "device"):
            raise ValueError("Choose the administrator or the viewer")
        clean.pop("role", None)
        if not clean:
            raise ValueError("enter at least one value")
        clean["role"] = role
    elif not clean:
        raise ValueError("enter at least one value")

    lines: list[str] = []
    if recipe == "identity":
        if "name" in clean:
            lines.append("sys name " + _arg(clean["name"], 63, "Name"))
        if "location" in clean:
            lines.append("sys location " + _arg(clean["location"], 127, "Location"))
        if "contact" in clean:
            lines.append("sys contact " + _arg(clean["contact"], 63, "Contact"))
        lines.append("sys show")
    elif recipe == "utility":
        if "sensitivity" in clean:
            sens = str(clean["sensitivity"]).strip().lower()
            if sens not in _SENS:
                raise ValueError("Sensitivity must be low, medium, or high")
            lines.append("upscfg sen " + sens)
        if "high_volts" in clean:
            lines.append("upscfg hvlimit " + str(_whole(clean["high_volts"], 50, 300, "High voltage")))
        if "low_volts" in clean:
            lines.append("upscfg lvlimit " + str(_whole(clean["low_volts"], 50, 300, "Low voltage")))
        if "high_volts" in clean and "low_volts" in clean:
            if int(str(clean["high_volts"]).strip()) <= int(str(clean["low_volts"]).strip()):
                raise ValueError("High voltage has to be above the low voltage")
        if "output_volts" in clean:
            lines.append("upscfg outpwr " + str(_whole(clean["output_volts"], 50, 300, "Output voltage")))
        lines.append("upscfg show")
    elif recipe == "restore":
        if "recharge_delay" in clean:
            lines.append("upscfg rechargedelay " + str(_choice(clean["recharge_delay"], _DELAYS, "Recharge delay")))
        if "recharge_capacity" in clean:
            lines.append("upscfg rechargecap " + str(_choice(clean["recharge_capacity"], _CAPS, "Recharge capacity")))
        if "return_delay" in clean:
            delay = _whole(clean["return_delay"], 0, 600, "Return delay")
            if delay % 5 != 0:
                raise ValueError("Return delay must be a multiple of 5 seconds")
            lines.append("upscfg returndelay " + str(delay))
        lines.append("upscfg show")
    elif recipe == "sensor":
        index = _whole(clean.get("index", 1), 1, 8, "Sensor")
        if "name" in clean:
            lines.append("envcfg index " + str(index) + " name " + _arg(clean["name"], 63, "Sensor name"))
        if "location" in clean:
            lines.append("envcfg index " + str(index) + " location " + _arg(clean["location"], 63, "Sensor location"))
        if "temp_high" in clean:
            lines.append("envcfg index " + str(index) + " temphthres " + str(_whole(clean["temp_high"], -40, 250, "High temperature")))
        if "temp_low" in clean:
            lines.append("envcfg index " + str(index) + " templthres " + str(_whole(clean["temp_low"], -40, 250, "Low temperature")))
        if "temp_high" in clean and "temp_low" in clean:
            if int(str(clean["temp_high"]).strip()) <= int(str(clean["temp_low"]).strip()):
                raise ValueError("High temperature has to be above the low temperature")
        if "humid_high" in clean:
            lines.append("envcfg index " + str(index) + " humhthres " + str(_whole(clean["humid_high"], 0, 100, "High humidity")))
        if "humid_low" in clean:
            lines.append("envcfg index " + str(index) + " humlthres " + str(_whole(clean["humid_low"], 0, 100, "Low humidity")))
        if "humid_high" in clean and "humid_low" in clean:
            if int(str(clean["humid_high"]).strip()) <= int(str(clean["humid_low"]).strip()):
                raise ValueError("High humidity has to be above the low humidity")
        lines.append("envcfg show")
    else:
        role = str(clean["role"])
        limit = 63 if role == "admin" else 15
        who = "Administrator" if role == "admin" else "Viewer"
        if "access" in clean:
            if role != "device":
                raise ValueError("Only the viewer account can be enabled or disabled")
            access = str(clean["access"]).strip().lower()
            if access not in ("enable", "disable"):
                raise ValueError("Viewer access must be enable or disable")
            lines.append("device access " + access)
        if "name" in clean:
            lines.append(role + " name " + _arg(clean["name"], limit, who + " name"))
        if "password" in clean:
            lines.append(role + " passwd " + _secret(clean["password"], limit, who + " password"))
        lines.append(role + " show")

    if len(lines) < 2:
        raise ValueError("enter at least one value")
    for line in lines:
        # Local shell settings only. SNMPv3 users are written by push_snmpv3, never here.
        if line.split(" ", 1)[0] not in ("sys", "upscfg", "envcfg", "admin", "device"):
            raise ValueError("refusing command")
        if not _LINE.match(line):
            raise ValueError("refusing command")
    return lines


_SHOW_LABELS = (
    "Firmware Update Date",
    "Hardware Version",
    "Firmware Version",
    "Serial Number",
    "MAC Address",
    "Name",
    "Location",
    "Contact",
    "Model",
)
_IDENTITY_LIMIT = {"name": 63, "location": 127, "contact": 63}


def _blankish(value) -> bool:
    text = str(value or "").strip().lower()
    return text == "" or text == "null"


def _entered(fields, key: str) -> bool:
    if not isinstance(fields, dict):
        return False
    value = fields.get(key)
    if value is None:
        return False
    return not _blankish(value)


def parse_identity_show(text: str) -> dict:
    """Read Name, Location, and Contact from `sys show`.

    A missing label is None. A label with nothing after it is an empty string.
    The word null is kept as text so the caller can refuse to write it back.
    """
    raw = str(text or "").replace("\r", " ").replace("\n", " ")
    raw = re.sub(r"CyberPower\s*>", " ", raw)
    flat = " ".join(raw.split())
    hits = []
    for label in _SHOW_LABELS:
        pattern = r"(?<![A-Za-z])" + re.escape(label) + r"\s*:"
        for match in re.finditer(pattern, flat):
            hits.append((match.start(), match.end(), label.lower()))
    hits.sort()
    out = {"name": None, "location": None, "contact": None}
    for index, (_start, end, label) in enumerate(hits):
        stop = hits[index + 1][0] if index + 1 < len(hits) else len(flat)
        if label in out:
            out[label] = flat[end:stop].strip()
    return out


def identity_readable(parsed: dict) -> None:
    """Fail closed when sys show did not include every identity label."""
    missing = [key for key in ("name", "location", "contact") if not isinstance(parsed, dict) or parsed.get(key) is None]
    if missing:
        raise ValueError("could not read " + ", ".join(missing))


def identity_fields_sent(commands) -> set:
    sent = set()
    for line in commands or []:
        if str(line).startswith("sys name "):
            sent.add("name")
        elif str(line).startswith("sys location "):
            sent.add("location")
        elif str(line).startswith("sys contact "):
            sent.add("contact")
    return sent


def identity_kept_note(fields: dict) -> str:
    """Say which identity boxes were left blank."""
    missing = [key for key in ("name", "location", "contact") if not _entered(fields, key)]
    if len(missing) == 1:
        return missing[0] + " left unchanged"
    if len(missing) == 2:
        return missing[0] + " and " + missing[1] + " left unchanged"
    if len(missing) == 3:
        return "name, location, and contact left unchanged"
    return ""


def identity_keep_detail(before: dict, fields: dict) -> str:
    parts = []
    for key in ("name", "location", "contact"):
        if _entered(fields, key):
            continue
        value = (before or {}).get(key) if isinstance(before, dict) else None
        if value is None:
            parts.append(key + " was not readable")
            continue
        shown = str(value).strip()
        parts.append(key + " stays " + ("(empty)" if _blankish(shown) else shown))
    return "; ".join(parts)[:1800]


def _identity_pair(before: dict, after: dict, key: str) -> tuple:
    if not isinstance(before, dict) or not isinstance(after, dict):
        raise ValueError("could not read " + key)
    if before.get(key) is None or after.get(key) is None:
        raise ValueError("could not read " + key)
    return str(before.get(key) or "").strip(), str(after.get(key) or "").strip()


def identity_restore_commands(before: dict, after: dict, sent: set) -> list:
    """Put back a field the user did not type, when the card changed it.

    A blank or the word null is never sent. If that field was already empty
    and the card wrote something else, the job fails instead of clearing it.
    """
    lines = []
    for key in ("name", "location", "contact"):
        if key in (sent or ()):
            continue
        old, new = _identity_pair(before, after, key)
        if old == new or (_blankish(old) and _blankish(new)):
            continue
        if _blankish(old):
            raise ValueError(key + " was empty and the card wrote a value; a blank is not sent back")
        written = _arg(old, _IDENTITY_LIMIT[key], key.title())
        if _blankish(_unwrap(written)):
            raise ValueError(key + " is not written back as a blank")
        line = "sys " + key + " " + written
        if not _LINE.match(line):
            raise ValueError("refusing command")
        lines.append(line)
    return lines


def identity_typed_values(commands) -> dict:
    """The name, location, and contact a command list actually sends."""
    found = {}
    for line in commands or []:
        text = str(line)
        for key in ("name", "location", "contact"):
            prefix = "sys " + key + " "
            if text.startswith(prefix):
                found[key] = _unwrap(text[len(prefix):])
    return found


def identity_saved(after: dict, commands) -> None:
    """The read-back has to match the typed text, not a quoted or shortened copy."""
    for key, want in identity_typed_values(commands).items():
        got = ""
        if isinstance(after, dict) and after.get(key) is not None:
            got = str(after.get(key)).strip()
        if got != want:
            raise ValueError(key + " was not saved as typed")


def identity_still_kept(before: dict, final: dict, sent: set) -> None:
    for key in ("name", "location", "contact"):
        if key in (sent or ()):
            continue
        old, now = _identity_pair(before, final, key)
        if old == now or (_blankish(old) and _blankish(now)):
            continue
        raise ValueError(key + " changed and was not kept")
