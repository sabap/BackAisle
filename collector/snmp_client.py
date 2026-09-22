from __future__ import annotations

import asyncio
import warnings
from typing import Any

warnings.filterwarnings("ignore", category=DeprecationWarning)

OIDS = {
    "sysName": (1, 3, 6, 1, 2, 1, 1, 5, 0),
    "ifPhysAddress": (1, 3, 6, 1, 2, 1, 2, 2, 1, 6, 1),
    "rfcRuntimeMin": (1, 3, 6, 1, 2, 1, 33, 1, 2, 3, 0),
    "model": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 1, 1, 0),
    "name": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 1, 2, 0),
    "firmware": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 2, 1, 0),
    "serial": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 2, 3, 0),
    "batteryStatus": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 2, 1, 1, 0),
    "timeOnBattery": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 2, 1, 2, 0),
    "capacity": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 2, 2, 1, 0),
    "runtimeTicks": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 2, 2, 4, 0),
    "replaceIndicator": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 2, 2, 5, 0),
    "inputVoltage": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 3, 2, 1, 0),
    "outputStatus": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 4, 1, 1, 0),
    "outputVoltage": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 4, 2, 1, 0),
    "outputLoad": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 4, 2, 3, 0),
    "outputPower": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 4, 2, 5, 0),
    "envirName": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 1, 1, 0),
    "tempF10": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 2, 1, 0),
    "humidity": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 3, 1, 0),
    # ENVIROSENSOR on RMCARD205 reports in environmentSensor2 tables (hardware 8)
    "envir2IdentSize": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 1, 1, 0),
    "envir2Name": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 1, 2, 1, 3, 1),
    "envir2TempUnit": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 2, 2, 0),
    "envir2Temp": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 2, 3, 1, 3, 1),
    "envir2Humid": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 3, 2, 1, 3, 1),
    "envir2Contact1": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 8, 4, 2, 1, 5, 1),
}

OUTPUT_STATUS = {
    1: "unknown", 2: "onLine", 3: "onBattery", 4: "onBoost", 5: "onSleep",
    6: "off", 7: "rebooting", 8: "onECO", 9: "onBypass", 10: "onBuck", 11: "onOverload",
}
BATTERY_STATUS = {1: "unknown", 2: "batteryNormal", 3: "batteryLow", 4: "batteryNotPresent"}


def _int(val) -> int | None:
    if val is None:
        return None
    name = val.__class__.__name__
    pretty = val.prettyPrint()
    if name in ("Null", "NoSuchObject", "NoSuchInstance", "EndOfMibView") or pretty in (
        "noSuchObject", "noSuchInstance", "endOfMibView", "",
    ):
        return None
    try:
        return int(pretty)
    except (TypeError, ValueError):
        return None


def _str(val) -> str | None:
    if val is None:
        return None
    name = val.__class__.__name__
    pretty = val.prettyPrint()
    if name in ("Null", "NoSuchObject", "NoSuchInstance") or pretty in (
        "noSuchObject", "noSuchInstance", "",
    ):
        return None
    if pretty.startswith("0x") and name == "OctetString":
        hx = pretty[2:]
        if len(hx) >= 12:
            parts = [hx[i:i + 2] for i in range(0, 12, 2)]
            return ":".join(parts)
    return pretty


def decode(raw: dict[str, Any]) -> dict[str, Any]:
    cap = _int(raw.get("capacity"))
    ticks = _int(raw.get("runtimeTicks"))
    rfc_min = _int(raw.get("rfcRuntimeMin"))
    runtime = float(rfc_min) if rfc_min is not None else (
        ticks / 100.0 / 60.0 if ticks is not None else None
    )
    temp_raw = _int(raw.get("tempF10"))
    # Legacy scalar: tenths of °F. Never display 784 as 784°F.
    temp_f = (temp_raw / 10.0) if temp_raw is not None else None
    env2_raw = _int(raw.get("envir2Temp"))
    env2_unit = _int(raw.get("envir2TempUnit"))  # 1=C 2=F
    if env2_raw is not None:
        # envir2Temperature is 1/100 of the unit in envir2TempUnit
        scaled = env2_raw / 100.0
        if env2_unit == 1:
            temp_f = scaled * 9.0 / 5.0 + 32.0
        else:
            temp_f = scaled
        temp_raw = env2_raw
        if temp_f is not None and (temp_f > 160 or temp_f < -20):
            temp_f = None
        elif temp_f is not None:
            temp_f = round(temp_f, 1)
    humid = _int(raw.get("humidity"))
    humidity_pct = float(humid) if humid is not None else None
    env2_h = _int(raw.get("envir2Humid"))
    if env2_h is not None:
        humidity_pct = round(env2_h / 100.0, 1)
        if humidity_pct > 100:
            humidity_pct = float(env2_h) if 0 <= env2_h <= 100 else None
    in_v = _int(raw.get("inputVoltage"))
    out_v = _int(raw.get("outputVoltage"))
    out_st = _int(raw.get("outputStatus"))
    envir_name = _str(raw.get("envir2Name")) or _str(raw.get("envirName"))
    env2_n = _int(raw.get("envir2IdentSize"))
    env_keys = (
        "envir2IdentSize", "envir2Name", "envirName", "envir2Temp", "envir2Humid",
        "tempF10", "humidity",
    )
    saw_env = any(k in raw for k in env_keys)
    legacy_temp = _int(raw.get("tempF10"))
    if env2_raw is not None or env2_h is not None or envir_name or (env2_n and env2_n > 0) or legacy_temp not in (None, 0):
        sensor_present = 1
    elif not saw_env:
        # This poll did not get an answer from the probe. Do not record "absent".
        sensor_present = None
    elif env2_n == 0 and env2_raw is None and env2_h is None and not envir_name:
        sensor_present = 0
    else:
        # Blank varbinds from a noSuchName group are not a real "no sensor" answer.
        sensor_present = None
    return {
        "model": _str(raw.get("model")),
        "snmp_name": _str(raw.get("name")) or _str(raw.get("sysName")),
        "firmware": _str(raw.get("firmware")),
        "serial": _str(raw.get("serial")),
        "mac": _str(raw.get("ifPhysAddress")),
        "output_status": out_st,
        "output_status_text": OUTPUT_STATUS.get(out_st or 0, "unknown"),
        "battery_status": _int(raw.get("batteryStatus")),
        "capacity_pct": float(cap) if cap is not None else None,
        "runtime_min": runtime,
        "runtime_ticks_raw": ticks,
        "load_pct": float(_int(raw.get("outputLoad"))) if _int(raw.get("outputLoad")) is not None else None,
        "input_voltage": (in_v / 10.0) if in_v is not None else None,
        "output_voltage": (out_v / 10.0) if out_v is not None else None,
        "temp_f": temp_f,
        "temp_raw": temp_raw,
        "temp_unit": env2_unit,
        "humidity_pct": humidity_pct,
        "power_w": float(_int(raw.get("outputPower"))) if _int(raw.get("outputPower")) is not None else None,
        "on_battery": 1 if out_st == 3 else 0,
        "sensor_present": sensor_present,
        "envir_name": envir_name,
        "contact1_status": _int(raw.get("envir2Contact1")),
        "replace_battery": 1 if _int(raw.get("replaceIndicator")) == 2 else 0,
        "time_on_battery_ticks": _int(raw.get("timeOnBattery")),
    }


# Status poll stays small so a dead card frees the worker in ~2s.
STATUS_KEYS = (
    "capacity", "runtimeTicks", "rfcRuntimeMin", "batteryStatus",
    "outputStatus", "outputLoad", "inputVoltage", "outputVoltage",
)
IDENTITY_KEYS = (
    "sysName", "ifPhysAddress", "model", "name", "firmware", "serial",
    "timeOnBattery", "replaceIndicator", "outputPower",
)
# Tried on every poll, but a timeout here must not fail the UPS reading.
# The reading, by itself. Optional name/contact OIDs are not in this set: a
# noSuchName or a timeout on those used to blank the temperature for the whole card.
ENV_READING_KEYS = (
    "envir2TempUnit", "envir2Temp", "envir2Humid",
)
ENV_EXTRA_KEYS = (
    "envir2IdentSize", "envir2Name",
    "envirName", "tempF10", "humidity", "envir2Contact1",
)


def new_engine():
    from pysnmp.hlapi.v3arch.asyncio import SnmpEngine
    return SnmpEngine()


def close_engine(engine) -> None:
    if engine is None:
        return
    try:
        engine.close_dispatcher()
    except Exception:
        pass


def _snmp_err(err_stat) -> bool:
    """noSuchName aborts a multi-get at the first OID that card does not have.

    CyberPower does this instead of returning noSuchObject for the rest of the
    request, so a later temperature OID is never delivered. That is not success.
    """
    if not err_stat:
        return False
    text = str(err_stat).strip().lower()
    return text not in ("0", "noerror")


async def _snmp_one(engine, creds, target, ctx, name: str, raw: dict[str, Any]) -> None:
    from pysnmp.hlapi.v3arch.asyncio import ObjectType, ObjectIdentity, get_cmd
    try:
        e1, e2, _e3, one = await get_cmd(
            engine, creds, target, ctx, ObjectType(ObjectIdentity(OIDS[name]))
        )
    except Exception:
        return
    if e1 or _snmp_err(e2) or not one:
        return
    raw[name] = one[0][1]


async def _snmp_batches(
    engine, creds, target, ctx, keys: tuple[str, ...], raw: dict[str, Any], *, fallback_singles: bool = False,
) -> None:
    from pysnmp.hlapi.v3arch.asyncio import ObjectType, ObjectIdentity, get_cmd
    names = list(keys)
    objs = [ObjectType(ObjectIdentity(OIDS[n])) for n in names]
    for i in range(0, len(objs), 4):
        batch_names = names[i:i + 4]
        batch_objs = objs[i:i + 4]
        try:
            err_ind, err_stat, err_idx, var_binds = await get_cmd(
                engine, creds, target, ctx, *batch_objs
            )
        except Exception:
            err_ind, err_stat, var_binds = "timeout", None, None
        if err_ind or _snmp_err(err_stat):
            if not fallback_singles:
                raise RuntimeError(str(err_ind or err_stat))
            # A timed-out or noSuchName group read must not skip temperature.
            for name in batch_names:
                if name not in raw:
                    await _snmp_one(engine, creds, target, ctx, name, raw)
            continue
        if var_binds:
            for name, vb in zip(batch_names, var_binds):
                raw[name] = vb[1]


async def snmp_get(
    host: str,
    user: str,
    auth: str,
    priv: str,
    timeout: float = 1.0,
    retries: int = 0,
    climate: bool = True,
    engine=None,
) -> dict[str, Any]:
    from pysnmp.hlapi.v3arch.asyncio import (
        UsmUserData, UdpTransportTarget, ContextData,
        usmHMACSHAAuthProtocol, usmAesCfb128Protocol,
    )
    own = engine is None
    if own:
        engine = new_engine()
    try:
        creds = UsmUserData(
            user, auth, priv,
            authProtocol=usmHMACSHAAuthProtocol,
            privProtocol=usmAesCfb128Protocol,
        )
        target = await UdpTransportTarget.create((host, 161), timeout=timeout, retries=retries)
        # One retry on the group doubles the wait before we ever ask for temperature alone.
        env_timeout = 5.0 if climate else 3.0
        env_target = await UdpTransportTarget.create((host, 161), timeout=env_timeout, retries=0)
        ctx = ContextData()
        raw: dict[str, Any] = {}
        await _snmp_batches(engine, creds, target, ctx, STATUS_KEYS, raw)
        try:
            await _snmp_batches(
                engine, creds, env_target, ctx, ENV_READING_KEYS, raw, fallback_singles=True,
            )
        except Exception:
            for name in ENV_READING_KEYS:
                if name not in raw:
                    await _snmp_one(engine, creds, env_target, ctx, name, raw)
        if climate:
            try:
                await _snmp_batches(engine, creds, env_target, ctx, ENV_EXTRA_KEYS, raw)
            except Exception:
                pass
            try:
                await _snmp_batches(engine, creds, target, ctx, IDENTITY_KEYS, raw)
            except Exception:
                pass
        if not raw:
            raise RuntimeError("empty SNMP GET")
        return decode(raw)
    finally:
        if own:
            close_engine(engine)


SET_OIDS = {
    "sysName": (1, 3, 6, 1, 2, 1, 1, 5, 0),
    "sysLocation": (1, 3, 6, 1, 2, 1, 1, 6, 0),
    "upsName": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 1, 2, 0),
    "envirLocation": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 1, 2, 0),
    "envirTempHigh": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 2, 2, 0),
    "envirTempLow": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 2, 3, 0),
    "envirHumidHigh": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 3, 2, 0),
    "envirHumidLow": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 4, 3, 3, 0),
    "agentFirmware": (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 2, 4, 0),
}


async def snmp_get_one(host: str, user: str, auth: str, priv: str, oid: tuple[int, ...], timeout: float = 4.0):
    from pysnmp.hlapi.v3arch.asyncio import (
        SnmpEngine, UsmUserData, UdpTransportTarget, ContextData,
        ObjectType, ObjectIdentity, get_cmd, usmHMACSHAAuthProtocol, usmAesCfb128Protocol,
    )
    engine = SnmpEngine()
    creds = UsmUserData(user, auth, priv, authProtocol=usmHMACSHAAuthProtocol, privProtocol=usmAesCfb128Protocol)
    target = await UdpTransportTarget.create((host, 161), timeout=timeout, retries=1)
    err_ind, err_stat, err_idx, var_binds = await get_cmd(
        engine, creds, target, ContextData(), ObjectType(ObjectIdentity(oid))
    )
    if err_ind:
        raise RuntimeError(str(err_ind))
    if err_stat:
        raise RuntimeError(f"{err_stat} idx={err_idx}")
    val = var_binds[0][1]
    pretty = val.prettyPrint()
    if val.__class__.__name__ in ("NoSuchObject", "NoSuchInstance", "Null"):
        return None
    return pretty


async def snmp_set(host: str, user: str, auth: str, priv: str, name: str, value, timeout: float = 4.0) -> None:
    from pysnmp.hlapi.v3arch.asyncio import (
        SnmpEngine, UsmUserData, UdpTransportTarget, ContextData,
        ObjectType, ObjectIdentity, set_cmd, usmHMACSHAAuthProtocol, usmAesCfb128Protocol,
    )
    from pysnmp.proto.rfc1902 import OctetString, Integer
    if name not in SET_OIDS:
        raise RuntimeError(f"OID {name} is not on the write allow-list")
    oid = SET_OIDS[name]
    if isinstance(value, (int, float)) and name.startswith("envir"):
        asn = Integer(int(value))
    else:
        asn = OctetString(str(value))
    engine = SnmpEngine()
    creds = UsmUserData(user, auth, priv, authProtocol=usmHMACSHAAuthProtocol, privProtocol=usmAesCfb128Protocol)
    target = await UdpTransportTarget.create((host, 161), timeout=timeout, retries=1)
    err_ind, err_stat, err_idx, var_binds = await set_cmd(
        engine, creds, target, ContextData(), ObjectType(ObjectIdentity(oid), asn)
    )
    if err_ind:
        raise RuntimeError(str(err_ind))
    if err_stat:
        raise RuntimeError(f"SNMP SET {name} failed: {err_stat} idx={err_idx}")

