import smtplib
from email.message import EmailMessage

import pytest

import maillist


class FakeSMTP:
    def __init__(self, raise_on_batch: bool = False) -> None:
        self.raise_on_batch = raise_on_batch
        self.calls: list[tuple[str, object, bytes]] = []

    def sendmail(self, from_addr: str, to_addrs: object, message: bytes):
        self.calls.append((from_addr, to_addrs, message))

        if self.raise_on_batch and isinstance(to_addrs, list):
            raise smtplib.SMTPException("batch failed")

        if isinstance(to_addrs, list):
            # Simulate one refused address in batched mode.
            return {"bad@example.com": (550, b"5.1.1 user unknown")}

        return {}


class ContextSMTP:
    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        return False

    def starttls(self) -> None:
        return None


# --- configuration ---------------------------------------------------------


def test_load_settings_reads_values_and_defaults(settings: maillist.Settings) -> None:
    assert settings.database.host == "db.example.test"
    assert settings.database.port == 3306
    assert settings.database.name == "mail"
    assert settings.database.password == "secret%with%percent"
    assert settings.smtp == maillist.SmtpSettings(host="localhost", port=25, starttls=True)
    assert settings.lists.loop_marker == "mailadmin-maillist"
    assert settings.lists.delivered_to_fallback is True
    assert settings.log_level == "INFO"


def test_load_settings_missing_file(tmp_path) -> None:
    with pytest.raises(maillist.ConfigError, match="cannot read"):
        maillist.load_settings(str(tmp_path / "absent.ini"))


def test_load_settings_missing_required_key_names_key_not_value(tmp_path) -> None:
    path = tmp_path / "maillist.ini"
    path.write_text("[database]\nuser = u\npassword = topsecret\n[list]\nowner = o@example.org\n")
    with pytest.raises(maillist.ConfigError) as excinfo:
        maillist.load_settings(str(path))
    assert "error_report_to" in str(excinfo.value)
    assert "topsecret" not in str(excinfo.value)


def test_load_settings_rejects_non_numeric_port(config_file) -> None:
    config_file.write_text(config_file.read_text().replace("[database]", "[database]\nport = abc"))
    with pytest.raises(maillist.ConfigError, match="port"):
        maillist.load_settings(str(config_file))


def test_load_settings_rejects_unknown_log_level(config_file) -> None:
    config_file.write_text(config_file.read_text() + "level = LOUD\n")
    with pytest.raises(maillist.ConfigError, match="level"):
        maillist.load_settings(str(config_file))


def test_load_settings_parses_booleans(config_file) -> None:
    text = config_file.read_text()
    text = text.replace("[list]", "[list]\ndelivered_to_fallback = no")
    config_file.write_text("[smtp]\nstarttls = no\n" + text)
    loaded = maillist.load_settings(str(config_file))
    assert loaded.smtp.starttls is False
    assert loaded.lists.delivered_to_fallback is False


def test_configure_logging_falls_back_to_stderr_when_file_unwritable(settings, tmp_path) -> None:
    broken = maillist.Settings(
        database=settings.database,
        smtp=settings.smtp,
        lists=settings.lists,
        log_file=str(tmp_path / "no-such-dir" / "maillist.log"),
        log_level="INFO",
    )
    maillist.configure_logging(broken)
    handlers = maillist.logger.handlers
    assert handlers
    assert not any(h.__class__.__name__ == "WatchedFileHandler" for h in handlers)
    maillist.logger.handlers.clear()


# --- recipients, headers, loop and bounce ---------------------------------


def test_normalize_recipients_deduplicates_and_skips_invalid() -> None:
    members = "Alice <alice@example.com><br />invalid-entry<br >ALICE@example.com\nBob <bob@example.com><BR/>"
    recipients = maillist.normalize_recipients(members, "MSG-1")
    assert recipients == ["alice@example.com", "bob@example.com"]


def test_should_drop_for_loop_matches_existing_markers(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = settings.lists.loop_marker
    msg["X-Original-To"] = "list@example.com"
    msg["To"] = "list@example.com"
    assert maillist.should_drop_for_loop(msg, settings.lists.loop_marker) is True


def test_should_not_drop_for_loop_with_foreign_marker(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = "someone-else"
    msg["X-Original-To"] = "list@example.com"
    msg["To"] = "list@example.com"
    assert maillist.should_drop_for_loop(msg, settings.lists.loop_marker) is False


def test_should_drop_for_bounce_from_mailer_daemon() -> None:
    msg = EmailMessage()
    msg["From"] = "Mail Delivery System <MAILER-DAEMON@mx.example.org>"
    assert maillist.should_drop_for_bounce(msg) is True


def test_should_drop_for_bounce_daemon_match_ignores_case() -> None:
    msg = EmailMessage()
    msg["From"] = "mailer-daemon@mx.example.org"
    assert maillist.should_drop_for_bounce(msg) is True


def test_should_not_drop_ordinary_sender() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    assert maillist.should_drop_for_bounce(msg) is False


def test_auto_submitted_alone_is_not_dropped() -> None:
    msg = EmailMessage()
    msg["Auto-Submitted"] = "auto-generated"
    assert maillist.should_drop_for_bounce(msg) is False


def test_should_drop_for_bounce_autoreply_header() -> None:
    msg = EmailMessage()
    msg["X-Autoreply"] = "yes"
    assert maillist.should_drop_for_bounce(msg) is True


def test_should_drop_for_bounce_null_return_path_with_report() -> None:
    msg = EmailMessage()
    msg["Return-Path"] = "<>"
    msg["Content-Type"] = "multipart/report; report-type=delivery-status"
    assert maillist.should_drop_for_bounce(msg) is True


def test_ensure_sender_header_sets_sender_for_list_mediation() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    maillist.ensure_sender_header(msg, "list@example.org")
    assert msg["Sender"] == "list@example.org"


def test_ensure_sender_header_does_not_override_existing_sender() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Sender"] = "already@example.org"
    maillist.ensure_sender_header(msg, "list@example.org")
    assert msg["Sender"] == "already@example.org"


def test_apply_list_headers_sets_headers_and_subject_tag(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Subject"] = "Hello"

    maillist.apply_list_headers(msg, "TAG", "list@example.org", "list@example.org", settings.lists)

    assert msg["Subject"] == "[TAG] Hello"
    assert msg["X-maillist"] == "mailadmin-maillist"
    assert msg["List-Id"] == "<list@example.org>"
    assert msg["List-Post"] == "<mailto:list@example.org>"
    assert msg["List-Owner"] == "owner@example.org"
    assert msg["List-Unsubscribe"] == "<mailto:list@example.org?subject=unsubscribe>"
    assert msg["Sender"] == "list@example.org"


def test_extract_delivered_to_local_part() -> None:
    msg = EmailMessage()
    msg["Delivered-To"] = "my.list@example.org"
    assert maillist.extract_delivered_to_local_part(msg) == "my.list"


# --- SMTP relay -----------------------------------------------------------


def test_send_to_list_batch_then_retry_refused_recipients() -> None:
    smtp = FakeSMTP()
    recipients = ["ok@example.com", "bad@example.com"]

    maillist.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-2")

    # First call is batched, second call retries refused recipient.
    assert len(smtp.calls) == 2
    assert smtp.calls[0][1] == recipients
    assert smtp.calls[1][1] == "bad@example.com"


def test_send_to_list_fallbacks_to_individual_on_batch_exception() -> None:
    smtp = FakeSMTP(raise_on_batch=True)
    recipients = ["first@example.com", "second@example.com"]

    maillist.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-3")

    # One batch attempt + one send per recipient.
    assert len(smtp.calls) == 3
    assert smtp.calls[0][1] == recipients
    assert smtp.calls[1][1] == "first@example.com"
    assert smtp.calls[2][1] == "second@example.com"


def test_send_via_local_postfix_notifies_and_does_not_raise_on_smtp_failure(monkeypatch, settings) -> None:
    notified: dict[str, object] = {}

    def fake_send_to_list(_smtp, _from_addr, _recipients, _message, _msg_id):
        raise smtplib.SMTPRecipientsRefused({"bad@example.com": (501, b"5.1.3 Bad recipient address syntax")})

    def fake_notify(_settings, msg_id: str, outbound_sender: str, recipients: list[str], error: Exception) -> None:
        notified["msg_id"] = msg_id
        notified["outbound_sender"] = outbound_sender
        notified["recipients"] = recipients
        notified["error"] = error

    monkeypatch.setattr(maillist.smtplib, "SMTP", lambda _host, _port, **_kw: ContextSMTP())
    monkeypatch.setattr(maillist, "send_to_list", fake_send_to_list)
    monkeypatch.setattr(maillist, "notify_delivery_issue", fake_notify)

    maillist.send_via_local_postfix(settings, "list@example.org", ["bad@example.com"], b"raw message", "MSG-4")

    assert notified["msg_id"] == "MSG-4"
    assert notified["outbound_sender"] == "list@example.org"
    assert notified["recipients"] == ["bad@example.com"]
    assert isinstance(notified["error"], smtplib.SMTPRecipientsRefused)


def test_send_via_local_postfix_skips_starttls_when_disabled(monkeypatch, settings) -> None:
    started: list[bool] = []

    class TrackingSMTP(ContextSMTP):
        def starttls(self) -> None:
            started.append(True)

        def sendmail(self, *_args):
            return {}

    monkeypatch.setattr(maillist.smtplib, "SMTP", lambda _host, _port, **_kw: TrackingSMTP())
    plain = maillist.Settings(
        database=settings.database,
        smtp=maillist.SmtpSettings(host="localhost", port=25, starttls=False),
        lists=settings.lists,
        log_file=settings.log_file,
        log_level=settings.log_level,
    )
    maillist.send_via_local_postfix(plain, "list@example.org", ["a@example.com"], b"raw", "MSG-6")
    assert started == []
    maillist.send_via_local_postfix(settings, "list@example.org", ["a@example.com"], b"raw", "MSG-7")
    assert started == [True]


def test_notify_delivery_issue_sends_email_report(monkeypatch, settings) -> None:
    captured: dict[str, EmailMessage] = {}

    class NotificationSMTP(ContextSMTP):
        def send_message(self, message: EmailMessage) -> None:
            captured["message"] = message

    monkeypatch.setattr(maillist.smtplib, "SMTP", lambda _host, _port, **_kw: NotificationSMTP())

    maillist.notify_delivery_issue(
        settings,
        "MSG-5",
        "list@example.org",
        ["a@example.com", "b@example.com"],
        smtplib.SMTPException("boom"),
    )

    message = captured["message"]
    assert message["To"] == "admins@example.org"
    assert message["From"] == "maillist@example.org"
    assert "MSG-5" in message["Subject"]
