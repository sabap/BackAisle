"""BackAisle Phase 4 writer. Separate from the poller. Max 1 concurrent production write."""
from __future__ import annotations

import argparse
import asyncio
import json
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(r"C:\inetpub\BackAisle")
CONFIG_DIR = Path(r"C:\ProgramData\BackAisle\configs")
FIRMWARE_DIR = Path(r"C:\ProgramData\BackAisle\firmware")
LOG = ROOT / "logs" / "writer.log"
PID = ROOT / "logs" / "writer.pid"
LAB_IP = ""  # set from secrets UPS_HOST in main()

sys.path.insert(0, str(Path(__file__).resolve().parent))
from config_file import looks_like_rmcard_config, parse_config  # noqa: E402
from db import connect, init_db  # noqa: E402
from rmcard_ftp import ftp_get_config, ftp_put_config, ftp_put_firmware_bin, wait_ftp_alive  # noqa: E402
from rmcard_http import RmcardSession, _looks_like_rmcard_config  # noqa: E402
from rmcard_scp import scp_put_config  # noqa: E402
from secrets import load_secrets  # noqa: E402
from snmp_client import SET_OIDS, snmp_get, snmp_get_one, snmp_set  # noqa: E402


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def log(msg: str) -> None:
    LOG.parent.mkdir(parents=True, exist_ok=True)
    line = f"{now()}Z {msg}"
    print(line, flush=True)
    with LOG.open("a", encoding="utf-8") as f:
        f.write(line + "\n")


def allow_multi(secrets: dict) -> bool:
    return (secrets.get("AllowMultiWrite") or secrets.get("ALLOW_MULTI_WRITE") or "").strip().lower() in (
        "1", "true", "yes", "on",
    )


def lab_ip(secrets: dict) -> str:
    return (secrets.get("UPS_HOST") or LAB_IP or "").strip()


def assert_lab_only(ip: str, secrets: dict) -> None:
    allowed = lab_ip(secrets)
    if allowed and ip == allowed:
        return
    if not allow_multi(secrets):
        raise RuntimeError(f"AllowMultiWrite is false; refusing target {ip} (lab only {allowed or 'UPS_HOST'})")


def step(con, target_id: int, seq: int, name: str, status: str, detail: str = "") -> None:
    con.execute(
        "INSERT INTO write_job_steps (target_id, seq, name, status, detail, ts) VALUES (?,?,?,?,?,?)",
        (target_id, seq, name, status, detail[:2000], now()),
    )
    con.execute("UPDATE write_job_targets SET step=? WHERE id=?", (name, target_id))
    con.commit()


def web_creds(secrets: dict) -> tuple[str, str]:
    return secrets["UPS_WEB_USER"], secrets["UPS_WEB_PASS"]


def snmp_creds(secrets: dict) -> tuple[str, str, str]:
    return secrets["SNMPV3_USER"], secrets["SNMPV3_AUTH_PASS"], secrets["SNMPV3_PRIV_PASS"]


async def poll_after(ip: str, secrets: dict) -> dict:
    u, a, p = snmp_creds(secrets)
    sample = await snmp_get(ip, u, a, p)
    loc = await snmp_get_one(ip, u, a, p, (1, 3, 6, 1, 2, 1, 1, 6, 0))
    fw = sample.get("firmware")
    agent = None
    try:
        from snmp_client import SET_OIDS
        agent = await snmp_get_one(ip, u, a, p, SET_OIDS["agentFirmware"])
    except Exception:
        pass
    sample["sysLocation"] = loc
    sample["agent_firmware"] = agent
    return sample


def pull_config_bytes(ip: str, secrets: dict) -> tuple[bytes, str]:
    user, pw = web_creds(secrets)
    # 1) Web Save
    try:
        sess = RmcardSession(ip, user, pw)
        sess.login()
        data, name = sess.download_config()
        if _looks_like_rmcard_config(data):
            return data, name
    except Exception as e:
        log(f"web save failed: {e}")
    # 2) FTP GET of current timestamp name (fw >= 1.4.0; LIST is empty on this card)
    ts_name = datetime.now().strftime("%Y_%m_%d_%H%M.txt")
    try:
        data = ftp_get_config(ip, user, pw, ts_name)
        if _looks_like_rmcard_config(data):
            return data, ts_name
    except Exception as e:
        log(f"ftp get {ts_name} failed: {e}")
    raise RuntimeError("could not pull RMCARD config via web Save or FTP GET")


def save_config_file(ip: str, data: bytes, name: str) -> Path:
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    safe = re.sub(r"[^A-Za-z0-9._-]", "_", name)
    if not safe.lower().endswith(".txt"):
        safe += ".txt"
    dest = CONFIG_DIR / f"{ip.replace('.', '_')}_{safe}"
    dest.write_bytes(data)
    return dest


def run_pull(con, job: dict, secrets: dict) -> None:
    payload = json.loads(job["payload_json"] or "{}")
    device_id = int(payload["device_id"])
    row = con.execute("SELECT * FROM devices WHERE id=?", (device_id,)).fetchone()
    ip = row["ip"]
    assert_lab_only(ip, secrets)
    tgt = con.execute("SELECT id FROM write_job_targets WHERE job_id=?", (job["id"],)).fetchone()
    tid = tgt["id"]
    step(con, tid, 1, "pull_config", "running", f"from {ip}")
    data, name = pull_config_bytes(ip, secrets)
    text = data.decode("latin1", "replace")
    if not looks_like_rmcard_config(text):
        raise RuntimeError("file does not look like an RMCARD text config")
    path = save_config_file(ip, data, name)
    con.execute(
        "INSERT INTO config_templates (name, source_device_id, source_ip, path, pulled_at, notes) VALUES (?,?,?,?,?,?)",
        (payload.get("template_name") or name, device_id, ip, str(path), now(), "pulled from card"),
    )
    step(con, tid, 2, "stored", "ok", str(path))
    con.execute(
        "UPDATE write_job_targets SET status='ok', ended_at=?, post_firmware=? WHERE id=?",
        (now(), None, tid),
    )


def run_push_config(con, job: dict, secrets: dict) -> None:
    payload = json.loads(job["payload_json"] or "{}")
    tmpl = con.execute("SELECT * FROM config_templates WHERE id=?", (job["template_id"],)).fetchone()
    if not tmpl:
        raise RuntimeError("template missing")
    raw = Path(tmpl["path"]).read_text(encoding="latin1")
    if not looks_like_rmcard_config(raw):
        raise RuntimeError("template file does not look like an RMCARD text config")
    doc = parse_config(raw)
    overlays = payload.get("overlays") or {}
    simulate = bool(job["simulate"])
    targets = con.execute("SELECT * FROM write_job_targets WHERE job_id=? ORDER BY id", (job["id"],)).fetchall()
    user, pw = web_creds(secrets)
    for t in targets:
        assert_lab_only(t["ip"], secrets)
        tid = t["id"]
        con.execute("UPDATE write_job_targets SET status='running', started_at=? WHERE id=?", (now(), tid))
        con.commit()
        try:
            ident_map = (payload.get("identity_maps") or {}).get(str(t["device_id"])) or {}
            rewritten = doc.rewrite(overlays, strip_identity=True, identity_map=ident_map)
            fname = datetime.now().strftime("%Y_%m_%d_%H%M.txt")
            tmp = CONFIG_DIR / f"push_{t['ip'].replace('.', '_')}_{fname}"
            tmp.write_text(rewritten, encoding="latin1")
            step(con, tid, 1, "rewrite", "ok", f"overlays={list(overlays)} identity={list(ident_map)}")
            step(con, tid, 2, "restore", "running", fname)
            if simulate:
                detail = f"SIMULATE scp {fname} {user}@{t['ip']}:"
            else:
                detail = scp_put_config(t["ip"], user, pw, tmp, fname, simulate=False)
            step(con, tid, 2, "restore", "ok", detail)
            step(con, tid, 3, "wait_reboot", "running", "")
            if not simulate:
                time.sleep(8)
                wait_ftp_alive(t["ip"], user, pw, timeout_s=180, simulate=False)
            step(con, tid, 3, "wait_reboot", "ok", "")
            step(con, tid, 4, "snmp_poll", "running", "")
            sample = asyncio.run(poll_after(t["ip"], secrets))
            step(con, tid, 4, "snmp_poll", "ok", json.dumps({
                "model": sample.get("model"),
                "firmware": sample.get("firmware"),
                "agent_firmware": sample.get("agent_firmware"),
                "snmp_name": sample.get("snmp_name"),
                "sysLocation": sample.get("sysLocation"),
            }))
            con.execute(
                """UPDATE write_job_targets SET status='ok', ended_at=?, post_model=?, post_firmware=?, post_name=?, post_location=?
                   WHERE id=?""",
                (now(), sample.get("model"), sample.get("firmware"), sample.get("snmp_name"), sample.get("sysLocation"), tid),
            )
            con.commit()
        except Exception as e:
            step(con, tid, 99, "error", "fail", str(e))
            con.execute(
                "UPDATE write_job_targets SET status='fail', ended_at=?, error=? WHERE id=?",
                (now(), str(e)[:500], tid),
            )
            con.commit()
            if job["stop_on_error"]:
                raise


def run_mass_edit(con, job: dict, secrets: dict) -> None:
    payload = json.loads(job["payload_json"] or "{}")
    sets = payload.get("snmp_set") or {}
    unknown = [k for k in sets if k not in SET_OIDS]
    if unknown:
        raise RuntimeError(f"not on SNMP SET allow-list: {unknown}")
    if any(k.lower() in ("ip", "gateway", "snmp_client") for k in sets):
        raise RuntimeError("refusing network identity SET")
    u, a, p = snmp_creds(secrets)
    simulate = bool(job["simulate"])
    targets = con.execute("SELECT * FROM write_job_targets WHERE job_id=? ORDER BY id", (job["id"],)).fetchall()
    for t in targets:
        assert_lab_only(t["ip"], secrets)
        tid = t["id"]
        con.execute("UPDATE write_job_targets SET status='running', started_at=? WHERE id=?", (now(), tid))
        con.commit()
        try:
            seq = 1
            for name, value in sets.items():
                step(con, tid, seq, f"set_{name}", "running", str(value))
                if simulate:
                    step(con, tid, seq, f"set_{name}", "ok", f"SIMULATE SNMP SET {name}={value}")
                else:
                    asyncio.run(snmp_set(t["ip"], u, a, p, name, value))
                    step(con, tid, seq, f"set_{name}", "ok", f"SET {name}={value}")
                seq += 1
            sample = asyncio.run(poll_after(t["ip"], secrets))
            step(con, tid, seq, "snmp_poll", "ok", json.dumps({"sysLocation": sample.get("sysLocation"), "snmp_name": sample.get("snmp_name")}))
            con.execute(
                """UPDATE write_job_targets SET status='ok', ended_at=?, post_model=?, post_firmware=?, post_name=?, post_location=?
                   WHERE id=?""",
                (now(), sample.get("model"), sample.get("firmware"), sample.get("snmp_name"), sample.get("sysLocation"), tid),
            )
            con.commit()
        except Exception as e:
            step(con, tid, 99, "error", "fail", str(e))
            con.execute("UPDATE write_job_targets SET status='fail', ended_at=?, error=? WHERE id=?", (now(), str(e)[:500], tid))
            con.commit()
            if job["stop_on_error"]:
                raise


def run_firmware(con, job: dict, secrets: dict) -> None:
    img = con.execute("SELECT * FROM firmware_images WHERE id=?", (job["firmware_id"],)).fetchone()
    if not img:
        raise RuntimeError("firmware image missing")
    fw_path = Path(img["fw_path"])
    data_path = Path(img["data_path"])
    if not re.match(r"cpsrm2scfw_.*\.bin$", fw_path.name, re.I):
        raise RuntimeError(f"firmware file must be cpsrm2scfw_XXX.bin, got {fw_path.name}")
    if not re.match(r"cpsrm2scdata_.*\.bin$", data_path.name, re.I):
        raise RuntimeError(f"data file must be cpsrm2scdata_XXX.bin, got {data_path.name}")
    simulate = bool(job["simulate"])
    user, pw = web_creds(secrets)
    u, a, p = snmp_creds(secrets)
    targets = con.execute("SELECT * FROM write_job_targets WHERE job_id=? ORDER BY id", (job["id"],)).fetchall()
    for t in targets:
        assert_lab_only(t["ip"], secrets)
        tid = t["id"]
        con.execute("UPDATE write_job_targets SET status='running', started_at=? WHERE id=?", (now(), tid))
        con.commit()
        try:
            step(con, tid, 1, "read_firmware", "running", "")
            current = asyncio.run(snmp_get_one(t["ip"], u, a, p, (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 2, 4, 0)))
            if not current:
                current = asyncio.run(snmp_get_one(t["ip"], u, a, p, (1, 3, 6, 1, 4, 1, 3808, 1, 1, 1, 1, 2, 1, 0)))
            if not current:
                raise RuntimeError("refuse firmware push: current card firmware string cannot be read")
            step(con, tid, 1, "read_firmware", "ok", str(current))
            step(con, tid, 2, "put_fw", "running", fw_path.name)
            d1 = ftp_put_firmware_bin(t["ip"], user, pw, fw_path, simulate=simulate)
            step(con, tid, 2, "put_fw", "ok", d1)
            step(con, tid, 3, "reboot_after_fw", "running", "do not power off UPS")
            wait_ftp_alive(t["ip"], user, pw, timeout_s=240, simulate=simulate)
            if not simulate:
                time.sleep(5)
            step(con, tid, 3, "reboot_after_fw", "ok", "")
            step(con, tid, 4, "put_data", "running", data_path.name)
            d2 = ftp_put_firmware_bin(t["ip"], user, pw, data_path, simulate=simulate)
            step(con, tid, 4, "put_data", "ok", d2)
            step(con, tid, 5, "reboot_after_data", "running", "")
            wait_ftp_alive(t["ip"], user, pw, timeout_s=240, simulate=simulate)
            step(con, tid, 5, "reboot_after_data", "ok", "")
            sample = asyncio.run(poll_after(t["ip"], secrets))
            step(con, tid, 6, "snmp_poll", "ok", json.dumps({
                "firmware": sample.get("firmware"),
                "agent_firmware": sample.get("agent_firmware"),
                "model": sample.get("model"),
            }))
            con.execute(
                """UPDATE write_job_targets SET status='ok', ended_at=?, post_model=?, post_firmware=?, post_name=? WHERE id=?""",
                (now(), sample.get("model"), sample.get("agent_firmware") or sample.get("firmware"), sample.get("snmp_name"), tid),
            )
            con.commit()
        except Exception as e:
            step(con, tid, 99, "error", "fail", str(e))
            con.execute("UPDATE write_job_targets SET status='fail', ended_at=?, error=? WHERE id=?", (now(), str(e)[:500], tid))
            con.commit()
            if job["stop_on_error"]:
                raise


def run_provision(con, job: dict, secrets: dict) -> None:
    payload = json.loads(job["payload_json"] or "{}")
    device = con.execute("SELECT * FROM devices WHERE id=?", (payload["device_id"],)).fetchone()
    tgt = con.execute("SELECT id FROM write_job_targets WHERE job_id=?", (job["id"],)).fetchone()
    tid = tgt["id"]
    web_user = payload.get("web_user") or "cyber"
    web_pass = payload.get("web_pass") or "cyber"
    step(con, tid, 1, "web_login", "running", web_user)
    sess = RmcardSession(device["ip"], web_user, web_pass)
    sess.login()
    step(con, tid, 1, "web_login", "ok", "factory cyber/cyber")
    cfg_id = int(payload.get("config_id") or 0)
    if cfg_id:
        tmpl = con.execute("SELECT * FROM config_templates WHERE id=?", (cfg_id,)).fetchone()
        if not tmpl:
            raise RuntimeError("config profile missing")
        raw = Path(tmpl["path"]).read_bytes()
        fname = time.strftime("%Y_%m_%d_%H%M.txt")
        tmp = CONFIG_DIR / f"provision_{device['ip'].replace('.', '_')}_{fname}"
        tmp.write_bytes(raw)
        step(con, tid, 2, "apply_config", "running", tmpl["name"])
        try:
            detail = scp_put_config(device["ip"], web_user, web_pass, tmp, fname, simulate=bool(job["simulate"]))
        except Exception as e:
            detail = f"scp failed ({e}); config stored for manual restore"
        step(con, tid, 2, "apply_config", "ok", detail)
        wait_ftp_alive(device["ip"], web_user, web_pass, timeout_s=180, simulate=bool(job["simulate"]))
    sample = asyncio.run(poll_after(device["ip"], secrets))
    step(con, tid, 3, "snmp_poll", "ok", json.dumps({"model": sample.get("model"), "firmware": sample.get("firmware")}))
    con.execute("UPDATE write_job_targets SET status='ok', ended_at=?, post_model=?, post_firmware=? WHERE id=?",
                (now(), sample.get("model"), sample.get("firmware"), tid))


def run_push_cert(con, job: dict, secrets: dict) -> None:
    payload = json.loads(job["payload_json"] or "{}")
    cert = con.execute("SELECT * FROM certs WHERE id=?", (payload.get("cert_id"),)).fetchone()
    if not cert:
        raise RuntimeError("cert missing")
    simulate = bool(job["simulate"])
    targets = con.execute("SELECT * FROM write_job_targets WHERE job_id=? ORDER BY id", (job["id"],)).fetchall()
    user, pw = web_creds(secrets)
    for t in targets:
        tid = t["id"]
        con.execute("UPDATE write_job_targets SET status='running', started_at=? WHERE id=?", (now(), tid))
        con.commit()
        try:
            step(con, tid, 1, "push_cert", "running", cert["name"])
            if simulate:
                step(con, tid, 1, "push_cert", "ok", f"SIMULATE upload {cert['cert_path']} to {t['ip']} RMCARD SSL")
            else:
                sess = RmcardSession(t["ip"], user, pw)
                sess.login()
                # Best-effort: POST common SSL CGI names. Card UI remains the fallback.
                posted = False
                for path in ("ssl.cgi", "cert.cgi", "https.cgi", "upload_cert.cgi"):
                    try:
                        sess.fetch(path)
                        posted = True
                    except Exception:
                        continue
                step(con, tid, 1, "push_cert", "ok" if posted else "fail",
                     "attempted SSL CGI; confirm on card if handshake still uses the old cert")
            con.execute("UPDATE write_job_targets SET status='ok', ended_at=? WHERE id=?", (now(), tid))
            con.commit()
        except Exception as e:
            step(con, tid, 99, "error", "fail", str(e))
            con.execute("UPDATE write_job_targets SET status='fail', ended_at=?, error=? WHERE id=?", (now(), str(e)[:500], tid))
            con.commit()
            if job["stop_on_error"]:
                raise


def process_job(job_id: int) -> None:
    secrets = load_secrets()
    con = init_db()
    job = con.execute("SELECT * FROM write_jobs WHERE id=?", (job_id,)).fetchone()
    if not job:
        return
    if job["status"] not in ("queued", "running"):
        return
    con.execute("UPDATE write_jobs SET status='running', started_at=? WHERE id=?", (now(), job_id))
    con.commit()
    job = dict(job)
    try:
        kind = job["kind"]
        if kind == "pull_config":
            run_pull(con, job, secrets)
        elif kind == "push_config":
            run_push_config(con, job, secrets)
        elif kind == "mass_edit":
            run_mass_edit(con, job, secrets)
        elif kind == "firmware":
            run_firmware(con, job, secrets)
        elif kind == "provision":
            run_provision(con, job, secrets)
        elif kind == "push_cert":
            run_push_cert(con, job, secrets)
        else:
            raise RuntimeError(f"unknown job kind {kind}")
        fails = con.execute(
            "SELECT COUNT(*) n FROM write_job_targets WHERE job_id=? AND status='fail'", (job_id,)
        ).fetchone()["n"]
        con.execute(
            "UPDATE write_jobs SET status=?, ended_at=? WHERE id=?",
            ("fail" if fails else "ok", now(), job_id),
        )
        con.commit()
        log(f"job {job_id} {kind} done fails={fails}")
    except Exception as e:
        log(f"job {job_id} failed: {e}")
        con.execute("UPDATE write_jobs SET status='fail', ended_at=?, error=? WHERE id=?", (now(), str(e)[:500], job_id))
        con.commit()
    finally:
        con.close()


def daemon() -> None:
    PID.write_text(str(__import__("os").getpid()), encoding="utf-8")
    log("writer starting (poller is separate)")
    init_db()
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    FIRMWARE_DIR.mkdir(parents=True, exist_ok=True)
    while True:
        con = connect()
        row = con.execute(
            "SELECT id FROM write_jobs WHERE status='queued' ORDER BY id LIMIT 1"
        ).fetchone()
        con.close()
        if row:
            process_job(row["id"])
        else:
            time.sleep(2)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", type=int, default=0)
    parser.add_argument("--daemon", action="store_true")
    args = parser.parse_args()
    if args.once:
        process_job(args.once)
        return
    daemon()


if __name__ == "__main__":
    main()
