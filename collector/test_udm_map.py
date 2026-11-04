"""UDM-Pro counter fields stay labeled only when ifName still matches."""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from udm_map import udm_counter_action, udm_counter_index  # noqa: E402

WAN_IN = "1.3.6.1.2.1.31.1.1.1.6.4"
WAN_OUT = "1.3.6.1.2.1.31.1.1.1.10.4"
SFP_IN = "1.3.6.1.2.1.31.1.1.1.6.3"
LAN_IN = "1.3.6.1.2.1.31.1.1.1.6.5"
DESCR = "1.3.6.1.2.1.1.1.0"
NAMES = {3: "eth9", 4: "eth8", 5: "eth10"}


def test_indexes() -> None:
    assert udm_counter_index(WAN_IN) == 4
    assert udm_counter_index(WAN_OUT) == 4
    assert udm_counter_index(SFP_IN) == 3
    assert udm_counter_index(LAN_IN) == 5
    assert udm_counter_index(DESCR) is None
    assert udm_counter_index("1.3.6.1.2.1.31.1.1.1.6.16") is None
    assert udm_counter_index("1.3.6.1.2.1.2.2.1.10.4") is None


def test_confirmed_name_keeps_counter() -> None:
    assert udm_counter_action(WAN_IN, NAMES) == "keep"
    assert udm_counter_action(WAN_OUT, NAMES) == "keep"
    assert udm_counter_action(DESCR, NAMES) == "keep"


def test_wrong_name_clears_counter() -> None:
    shifted = {3: "eth9", 4: "eth10", 5: "eth8"}
    assert udm_counter_action(WAN_IN, shifted) == "clear"
    assert udm_counter_action(LAN_IN, shifted) == "clear"
    assert udm_counter_action(SFP_IN, shifted) == "keep"


def test_timeout_leaves_stored_counter() -> None:
    assert udm_counter_action(WAN_IN, None) == "skip"
    assert udm_counter_action(DESCR, None) == "keep"


if __name__ == "__main__":
    test_indexes()
    test_confirmed_name_keeps_counter()
    test_wrong_name_clears_counter()
    test_timeout_leaves_stored_counter()
    print("UDM_MAP_OK")
