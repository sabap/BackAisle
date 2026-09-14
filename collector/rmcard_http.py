"""RMCARD205 web session: login, save-config download, restore upload."""
from __future__ import annotations

import re
import ssl
import time
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path
from typing import Callable

SAVE_HINTS = re.compile(
    rb"Save Configuration|Restore Configuration|save_config|restore_config|YYYY_MM_DD",
    re.I,
)


class RmcardSession:
    def __init__(self, host: str, user: str, password: str):
        self.host = host
        self.user = user
        self.password = password
        self.base = f"https://{host}/"
        ctx = ssl._create_unverified_context()
        self.cj = CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ctx),
            urllib.request.HTTPCookieProcessor(self.cj),
        )

    def fetch(self, path: str, data: dict | bytes | None = None, timeout: int = 30):
        url = path if path.startswith("http") else self.base + path.lstrip("/")
        body = None
        if isinstance(data, dict):
            body = urllib.parse.urlencode(data).encode()
        elif isinstance(data, bytes):
            body = data
        req = urllib.request.Request(url, data=body)
        with self.opener.open(req, timeout=timeout) as resp:
            raw = resp.read()
            hdr = {k.lower(): v for k, v in resp.headers.items()}
            return resp.geturl(), resp.status, hdr, raw

    def login(self) -> None:
        try:
            self.fetch("logout.html")
        except Exception:
            pass
        self.fetch("login.html")
        self.fetch("login_pass.cgi", {"username": self.user, "password": self.password, "action": "LOGIN"})
        ready = 0
        for step in (0, 1, 2, 2, 2, 2, 2):
            url, st, hd, raw = self.fetch(f"login_counter.html?stap={step}")
            if str(hd.get("auth_state") or "") not in ("", "0"):
                ready += 1
            time.sleep(0.4)
            if ready >= 3:
                break
        url, st, hd, raw = self.fetch("login.cgi?action=LOGIN")
        if b"summary.html" not in raw and "summary.html" not in url:
            raise RuntimeError(f"web login failed: {url} status={st} bytes={len(raw)}")
        self._home = raw

    def download_config(self) -> tuple[bytes, str]:
        """Click System → About Save Configuration. Returns (bytes, filename)."""
        url, st, hd, body = self.fetch("get_set.cgi?getset=Save")
        if _looks_like_rmcard_config(body):
            return body, _filename_from_headers(hd, body)
        url, st, hd, about = self.fetch("about.html")
        if _looks_like_rmcard_config(about):
            return about, _filename_from_headers(hd, about)
        endpoints = ["get_set.cgi?getset=Save"]
        for m in re.finditer(rb'(?:href|action|src)=["\']([^"\']+)["\']', about, re.I):
            endpoints.append(m.group(1).decode("latin1", "replace"))
        endpoints += [
            "save_file.cgi", "save_file.cgi?action=saveconfig", "save_config.cgi",
            "saveconfig.cgi", "config_save.cgi", "about.cgi?action=Save",
            "save.cgi", "download.cgi?type=config", "sys_about.cgi?action=save",
            "save_setting.cgi", "NMS_saveconfig.cgi",
        ]
        seen = set()
        for target in endpoints:
            if not target or target.startswith("JavaScript") or target in seen:
                continue
            seen.add(target)
            if not re.search(r"save|config|download|about|file|\.cgi|\.txt", target, re.I):
                continue
            try:
                u2, st2, hd2, b2 = self.fetch(target)
            except Exception:
                continue
            if _looks_like_rmcard_config(b2):
                return b2, _filename_from_headers(hd2, b2)
            if ".cgi" in target:
                try:
                    u3, st3, hd3, b3 = self.fetch(target.split("?")[0], {"action": "Save"})
                    if _looks_like_rmcard_config(b3):
                        return b3, _filename_from_headers(hd3, b3)
                except Exception:
                    continue
        raise RuntimeError("could not find RMCARD Save Configuration download")

    def restore_config_http(self, content: bytes, filename: str) -> str:
        """System → About Restore: POST /about.cgi multipart field upfile. Card reboots."""
        bound = "----BackAisle" + str(int(time.time()))
        head = (
            f"--{bound}\r\n"
            f'Content-Disposition: form-data; name="upfile"; filename="{filename}"\r\n'
            f"Content-Type: text/plain\r\n\r\n"
        ).encode("ascii")
        tail = f"\r\n--{bound}--\r\n".encode("ascii")
        url = self.base + "about.cgi"
        req = urllib.request.Request(
            url,
            data=head + content + tail,
            headers={"Content-Type": f"multipart/form-data; boundary={bound}"},
            method="POST",
        )
        try:
            with self.opener.open(req, timeout=12) as resp:
                resp.read(256)
                return f"HTTP POST /about.cgi restore {filename} status={resp.status}"
        except Exception as e:
            # RMCARD often drops the HTTP session as it applies the file and reboots.
            return f"HTTP POST /about.cgi restore {filename} (connection ended: {type(e).__name__})"


def _filename_from_headers(hd: dict, body: bytes) -> str:
    cd = hd.get("content-disposition") or ""
    m = re.search(r'filename="?([^";]+)', cd)
    if m:
        return m.group(1).strip()
    m = re.search(rb"(\d{4}_\d{2}_\d{2}_\d{4}\.txt)", body, re.I)
    if m:
        return m.group(1).decode()
    return time.strftime("%Y_%m_%d_%H%M.txt")


def _looks_like_rmcard_config(body: bytes) -> bool:
    from config_file import looks_like_rmcard_config
    if not body or len(body) < 40:
        return False
    if body[:2] in (b"MZ", b"\x7fE", b"\x1f\x8b"):
        return False
    return looks_like_rmcard_config(body.decode("latin1", "replace"))
