"""A profile apply keeps this card's address and does not copy the source card."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from config_file import apply_profile_keep_network

_TEMPLATE = """CyberPowerSystems, RMCARD205
SYSTEM_IP_ADDRESS, 10.1.1.1
SYSTEM_GATEWAY_ADDRESS, 10.1.1.254
SYSTEM_SUBMASK_ADDRESS, 255.255.255.0
SYSTEM_HOST_NAME, Other-UPS
SYSTEM_NAME, Other-UPS
MAC_ADDRESS, 00:11:22:33:44:55
SNMPv3_ACCESS_IPADDR1, 10.9.9.9
NTP_ENABLE, Enabled
"""

_CURRENT = """CyberPowerSystems, RMCARD205
SYSTEM_IP_ADDRESS, 10.1.1.2
SYSTEM_GATEWAY_ADDRESS, 10.1.1.1
SYSTEM_SUBMASK_ADDRESS, 255.255.0.0
SYSTEM_HOST_NAME, This-UPS
SYSTEM_NAME, This-UPS
MAC_ADDRESS, AA:BB:CC:DD:EE:FF
SNMPv3_ACCESS_IPADDR1, 10.0.0.1
"""


def _expect(label: str, cond: bool) -> None:
    if not cond:
        raise SystemExit(label)


out = apply_profile_keep_network(_TEMPLATE, _CURRENT, "10.1.1.2", "This-UPS")
_expect("inventory address", "SYSTEM_IP_ADDRESS, 10.1.1.2" in out)
_expect("source address copied", "10.1.1.1" not in out or "SYSTEM_GATEWAY_ADDRESS, 10.1.1.1" in out)
_expect("source address line", "SYSTEM_IP_ADDRESS, 10.1.1.1" not in out)
_expect("source gateway", "10.1.1.254" not in out)
_expect("this gateway", "SYSTEM_GATEWAY_ADDRESS, 10.1.1.1" in out)
_expect("this mask", "SYSTEM_SUBMASK_ADDRESS, 255.255.0.0" in out)
_expect("source mac", "00:11:22:33:44:55" not in out)
_expect("this mac", "MAC_ADDRESS, AA:BB:CC:DD:EE:FF" in out)
_expect("profile snmp", "SNMPv3_ACCESS_IPADDR1, 10.9.9.9" in out)
_expect("profile ntp", "NTP_ENABLE, Enabled" in out)
_expect("this name", "SYSTEM_HOST_NAME, This-UPS" in out)
_expect("source name", "Other-UPS" not in out)

_bare = apply_profile_keep_network(_TEMPLATE, "CyberPowerSystems, RMCARD205\n", "10.1.1.2", "This-UPS")
_expect("omitted source gateway", "SYSTEM_GATEWAY_ADDRESS" not in _bare)
_expect("omitted source mac", "MAC_ADDRESS" not in _bare)
_expect("forced address", "SYSTEM_IP_ADDRESS, 10.1.1.2" in _bare)
_expect("snmp still applied", "SNMPv3_ACCESS_IPADDR1, 10.9.9.9" in _bare)
print("ok")
