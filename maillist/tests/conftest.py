import io
import sys
import textwrap
from pathlib import Path

import pytest

import maillist
import maillist_config
import maillist_delivery

CONFIG_TEXT = textwrap.dedent(
    """\
    [database]
    host = db.example.test
    user = listreader
    password = secret%with%percent

    [list]
    owner = owner@example.org
    error_report_to = admins@example.org
    error_report_from = maillist@example.org

    [log]
    file = {log_file}
    """
)


@pytest.fixture
def config_file(tmp_path: Path) -> Path:
    path = tmp_path / "maillist.ini"
    path.write_text(CONFIG_TEXT.format(log_file=tmp_path / "maillist.log"))
    return path


@pytest.fixture
def settings(config_file: Path) -> maillist_config.Settings:
    return maillist_config.load_settings(str(config_file))


# --- helpers for tests that drive maillist.main() ---------------------------

RAW_MAIL = (
    b"From: Author <author@example.net>\r\n"
    b"To: list@example.org\r\n"
    b"Delivered-To: list.example.org@maillist.invalid\r\n"
    b"Subject: Hello\r\n"
    b"Message-Id: <m1@example.net>\r\n"
    b"\r\n"
    b"Body\r\n"
)


class FakeCursor:
    def __init__(self, rows, calls):
        self.rows, self.calls = rows, calls

    def execute(self, query, params=()):
        self.calls.append((query, params))

    def fetchone(self):
        return self.rows[0] if self.rows else None

    def fetchall(self):
        return list(self.rows)

    def close(self):
        pass


class FakeConnection:
    def __init__(self, rows):
        self.rows, self.calls = rows, []

    def cursor(self):
        return FakeCursor(self.rows, self.calls)

    def close(self):
        pass


class RecordingSMTP:
    """Fake smtplib.SMTP. Subclass it to script failures."""

    instances: list["RecordingSMTP"] = []

    def __init__(self, host, port, timeout=None):
        self._host = host
        self.port = port
        self.timeout = timeout
        self.sent: list[tuple[str, object, bytes]] = []
        self.messages = []
        self.tls_context = None
        self.tls_server_hostname = None
        self.starttls_called = False
        RecordingSMTP.instances.append(self)

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def close(self):
        pass

    def starttls(self, context=None):
        self.starttls_called = True
        self.tls_context = context
        self.tls_server_hostname = self._host

    def sendmail(self, from_addr, to_addrs, message):
        self.sent.append((from_addr, to_addrs, message))
        return {}

    def send_message(self, message):
        self.messages.append(message)


@pytest.fixture
def run_main(monkeypatch, config_file):
    def run(
        argv,
        rows=(("TAG", "list@example.org", "a@example.org\nb@example.org"),),
        raw=RAW_MAIL,
        connect=None,
        smtp=RecordingSMTP,
    ):
        RecordingSMTP.instances.clear()
        connection = FakeConnection(list(rows))
        monkeypatch.setenv("MAILLIST_CONFIG", str(config_file))
        monkeypatch.setattr(sys, "stdin", io.TextIOWrapper(io.BytesIO(raw)))
        monkeypatch.setattr(maillist.mysql.connector, "connect", connect or (lambda **_: connection))
        monkeypatch.setattr(maillist_delivery.smtplib, "SMTP", smtp)
        code = maillist.main(argv)
        return code, connection, RecordingSMTP.instances

    return run
