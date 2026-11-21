"""RMCARD login must not keep the card's only web session for itself."""
from __future__ import annotations

import rmcard_http

rmcard_http.time.sleep = lambda *_a, **_k: None


def _session():
    sess = rmcard_http.RmcardSession.__new__(rmcard_http.RmcardSession)
    sess.user = "user"
    sess.password = "secret"
    return sess


def test_retry_logs_out_before_the_next_login() -> None:
    sess = _session()
    events: list[str] = []
    logins = {"n": 0}

    def fetch(path, data=None, timeout=30):
        events.append(path)
        if path == "login.cgi?action=LOGIN":
            logins["n"] += 1
            if logins["n"] == 1:
                return (
                    "https://card/error.html",
                    200,
                    {},
                    b"Someone is currently logged into the Web Server",
                )
            return ("https://card/summary.html", 200, {}, b"summary.html")
        return ("https://card/" + path, 200, {}, b"ok")

    sess.fetch = fetch
    sess.login()
    if events.count("login.cgi?action=LOGIN") < 2:
        raise AssertionError(repr(events))
    first = events.index("login.cgi?action=LOGIN")
    second = events.index("login.cgi?action=LOGIN", first + 1)
    assert "logout.html" in events[first + 1 : second]


def test_failed_login_logs_out() -> None:
    sess = _session()
    events: list[str] = []

    def fetch(path, data=None, timeout=30):
        events.append(path)
        if path == "login.cgi?action=LOGIN":
            return (
                "https://card/error.html",
                200,
                {},
                b"Someone is currently logged into the Web Server",
            )
        return ("https://card/" + path, 200, {}, b"ok")

    sess.fetch = fetch
    try:
        sess.login()
        raise AssertionError("login should fail")
    except RuntimeError as exc:
        text = str(exc)
    assert "already has one web session" in text
    assert "browser tab that says" not in text
    last_login = len(events) - 1 - events[::-1].index("login.cgi?action=LOGIN")
    assert "logout.html" in events[last_login + 1 :]


if __name__ == "__main__":
    test_retry_logs_out_before_the_next_login()
    test_failed_login_logs_out()
    print("ok")
