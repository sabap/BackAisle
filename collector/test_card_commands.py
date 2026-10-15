"""Command builder checks. No card and no secrets."""
from card_commands import build_card_commands


def expect(recipe, fields, lines):
    got = build_card_commands(recipe, fields)
    if got != lines:
        raise SystemExit(recipe + " got " + repr(got))


def rejects(recipe, fields):
    try:
        build_card_commands(recipe, fields)
    except ValueError:
        return
    raise SystemExit(recipe + " should reject " + repr(fields))


expect("identity", {"name": "A"}, [
    "sys name A",
    "sys show",
])
expect("identity", {"name": "IDF Lab", "contact": "Facilities"}, [
    "sys name IDF Lab",
    "sys contact Facilities",
    "sys show",
])
expect("identity", {"location": "Server Room 2"}, [
    "sys location Server Room 2",
    "sys show",
])
expect("utility", {"sensitivity": "low", "high_volts": 140, "low_volts": "100"}, [
    "upscfg sen low",
    "upscfg hvlimit 140",
    "upscfg lvlimit 100",
    "upscfg show",
])
expect("restore", {"recharge_delay": 120, "recharge_capacity": 15, "return_delay": 10}, [
    "upscfg rechargedelay 120",
    "upscfg rechargecap 15",
    "upscfg returndelay 10",
    "upscfg show",
])
expect("sensor", {"index": 2, "temp_high": 90, "name": "Closet probe"}, [
    "envcfg index 2 name Closet probe",
    "envcfg index 2 temphthres 90",
    "envcfg show",
])

rejects("identity", {})
rejects("identity", {"name": "drop; sys reset all"})
rejects("utility", {"sensitivity": "bypass"})
rejects("utility", {"high_volts": 100, "low_volts": 100})
rejects("restore", {"return_delay": 7})
rejects("sensor", {"index": 1})
rejects("upsctrl", {"reboot": "10/20"})
rejects("identity", {"reset": "all"})

print("card commands ok")
