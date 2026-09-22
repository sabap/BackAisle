"""BackAisle SNMPv3 collector. Polling is source of truth. Traps are a fast path."""
from __future__ import annotations

import argparse
import asyncio
import json
import random
import smtplib
import socket
import sys
import threading
import time
import traceback
from datetime import datetime, timezone
from email.message import EmailMessage
from pathlib import Path

ROOT = Path(r"C:\inetpub\BackAisle")
sys.path.insert(0, str(Path(__file__).resolve().parent))

from db import connect, init_db, is_sqlsrv  # noqa: E402
from secrets import load_secrets  # noqa: E402
from profiles import get_secret, seed_from_env  # noqa: E402

snmp_get = None  # set after SQL connect; pysnmp/cryptography can break msodbcsql TLS
new_engine = None
close_engine = None

LOG = ROOT / "logs" / "collector.log"
PID = ROOT / "logs" / "collector.pid"
HEARTBEAT = ROOT / "logs" / "collector.heartbeat.json"
STATUS_INTERVAL = 60
CLIMATE_INTERVAL = 300
TRAP_PORT = 162
# A full enviro fallback is a few seconds. 24 workers keep a 60s cycle for ~150 UPS
# as long as a typical card finishes in under ~8s. Dead cards still free a slot at SNMP_TIMEOUT_S.
POLL_TIMEOUT_S = 12.0
CLIMATE_TIMEOUT_S = 20.0
SNMP_TIMEOUT_S = 1.0
WORKER_MIN = 8
WORKER_MAX = 24


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def insert_pending_alert(con, device_id, code, ts) -> None:
    if is_sqlsrv():
        try:
            con.execute(
                "INSERT INTO pending_alerts (device_id, code, first_seen) VALUES (?,?,?)",
                (device_id, code, ts),
            )
        except Exception:
            pass
        return
    con.execute(
        "INSERT OR IGNORE INTO pending_alerts (device_id, code, first_seen) VALUES (?,?,?)",
        (device_id, code, ts),
    )


def _db_int(v, default: int = 0) -> int:
    """SQL Server PDO often returns numbers as strings. '0' must not be truthy."""
    if v is None or v is False:
        return default
    if isinstance(v, bool):
        return int(v)
    try:
        return int(v)
    except (TypeError, ValueError):
        return default


def _device_row(r) -> dict:
    d = dict(r)
    for key in (
        "id", "is_simulated", "enabled", "sensor_expected", "sensor_present",
        "snmp_profile_id", "group_id", "consecutive_failures", "kind",
    ):
        if key == "kind":
            continue
        if key in d and d[key] is not None and d[key] != "":
            d[key] = _db_int(d[key], 0 if key != "id" else 0)
    if "va_rating" in d and d["va_rating"] not in (None, ""):
        try:
            d["va_rating"] = float(d["va_rating"])
        except (TypeError, ValueError):
            pass
    return d


def upsert_poll_state_ok(con, device_id, ts) -> None:
    device_id = _db_int(device_id)
    if is_sqlsrv():
        con.execute(
            "UPDATE poll_state SET last_success=?, last_attempt=?, consecutive_failures=0, last_error=NULL, comm_state='ok' WHERE device_id=?",
            (ts, ts, device_id),
        )
        try:
            con.execute(
                "INSERT INTO poll_state (device_id, last_success, last_attempt, consecutive_failures, last_error, comm_state) VALUES (?,?,?,0,NULL,'ok')",
                (device_id, ts, ts),
            )
        except Exception:
            pass
        return
    con.execute(
        """INSERT INTO poll_state (device_id, last_success, last_attempt, consecutive_failures, last_error, comm_state)
           VALUES (?,?,?,0,NULL,'ok')
           ON CONFLICT(device_id) DO UPDATE SET last_success=excluded.last_success,
             last_attempt=excluded.last_attempt, consecutive_failures=0, last_error=NULL, comm_state='ok'""",
        (device_id, ts, ts),
    )


def upsert_poll_state_fail(con, device_id, ts, n, err, comm) -> None:
    if is_sqlsrv():
        con.execute(
            "UPDATE poll_state SET last_attempt=?, consecutive_failures=?, last_error=?, comm_state=? WHERE device_id=?",
            (ts, n, err, comm, device_id),
        )
        try:
            con.execute(
                "INSERT INTO poll_state (device_id, last_attempt, consecutive_failures, last_error, comm_state) VALUES (?,?,?,?,?)",
                (device_id, ts, n, err, comm),
            )
        except Exception:
            pass
        return
    con.execute(
        """INSERT INTO poll_state (device_id, last_attempt, consecutive_failures, last_error, comm_state)
           VALUES (?,?,?,?,?)
           ON CONFLICT(device_id) DO UPDATE SET last_attempt=excluded.last_attempt,
             consecutive_failures=excluded.consecutive_failures, last_error=excluded.last_error, comm_state=excluded.comm_state""",
        (device_id, ts, n, err, comm),
    )


def log(msg: str) -> None:
    LOG.parent.mkdir(parents=True, exist_ok=True)
    line = f"{now()}Z {msg}"
    try:
        print(line, flush=True)
    except OSError:
        pass
    with LOG.open("a", encoding="utf-8") as f:
        f.write(line + "\n")


def thresholds_for(con, device: dict) -> dict:
    rows = con.execute(
        """SELECT * FROM thresholds
           WHERE scope='global'
              OR (scope='site' AND site=?)
              OR (scope='closet' AND site=? AND idf_closet=?)
              OR (scope='device' AND device_id=?)
           ORDER BY CASE scope WHEN 'device' THEN 4 WHEN 'closet' THEN 3 WHEN 'site' THEN 2 ELSE 1 END""",
        (device["site"], device["site"], device["idf_closet"], device["id"]),
    ).fetchall()
    out = dict(rows[0]) if rows else {}
    for r in rows:
        out.update({k: r[k] for k in r.keys()})
    return out


def emit_event(con, device_id, severity, code, message, details=None):
    con.execute(
        "INSERT INTO events (device_id, ts, severity, code, message, details) VALUES (?,?,?,?,?,?)",
        (device_id, now(), severity, code, message, details),
    )


def set_alert(con, device_id, code, severity, message) -> bool:
    open_row = con.execute(
        "SELECT id FROM alerts WHERE device_id=? AND code=? AND status='open'",
        (device_id, code),
    ).fetchone()
    if open_row:
        con.execute("UPDATE alerts SET message=? WHERE id=?", (message, open_row["id"]))
        return False
    con.execute(
        """INSERT INTO alerts (device_id, code, severity, status, message, opened_at)
           VALUES (?,?,?,'open',?,?)""",
        (device_id, code, severity, message, now()),
    )
    emit_event(con, device_id, severity, code, message)
    return True


def clear_alert(con, device_id, code):
    open_row = con.execute(
        "SELECT id FROM alerts WHERE device_id=? AND code=? AND status='open'",
        (device_id, code),
    ).fetchone()
    if not open_row:
        return
    con.execute(
        "UPDATE alerts SET status='cleared', cleared_at=? WHERE id=?",
        (now(), open_row["id"]),
    )
    con.execute("DELETE FROM pending_alerts WHERE device_id=? AND code=?", (device_id, code))
    emit_event(con, device_id, "info", code + ".cleared", f"{code} cleared")


def maybe_mail(secrets: dict, subject: str, body: str) -> None:
    host = secrets.get("SMTP_HOST") or ""
    to = secrets.get("SMTP_TO") or ""
    if not host or not to:
        return
    try:
        msg = EmailMessage()
        msg["From"] = secrets.get("SMTP_FROM") or "backaisle@localhost"
        msg["To"] = to
        msg["Subject"] = subject
        msg.set_content(body)
        with smtplib.SMTP(host, int(secrets.get("SMTP_PORT") or 25), timeout=10) as s:
            user, pw = secrets.get("SMTP_USER"), secrets.get("SMTP_PASS")
            if user:
                s.starttls()
                s.login(user, pw or "")
            s.send_message(msg)
    except Exception as e:
        log(f"smtp failed: {e}")


def evaluate(con, device, sample, secrets):
    th = thresholds_for(con, device)
    did = device["id"]
    new_alerts = []

    def fire(code, sev, msg, cond):
        if cond:
            if set_alert(con, did, code, sev, msg):
                new_alerts.append((code, msg))
        else:
            clear_alert(con, did, code)

    on_batt = sample.get("on_battery") == 1
    ticks = sample.get("time_on_battery_ticks") or 0
    on_batt_min = (ticks / 100.0 / 60.0) if ticks else (5 if on_batt else 0)
    fire("on_battery", "crit", f"On battery ({sample.get('runtime_min')} min remaining)", on_batt)
    fire(
        "on_battery_long",
        "crit",
        f"On battery longer than {th.get('on_battery_minutes')} min",
        on_batt and on_batt_min >= float(th.get("on_battery_minutes") or 5),
    )
    cap = sample.get("capacity_pct")
    cap_low = th.get("capacity_low")
    fire(
        "capacity_low",
        "crit",
        f"Capacity {cap}% below {cap_low}%" if cap is not None else "Capacity low",
        cap is not None and cap < float(cap_low),
    )
    rt = sample.get("runtime_min")
    rt_low = th.get("runtime_low_min")
    fire(
        "runtime_low",
        "warn",
        f"Runtime {rt:.0f} min below {rt_low}" if rt is not None else "Runtime low",
        rt is not None and rt < float(rt_low),
    )
    fire("replace_battery", "warn", "Battery replace indicator", sample.get("replace_battery") == 1)
    temp = sample.get("temp_f")
    fire("temp_high", "warn", f"Closet {temp}°F above {th.get('temp_high_f')}", temp is not None and temp > float(th["temp_high_f"]))
    fire("temp_low", "warn", f"Closet {temp}°F below {th.get('temp_low_f')}", temp is not None and temp < float(th["temp_low_f"]))
    hum = sample.get("humidity_pct")
    fire("humidity_high", "warn", f"Humidity {hum}% above {th.get('humidity_high')}", hum is not None and hum > float(th["humidity_high"]))
    fire("humidity_low", "warn", f"Humidity {hum}% below {th.get('humidity_low')}", hum is not None and hum < float(th["humidity_low"]))
    expected = device["sensor_expected"]
    present = sample.get("sensor_present")
    # None means this poll did not read the probe. Do not call that "absent".
    fire("sensor_missing", "warn", "Sensor expected but not attached", expected and present == 0)
    fire("poll_fail", "crit", "unreachable", False)
    for code, msg in new_alerts:
        insert_pending_alert(con, did, code, now())


def _carry_climate(con, device_id: int, sample: dict) -> dict:
    """A later UPS poll that missed the sensor must not erase the last real temp/humidity."""
    if sample.get("sensor_present") is not None or sample.get("temp_f") is not None:
        return sample
    prev = con.execute(
        """SELECT temp_f, humidity_pct, sensor_present FROM samples
           WHERE device_id=? AND temp_f IS NOT NULL ORDER BY ts DESC LIMIT 1""",
        (device_id,),
    ).fetchone()
    if not prev:
        return sample
    sample = dict(sample)
    sample["temp_f"] = prev["temp_f"] if isinstance(prev, dict) else prev[0]
    sample["humidity_pct"] = prev["humidity_pct"] if isinstance(prev, dict) else prev[1]
    carried = prev["sensor_present"] if isinstance(prev, dict) else prev[2]
    if carried is not None:
        sample["sensor_present"] = carried
    return sample


def store_sample(con, device_id, sample, va_rating=2000):
    device_id = _db_int(device_id)
    ts = now()
    sample = _carry_climate(con, device_id, sample)
    # Record the success even if the sample row insert fails (missing column, etc.).
    upsert_poll_state_ok(con, device_id, ts)
    load = sample.get("load_pct")
    power = sample.get("power_w")
    if power is None and load is not None:
        power = float(load) / 100.0 * float(va_rating or 2000)
    row = (
        device_id, ts, sample.get("output_status"), sample.get("battery_status"),
        sample.get("capacity_pct"), sample.get("runtime_min"), sample.get("load_pct"),
        sample.get("input_voltage"), sample.get("output_voltage"), sample.get("temp_f"),
        sample.get("humidity_pct"), sample.get("on_battery"), sample.get("sensor_present"),
        sample.get("replace_battery"), power,
    )
    try:
        con.execute(
            """INSERT INTO samples (device_id, ts, output_status, battery_status, capacity_pct, runtime_min,
               load_pct, input_voltage, output_voltage, temp_f, humidity_pct, on_battery, sensor_present, replace_battery, power_w)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            row,
        )
    except Exception as e:
        if "power_w" not in str(e).lower() and "invalid column" not in str(e).lower():
            log(f"sample insert device {device_id}: {e}")
        else:
            con.execute(
                """INSERT INTO samples (device_id, ts, output_status, battery_status, capacity_pct, runtime_min,
                   load_pct, input_voltage, output_voltage, temp_f, humidity_pct, on_battery, sensor_present, replace_battery)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                row[:-1],
            )
    # identity autofill
    fields = []
    args = []
    for col, key in (("model", "model"), ("serial", "serial"), ("firmware", "firmware"), ("snmp_name", "snmp_name"), ("mac", "mac")):
        if sample.get(key):
            fields.append(f"{col}=?")
            args.append(sample[key])
    if sample.get("sensor_present") is not None:
        fields.append("sensor_present=?")
        args.append(sample["sensor_present"])
    if fields:
        args.append(device_id)
        con.execute(f"UPDATE devices SET {', '.join(fields)}, updated_at=? WHERE id=?", [*args[:-1], now(), device_id])


def mark_fail(con, device, err, secrets):
    did = _db_int(device["id"])
    st = con.execute("SELECT consecutive_failures FROM poll_state WHERE device_id=?", (did,)).fetchone()
    prev = 0
    if st:
        try:
            prev = _db_int(st["consecutive_failures"] if isinstance(st, dict) else list(st)[0])
        except Exception:
            prev = 0
    n = prev + 1
    upsert_poll_state_fail(con, did, now(), n, str(err)[:500], "down" if n >= 3 else "degraded")
    th = thresholds_for(con, device)
    if n >= int(th.get("poll_fail_count") or 3):
        if set_alert(con, device["id"], "poll_fail", "crit", f"Unreachable: {err}"):
            insert_pending_alert(con, device["id"], "poll_fail", now())
    emit_event(con, device["id"], "warn", "poll_timeout", str(err)[:200])


def simulate(device: dict) -> dict:
    rng = random.Random(device["id"] * 1000 + int(time.time() // 60))
    on_batt = device["id"] % 37 == 0
    no_sensor = device["id"] % 11 == 0
    hot = device["id"] % 29 == 0
    cap = 22 if device["id"] % 41 == 0 else rng.randint(78, 100)
    return {
        "model": device.get("model") or "PR2000RT2UC",
        "snmp_name": device.get("snmp_name") or device.get("hostname"),
        "firmware": "1.1746",
        "serial": device.get("serial"),
        "mac": device.get("mac"),
        "output_status": 3 if on_batt else 2,
        "battery_status": 3 if cap < 30 else 2,
        "capacity_pct": float(cap),
        "runtime_min": 8.0 if cap < 30 else (18.0 if on_batt else float(rng.randint(90, 180))),
        "load_pct": float(rng.randint(8, 35)),
        "input_voltage": 0.0 if on_batt else 118.0 + rng.random(),
        "output_voltage": 118.0 + rng.random(),
        "temp_f": None if no_sensor else (91.0 if hot else 72.0 + rng.random() * 6),
        "humidity_pct": None if no_sensor else 40.0 + rng.random() * 10,
        "on_battery": 1 if on_batt else 0,
        "sensor_present": 0 if no_sensor else 1,
        "replace_battery": 1 if device["id"] % 43 == 0 else 0,
        "time_on_battery_ticks": 60000 if on_batt else 0,
        "power_w": float(rng.randint(8, 35)) / 100.0 * float(device.get("va_rating") or 2000),
    }


async def poll_live(device, secrets, climate=False, engine=None):
    cred = get_secret(device.get("snmp_profile_id"))
    user = device.get("snmp_username") or cred["user"] or secrets["SNMPV3_USER"]
    budget = CLIMATE_TIMEOUT_S if climate else POLL_TIMEOUT_S
    return await asyncio.wait_for(
        snmp_get(
            device["ip"],
            user,
            cred["auth_pass"],
            cred["priv_pass"],
            timeout=SNMP_TIMEOUT_S,
            retries=0,
            climate=climate,
            engine=engine,
        ),
        timeout=budget,
    )


def _apply_result(con, secrets, kind: str, device: dict, payload) -> None:
    try:
        if kind == "err":
            mark_fail(con, device, payload, secrets)
        else:
            store_sample(con, device["id"], payload, device.get("va_rating") or 2000)
            evaluate(con, device, payload, secrets)
    except Exception as e:
        log(f"store fail device {device['id']}: {e}")


def apply_result(secrets, kind: str, device: dict, payload) -> None:
    con = connect()
    try:
        _apply_result(con, secrets, kind, device, payload)
        con.commit()
    finally:
        con.close()


def _housekeep(con, secrets) -> None:
    con.execute("DELETE FROM samples WHERE ts < datetime('now','-90 days')")
    if is_sqlsrv():
        con.execute("DELETE FROM samples_hourly WHERE hour_ts >= DATEADD(hour, -3, SYSUTCDATETIME())")
        con.execute(
            """INSERT INTO samples_hourly (device_id, hour_ts, capacity_avg, runtime_avg, load_avg, input_voltage_avg, temp_f_avg, humidity_avg, power_avg)
               SELECT device_id, CONVERT(varchar(13), ts, 120) + ':00:00',
                      AVG(capacity_pct), AVG(runtime_min), AVG(load_pct), AVG(input_voltage), AVG(temp_f), AVG(humidity_pct), AVG(power_w)
               FROM samples WHERE ts >= DATEADD(hour, -2, SYSUTCDATETIME())
               GROUP BY device_id, CONVERT(varchar(13), ts, 120) + ':00:00'"""
        )
        con.execute("DELETE FROM samples_hourly WHERE hour_ts < DATEADD(day, -370, SYSUTCDATETIME())")
    else:
        con.execute(
            """INSERT OR REPLACE INTO samples_hourly (device_id, hour_ts, capacity_avg, runtime_avg, load_avg, input_voltage_avg, temp_f_avg, humidity_avg, power_avg)
               SELECT device_id, strftime('%Y-%m-%d %H:00:00', ts),
                      AVG(capacity_pct), AVG(runtime_min), AVG(load_pct), AVG(input_voltage), AVG(temp_f), AVG(humidity_pct), AVG(power_w)
               FROM samples WHERE ts >= datetime('now','-2 hours')
               GROUP BY device_id, strftime('%Y-%m-%d %H:00:00', ts)"""
        )
        con.execute("DELETE FROM samples_hourly WHERE hour_ts < datetime('now','-370 days')")
    process_group_alerts(con, secrets)
    con.commit()


def write_heartbeat(extra: dict | None = None) -> None:
    payload = {
        "ts": int(time.time()),
        "at": now(),
        "pid": os_getpid(),
    }
    if extra:
        payload.update(extra)
    try:
        HEARTBEAT.parent.mkdir(parents=True, exist_ok=True)
        HEARTBEAT.write_text(json.dumps(payload), encoding="utf-8")
    except OSError:
        pass


def load_devices_by_ids(ids: list[int]) -> list[dict]:
    ids = [int(i) for i in ids if int(i) > 0]
    if not ids:
        return []
    con = connect()
    try:
        q = ",".join("?" * len(ids))
        rows = [_device_row(r) for r in con.execute(
            f"""SELECT d.*, sp.username AS snmp_username, ps.comm_state
               FROM devices d
               LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile_id
               LEFT JOIN poll_state ps ON ps.device_id=d.id
               WHERE d.id IN ({q})""",
            ids,
        ).fetchall()]
    finally:
        con.close()
    return rows


def load_enabled_devices() -> list[dict]:
    con = connect()
    try:
        rows = [_device_row(r) for r in con.execute(
            """SELECT d.*, sp.username AS snmp_username, ps.comm_state
               FROM devices d
               LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile_id
               LEFT JOIN poll_state ps ON ps.device_id=d.id
               WHERE d.enabled=1 AND IFNULL(d.kind,'ups')='ups'"""
        ).fetchall()]
    finally:
        con.close()
    return rows


def _worker_count(n_live: int) -> int:
    if n_live <= 0:
        return WORKER_MIN
    return min(WORKER_MAX, max(WORKER_MIN, n_live))


def _comm_rank(device: dict) -> int:
    state = (device.get("comm_state") or "unknown").lower()
    if state == "ok":
        return 0
    if state == "degraded":
        return 1
    if state == "unknown":
        return 2
    return 3


def _poll_sims(secrets, sims, last_done: dict[int, float], t0: float, force: bool = False) -> None:
    pending = []
    for d in sims:
        if not force and t0 - last_done.get(d["id"], 0.0) < STATUS_INTERVAL:
            continue
        last_done[d["id"]] = t0
        pending.append(d)
    if not pending:
        return
    con = connect()
    try:
        for d in pending:
            try:
                if d["id"] % 23 == 0:
                    raise TimeoutError("simulated timeout")
                _apply_result(con, secrets, "ok", d, simulate(d))
            except Exception as e:
                _apply_result(con, secrets, "err", d, e)
        con.commit()
    finally:
        con.close()


def _housekeep_unlocked(secrets) -> None:
    con = connect()
    try:
        _housekeep(con, secrets)
    finally:
        con.close()


class WorkerPool:
    """Persistent SNMP workers. A slow/dead card occupies one slot until its timeout, then the worker takes the next device."""

    def __init__(self, secrets: dict):
        self.secrets = secrets
        self.queue: asyncio.Queue = asyncio.Queue()
        self.in_flight: set[int] = set()
        self.last_done: dict[int, float] = {}
        self.last_climate: dict[int, float] = {}
        self.workers: list[asyncio.Task] = []
        self.ok = 0
        self.fail = 0
        self.timeouts = 0

    async def start(self, n: int) -> None:
        self.workers = [t for t in self.workers if not t.done()]
        while len(self.workers) < n:
            wid = len(self.workers)
            self.workers.append(asyncio.create_task(self._worker(wid), name=f"snmp-w{wid}"))
        extra = self.workers[n:]
        self.workers = self.workers[:n]
        for task in extra:
            task.cancel()

    async def stop(self) -> None:
        for task in self.workers:
            task.cancel()
        if self.workers:
            await asyncio.gather(*self.workers, return_exceptions=True)
        self.workers = []

    def enqueue(self, device: dict, climate: bool) -> bool:
        did = device["id"]
        if did in self.in_flight:
            return False
        self.in_flight.add(did)
        self.queue.put_nowait((device, climate))
        return True

    async def join(self) -> None:
        await self.queue.join()

    async def _worker(self, wid: int) -> None:
        engine = None
        try:
            engine = new_engine()
            while True:
                device, climate = await self.queue.get()
                t0 = time.time()
                try:
                    sample = await poll_live(device, self.secrets, climate=climate, engine=engine)
                    apply_result(self.secrets, "ok", device, sample)
                    self.ok += 1
                except asyncio.CancelledError:
                    raise
                except Exception as e:
                    apply_result(self.secrets, "err", device, e)
                    self.fail += 1
                    err = str(e).lower()
                    if "timeout" in err or "timed out" in err:
                        self.timeouts += 1
                    close_engine(engine)
                    engine = new_engine()
                finally:
                    self.in_flight.discard(device["id"])
                    self.last_done[device["id"]] = time.time()
                    if climate:
                        self.last_climate[device["id"]] = t0
                    self.queue.task_done()
        except asyncio.CancelledError:
            return
        except Exception:
            log(f"worker {wid} died\n" + traceback.format_exc())
        finally:
            close_engine(engine)


def _due_live(live: list[dict], pool: WorkerPool, t0: float, force: bool = False) -> list[tuple[dict, bool]]:
    due = []
    for d in live:
        if d["id"] in pool.in_flight:
            continue
        if not force and t0 - pool.last_done.get(d["id"], 0.0) < STATUS_INTERVAL:
            continue
        climate = force or (t0 - pool.last_climate.get(d["id"], 0.0) >= CLIMATE_INTERVAL)
        due.append((d, climate))
    due.sort(key=lambda item: (_comm_rank(item[0]), item[0]["id"]))
    return due


async def poll_cycle(secrets, climate=False, only_ids: list[int] | None = None):
    devices = load_devices_by_ids(only_ids) if only_ids else load_enabled_devices()
    live = [d for d in devices if not d["is_simulated"]]
    sims = [d for d in devices if d["is_simulated"]]
    n_w = _worker_count(len(live))
    pool = WorkerPool(secrets)
    await pool.start(n_w)
    t0 = time.time()
    _poll_sims(secrets, sims, pool.last_done, t0, force=True)
    for d, want_climate in _due_live(live, pool, t0, force=True):
        pool.enqueue(d, climate or want_climate)
    queued = pool.queue.qsize()
    budget = max(45.0, (len(live) / max(n_w, 1)) * CLIMATE_TIMEOUT_S + 20.0)
    try:
        await asyncio.wait_for(pool.join(), timeout=budget)
    except asyncio.TimeoutError:
        log(f"poll cycle join timeout after {budget:.0f}s in_flight={len(pool.in_flight)}")
    await pool.stop()
    try:
        _housekeep_unlocked(secrets)
    except Exception as e:
        log(f"housekeep: {e}")
    elapsed = time.time() - t0
    log(
        f"poll cycle devices={len(devices)} live={len(live)} workers={n_w} "
        f"ok={pool.ok} fail={pool.fail} timeout={pool.timeouts} queued={queued} "
        f"climate={climate} {elapsed:.1f}s"
    )


async def daemon(secrets):
    pool = WorkerPool(secrets)
    last_housekeep = 0.0
    last_log = 0.0
    last_n = 0
    log("snmp worker pool ready")
    while True:
        t0 = time.time()
        try:
            devices = load_enabled_devices()
            live = [d for d in devices if not d["is_simulated"]]
            sims = [d for d in devices if d["is_simulated"]]
            n_w = _worker_count(len(live))
            await pool.start(n_w)
            if n_w != last_n:
                log(f"workers={n_w} (min={WORKER_MIN} max={WORKER_MAX}) live={len(live)} sim={len(sims)}")
                last_n = n_w
            _poll_sims(secrets, sims, pool.last_done, t0)
            queued = 0
            for d, climate in _due_live(live, pool, t0):
                if pool.enqueue(d, climate):
                    queued += 1
            if t0 - last_housekeep >= 30:
                await asyncio.to_thread(_housekeep_unlocked, secrets)
                last_housekeep = t0
            if t0 - last_log >= 30:
                log(
                    f"workers={n_w} in_flight={len(pool.in_flight)} queue={pool.queue.qsize()} "
                    f"queued={queued} live={len(live)} sim={len(sims)} "
                    f"ok={pool.ok} fail={pool.fail} timeout={pool.timeouts}"
                )
                last_log = t0
            write_heartbeat({
                "ok": pool.ok,
                "fail": pool.fail,
                "timeout": pool.timeouts,
                "in_flight": len(pool.in_flight),
                "queue": pool.queue.qsize(),
                "live": len(live),
                "sim": len(sims),
            })
        except Exception:
            log("poll tick error\n" + traceback.format_exc())
        await asyncio.sleep(0.25)


def _group_descendants(con, gid: int) -> list[int]:
    ids = [gid]
    stack = [gid]
    while stack:
        cur = stack.pop()
        for r in con.execute("SELECT id FROM groups WHERE parent_id=?", (cur,)):
            ids.append(r["id"])
            stack.append(r["id"])
    return ids


def process_group_alerts(con, secrets) -> None:
    hold_row = con.execute("SELECT v FROM settings WHERE k='alert_hold_sec'").fetchone()
    hold = int(hold_row["v"] if hold_row else 180)
    pending = con.execute(
        "SELECT pa.*, d.group_id, d.hostname, d.ip FROM pending_alerts pa JOIN devices d ON d.id=pa.device_id WHERE pa.mailed_at IS NULL"
    ).fetchall()
    for pa in pending:
        age = con.execute(
            "SELECT CAST((julianday('now') - julianday(?))*86400 AS INT) s",
            (pa["first_seen"],),
        ).fetchone()["s"]
        ghold = hold
        if pa["group_id"]:
            gh = con.execute("SELECT alert_hold_sec FROM groups WHERE id=?", (pa["group_id"],)).fetchone()
            if gh:
                ghold = int(gh["alert_hold_sec"] or hold)
        if age is None or age < ghold:
            continue
        mailed_group = False
        gid = pa["group_id"]
        if gid:
            gids = _group_descendants(con, gid)
            q = ",".join("?" * len(gids))
            members = [r["id"] for r in con.execute(
                f"SELECT id FROM devices WHERE enabled=1 AND group_id IN ({q})", gids
            )]
            if members:
                open_n = con.execute(
                    f"SELECT COUNT(DISTINCT device_id) n FROM alerts WHERE status='open' AND code=? AND device_id IN ({','.join('?'*len(members))})",
                    [pa["code"], *members],
                ).fetchone()["n"]
                if open_n >= len(members) and len(members) > 1:
                    gname = con.execute("SELECT name FROM groups WHERE id=?", (gid,)).fetchone()["name"]
                    msg = f"All {len(members)} units in {gname} have {pa['code']}"
                    exist = con.execute(
                        "SELECT id FROM group_alerts WHERE group_id=? AND code=? AND status='open'",
                        (gid, pa["code"]),
                    ).fetchone()
                    if not exist:
                        con.execute(
                            "INSERT INTO group_alerts (group_id, code, status, message, opened_at) VALUES (?,?, 'open', ?, ?)",
                            (gid, pa["code"], msg, now()),
                        )
                        maybe_mail(secrets, f"BackAisle group {gname}: {pa['code']}", msg)
                    for mid in members:
                        con.execute("UPDATE pending_alerts SET mailed_at=? WHERE device_id=? AND code=?", (now(), mid, pa["code"]))
                    mailed_group = True
        if not mailed_group:
            maybe_mail(
                secrets,
                f"BackAisle {pa['code']}: {pa['hostname'] or pa['ip']}",
                pa["code"],
            )
            con.execute("UPDATE pending_alerts SET mailed_at=? WHERE device_id=? AND code=?", (now(), pa["device_id"], pa["code"]))


async def qc_poll(secrets, n=10):
    host = (secrets.get("UPS_HOST") or "").strip()
    if not host:
        raise RuntimeError("UPS_HOST is not set in secrets.env")
    con = init_db()
    rows = []
    for i in range(n):
        sample = await snmp_get(
            host,
            secrets["SNMPV3_USER"],
            secrets["SNMPV3_AUTH_PASS"],
            secrets["SNMPV3_PRIV_PASS"],
        )
        rows.append(sample)
        log(f"QC {i+1}/{n} cap={sample.get('capacity_pct')} runtime={sample.get('runtime_min')} temp={sample.get('temp_f')} load={sample.get('load_pct')} inV={sample.get('input_voltage')}")
        await asyncio.sleep(1)
    path = ROOT / "docs" / "qc-poll.md"
    lines = [
        "# Phase 1 QC — live polls of UPS_HOST",
        "",
        "Assert: temp not shown as 784°F; capacity 0–100; runtime converted from TimeTicks.",
        "",
        "| # | capacity % | runtime min | load % | input V | output status | temp °F | humidity | sensor | runtime_ticks_raw |",
        "|---|------------|-------------|--------|---------|---------------|---------|----------|--------|-------------------|",
    ]
    for i, s in enumerate(rows, 1):
        cap = s.get("capacity_pct")
        assert cap is None or 0 <= cap <= 100, cap
        temp = s.get("temp_f")
        assert temp is None or temp < 200, f"temp looked unscaled: {temp}"
        lines.append(
            f"| {i} | {cap} | {None if s.get('runtime_min') is None else round(s['runtime_min'],1)} | {s.get('load_pct')} | {s.get('input_voltage')} | {s.get('output_status_text')} | {temp} | {s.get('humidity_pct')} | {s.get('sensor_present')} | {s.get('runtime_ticks_raw')} |"
        )
    lines += [
        "",
        "## Notes",
        f"- First poll runtime_ticks_raw={rows[0].get('runtime_ticks_raw')} → minutes {rows[0].get('runtime_min')}",
        f"- Sensor present={rows[0].get('sensor_present')} envir={rows[0].get('envir_name')} temp={rows[0].get('temp_f')} (Null reading is allowed; do not invent °F)",
        "- All polls used SNMPv3 authPriv from this collector.",
        "",
    ]
    path.write_text("\n".join(lines), encoding="utf-8")
    log(f"wrote {path}")
    con.close()


def trap_loop(secrets):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.bind(("0.0.0.0", TRAP_PORT))
        sock.settimeout(1.0)
        log(f"trap listener on UDP {TRAP_PORT}")
    except OSError as e:
        log(f"trap listener not bound ({e}); polling remains source of truth")
        return
    while True:
        try:
            data, addr = sock.recvfrom(8192)
        except socket.timeout:
            continue
        except Exception as e:
            log(f"trap sock: {e}")
            continue
        try:
            con = connect()
            ip = addr[0]
            dev = con.execute("SELECT id FROM devices WHERE ip=?", (ip,)).fetchone()
            did = dev["id"] if dev else None
            con.execute(
                "INSERT INTO events (device_id, ts, severity, code, message, details) VALUES (?,?,?,?,?,?)",
                (did, now(), "info", "snmp_trap", f"trap from {ip}", json.dumps({"bytes": len(data), "src": ip})),
            )
            if did:
                ts = now()
                if is_sqlsrv():
                    con.execute(
                        "UPDATE poll_state SET last_trap=?, last_attempt=? WHERE device_id=?",
                        (ts, ts, did),
                    )
                    try:
                        con.execute(
                            "INSERT INTO poll_state (device_id, last_trap, last_attempt, consecutive_failures, comm_state) VALUES (?,?,?,0,'ok')",
                            (did, ts, ts),
                        )
                    except Exception:
                        pass
                else:
                    con.execute(
                        """INSERT INTO poll_state (device_id, last_trap, last_attempt, consecutive_failures, comm_state)
                           VALUES (?,?,?,0,'ok')
                           ON CONFLICT(device_id) DO UPDATE SET last_trap=excluded.last_trap""",
                        (did, ts, ts),
                    )
            con.commit()
            con.close()
        except Exception as e:
            log(f"trap store: {e}")





def _bind_stdio_to_log() -> None:
    """Hidden Start-Process on Windows often hands Python a broken console; keep the daemon alive."""
    try:
        if sys.stdout is None or sys.stderr is None:
            raise OSError("stdio missing")
        sys.stdout.flush()
        sys.stderr.flush()
    except Exception:
        fp = LOG.open("a", encoding="utf-8", buffering=1)
        sys.stdout = fp
        sys.stderr = fp


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--qc", type=int, default=0)
    parser.add_argument("--init", action="store_true")
    parser.add_argument("--ids", default="", help="Comma-separated device ids for a one-shot poll")
    parser.add_argument("--id", type=int, default=0, help="Single device id for a one-shot poll")
    args = parser.parse_args()
    only_ids: list[int] = []
    if args.ids:
        for part in str(args.ids).split(","):
            part = part.strip()
            if part.isdigit():
                only_ids.append(int(part))
    if args.id > 0:
        only_ids.append(args.id)
    only_ids = list(dict.fromkeys(only_ids))
    if not (args.once or args.qc or args.init or only_ids):
        _bind_stdio_to_log()
    secrets = load_secrets()
    init_db()
    global snmp_get, new_engine, close_engine
    from snmp_client import close_engine, new_engine, snmp_get  # noqa: E402
    seed_from_env()
    PID.write_text(str(os_getpid()), encoding="utf-8")
    if args.qc:
        asyncio.run(qc_poll(secrets, args.qc))
        return
    if only_ids:
        log(f"one-shot poll ids={only_ids}")
        asyncio.run(poll_cycle(secrets, climate=True, only_ids=only_ids))
        return
    if args.once or args.init:
        asyncio.run(poll_cycle(secrets, climate=True))
        return
    threading.Thread(target=trap_loop, args=(secrets,), daemon=True).start()
    log("collector starting")
    asyncio.run(daemon(secrets))


def os_getpid():
    import os
    return os.getpid()


if __name__ == "__main__":
    import faulthandler
    faulthandler.enable()
    try:
        main()
    except Exception:
        log("fatal\n" + traceback.format_exc())
        raise
