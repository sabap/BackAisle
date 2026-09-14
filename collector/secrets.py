from __future__ import annotations

import os
from pathlib import Path

CANDIDATES = [
    Path(os.environ.get("BACKAISLE_SECRETS", "")),
    Path(r"C:\ProgramData\BackAisle\secrets.env"),
    Path(r"C:\inetpub\BackAisle\secrets.env"),
]


def load_secrets() -> dict[str, str]:
    out: dict[str, str] = {}
    for path in CANDIDATES:
        if not path or not path.is_file():
            continue
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            out[k.strip()] = v.strip()
        break
    return out
