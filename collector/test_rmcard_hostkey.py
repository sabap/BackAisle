"""Host-key offer for an RMCARD. No network and no secrets."""
import paramiko
from paramiko.rsakey import RSAKey

from rmcard_cli import _allow_ssh_rsa_host_key, _card_reject_message


class _DeadSock:
    def settimeout(self, _timeout):
        return None

    def send(self, data):
        return 0

    def recv(self, _size):
        return b""

    def close(self):
        return None


transport = paramiko.Transport(_DeadSock())
try:
    _allow_ssh_rsa_host_key(transport)
    offered = transport.preferred_keys
    if "ssh-rsa" not in offered:
        raise SystemExit("ssh-rsa host key was not offered")
    if offered.index("rsa-sha2-256") > offered.index("ssh-rsa"):
        raise SystemExit("ssh-rsa ranked ahead of rsa-sha2-256")
    if transport._key_info.get("ssh-rsa") is not RSAKey:
        raise SystemExit("ssh-rsa was not checked as an RSA key")
    if "ssh-rsa" not in RSAKey.HASHES:
        raise SystemExit("ssh-rsa signature could not be checked")
    _allow_ssh_rsa_host_key(transport)
    if transport.preferred_keys.count("ssh-rsa") != 1:
        raise SystemExit("ssh-rsa was offered more than once")
finally:
    transport.close()

_help = """\
CyberPower > sys contact Facilities Team
                         ^
Parameter Error

sys : Show or configure System
  contact : Set the person to contact about this equipment
  reset : Reset or reboot Remote Management Card
CyberPower >
"""
_msg = _card_reject_message('sys contact "Facilities Team"', _help)
if "Reset or reboot Remote Management Card" in _msg:
    raise SystemExit("reject message used the reset help line: " + _msg)
if "Parameter Error" not in _msg or "was not reset" not in _msg or 'sys contact "Facilities Team"' not in _msg:
    raise SystemExit("reject message drifted: " + _msg)

print("rmcard host key ok")
