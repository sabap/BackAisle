"""UDM-Pro interface map.

ifIndex 3, 4, and 5 are eth9, eth8, and eth10 on the UDM-Pro kernel order.
Later indexes move when a VLAN or a tunnel is added, so those stay off this map.
"""
from __future__ import annotations

IF_NAME_BASE = (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1)
_HC_IN = (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 6)
_HC_OUT = (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 10)

# RJ45 WAN is port 9, SFP WAN is port 10, SFP LAN is port 11.
UDM_PORT_NAMES = {3: "eth9", 4: "eth8", 5: "eth10"}


def _parts(oid: str) -> tuple[int, ...] | None:
    nums = []
    for piece in str(oid).strip().lstrip(".").split("."):
        if piece == "":
            continue
        if not piece.isdigit():
            return None
        nums.append(int(piece))
    return tuple(nums)


def udm_counter_index(oid: str) -> int | None:
    parts = _parts(oid)
    if parts is None or len(parts) != 12:
        return None
    if parts[:-1] not in (_HC_IN, _HC_OUT):
        return None
    if parts[-1] not in UDM_PORT_NAMES:
        return None
    return parts[-1]


def udm_counter_action(oid: str, names: dict[int, str] | None) -> str:
    """keep, clear, or skip a UDM counter field.

    names is ifIndex to ifName. None means the name read timed out, so the
    stored counter stays. A different name clears the row instead of labeling
    the wrong port.
    """
    idx = udm_counter_index(oid)
    if idx is None:
        return "keep"
    if names is None:
        return "skip"
    if str(names.get(idx, "")).strip() == UDM_PORT_NAMES[idx]:
        return "keep"
    return "clear"
