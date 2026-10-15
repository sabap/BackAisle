"""One SSH session to a CyberPower RMCARD command shell. Keyboard-interactive login."""
from __future__ import annotations

import re
import socket
import time

import paramiko

from card_commands import _LINE

_PROMPT = re.compile(r"(?m)^CyberPower >\s*$")
_USER = re.compile(r"user\s*name\s*:", re.I)
_PASS = re.compile(r"password\s*:", re.I)


class RmcardCli:
    def __init__(self, host: str, username: str, password: str, timeout: float = 20):
        self.host = host
        self.username = username
        self.password = password
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
        chan.send(command + "\r")
        text = self._read(lambda buf: self._prompt_count(buf) > before, self.timeout)
        if "Parameter Error" in text or "Command not found" in text or "doesn't know this command" in text:
            raise RuntimeError(self._safe(_one_line(text)))
        return text

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
        deadline = time.time() + self.timeout
        buf = ""
        chan.send("\r")
        while time.time() < deadline:
            chunk = self._recv(0.6)
            if chunk:
                buf += chunk
            if _PROMPT.search(buf):
                return
            if _USER.search(buf) and not sent_user:
                chan.send(self.username + "\r")
                sent_user = True
                buf = ""
                continue
            if _PASS.search(buf) and not sent_pass:
                chan.send(self.password + "\r")
                sent_pass = True
                buf = ""
                continue
        raise TimeoutError("timed out waiting for the card prompt")

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

    def _safe(self, text: str) -> str:
        if self.password:
            text = text.replace(self.password, "********")
        return text[:400]

    @staticmethod
    def _prompt_count(buf: str) -> int:
        return len(_PROMPT.findall(buf))


def _one_line(text: str) -> str:
    parts = [part.strip() for part in text.splitlines() if part.strip()]
    useful = [part for part in parts if not part.startswith("CyberPower >")]
    return (useful[-1] if useful else "Parameter Error")[:300]
