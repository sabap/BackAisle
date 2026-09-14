"""RMCARD FTP: config GET (fw>=1.4.0), config PUT, firmware binary PUT. Web admin login."""
from __future__ import annotations

import io
import time
from ftplib import FTP, error_perm
from pathlib import Path


def ftp_connect(host: str, user: str, password: str, timeout: int = 20) -> FTP:
    ftp = FTP()
    ftp.connect(host, 21, timeout=timeout)
    ftp.login(user, password)
    ftp.set_pasv(True)
    return ftp


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
    deadline = time.time() + timeout_s
    last = None
    while time.time() < deadline:
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
