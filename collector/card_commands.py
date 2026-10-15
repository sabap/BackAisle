"""Ready-made RMCARD command lines. The writer sends only what this module builds."""
from __future__ import annotations

import re

RECIPES = ("identity", "utility", "restore", "sensor")

_TEXT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9 .,_/#()+\-]*$")
_DELAYS = (0, 60, 120, 180, 300, 600, 1200, 1800, 3600)
_CAPS = (0, 15, 30, 45, 60, 75, 90)
_SENS = ("low", "medium", "high")

_FIELDS = {
    "identity": {"name", "location", "contact"},
    "utility": {"sensitivity", "high_volts", "low_volts", "output_volts"},
    "restore": {"recharge_delay", "recharge_capacity", "return_delay"},
    "sensor": {"index", "name", "location", "temp_high", "temp_low", "humid_high", "humid_low"},
}

_LINE = re.compile(
    r"^(?:"
    r"sys (?:name|location|contact) \S.*"
    r"|sys show"
    r"|upscfg sen (?:high|medium|low)"
    r"|upscfg (?:hvlimit|lvlimit|outpwr) \d+"
    r"|upscfg rechargedelay (?:0|60|120|180|300|600|1200|1800|3600)"
    r"|upscfg rechargecap (?:0|15|30|45|60|75|90)"
    r"|upscfg returndelay \d+"
    r"|upscfg show"
    r"|envcfg index [1-8] (?:name|location) \S.*"
    r"|envcfg index [1-8] (?:temphthres|templthres|humhthres|humlthres) -?\d+"
    r"|envcfg show"
    r")$"
)


def _text(value, limit: int, label: str) -> str:
    text = str(value if value is not None else "").strip()
    if not text or len(text) > limit or not _TEXT.match(text):
        raise ValueError(label + " can use letters, numbers, spaces, and . , _ - / # ( ) +")
    return text


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
        if isinstance(value, str) and value.strip() == "":
            continue
        clean[key] = value
    if recipe == "sensor":
        clean.pop("index", None)
        if not clean:
            raise ValueError("enter at least one value")
        clean["index"] = fields.get("index", 1)
    elif not clean:
        raise ValueError("enter at least one value")

    lines: list[str] = []
    if recipe == "identity":
        if "name" in clean:
            lines.append("sys name " + _text(clean["name"], 63, "Name"))
        if "location" in clean:
            lines.append("sys location " + _text(clean["location"], 127, "Location"))
        if "contact" in clean:
            lines.append("sys contact " + _text(clean["contact"], 63, "Contact"))
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
    else:
        index = _whole(clean.get("index", 1), 1, 8, "Sensor")
        if "name" in clean:
            lines.append("envcfg index " + str(index) + " name " + _text(clean["name"], 63, "Sensor name"))
        if "location" in clean:
            lines.append("envcfg index " + str(index) + " location " + _text(clean["location"], 63, "Sensor location"))
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

    if len(lines) < 2:
        raise ValueError("enter at least one value")
    for line in lines:
        if not _LINE.match(line):
            raise ValueError("refusing command")
    return lines
