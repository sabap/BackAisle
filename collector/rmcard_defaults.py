"""Factory local login on a new RMCARD.

Administrator is the SSH, web, and FTP account. Viewer is the read-only web
account. Neither value is an SNMPv3 user. A later new-unit deploy signs in
with the administrator pair and can push a default configuration.
"""

RMCARD_FACTORY_USER = "cyber"
RMCARD_FACTORY_PASS = "cyber"
RMCARD_FACTORY_VIEWER = "device"
RMCARD_FACTORY_VIEWER_PASS = "cyber"


def factory_admin() -> tuple[str, str]:
    """Username and password on a card that is still at factory local login."""
    return RMCARD_FACTORY_USER, RMCARD_FACTORY_PASS


def factory_viewer() -> tuple[str, str]:
    """Read-only web account shipped on a new card."""
    return RMCARD_FACTORY_VIEWER, RMCARD_FACTORY_VIEWER_PASS
