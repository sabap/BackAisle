"""SCP restore of YYYY_MM_DD_HHMM.txt to user@ip: (RMCARD reboots after 100%)."""
from __future__ import annotations

from pathlib import Path


def scp_put_config(host: str, user: str, password: str, local_path: Path, remote_name: str, simulate: bool = False) -> str:
    if simulate:
        return f"SIMULATE scp {local_path.name} {user}@{host}:{remote_name}"
    import os
    import subprocess
    import tempfile

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
        "-o", "HostKeyAlgorithms=+ssh-rsa",
        "-o", "PubkeyAcceptedAlgorithms=+ssh-rsa",
        "-o", "PubkeyAcceptedKeyTypes=+ssh-rsa",
        "-c", "aes128-cbc",
        str(local_path),
        f"{user}@{host}:{remote_name}",
    ]
    try:
        proc = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=90, stdin=subprocess.DEVNULL)
        if proc.returncode != 0:
            raise RuntimeError(f"scp exit {proc.returncode}: {proc.stderr.strip() or proc.stdout.strip()}")
    finally:
        try:
            ask.unlink()
        except OSError:
            pass
    return f"SCP put {remote_name} to {user}@{host}:"
