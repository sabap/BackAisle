"""RMCARD205 web session: login, save-config download, restore upload."""
from __future__ import annotations

import re
import socket
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


def tcp_open(host: str, port: int, timeout: float = 2.0) -> bool:
    s = socket.socket()
    s.settimeout(timeout)
    try:
        s.connect((host, port))
        return True
    except OSError:
        return False
    finally:
        try:
            s.close()
        except OSError:
            pass


def pick_rmcard_scheme(host: str) -> str:
    """Prefer HTTPS when 443 answers. Use HTTP when 443 is refused (WinError 10061)."""
    if tcp_open(host, 443):
        return "https"
    if tcp_open(host, 80):
        return "http"
    return "https"


class _StayOnHttpRedirect(urllib.request.HTTPRedirectHandler):
    """If 443 is closed, do not follow HTTP -> HTTPS (that 303 is what production 10061 is)."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if newurl.lower().startswith("https://"):
            newurl = "http://" + newurl[8:]
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class RmcardSession:
    def __init__(self, host: str, user: str, password: str, scheme: str | None = None):
        self.host = host
        self.user = user
        self.password = password
        chosen = (scheme or pick_rmcard_scheme(host)).lower()
        if chosen not in ("http", "https"):
            chosen = "https"
        self.scheme = chosen
        self.base = f"{chosen}://{host}/"
        ctx = ssl._create_unverified_context()
        self.cj = CookieJar()
        handlers: list = [
            urllib.request.HTTPSHandler(context=ctx),
            urllib.request.HTTPCookieProcessor(self.cj),
        ]
        if chosen == "http":
            handlers.insert(0, _StayOnHttpRedirect())
        self.opener = urllib.request.build_opener(*handlers)

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

    def logout(self) -> None:
        for path in (
            "logout.html",
            "logout.cgi",
            "logout.cgi?action=LOGOUT",
            "login.cgi?action=LOGOUT",
            "login.html?action=LOGOUT",
            "log_out.html",
        ):
            try:
                self.fetch(path, timeout=8)
            except Exception:
                pass

    @staticmethod
    def _logged_in(url: str, raw: bytes) -> bool:
        return "summary.html" in (url or "") or b"summary.html" in (raw or b"")

    @staticmethod
    def _busy_session(url: str, raw: bytes) -> bool:
        low = (raw or b"").lower()
        if b"someone is currently logged" in low:
            return True
        if b"already logged" in low or b"another user" in low:
            return True
        if "error.html" in (url or "") and b"here to login" in low:
            return True
        return False

    def _login_once(self):
        """Full login_pass + counter dance. Stopping the counter early lands on error.html."""
        self.fetch("login.html")
        self.fetch("login_pass.cgi", {"username": self.user, "password": self.password, "action": "LOGIN"})
        for step in (0, 1, 2, 2, 2, 2, 2):
            self.fetch(f"login_counter.html?stap={step}")
            time.sleep(0.4)
        return self.fetch("login.cgi?action=LOGIN")

    def _click_login_again(self) -> None:
        """error.html: 'Please click here to login again' is href='/'."""
        for path in ("/", "login.html", "error.html"):
            try:
                self.fetch(path, timeout=8)
            except Exception:
                pass

    def login(self) -> None:
        self.logout()
        time.sleep(0.4)
        url, st, hd, raw = self._login_once()
        if self._logged_in(url, raw):
            self._home = raw
            return
        # Card holds one web login. Do not logout() here — that is a different cookie jar
        # than the session already on the card. Click "here to login again" and retry.
        self._click_login_again()
        time.sleep(1.0)
        url, st, hd, raw = self._login_once()
        if self._logged_in(url, raw):
            self._home = raw
            return
        self.logout()
        time.sleep(1.2)
        url, st, hd, raw = self._login_once()
        if not self._logged_in(url, raw):
            if self._busy_session(url, raw):
                raise RuntimeError(
                    "web login failed: RMCARD already has a web session "
                    "(close the UPS browser tab that says 'Someone is currently logged in', then retry)"
                )
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


def wait_http_alive(host: str, timeout_s: int = 360) -> str:
    """RMCARD often leaves FTP off; HTTPS login.html is enough to know the card is back."""
    import ssl
    import urllib.request

    ctx = ssl._create_unverified_context()
    deadline = time.time() + timeout_s
    last = None
    while time.time() < deadline:
        for url in (
            f"https://{host}/login.html",
            f"http://{host}/login.html",
            f"https://{host}/",
            f"http://{host}/",
        ):
            try:
                req = urllib.request.Request(url, method="GET")
                with urllib.request.urlopen(req, timeout=8, context=ctx) as resp:
                    resp.read(128)
                    return f"{url} status={resp.status}"
            except Exception as e:
                last = e
        time.sleep(5)
    raise RuntimeError(f"card did not come back on HTTP within {timeout_s}s: {last}")


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
