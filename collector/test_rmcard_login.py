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


def test_save_does_not_walk_the_card_site() -> None:
    sess = _session()
    calls = []
    body = b"CyberPowerSystems, RMCARD205\nSYSTEM_IP_ADDRESS, 10.1.1.1\nNTP_ENABLE, Enabled\n"

    def fetch(path, data=None, timeout=30):
        calls.append(path)
        if path == "get_set.cgi?getset=Save":
            return (
                "https://card/save",
                200,
                {"content-disposition": "attachment; filename=2026_11_23_0000.txt"},
                body,
            )
        raise AssertionError(path)

    sess.fetch = fetch
    _data, name = sess.download_config()
    if calls != ["get_set.cgi?getset=Save"]:
        raise AssertionError(repr(calls))
    if not name.endswith(".txt"):
        raise AssertionError(name)


def test_save_stops_after_known_urls() -> None:
    sess = _session()
    calls = []

    def fetch(path, data=None, timeout=30):
        calls.append(path)
        return ("https://card/x", 200, {}, b"<html href='summary.cgi'></html>")

    sess.fetch = fetch
    try:
        sess.download_config()
        raise AssertionError("save should fail")
    except RuntimeError as exc:
        if "Save Configuration" not in str(exc):
            raise
    if "about.html" in calls or len(calls) > 4:
        raise AssertionError(repr(calls))


def test_deadline_stops_before_login() -> None:
    sess = _session()
    calls: list[str] = []

    def fetch(path, data=None, timeout=30):
        calls.append(path)
        return ("https://card/" + path, 200, {}, b"ok")

    sess.fetch = fetch
    try:
        sess.login(tries=1, deadline=rmcard_http.time.monotonic() - 1)
        raise AssertionError("login should stop")
    except rmcard_http.CardReadDeadline:
        pass
    if calls:
        raise AssertionError(repr(calls))


def test_deadline_stops_before_save() -> None:
    sess = _session()
    calls: list[str] = []

    def fetch(path, data=None, timeout=30):
        calls.append(path)
        return ("https://card/save", 200, {}, b"CyberPowerSystems, RMCARD205\n")

    sess.fetch = fetch
    try:
        sess.download_config(deadline=rmcard_http.time.monotonic() - 1)
        raise AssertionError("save should stop")
    except rmcard_http.CardReadDeadline:
        pass
    if calls:
        raise AssertionError(repr(calls))


def test_timed_login_tries_one_logout() -> None:
    sess = _session()
    calls: list[str] = []

    def fetch(path, data=None, timeout=30):
        calls.append(path)
        if path == "logout.html":
            raise OSError("down")
        if path == "login.cgi?action=LOGIN":
            return ("https://card/summary.html", 200, {}, b"summary.html")
        return ("https://card/" + path, 200, {}, b"ok")

    sess.fetch = fetch
    sess.login(tries=1, deadline=rmcard_http.time.monotonic() + 30)
    if "logout.cgi" in calls or "login.cgi?action=LOGOUT" in calls:
        raise AssertionError(repr(calls))
    if "logout.html" not in calls:
        raise AssertionError(repr(calls))


def test_config_name_uses_the_card_minute_first() -> None:
    from datetime import datetime, timezone
    from rmcard_ftp import config_pull_names, parse_snmp_date

    card = datetime(2026, 11, 23, 14, 46)
    local = datetime(2026, 11, 23, 9, 10)
    utc = datetime(2026, 11, 23, 19, 46, tzinfo=timezone.utc)
    names = config_pull_names(card, local, utc)
    if names[0] != "2026_11_23_1446.txt":
        raise AssertionError(repr(names))
    if "2026_11_23_1445.txt" not in names or "2026_11_23_1447.txt" not in names:
        raise AssertionError(repr(names))
    if "2026_11_23_0910.txt" not in names or "2026_11_23_1946.txt" not in names:
        raise AssertionError(repr(names))
    if names.index("2026_11_23_1946.txt") > names.index("2026_11_23_0910.txt"):
        raise AssertionError(repr(names))
    if names.index("2026_11_23_1946.txt") > names.index("2026_11_23_1445.txt"):
        raise AssertionError(repr(names))
    found = parse_snmp_date("0x07ea0b170e2e0000")
    if found != card:
        raise AssertionError(repr(found))
    text = parse_snmp_date("2026-11-23,14:46:13.0,-5:0")
    if text != card:
        raise AssertionError(repr(text))


def test_ftp_lists_real_text_names_only() -> None:
    from rmcard_ftp import ftp_text_names

    names = ftp_text_names([
        "cpsrm2scfw.bin",
        "folder/2026_11_23_0101.txt",
        "../secret.txt",
        "notes.TXT",
        "2026_11_23_0101.txt",
    ])
    if names != ["2026_11_23_0101.txt", "notes.TXT"]:
        raise AssertionError(repr(names))


def test_ftp_timeout_tries_the_next_clock() -> None:
    import writer
    from datetime import datetime as real_datetime
    from datetime import timezone as real_timezone

    calls: list[str] = []
    opened = []
    body = b"CyberPowerSystems, RMCARD205\nSYSTEM_IP_ADDRESS, 10.1.1.1\n"

    class Frozen(real_datetime):
        @classmethod
        def now(cls, tz=None):
            if tz is not None:
                return real_datetime(2026, 11, 23, 19, 46, tzinfo=real_timezone.utc)
            return real_datetime(2026, 11, 23, 9, 10)

    def fake_ftp(ip, user, pw, names, timeout=6):
        calls.append(names[0])
        if names[0] == "2026_11_23_1946.txt":
            raise TimeoutError("timed out")
        return body, names[0]

    class NoWeb:
        def __init__(self, *args, **kwargs):
            opened.append(args)

    old = (writer.datetime, writer.ftp_get_named_config, writer.card_clock, writer.RmcardSession)
    writer.datetime = Frozen
    writer.ftp_get_named_config = fake_ftp
    writer.card_clock = lambda ip, secrets: None
    writer.RmcardSession = NoWeb
    try:
        data, name = writer.pull_config_bytes("10.1.1.1", {})
    finally:
        writer.datetime, writer.ftp_get_named_config, writer.card_clock, writer.RmcardSession = old
    if opened:
        raise AssertionError(repr(opened))
    if calls[:2] != ["2026_11_23_1946.txt", "2026_11_23_0910.txt"]:
        raise AssertionError(repr(calls))
    if name != "2026_11_23_0910.txt" or b"RMCARD205" not in data:
        raise AssertionError(repr(name))


def test_factory_login_enables_ftp_then_signs_out() -> None:
    import writer

    seen: list[str] = []

    class Fake:
        def __init__(self, ip, user, password, timeout=20, new_user=None, new_password=None):
            if ip != "10.1.1.1" or not user or not password:
                raise AssertionError("missing login")
            self.account_changed = False

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return None

        def run(self, command):
            seen.append(command)
            return "ok"

        def sign_out(self):
            seen.append("exit")

    def waited(ip, user, password, timeout_s=45):
        seen.append("wait")
        if user != "card" or timeout_s != 45:
            raise AssertionError(user)

    changed = writer.enable_factory_ftp("10.1.1.1", "card", "secret", opener=Fake, wait=waited)
    if changed or seen != ["ftp access enable", "exit", "wait"]:
        raise AssertionError(repr(seen))


def test_factory_login_reads_with_the_new_account() -> None:
    import writer

    seen: list[str] = []

    class Fake:
        def __init__(self, ip, user, password, timeout=20, new_user=None, new_password=None):
            self.account_changed = True

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return None

        def run(self, command):
            seen.append(command)
            return "ok"

        def sign_out(self):
            seen.append("exit")

    def waited(ip, user, password, timeout_s=45):
        seen.append("ftp:" + user)
        if password != "next-secret" or timeout_s != 45:
            raise AssertionError("ftp login")

    changed = writer.enable_factory_ftp(
        "10.1.1.1", "card", "secret", "ops", "next-secret", opener=Fake, wait=waited
    )
    if not changed or seen != ["ftp access enable", "exit", "ftp:ops"]:
        raise AssertionError(repr(seen))


def _scripted_cli(chunks: list[str], new_user: str = "", new_password: str = ""):
    from rmcard_cli import RmcardCli

    sent: list[str] = []
    pending = list(chunks)

    class Chan:
        def send(self, data):
            sent.append(data)

    cli = RmcardCli(
        "10.1.1.1",
        "card",
        "secret",
        new_user=new_user or None,
        new_password=new_password or None,
    )
    cli._chan = Chan()

    def recv(_wait):
        if not pending:
            return ""
        return pending.pop(0)

    cli._recv = recv
    return cli, sent


def test_first_login_answers_new_user_name() -> None:
    cli, sent = _scripted_cli(
        [
            "CyberPower SystemsCommand Shell v1.0\r\nNew User Name:",
            "New Password:",
            "Confirm Password:",
            "CyberPower > ",
        ],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if sent != ["ops\r", "next-secret\r", "next-secret\r"]:
        raise AssertionError(repr(sent))
    if not cli.account_changed:
        raise AssertionError("account was not marked changed")


def test_first_login_without_a_saved_name_does_not_send_the_factory_user() -> None:
    cli, sent = _scripted_cli(
        ["CyberPower SystemsCommand Shell v1.0\r\nNew User Name:"],
    )
    try:
        cli._reach_prompt()
    except RuntimeError as exc:
        text = str(exc)
    else:
        raise AssertionError("expected a refusal")
    if "Assign a Web/SSH/FTP login on this UPS first." not in text:
        raise AssertionError(text)
    if sent != []:
        raise AssertionError(repr(sent))


def test_first_login_accepts_a_plain_password_prompt() -> None:
    cli, sent = _scripted_cli(
        [
            "New User Name:",
            "Password:",
            "Password:",
            "CyberPower > ",
        ],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if sent != ["ops\r", "next-secret\r", "next-secret\r"] or not cli.account_changed:
        raise AssertionError(repr(sent))


def test_shell_login_keeps_the_session_account() -> None:
    cli, sent = _scripted_cli(
        ["User Name:", "Password:", "CyberPower > "],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if cli.account_changed or sent != ["card\r", "secret\r"]:
        raise AssertionError(repr((cli.account_changed, sent)))


def test_plain_prompt_does_not_change_the_account() -> None:
    cli, sent = _scripted_cli(
        ["CyberPower SystemsCommand Shell v1.0\r\nCyberPower > "],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if cli.account_changed or sent != []:
        raise AssertionError(repr((cli.account_changed, sent)))


def test_second_password_prompt_is_the_confirmation() -> None:
    cli, sent = _scripted_cli(
        [
            "New User Name:\r\n",
            "New Password:\r\n",
            "Enter new password again:\r\n",
            "CyberPower > \r\n",
        ],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if sent != ["ops\r", "next-secret\r", "next-secret\r"] or not cli.account_changed:
        raise AssertionError(repr(sent))


def test_echoed_password_prompt_is_not_a_rejection() -> None:
    cli, sent = _scripted_cli(
        [
            "New User Name:",
            "New Password:",
            "New Password: next-secret\r\nConfirm Password:",
            "CyberPower > ",
        ],
        "ops",
        "next-secret",
    )
    cli._reach_prompt()
    if sent != ["ops\r", "next-secret\r", "next-secret\r"]:
        raise AssertionError(repr(sent))


def test_password_error_quotes_the_card_and_hides_the_secret() -> None:
    cli, _sent = _scripted_cli(
        [
            "New User Name:",
            "New Password:",
            "Password Error\r\nNew Password:",
        ],
        "ops",
        "next-secret",
    )
    try:
        cli._reach_prompt()
    except RuntimeError as exc:
        text = str(exc)
    else:
        raise AssertionError("expected a refusal")
    if "rejected the new administrator password" not in text or "Password Error" not in text:
        raise AssertionError(text)
    if "next-secret" in text:
        raise AssertionError("secret leaked")


def test_rejected_administrator_password_is_not_retried() -> None:
    import writer

    text = (
        "could not enable FTP over SSH: the card rejected the new "
        "administrator password. Card said: Password Error"
    )
    if writer.is_retryable(RuntimeError(text)):
        raise AssertionError(text)


def test_second_new_user_prompt_stops() -> None:
    cli, sent = _scripted_cli(
        [
            "New User Name:",
            "New User Name:",
        ],
        "ops",
        "next-secret",
    )
    try:
        cli._reach_prompt()
    except RuntimeError as exc:
        text = str(exc)
    else:
        raise AssertionError("expected a refusal")
    if "rejected the new administrator name" not in text:
        raise AssertionError(text)
    if sent != ["ops\r"]:
        raise AssertionError(repr(sent))


def test_password_lines_are_not_sent_in_one_burst() -> None:
    events: list[str] = []
    cli, sent = _scripted_cli(
        [
            "New User Name:\r\nNew Password:\r\nAdmin Confirm Password:\r\n",
            "",
            "",
            "CyberPower > ",
        ],
        "ops",
        "next-secret",
    )
    original_recv = cli._recv
    original_send = cli._chan.send

    def recv(wait):
        events.append("recv")
        return original_recv(wait)

    def send(data):
        events.append("send:" + data)
        original_send(data)

    cli._recv = recv
    cli._chan.send = send
    cli._reach_prompt()
    if sent != ["ops\r", "next-secret\r", "next-secret\r"]:
        raise AssertionError(repr(sent))
    first = events.index("send:next-secret\r")
    second = events.index("send:next-secret\r", first + 1)
    if "recv" not in events[first + 1 : second]:
        raise AssertionError(repr(events))


def test_confirm_mismatch_does_not_send_another_password() -> None:
    cli, sent = _scripted_cli(
        [
            "New User Name:",
            "New Password:",
            "Admin Confirm Password:",
            "Admin Confirm Password has been entered incorrectly. New Password:",
        ],
        "ops",
        "next-secret",
    )
    try:
        cli._reach_prompt()
    except RuntimeError as exc:
        text = str(exc)
    else:
        raise AssertionError("expected a refusal")
    if "entered incorrectly" not in text or "next-secret" in text:
        raise AssertionError(text)
    if sent != ["ops\r", "next-secret\r", "next-secret\r"]:
        raise AssertionError(repr(sent))


def test_silent_shell_presses_enter_once() -> None:
    cli, sent = _scripted_cli(["", "CyberPower > "], "ops", "next-secret")
    cli._reach_prompt()
    if cli.account_changed or sent != ["\r"]:
        raise AssertionError(repr((cli.account_changed, sent)))


def test_read_failure_is_not_retried() -> None:
    import writer

    samples = (
        "could not read the card config: timed out",
        "card read deadline",
        "job cancelled",
    )
    for text in samples:
        if writer.is_retryable(RuntimeError(text)):
            raise AssertionError(text)


if __name__ == "__main__":
    test_retry_logs_out_before_the_next_login()
    test_failed_login_logs_out()
    test_save_does_not_walk_the_card_site()
    test_save_stops_after_known_urls()
    test_deadline_stops_before_login()
    test_deadline_stops_before_save()
    test_timed_login_tries_one_logout()
    test_config_name_uses_the_card_minute_first()
    test_ftp_timeout_tries_the_next_clock()
    test_factory_login_enables_ftp_then_signs_out()
    test_factory_login_reads_with_the_new_account()
    test_first_login_answers_new_user_name()
    test_first_login_accepts_a_plain_password_prompt()
    test_first_login_without_a_saved_name_does_not_send_the_factory_user()
    test_shell_login_keeps_the_session_account()
    test_plain_prompt_does_not_change_the_account()
    test_second_password_prompt_is_the_confirmation()
    test_echoed_password_prompt_is_not_a_rejection()
    test_password_error_quotes_the_card_and_hides_the_secret()
    test_rejected_administrator_password_is_not_retried()
    test_second_new_user_prompt_stops()
    test_password_lines_are_not_sent_in_one_burst()
    test_confirm_mismatch_does_not_send_another_password()
    test_silent_shell_presses_enter_once()
    test_ftp_lists_real_text_names_only()
    test_read_failure_is_not_retried()
    print("ok")
