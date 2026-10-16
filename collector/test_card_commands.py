"""Command builder checks. No card and no secrets."""
from card_commands import (
    IDENTITY_SHOW_LIMIT,
    _LINE,
    build_card_commands,
    identity_fields_sent,
    identity_keep_detail,
    identity_kept_note,
    identity_readable,
    identity_restore_commands,
    identity_still_kept,
    parse_identity_show,
    redact_card_text,
)
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

expect("identity", {"name": "A", "location": "", "contact": None}, [
    "sys name A",
    "sys show",
])
expect("identity", {"name": "A", "location": "null", "contact": " NULL "}, [
    "sys name A",
    "sys show",
])
rejects("identity", {"name": "null"})
rejects("identity", {"name": " NULL "})

_SHOW = """CyberPower > sys show
Name: RMCARD205 (305)
Location: Server Room 2
Contact: Admainistrator
Model: RMCARD205
Hardware Version: 1.0
Firmware Version: 1.4.1
Firmware Update Date: 01/15/2024
Serial Number: ABC123
MAC Address: 00:0C:15:07:8D:BD
CyberPower >
"""
_parsed = parse_identity_show(_SHOW)
if _parsed != {"name": "RMCARD205 (305)", "location": "Server Room 2", "contact": "Admainistrator"}:
    raise SystemExit("sys show parse failed: " + repr(_parsed))
_one_line = parse_identity_show(
    "Name: Lab UPS Location: Closet A Contact: Facilities Model: RMCARD205 CyberPower >"
)
if _one_line != {"name": "Lab UPS", "location": "Closet A", "contact": "Facilities"}:
    raise SystemExit("one-line sys show parse failed: " + repr(_one_line))
_empty = parse_identity_show("Name: Lab\nLocation:\nContact: null\nModel: RMCARD205\n")
if _empty != {"name": "Lab", "location": "", "contact": "null"}:
    raise SystemExit("blank identity parse failed: " + repr(_empty))
_missing = parse_identity_show("Name: Lab\nContact: Desk\nModel: RMCARD205\n")
if _missing["location"] is not None:
    raise SystemExit("missing location should be unreadable")
try:
    identity_readable(_missing)
except ValueError as exc:
    if "location" not in str(exc):
        raise SystemExit("unreadable identity raised the wrong error: " + str(exc))
else:
    raise SystemExit("missing location was accepted")

if identity_kept_note({"name": "A"}) != "location and contact left unchanged":
    raise SystemExit("kept note for name only drifted")
if identity_kept_note({"name": "A", "location": "null"}) != "location and contact left unchanged":
    raise SystemExit("the word null was treated as a typed location")
if identity_kept_note({"location": "Closet"}) != "name and contact left unchanged":
    raise SystemExit("kept note for location only drifted")
if identity_kept_note({"name": "A", "contact": "Desk"}) != "location left unchanged":
    raise SystemExit("kept note for one blank drifted")
if identity_fields_sent(["sys name A", "sys show"]) != {"name"}:
    raise SystemExit("sent fields drifted")

_before = {"name": "Lab UPS", "location": "Server Room 2", "contact": "Facilities"}
_cleared = {"name": "Lab UPS", "location": "", "contact": "null"}
_restored = identity_restore_commands(_before, _cleared, {"name"})
if _restored != ["sys location Server Room 2", "sys contact Facilities"]:
    raise SystemExit("did not put the cleared fields back: " + repr(_restored))
for _line in _restored:
    if not _LINE.match(_line) or _line.endswith("null") or _line.endswith(" "):
        raise SystemExit("restore command is not safe: " + _line)
if identity_restore_commands(_before, _before, {"name"}) != []:
    raise SystemExit("unchanged fields produced a command")
_user_location = identity_restore_commands(
    _before,
    {"name": "Lab UPS", "location": "null", "contact": "Facilities"},
    {"location"},
)
if _user_location != []:
    raise SystemExit("a typed field was restored: " + repr(_user_location))
try:
    identity_restore_commands(
        {"name": "Lab", "location": "", "contact": "null"},
        {"name": "Lab", "location": "Office", "contact": ""},
        set(),
    )
except ValueError as exc:
    if "blank is not sent" not in str(exc):
        raise SystemExit("empty field raised the wrong error: " + str(exc))
else:
    raise SystemExit("an empty field was written back")
if identity_restore_commands(
    {"name": "Lab", "location": "", "contact": "null"},
    {"name": "Lab", "location": "NULL", "contact": ""},
    set(),
) != []:
    raise SystemExit("blank and null were treated as a change")
try:
    identity_still_kept(_before, _cleared, {"name"})
except ValueError as exc:
    if "changed and was not kept" not in str(exc):
        raise SystemExit("kept check raised the wrong error: " + str(exc))
else:
    raise SystemExit("a cleared field passed the kept check")
identity_still_kept(_before, _before, {"name"})
if "location stays Server Room 2" not in identity_keep_detail(_before, {"name": "A"}):
    raise SystemExit("keep detail dropped the existing location")
if "null" in identity_keep_detail({"name": "Lab", "location": "null", "contact": ""}, {"name": "A"}):
    raise SystemExit("keep detail stored the word null")


def _show(name, location, contact):
    return (
        "Name: " + name + "\n"
        "Location: " + location + "\n"
        "Contact: " + contact + "\n"
        "Model: RMCARD205\n"
        "CyberPower >\n"
    )


class _FakeCli:
    def __init__(self, replies):
        self.replies = list(replies)
        self.sent = []

    def run(self, command):
        self.sent.append(command)
        if not self.replies:
            raise RuntimeError("no reply for " + command)
        return self.replies.pop(0)


class _FakeCon:
    def execute(self, *_args):
        return None

    def commit(self):
        return None


_last_cli = []


def _apply(replies, commands, fields):
    import writer

    cli = _FakeCli(replies)
    _last_cli.append(cli)
    writer._apply_identity(cli, _FakeCon(), 1, commands, fields, [])
    return cli.sent


def _refuse_blank(commands):
    for command in commands:
        if command.endswith("null") or command.endswith(" ") or command in ("sys name", "sys location", "sys contact"):
            raise SystemExit("blank identity command: " + command)


_kept = _show("Lab UPS", "Server Room 2", "Facilities")
_wiped = _show("Lab UPS", "", "null")
_sent = _apply(
    [_kept, "Success", _wiped, "Success", "Success", _kept],
    ["sys name Queue", "sys show"],
    {"name": "Queue"},
)
if _sent[0] != "sys show" or "sys name Queue" not in _sent:
    raise SystemExit("typed name was not sent: " + repr(_sent))
if "sys location Server Room 2" not in _sent or "sys contact Facilities" not in _sent:
    raise SystemExit("cleared fields were not put back: " + repr(_sent))
_refuse_blank(_sent)

_same = _apply([_kept, "Success", _kept], ["sys name Queue", "sys show"], {"name": "Queue"})
if any(command.startswith("sys location") or command.startswith("sys contact") for command in _same):
    raise SystemExit("unchanged fields were written: " + repr(_same))

try:
    _apply(["Name: Lab\nCyberPower >\n"], ["sys name Queue", "sys show"], {"name": "Queue"})
except RuntimeError as exc:
    if "could not read" not in str(exc):
        raise SystemExit("unreadable show raised the wrong error: " + str(exc))
else:
    raise SystemExit("a partial show was accepted")
if _last_cli[-1].sent != ["sys show"]:
    raise SystemExit("a setter ran before the identity could be read: " + repr(_last_cli[-1].sent))

_import_writer = __import__("writer")
_clip = _FakeCli(["x" * IDENTITY_SHOW_LIMIT])
try:
    _import_writer._apply_identity(_clip, _FakeCon(), 1, ["sys name Queue", "sys show"], {"name": "Queue"}, [])
except RuntimeError as exc:
    if "whole identity" not in str(exc):
        raise SystemExit("clipped show raised the wrong error: " + str(exc))
else:
    raise SystemExit("a clipped show was accepted")
if _clip.sent != ["sys show"]:
    raise SystemExit("a setter ran before the identity could be read: " + repr(_clip.sent))

try:
    _apply(
        [_show("Lab UPS", "", "Facilities"), "Success", _show("Lab UPS", "Office", "Facilities")],
        ["sys name Queue", "sys show"],
        {"name": "Queue"},
    )
except RuntimeError as exc:
    if "blank is not sent" not in str(exc):
        raise SystemExit("empty location raised the wrong error: " + str(exc))
else:
    raise SystemExit("an empty location was written")
if any(command.startswith("sys location") for command in _last_cli[-1].sent):
    raise SystemExit("wrote a location that was empty: " + repr(_last_cli[-1].sent))

_only_contact = _apply(
    [_kept, "Success", _show("Lab UPS", "Closet A", ""), "Success", _kept],
    ["sys location Closet A", "sys show"],
    {"location": "Closet A"},
)
if "sys contact Facilities" not in _only_contact:
    raise SystemExit("contact was not put back: " + repr(_only_contact))
if any(command.startswith("sys name ") for command in _only_contact) or "sys location Server Room 2" in _only_contact:
    raise SystemExit("a typed or untouched field was rewritten: " + repr(_only_contact))
_refuse_blank(_only_contact)

print("card commands ok")
