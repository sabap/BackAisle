"""SNMPv3 trap receiver.

Polling remains the source of truth. A trap opens or clears the same alert
sooner, and the notification sweep runs without waiting for the next poll.
SNMPv1 and SNMPv2c packets are ignored. Passphrases are never logged.
"""
from __future__ import annotations

import asyncio
import socket
import time
from typing import Callable

# CyberPower enterprise traps are 1.3.6.1.4.1.3808.0.<specific>.
# Numbers come from docs/CPS-MIB.mib. The value is
# (alert code, clear, severity, message, extra codes to clear).
CPS_TRAPS = {
    1: ("ups_link", False, "crit", "The management card lost communication with the UPS", ()),
    2: ("overload", False, "crit", "The UPS is overloaded", ()),
    4: ("capacity_low", False, "crit", "The UPS battery is discharged", ()),
    5: ("on_battery", False, "crit", "The UPS is on battery", ()),
    7: ("capacity_low", False, "crit", "The UPS battery is low", ()),
    8: ("ups_link", True, "info", "The management card is communicating with the UPS", ()),
    9: ("on_battery", True, "info", "Utility power is restored", ("on_battery_long",)),
    11: ("capacity_low", True, "info", "The UPS has returned from a low battery", ()),
    16: ("temp_high", False, "warn", "The UPS reports a high temperature", ()),
    17: ("temp_high", True, "info", "The UPS temperature is back in range", ()),
    19: ("overload", True, "info", "The UPS is no longer overloaded", ()),
    20: ("capacity_low", True, "info", "The UPS has returned from a discharged battery", ()),
    35: ("runtime_low", False, "warn", "The UPS runtime is below its threshold", ()),
    39: ("replace_battery", False, "warn", "The UPS battery is not present", ()),
    49: ("replace_battery", False, "warn", "The UPS battery has reached its expiration", ()),
    50: ("replace_battery", False, "warn", "The UPS is asking for a battery replacement", ()),
    58: ("on_battery", False, "crit", "The UPS is on battery", ()),
    200: ("temp_high", False, "warn", "The closet temperature is high", ()),
    201: ("temp_high", True, "info", "The closet temperature is back in range", ()),
    202: ("temp_low", False, "warn", "The closet temperature is low", ()),
    203: ("temp_low", True, "info", "The closet temperature is back in range", ()),
    204: ("humidity_high", False, "warn", "The closet humidity is high", ()),
    205: ("humidity_high", True, "info", "The closet humidity is back in range", ()),
    206: ("humidity_low", False, "warn", "The closet humidity is low", ()),
    207: ("humidity_low", True, "info", "The closet humidity is back in range", ()),
    208: ("water", False, "crit", "A contact sensor is abnormal", ()),
    209: ("water", True, "info", "The contact sensor is normal", ()),
    210: ("sensor_missing", True, "info", "The environment sensor is connected", ()),
    211: ("sensor_missing", False, "warn", "The environment sensor is disconnected", ()),
    254: ("temp_high", False, "warn", "The closet temperature alarm is active", ()),
    255: ("temp_high", True, "info", "The closet temperature alarm cleared", ()),
    257: ("humidity_high", False, "warn", "The closet humidity alarm is active", ()),
    258: ("humidity_high", True, "info", "The closet humidity alarm cleared", ()),
    260: ("water", False, "crit", "A contact alarm is active", ()),
    261: ("water", True, "info", "The contact alarm cleared", ()),
}

TRAP_OID = "1.3.6.1.6.3.1.1.4.1.0"
INFO_OID = "1.3.6.1.4.1.3808.2.2.3"
RFC_ON_BATTERY = "1.3.6.1.2.1.33.2.1"
CPS_PREFIX = "1.3.6.1.4.1.3808.0."


def _action(code: str, clear: bool, severity: str, message: str, extra: tuple[str, ...]) -> dict:
    return {
        "code": code,
        "clear": clear,
        "severity": severity,
        "message": message,
        "extra": extra,
    }


def classify(oid: str) -> dict | None:
    """Map a trap OID to an alert action. Unknown OIDs stay events only."""
    text = (oid or "").strip().lstrip(".")
    if text == RFC_ON_BATTERY:
        return _action("on_battery", False, "crit", "The UPS is on battery", ())
    if text.startswith(CPS_PREFIX):
        tail = text[len(CPS_PREFIX):]
        if tail.isdigit():
            row = CPS_TRAPS.get(int(tail))
            if row:
                return _action(*row)
    return None


def info_text(value: str) -> str:
    raw = (value or "").replace("\x00", "").strip()
    if not raw:
        return ""
    cleaned = "".join(ch if ch.isprintable() else " " for ch in raw)
    cleaned = " ".join(cleaned.split())
    if not cleaned or cleaned.lower() in ("null", "none"):
        return ""
    return cleaned[:180]


def oid_text(name) -> str:
    if hasattr(name, "asTuple"):
        return ".".join(str(part) for part in name.asTuple())
    return str(name).strip().lstrip(".")


def trap_fields(var_binds) -> tuple[str, str]:
    """Return the trap OID and the CyberPower info string, if present."""
    trap_oid = ""
    info = ""
    for bind in var_binds:
        if hasattr(bind, "getComponentByPosition"):
            name = bind.getComponentByPosition(0)
            value = bind.getComponentByPosition(1)
        else:
            name, value = bind[0], bind[1]
        oid = oid_text(name)
        if oid == TRAP_OID:
            trap_oid = oid_text(value)
        elif oid == INFO_OID:
            shown = value.prettyPrint() if hasattr(value, "prettyPrint") else str(value)
            info = info_text(shown)
    return trap_oid, info


def peek_usm(data: bytes) -> tuple[bytes, str] | None:
    """Read the engine id and user from an SNMPv3 header. Returns None for any other packet."""
    if not data or data[0] != 0x30:
        return None
    try:
        from pyasn1.codec.ber import decoder
        from pysnmp.proto.mpmod.rfc3412 import SNMPv3Message
        from pysnmp.proto.secmod.rfc3414.service import UsmSecurityParameters
    except Exception:
        return None
    try:
        msg, _ = decoder.decode(data, asn1Spec=SNMPv3Message())
        if int(msg.getComponentByPosition(0)) != 3:
            return None
        params, _ = decoder.decode(
            bytes(msg.getComponentByPosition(2)),
            asn1Spec=UsmSecurityParameters(),
        )
    except Exception:
        return None
    engine_id = bytes(params.getComponentByPosition(0))
    user = bytes(params.getComponentByPosition(3)).decode("utf-8", "replace").strip()
    if len(engine_id) < 5 or len(engine_id) > 32 or not user:
        return None
    return engine_id, user


def _source_ip(snmp_engine, state_reference) -> str:
    try:
        _domain, address = snmp_engine.message_dispatcher.get_transport_info(state_reference)
    except Exception:
        return ""
    if isinstance(address, (tuple, list)) and address:
        host = address[0]
    else:
        host = address
    if isinstance(host, bytes):
        host = host.decode("utf-8", "replace")
    return str(host or "").strip()


def serve(port: int, log: Callable[[str], None], on_trap: Callable[[str, str, str], None], load_users: Callable[[], dict]) -> None:
    """Listen on UDP port until the process exits. on_trap(ip, trap_oid, info) runs for each accepted trap."""
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.bind(("0.0.0.0", int(port)))
    except OSError as exc:
        log(f"trap listener not bound ({exc}); polling remains source of truth")
        sock.close()
        loop.close()
        return
    sock.setblocking(False)
    try:
        from pysnmp.carrier.asyncio.dgram import udp
        from pysnmp.entity import config as snmp_config
        from pysnmp.entity.engine import SnmpEngine
        from pysnmp.entity.rfc3413 import ntfrcv
    except Exception as exc:
        log(f"trap listener not started ({exc}); polling remains source of truth")
        sock.close()
        loop.close()
        return

    engine = SnmpEngine()
    transport = udp.UdpTransport().open_server_mode(sock=sock)
    snmp_config.add_transport(engine, udp.DOMAIN_NAME, transport)
    users: dict[str, tuple[str, str]] = {}
    registered: set[tuple[bytes, str]] = set()
    rejected: set[tuple[bytes, str]] = set()
    loaded_at = 0.0
    noted: set[str] = set()

    def refresh_users(force: bool = False) -> None:
        nonlocal users, loaded_at
        if not force and (time.time() - loaded_at) < 300:
            return
        try:
            users = load_users() or {}
        except Exception as exc:
            log(f"trap credentials not loaded ({exc})")
            users = {}
        loaded_at = time.time()

    def ensure_user(engine_id: bytes, username: str) -> None:
        key = (engine_id, username)
        if key in registered or key in rejected:
            return
        cred = users.get(username)
        if not cred:
            return
        auth_pass, priv_pass = cred
        if not auth_pass or not priv_pass:
            return
        try:
            snmp_config.add_v3_user(
                engine,
                username,
                snmp_config.USM_AUTH_HMAC96_SHA,
                auth_pass,
                snmp_config.USM_PRIV_CFB128_AES,
                priv_pass,
                securityEngineId=engine_id,
            )
        except Exception:
            rejected.add(key)
            log(f"trap user {username} could not be registered")
            return
        registered.add(key)

    original = transport._callback_function

    def wrapped(callback_transport, address, datagram: bytes):
        refresh_users()
        peeked = peek_usm(datagram)
        if peeked is None:
            host = address[0] if isinstance(address, (tuple, list)) and address else ""
            note = f"ignored non-v3 {host}"
            if note not in noted:
                noted.add(note)
                log(f"ignored a trap that is not SNMPv3 from {host}")
            return
        engine_id, username = peeked
        if username not in users:
            host = address[0] if isinstance(address, (tuple, list)) and address else ""
            note = f"unknown-user {host} {username}"
            if note not in noted:
                noted.add(note)
                log(f"trap from {host} uses SNMPv3 user {username}, which is not on a credential profile")
            return
        ensure_user(engine_id, username)
        if (engine_id, username) not in registered:
            return
        if original is None:
            return
        try:
            original(callback_transport, address, datagram)
        except Exception:
            host = address[0] if isinstance(address, (tuple, list)) and address else ""
            note = f"rejected {host} {username}"
            if note not in noted:
                noted.add(note)
                log(f"trap from {host} for user {username} was not accepted")

    transport._callback_function = wrapped

    def callback(snmp_engine, state_reference, _context_engine_id, _context_name, var_binds, _cb_ctx):
        ip = _source_ip(snmp_engine, state_reference)
        try:
            trap_oid, info = trap_fields(var_binds)
        except Exception as exc:
            log(f"trap decode {ip}: {exc}")
            return
        if not trap_oid:
            log(f"trap from {ip} had no trap OID")
            return
        try:
            on_trap(ip, trap_oid, info)
        except Exception as exc:
            log(f"trap store {ip}: {exc}")

    ntfrcv.NotificationReceiver(engine, callback)
    refresh_users(force=True)
    try:
        pending = getattr(transport, "_lport", None)
        if pending is not None:
            loop.run_until_complete(pending)
        if transport.transport is None:
            raise OSError("socket did not open")
    except Exception as exc:
        log(f"trap listener not bound ({exc}); polling remains source of truth")
        _close_listener(engine, transport, sock, loop)
        return
    log(f"trap listener on UDP {port} ({len(users)} SNMPv3 user{'s' if len(users) != 1 else ''})")
    try:
        engine.transport_dispatcher.job_started(1)
        engine.transport_dispatcher.run_dispatcher()
    finally:
        _close_listener(engine, transport, sock, loop)


def _close_listener(engine, transport, sock, loop) -> None:
    if engine is not None:
        try:
            engine.transport_dispatcher.close_dispatcher()
        except Exception:
            pass
    if transport is not None:
        try:
            transport.close_transport()
        except Exception:
            pass
    try:
        sock.close()
    except OSError:
        pass
    if loop is None or loop.is_closed():
        return
    try:
        pending = [task for task in asyncio.all_tasks(loop) if not task.done()]
        for task in pending:
            task.cancel()
        if pending and not loop.is_running():
            loop.run_until_complete(asyncio.gather(*pending, return_exceptions=True))
    except Exception:
        pass
    if not loop.is_closed():
        loop.close()


def _self_test() -> None:
    from pyasn1.codec.ber import encoder
    from pysnmp.proto.mpmod.rfc3412 import HeaderData, SNMPv3Message
    from pysnmp.proto.secmod.rfc3414.service import UsmSecurityParameters

    on_batt = classify("1.3.6.1.4.1.3808.0.5")
    assert on_batt and on_batt["code"] == "on_battery" and on_batt["clear"] is False
    restored = classify("1.3.6.1.4.1.3808.0.9")
    assert restored and restored["clear"] is True and "on_battery_long" in restored["extra"]
    assert classify("1.3.6.1.2.1.33.2.1")["code"] == "on_battery"
    assert classify("1.3.6.1.4.1.3808.0.18") is None
    assert classify("1.3.6.1.4.1.9.1.1") is None
    assert info_text("  power \x00failed  ") == "power failed"
    assert info_text("") == ""

    header = HeaderData()
    header.setComponentByPosition(0, 7)
    header.setComponentByPosition(1, 484)
    header.setComponentByPosition(2, b"\x03")
    header.setComponentByPosition(3, 3)
    params = UsmSecurityParameters()
    params.setComponentByPosition(0, b"\x80\x00\x1f\x88\x01")
    params.setComponentByPosition(1, 1)
    params.setComponentByPosition(2, 9)
    params.setComponentByPosition(3, b"labuser")
    params.setComponentByPosition(4, b"")
    params.setComponentByPosition(5, b"")
    msg = SNMPv3Message()
    msg.setComponentByPosition(0, 3)
    msg.setComponentByPosition(1, header)
    msg.setComponentByPosition(2, encoder.encode(params))
    from pysnmp.proto.mpmod.rfc3412 import ScopedPduData
    scoped = ScopedPduData()
    scoped.setComponentByPosition(1, b"")
    msg.setComponentByPosition(3, scoped)
    peeked = peek_usm(encoder.encode(msg))
    assert peeked == (b"\x80\x00\x1f\x88\x01", "labuser"), peeked
    assert peek_usm(b"\x30\x03\x02\x01\x00") is None
    assert peek_usm(b"") is None
    print("TRAPS_OK")


if __name__ == "__main__":
    _self_test()
