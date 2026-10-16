"""Command builder checks. No card and no secrets."""
from card_commands import _LINE, build_card_commands, redact_card_text
from rmcard_defaults import factory_admin, factory_viewer


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

expect("account", {"role": "admin", "name": "Ops"}, [
    "admin name Ops",
    "admin show",
])
expect("account", {"role": "admin", "password": "GoodPass1"}, [
    "admin passwd GoodPass1",
    "admin show",
])
expect("account", {"role": "device", "access": "disable", "name": "view1", "password": "ViewPass1"}, [
    "device access disable",
    "device name view1",
    "device passwd ViewPass1",
    "device show",
])
expect("account", {"role": "device", "access": "enable"}, [
    "device access enable",
    "device show",
])
expect("account", {"role": "device", "name": "123456789012345"}, [
    "device name 123456789012345",
    "device show",
])

rejects("account", {"role": "admin"})
rejects("account", {"role": "admin", "access": "enable"})
rejects("account", {"role": "device", "name": "this name is too long"})
rejects("account", {"role": "admin", "password": "bad pass"})
rejects("account", {"role": "admin", "password": "abc"})
rejects("account", {"role": "admin", "password": "no;semi"})
rejects("account", {"role": "device", "password": "GoodPass123456789"})
rejects("account", {"role": "nope", "name": "Ops"})

for line in build_card_commands("account", {"role": "admin", "name": "Ops", "password": "GoodPass1"}):
    if line.split(" ", 1)[0] not in ("admin", "device"):
        raise SystemExit("account command left the local login: " + line)
    if "snmp" in line or "usm" in line or "trap" in line:
        raise SystemExit("account command mentioned snmp: " + line)

for blocked in (
    "snmpv3 username 1 backaisle",
    "snmpv3 auth 1 SHA",
    "usm user 1 backaisle",
    "trap add 1 10.1.1.1",
    "admin name Ops; snmpv3 show",
):
    if _LINE.match(blocked):
        raise SystemExit("snmp command was allowed: " + blocked)

rejects("account", {"role": "admin", "name": "Ops", "snmp_user": "backaisle"})

admin_user, admin_pass = factory_admin()
viewer_user, viewer_pass = factory_viewer()
if (admin_user, admin_pass) != ("cyber", "cyber") or (viewer_user, viewer_pass) != ("device", "cyber"):
    raise SystemExit("factory card login drifted")

shown = redact_card_text("admin name Ops | admin passwd GoodPass1 | admin show", ["GoodPass1"])
if shown != "admin name Ops | admin passwd ******** | admin show" or "GoodPass1" in shown:
    raise SystemExit("redact failed: " + shown)
hidden = redact_card_text("Input admin password : GoodPass1\nPass", ["GoodPass1", "OldLogin9"])
if "GoodPass1" in hidden or "OldLogin9" in hidden:
    raise SystemExit("response redact failed")

print("card commands ok")
