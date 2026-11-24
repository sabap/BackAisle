"""One SSH session to a CyberPower RMCARD command shell. Keyboard-interactive login."""
from __future__ import annotations

import re
import socket
import time

import paramiko
from cryptography.hazmat.primitives import hashes
from paramiko.rsakey import RSAKey

from card_commands import IDENTITY_SHOW_LIMIT, _LINE

_PROMPT = re.compile(r"(?m)^CyberPower >\s*$")
_NEW_USER = re.compile(r"new\s+user\s*name\s*:", re.I)
_NEW_PASS = re.compile(r"new\s+pass(?:word)?\s*:", re.I)
_NEW_CONFIRM = re.compile(
    r"(?:confirm|retype|re-enter|reenter|repeat|verify|again)\s+(?:new\s+)?pass(?:word)?\s*:|"
    r"(?:confirm|retype|re-enter|reenter)\s*:",
    re.I,
)
_USER = re.compile(r"user\s*name\s*:", re.I)
_PASS = re.compile(r"password\s*:", re.I)
# "Enter new password again:" keeps words between the noun and the colon.
_PASS_ASK = re.compile(r"pass(?:word)?\b[^\r\n:]{0,40}:", re.I)
_CONFIRM = re.compile(r"(?i)input\s+admin\s+password\s*:")
_ACCOUNT_FAIL = re.compile(r"(?im)^fail\s*$|password error")
_PASSWORD_REJECT = re.compile(
    r"password error|invalid password|entered incorrectly|does not match|do not match|"
    r"not match|too short|too long|not accepted|^fail\b",
    re.I | re.M,
)
_PROMPT_KINDS = (
    ("prompt", _PROMPT),
    ("new_user", _NEW_USER),
    ("confirm", _NEW_CONFIRM),
    ("new_pass", _NEW_PASS),
    ("user", _USER),
    ("pass", _PASS),
    ("pass_ask", _PASS_ASK),
)
_ADMIN_PASSWD = re.compile(r"^admin passwd (\S+)$")


def _prompt_hits(buf: str) -> list[tuple[int, int, str]]:
    """Earliest prompt wins. A label inside that prompt is not a second question."""
    found: list[tuple[int, int, str]] = []
    for kind, pattern in _PROMPT_KINDS:
        for match in pattern.finditer(buf):
            found.append((match.start(), match.end(), kind))
    found.sort()
    kept: list[tuple[int, int, str]] = []
    covered = -1
    for start, end, kind in found:
        if start < covered:
            continue
        kept.append((start, end, kind))
        covered = end
    return kept


def _answer_was_echoed(buf: str, start: int, end: int, sent: str) -> bool:
    """A terminal repeats the prompt plus the text just typed. That is not a new question."""
    newline = buf.find("\n", start)
    line = buf[start:] if newline < 0 else buf[start:newline]
    tail = line[end - start:]
    if sent and sent in tail:
        return True
    return bool(re.search(r"\*{2,}", tail))


def _allow_ssh_rsa_host_key(transport: paramiko.Transport) -> None:
    """RMCARD presents an ssh-rsa host key. Paramiko 3 does not offer that name."""
    if "ssh-rsa" not in RSAKey.HASHES:
        RSAKey.HASHES["ssh-rsa"] = hashes.SHA1
    offered = tuple(transport._preferred_keys)
    if "ssh-rsa" not in offered:
        transport._preferred_keys = offered + ("ssh-rsa",)
    info = dict(transport._key_info)
    info.setdefault("ssh-rsa", RSAKey)
    transport._key_info = info


class RmcardCli:
    def __init__(
        self,
        host: str,
        username: str,
        password: str,
        timeout: float = 20,
        new_user: str | None = None,
        new_password: str | None = None,
    ):
        self.host = host
        self.username = username
        self.password = password
        self.new_user = (new_user or "").strip()
        self.new_password = new_password or ""
        self.account_changed = False
        self._confirm_password = password
        self._secrets = [password] if password else []
        if self.new_password:
            self._remember(self.new_password)
        self.timeout = timeout
        self._transport: paramiko.Transport | None = None
        self._chan = None

    def __enter__(self) -> "RmcardCli":
        self.open()
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        self.close()

    def open(self) -> None:
        sock = socket.create_connection((self.host, 22), timeout=self.timeout)
        transport = paramiko.Transport(
            sock,
            disabled_algorithms={"pubkeys": ["rsa-sha2-512", "rsa-sha2-256"]},
        )
        _allow_ssh_rsa_host_key(transport)
        self._transport = transport
        try:
            transport.start_client(timeout=self.timeout)
            self._login(transport)
            chan = transport.open_session(timeout=self.timeout)
            chan.get_pty(term="vt100", width=200, height=48)
            chan.invoke_shell()
            self._chan = chan
            self._reach_prompt()
        except Exception:
            self.close()
            raise

    def close(self) -> None:
        chan = self._chan
        self._chan = None
        if chan is not None:
            try:
                chan.close()
            except Exception:
                pass
        transport = self._transport
        self._transport = None
        if transport is not None:
            try:
                transport.close()
            except Exception:
                pass

    def run(self, command: str) -> str:
        if not _LINE.match(command):
            raise RuntimeError("refusing command")
        chan = self._chan
        if chan is None:
            raise RuntimeError("card session is not open")
        before = self._prompt_count("")
        pending = None
        matched = _ADMIN_PASSWD.match(command)
        if matched:
            pending = matched.group(1)
            self._remember(pending)
        answered = False

        def done(buf: str) -> bool:
            nonlocal answered
            if not answered and _CONFIRM.search(buf):
                chan.send(self._confirm_password + "\r")
                answered = True
                return False
            return self._prompt_count(buf) > before

        chan.send(command + "\r")
        text = self._read(done, self.timeout)
        if "Parameter Error" in text or "Command not found" in text or "doesn't know this command" in text:
            raise RuntimeError(self._safe(_card_reject_message(command, text)))
        if command.startswith(("admin ", "device ")) and _ACCOUNT_FAIL.search(text):
            raise RuntimeError("password error")
        if pending:
            self._confirm_password = pending
        return self._safe(text)

    def sign_out(self) -> None:
        """Send the card's exit command, then close. exit commits a service change."""
        chan = self._chan
        if chan is not None:
            try:
                chan.send("exit\r")
            except Exception:
                pass
        self.close()

    def _login(self, transport: paramiko.Transport) -> None:
        def handler(title, instructions, prompts):
            return [self.password for _prompt in prompts]

        try:
            transport.auth_interactive(self.username, handler)
        except paramiko.BadAuthenticationType as exc:
            allowed = [str(item) for item in (exc.allowed_types or [])]
            if "password" not in allowed:
                raise RuntimeError("authentication failed") from None
            transport.auth_password(self.username, self.password)
        except paramiko.AuthenticationException as exc:
            raise RuntimeError("authentication failed") from exc
        if not transport.is_authenticated():
            raise RuntimeError("authentication failed")

    def _reach_prompt(self) -> None:
        chan = self._chan
        assert chan is not None
        sent_user = False
        sent_pass = False
        sent_new_user = False
        password_answers = 0
        recovered = False
        just_sent = ""
        # A factory card is already waiting at New User Name. Enter before
        # that question is a blank name, and the saved password then lands
        # in the wrong field. Press Enter only when the card has said nothing.
        started = False
        woke = False
        first_read = True
        deadline = time.time() + self.timeout
        buf = ""
        while time.time() < deadline:
            chunk = self._recv(2.0 if first_read else 0.6)
            first_read = False
            if chunk:
                buf += chunk
            elif not started and not woke and not _prompt_hits(buf):
                chan.send("\r")
                woke = True
                continue
            if sent_new_user and password_answers and _PASSWORD_REJECT.search(buf):
                # Firmware 1.4.4 asks for New Password again when the confirm
                # did not match. Answer that question once. A second rejection stops.
                asks_again = (
                    not recovered
                    and re.search(r"entered incorrectly", buf, re.I) is not None
                    and _NEW_PASS.search(buf) is not None
                )
                if not asks_again:
                    raise RuntimeError(self._rejected_password(buf))
                recovered = True
                password_answers = 0
            while True:
                hits = _prompt_hits(buf)
                if not hits:
                    break
                start, end, kind = hits[0]
                if kind != "prompt" and _answer_was_echoed(buf, start, end, just_sent):
                    buf = buf[end:]
                    continue
                if kind == "prompt":
                    return
                line_start = buf.rfind("\n", 0, start) + 1
                if _CONFIRM.search(buf[line_start:end]):
                    # "input admin password" asks for the password that
                    # already works. The new password is a later question.
                    chan.send(self.password + "\r")
                    started = True
                    just_sent = self.password
                    buf = buf[end:]
                    break
                if kind == "new_user" or (kind == "user" and sent_new_user):
                    if sent_new_user:
                        raise RuntimeError(self._rejected_name(buf))
                    if not self.new_user or not self.new_password:
                        raise RuntimeError(
                            "the card requires a new administrator name. "
                            "Assign a Web/SSH/FTP login on this UPS first."
                        )
                    chan.send(self.new_user + "\r")
                    sent_new_user = True
                    started = True
                    self.account_changed = True
                    just_sent = self.new_user
                    buf = buf[end:]
                    break
                if sent_new_user and kind in ("confirm", "new_pass", "pass", "pass_ask"):
                    # The second prompt is the confirmation. It often says
                    # "New Password" again, without the word confirm.
                    # One answer, then read again. The next question may
                    # already be in this buffer, and a second line in the
                    # same burst is read into the first field.
                    if password_answers >= 2:
                        raise RuntimeError(self._rejected_password(buf))
                    chan.send(self.new_password + "\r")
                    password_answers += 1
                    started = True
                    just_sent = self.new_password
                    buf = buf[end:]
                    break
                if kind == "user" and not sent_user:
                    chan.send(self.username + "\r")
                    sent_user = True
                    started = True
                    just_sent = self.username
                    buf = buf[end:]
                    break
                if kind in ("pass", "pass_ask") and not sent_pass:
                    chan.send(self.password + "\r")
                    sent_pass = True
                    started = True
                    just_sent = self.password
                    buf = buf[end:]
                    break
                buf = buf[end:]
        raise TimeoutError("timed out waiting for the card prompt")

    def _rejected_name(self, buf: str) -> str:
        return "the card rejected the new administrator name. Card said: " + self._card_said(buf)

    def _rejected_password(self, buf: str) -> str:
        return "the card rejected the new administrator password. Card said: " + self._card_said(buf)

    def _card_said(self, buf: str) -> str:
        shown = " ".join(self._safe(buf).split())
        if len(shown) > 180:
            shown = shown[:180]
        return shown or "the prompt returned"

    def _read(self, done, timeout: float) -> str:
        deadline = time.time() + timeout
        buf = ""
        quiet_at = None
        while time.time() < deadline:
            chunk = self._recv(0.4)
            if chunk:
                buf += chunk
                quiet_at = time.time()
            if done(buf) and quiet_at is not None and time.time() - quiet_at >= 0.3:
                return buf
        raise TimeoutError("timed out waiting for the card prompt")

    def _recv(self, wait: float) -> str:
        chan = self._chan
        if chan is None:
            return ""
        chan.settimeout(wait)
        try:
            data = chan.recv(8192)
        except socket.timeout:
            return ""
        except EOFError:
            return ""
        if not data:
            return ""
        return data.decode("utf-8", "replace").replace("\x00", "")

    def _remember(self, secret: str) -> None:
        if secret and secret not in self._secrets:
            self._secrets.append(secret)

    def _safe(self, text: str) -> str:
        for secret in self._secrets:
            if secret:
                text = text.replace(secret, "********")
        return text[:IDENTITY_SHOW_LIMIT]

    @staticmethod
    def _prompt_count(buf: str) -> int:
        return len(_PROMPT.findall(buf))


def _card_reject_message(command: str, text: str) -> str:
    """Name the command the card refused.

    A bad argument prints the sys help, and that help ends with the reset
    line. The reset line is not the command that was sent.
    """
    if "Parameter Error" in text:
        reason = "Parameter Error"
    elif "Command not found" in text:
        reason = "Command not found"
    else:
        reason = "the card does not know this command"
    shown = " ".join(str(command or "").split())
    if len(shown) > 180:
        shown = shown[:180]
    return shown + " was rejected (" + reason + "). The card was not reset."


_IAC, _DO, _DONT, _WILL, _WONT, _SB, _SE = 255, 253, 254, 251, 252, 250, 240


def _telnet_eat(sock, data: bytes) -> bytes:
    """Answer telnet options and return the text bytes."""
    out = bytearray()
    replies = bytearray()
    index = 0
    while index < len(data):
        if data[index] != _IAC or index + 1 >= len(data):
            if data[index] != _IAC:
                out.append(data[index])
            index += 1
            continue
        cmd = data[index + 1]
        if cmd in (_DO, _DONT, _WILL, _WONT) and index + 2 < len(data):
            opt = data[index + 2]
            reply = {_WILL: _DO, _DO: _WILL, _DONT: _WONT, _WONT: _DONT}[cmd]
            replies.extend((_IAC, reply, opt))
            index += 3
            continue
        if cmd == _SB:
            end = data.find(bytes((_IAC, _SE)), index + 2)
            index = len(data) if end < 0 else end + 2
            continue
        index += 2
    if replies:
        sock.sendall(replies)
    return bytes(out)


def _telnet_read(sock, marker: str, seconds: float) -> str:
    end = time.time() + seconds
    buf = ""
    while time.time() < end and marker not in buf:
        sock.settimeout(0.4)
        try:
            chunk = sock.recv(4096)
        except socket.timeout:
            continue
        if not chunk:
            break
        buf += _telnet_eat(sock, chunk).decode("utf-8", "replace").replace("\x00", "")
    return buf


def enable_ssh_on_console(sock, user: str, password: str) -> None:
    """A factory card serves telnet and leaves SSH closed. Enable SSH, then exit."""
    banner = _telnet_read(sock, "Login Name:", 8)
    if "Login Name:" not in banner:
        raise RuntimeError("the card did not ask for a telnet login")
    sock.sendall(user.encode() + b"\r")
    asked = _telnet_read(sock, "Login Password:", 8)
    if "Login Password:" not in asked:
        raise RuntimeError("the card did not ask for the telnet password")
    sock.sendall(password.encode() + b"\r")
    shell = _telnet_read(sock, "CyberPower >", 12)
    if "CyberPower >" not in shell:
        raise RuntimeError("telnet did not reach the command prompt")
    sock.sendall(b"console ssh enable\r")
    reply = _telnet_read(sock, "CyberPower >", 8)
    if "Parameter Error" in reply or "Command not found" in reply or "CyberPower >" not in reply:
        raise RuntimeError("the card did not enable SSH")
    sock.sendall(b"exit\r")


def enable_ssh_via_telnet(host: str, user: str, password: str) -> None:
    sock = socket.create_connection((host, 23), timeout=12)
    try:
        enable_ssh_on_console(sock, user, password)
    finally:
        sock.close()
    deadline = time.time() + 15
    last: Exception | None = None
    while time.time() < deadline:
        try:
            probe = socket.create_connection((host, 22), timeout=3)
            probe.close()
            return
        except OSError as exc:
            last = exc
            time.sleep(1)
    raise RuntimeError("SSH did not open after it was enabled") from last
