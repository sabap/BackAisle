"""SCP restore of YYYY_MM_DD_HHMM.txt to user@ip: (RMCARD reboots after 100%)."""
from __future__ import annotations

import shutil
from pathlib import Path


def scp_put_config(host: str, user: str, password: str, local_path: Path, remote_name: str, simulate: bool = False) -> str:
    if simulate:
        return f"SIMULATE scp {remote_name} {user}@{host}:"
    import os
    import subprocess
    import tempfile

    # Card rejects `scp -t filename.txt` ("key exchange failed"). Dest must be `user@host:`
    # so OpenSSH runs `scp -t .` and the local basename is YYYY_MM_DD_HHMM.txt.
    staged = Path(tempfile.gettempdir()) / remote_name
    shutil.copyfile(local_path, staged)
    ask = Path(tempfile.gettempdir()) / "ba_askpass.cmd"
    # cmd echo: disable delayed expansion so '!' in the web password is literal
    ask.write_text(
        "@echo off\r\nsetlocal DisableDelayedExpansion\r\necho " + password + "\r\n",
        encoding="ascii",
        newline="\r\n",
    )
    env = os.environ.copy()
    env["DISPLAY"] = "unused:0"
    env["SSH_ASKPASS"] = str(ask)
    env["SSH_ASKPASS_REQUIRE"] = "force"
    cmd = [
        r"C:\Windows\System32\OpenSSH\scp.exe",
        "-O",
        "-o", "StrictHostKeyChecking=no",
        "-o", "UserKnownHostsFile=NUL",
        "-o", "PreferredAuthentications=keyboard-interactive,password",
        "-o", "NumberOfPasswordPrompts=1",
        "-o", "HostKeyAlgorithms=+ssh-rsa,ssh-ed25519",
        "-o", "PubkeyAcceptedAlgorithms=+ssh-rsa",
        "-o", "PubkeyAcceptedKeyTypes=+ssh-rsa",
        "-o", "KexAlgorithms=+diffie-hellman-group-exchange-sha256,diffie-hellman-group14-sha256,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1",
        "-o", "Ciphers=aes128-ctr,aes256-ctr,aes128-cbc,aes256-cbc",
        "-o", "MACs=hmac-sha2-256,hmac-sha2-512",
        str(staged),
        f"{user}@{host}:",
    ]
    try:
        proc = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=90, stdin=subprocess.DEVNULL)
        if proc.returncode != 0:
            raise RuntimeError(f"scp exit {proc.returncode}: {proc.stderr.strip() or proc.stdout.strip()}")
    finally:
        for p in (ask, staged):
            try:
                p.unlink()
            except OSError:
                pass
    return f"SCP put {remote_name} to {user}@{host}:"
