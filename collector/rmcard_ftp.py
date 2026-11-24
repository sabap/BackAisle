"""RMCARD FTP: config GET (fw>=1.4.0), config PUT, firmware binary PUT. Web admin login."""
from __future__ import annotations

import io
import re
import time
from datetime import datetime, timedelta
from ftplib import FTP, error_perm, error_temp
from pathlib import Path

# The card builds this file when the name matches its current minute. NLST stays empty.
CONFIG_STAMP = re.compile(r"^\d{4}_\d{2}_\d{2}_\d{4}\.txt$")


def config_stamp(moment: datetime) -> str:
    """YYYY_MM_DD_HHMM.txt from a clock. The card uses its own local time."""
    return moment.strftime("%Y_%m_%d_%H%M.txt")


def config_pull_names(card: datetime | None, local: datetime, utc: datetime) -> list[str]:
    """Likely save-file names. Exact minutes come before the neighbors.

    A wrong minute waits out the FTP timeout. It does not refuse at once, so
    the card clock and UTC are tried before this server's clock.
    """
    def bare(moment: datetime | None) -> datetime | None:
        if moment is None:
            return None
        return moment.replace(tzinfo=None) if moment.tzinfo else moment

    clocks = [bare(card), bare(utc), bare(local)]
    ordered: list[str] = []

    def add(moment: datetime | None) -> None:
        if moment is None:
            return
        name = config_stamp(moment)
        if name not in ordered:
            ordered.append(name)

    for moment in clocks:
        add(moment)
    for moment in clocks:
        if moment is None:
            continue
        add(moment - timedelta(minutes=1))
        add(moment + timedelta(minutes=1))
    return ordered[:9]


def parse_snmp_date(text: str | None) -> datetime | None:
    """HOST-RESOURCES hrSystemDate. The octets are the card's local clock."""
    raw = (text or "").strip()
    if not raw:
        return None
    matched = re.match(
        r"(\d{4})-(\d{2})-(\d{2})[ T,](\d{2}):(\d{2})",
        raw,
    )
    if matched:
        year, month, day, hour, minute = (int(part) for part in matched.groups())
        try:
            return datetime(year, month, day, hour, minute)
        except ValueError:
            return None
    hex_text = raw[2:] if raw.lower().startswith("0x") else raw
    hex_text = re.sub(r"[^0-9A-Fa-f]", "", hex_text)
    if len(hex_text) < 16 or len(hex_text) % 2:
        return None
    try:
        blob = bytes.fromhex(hex_text[:22] if len(hex_text) >= 22 else hex_text[:16])
    except ValueError:
        return None
    if len(blob) < 7:
        return None
    year = (blob[0] << 8) | blob[1]
    month, day, hour, minute = blob[2], blob[3], blob[4], blob[5]
    try:
        return datetime(year, month, day, hour, minute)
    except ValueError:
        return None


def ftp_connect(host: str, user: str, password: str, timeout: int = 20) -> FTP:
    ftp = FTP()
    ftp.connect(host, 21, timeout=timeout)
    ftp.login(user, password)
    ftp.set_pasv(True)
    return ftp


def ftp_command_verb(cmd: str) -> str:
    """The FTP verb only. The rest of PASS is the password and is not logged."""
    word = (cmd or "").split(" ", 1)[0].strip().lower()
    return (word or "cmd")[:12]


def ftp_reply_code(resp: str) -> str:
    """The three-digit reply. The rest of a PASV reply is an address."""
    text = resp or ""
    if len(text) >= 3 and text[0].isdigit():
        return text[:3]
    return "ok"


def ftp_brief(exc: BaseException, secret: str = "") -> str:
    """A short exception label. Reply text that is not a code is dropped."""
    text = " ".join(str(exc).split())
    if secret and len(secret) >= 4 and not secret.isdigit() and secret in text:
        text = text.replace(secret, "********")
    kind = type(exc).__name__
    number = getattr(exc, "winerror", None)
    if number is None:
        number = getattr(exc, "errno", None)
    suffix = f" win={int(number)}" if isinstance(number, int) and number else ""
    if not text:
        return kind + " no-message" + suffix
    matched = re.match(r"(\d{3})\b", text)
    if matched:
        return f"{kind} reply={matched.group(1)}" + suffix
    if "timed out" in text.lower() or "timeout" in text.lower():
        return kind + " timed-out" + suffix
    return kind + " no-message" + suffix


class _TraceFTP(FTP):
    """Same FTP session. Each command records its verb, time, and reply code."""

    def __init__(self) -> None:
        super().__init__()
        self.events: list[str] = []
        self.secret = ""

    def sendcmd(self, cmd):
        verb = ftp_command_verb(cmd)
        started = time.monotonic()
        try:
            resp = FTP.sendcmd(self, cmd)
        except Exception as exc:
            self.events.append(
                f"{verb} {time.monotonic() - started:.2f}s {ftp_brief(exc, self.secret)}"
            )
            raise
        self.events.append(f"{verb} {time.monotonic() - started:.2f}s {ftp_reply_code(resp)}")
        return resp


def _fail_stage(events: list[str]) -> str:
    """Where an exception landed when the command itself was not recorded.

    PASV can succeed and the data socket can still fail. That is not a failed
    control connection. Login verbs mean the TCP connect already finished.
    """
    verbs = [(event or "").split(" ", 1)[0] for event in events]
    if "retr" in verbs or "pasv" in verbs:
        return "data"
    if "user" in verbs or "pass" in verbs:
        return "login"
    return "connect"


def _retr_config_bytes(host: str, user: str, password: str, name: str, timeout: int) -> bytes:
    ftp = _TraceFTP()
    ftp.secret = password or ""
    mark = time.monotonic()
    try:
        ftp.connect(host, 21, timeout=timeout)
        ftp.events.append(f"connect {time.monotonic() - mark:.2f}s")
        ftp.login(user, password)
        ftp.set_pasv(True)
        buf = io.BytesIO()
        mark = time.monotonic()
        ftp.retrbinary(f"RETR {name}", buf.write)
        ftp.events.append(f"data {time.monotonic() - mark:.2f}s bytes={len(buf.getvalue())}")
        return buf.getvalue()
    except Exception as exc:
        brief = ftp_brief(exc, password or "")
        if not any(brief in event for event in ftp.events):
            ftp.events.append(f"{_fail_stage(ftp.events)} {time.monotonic() - mark:.2f}s {brief}")
        note = name + " " + " ".join(ftp.events)
        if password and len(password) >= 4 and not password.isdigit() and password in note:
            note = note.replace(password, "********")
        raise RuntimeError(note) from exc
    finally:
        _close(ftp)


def ftp_text_names(names: list[str]) -> list[str]:
    """Basenames of .txt files on the card. A guessed timestamp is not a real file."""
    found: list[str] = []
    for name in names:
        raw = str(name or "").replace("\\", "/").strip()
        if not raw or ".." in raw:
            continue
        base = raw.split("/")[-1].strip()
        if not base or "/" in base:
            continue
        if not base.lower().endswith(".txt"):
            continue
        if base not in found:
            found.append(base)
        if len(found) >= 4:
            break
    return found


def ftp_read_text_files(host: str, user: str, password: str, timeout: int = 4) -> list[tuple[str, bytes]]:
    """Read up to four .txt files already stored on the card."""
    timeout = 2 if timeout < 2 else min(int(timeout), 8)
    ftp = ftp_connect(host, user, password, timeout)
    out: list[tuple[str, bytes]] = []
    try:
        listed = ftp.nlst()
        for base in ftp_text_names(list(listed or [])):
            buf = io.BytesIO()
            try:
                ftp.retrbinary(f"RETR {base}", buf.write)
            except error_perm:
                continue
            data = buf.getvalue()
            if len(data) > 1_000_000:
                continue
            out.append((base, data))
        return out
    finally:
        _close(ftp)


def ftp_get_named_config(host: str, user: str, password: str, names: list[str], timeout: int = 6) -> tuple[bytes, str]:
    """RETR each clock name until the card returns a file.

    The directory listing is empty. The name has to be the card's current
    YYYY_MM_DD_HHMM.txt. A different minute waits until the socket timeout,
    so each name gets its own login and a timeout does not skip the rest.
    """
    allowed = [name for name in names if CONFIG_STAMP.match(name)]
    if not allowed:
        raise RuntimeError("ftp config name missing")
    timeout = 3 if timeout < 3 else min(int(timeout), 12)
    last: BaseException | str = "ftp did not return a config file"
    for name in allowed:
        try:
            data = _retr_config_bytes(host, user, password, name, timeout)
        except Exception as exc:
            last = exc if isinstance(exc, RuntimeError) else RuntimeError(f"{name} {ftp_brief(exc, password)}")
            continue
        if len(data) > 1_000_000:
            last = RuntimeError(f"{name} file-too-large")
            continue
        if data:
            return data, name
        last = RuntimeError(f"{name} empty-file")
    if isinstance(last, BaseException):
        raise last
    raise RuntimeError(str(last))


def ftp_get_config(host: str, user: str, password: str, filename: str, timeout: int = 30) -> bytes:
    ftp = ftp_connect(host, user, password, timeout)
    buf = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {filename}", buf.write)
        return buf.getvalue()
    finally:
        _close(ftp)


def ftp_put_config(host: str, user: str, password: str, filename: str, content: bytes, simulate: bool = False) -> str:
    """Upload config .txt (ASCII). Card typically reboots after transfer+QUIT."""
    if simulate:
        return f"SIMULATE FTP STOR {filename} ({len(content)} bytes) then QUIT"
    ftp = ftp_connect(host, user, password)
    try:
        ftp.voidcmd("TYPE A")
        ftp.storbinary(f"STOR {filename}", io.BytesIO(content))
    finally:
        _close(ftp)
    return f"FTP STOR {filename} ({len(content)} bytes) QUIT"


def ftp_put_firmware_bin(host: str, user: str, password: str, local_path: Path, simulate: bool = False) -> str:
    """Binary STOR of cpsrm2scfw_*.bin or cpsrm2scdata_*.bin then QUIT (card reboots)."""
    name = local_path.name
    size = local_path.stat().st_size if local_path.is_file() else 0
    if simulate:
        return f"SIMULATE FTP TYPE I; STOR {name} ({size} bytes); QUIT"
    if not local_path.is_file():
        raise RuntimeError(f"firmware file missing: {local_path}")
    ftp = ftp_connect(host, user, password, timeout=60)
    try:
        ftp.voidcmd("TYPE I")
        with local_path.open("rb") as f:
            ftp.storbinary(f"STOR {name}", f)
    finally:
        _close(ftp)
    return f"FTP STOR {name} ({size} bytes) QUIT"


def wait_ftp_alive(host: str, user: str, password: str, timeout_s: int = 180, simulate: bool = False) -> None:
    if simulate:
        time.sleep(1)
        return
    wait_ftp_logins(host, [(user, password)], timeout_s=timeout_s)


def wait_ftp_logins(host: str, logins: list[tuple[str, str]], timeout_s: int = 180) -> None:
    """Try each login until one is accepted. A factory apply may have just changed the account."""
    unique: list[tuple[str, str]] = []
    for user, password in logins:
        pair = (user or "", password or "")
        if not pair[0] or not pair[1] or pair in unique:
            continue
        unique.append(pair)
    if not unique:
        raise RuntimeError(f"card did not come back on FTP within {timeout_s}s: no login")
    deadline = time.time() + timeout_s
    last: Exception | None = None
    while time.time() < deadline:
        for user, password in unique:
            try:
                ftp = ftp_connect(host, user, password, timeout=8)
                _close(ftp)
                return
            except Exception as e:
                last = e
        time.sleep(5)
    raise RuntimeError(f"card did not come back on FTP within {timeout_s}s: {last}")


def _close(ftp: FTP) -> None:
    try:
        ftp.quit()
    except Exception:
        try:
            ftp.close()
        except Exception:
            pass
