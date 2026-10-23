import logging
import smtplib
import ssl
from email.message import EmailMessage

import pytest

import maillist
from tests.conftest import RAW_MAIL, RecordingSMTP


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


def _settings_with_log_file(settings, log_file: str) -> maillist.Settings:
    return maillist.Settings(
        database=settings.database,
        smtp=settings.smtp,
        lists=settings.lists,
        log_file=log_file,
        log_level="INFO",
    )


def test_logging_falls_back_to_syslog_and_never_to_stderr(settings, tmp_path, monkeypatch, capsys) -> None:
    created: list[dict] = []

    class FakeSysLog(logging.Handler):
        def __init__(self, address=None, **_kw):
            super().__init__()
            created.append({"address": address})

        def emit(self, record):
            pass

    monkeypatch.setattr(maillist.logging.handlers, "SysLogHandler", FakeSysLog)
    maillist.configure_logging(_settings_with_log_file(settings, str(tmp_path / "no-dir" / "x.log")))
    try:
        maillist.logger.error("something")
        assert created == [{"address": "/dev/log"}]
        assert not any(isinstance(h, logging.StreamHandler) for h in maillist.logger.handlers)
        captured = capsys.readouterr()
        assert captured.out == "" and captured.err == ""
    finally:
        maillist.logger.handlers.clear()


def test_logging_falls_back_to_null_handler_without_syslog(settings, tmp_path, monkeypatch, capsys) -> None:
    def no_syslog(*_args, **_kw):
        raise OSError("no /dev/log")

    monkeypatch.setattr(maillist.logging.handlers, "SysLogHandler", no_syslog)
    maillist.configure_logging(_settings_with_log_file(settings, str(tmp_path / "no-dir" / "x.log")))
    try:
        maillist.logger.error("something")
        assert [type(h) for h in maillist.logger.handlers] == [logging.NullHandler]
        captured = capsys.readouterr()
        assert captured.out == "" and captured.err == ""
    finally:
        maillist.logger.handlers.clear()


def test_load_settings_reads_tls_keys(config_file) -> None:
    config_file.write_text(
        config_file.read_text().replace(
            "[list]", "[smtp]\nca_file = /etc/ssl/relay-ca.pem\ntls_name = mx.example.org\n[list]"
        )
    )
    loaded = maillist.load_settings(str(config_file))
    assert loaded.smtp.ca_file == "/etc/ssl/relay-ca.pem"
    assert loaded.smtp.tls_name == "mx.example.org"


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

BAD = "bad@example.org"
OK = "ok@example.org"


class ScriptedSMTP(RecordingSMTP):
    """Batch (list) and single-recipient sends are scripted separately."""

    batch_result = None  # dict returned, or exception raised, for the batch send
    single_errors: dict = {}  # recipient -> exception raised for the single send

    def sendmail(self, from_addr, to_addrs, message):
        self.sent.append((from_addr, to_addrs, message))
        if isinstance(to_addrs, list):
            if isinstance(self.batch_result, Exception):
                raise self.batch_result
            return dict(self.batch_result or {})
        error = self.single_errors.get(to_addrs)
        if error is not None:
            raise error
        return {}


def scripted(batch_result=None, single_errors=None):
    return type("Scripted", (ScriptedSMTP,), {"batch_result": batch_result, "single_errors": single_errors or {}})


MEMBERS_OK_BAD = (("TAG", "list@example.org", f"{OK}\n{BAD}"),)


def test_send_to_list_batch_then_retry_refused_recipients() -> None:
    smtp = FakeSMTP()
    recipients = ["ok@example.com", "bad@example.com"]

    result = maillist.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-2")

    # First call is batched, second call retries the refused recipient, which now succeeds.
    assert len(smtp.calls) == 2
    assert smtp.calls[0][1] == recipients
    assert smtp.calls[1][1] == "bad@example.com"
    assert sorted(result.accepted) == sorted(recipients)
    assert result.failed == {}


def test_send_to_list_fallbacks_to_individual_on_batch_exception() -> None:
    smtp = FakeSMTP(raise_on_batch=True)
    recipients = ["first@example.com", "second@example.com"]

    result = maillist.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-3")

    # One batch attempt + one send per recipient.
    assert len(smtp.calls) == 3
    assert smtp.calls[0][1] == recipients
    assert smtp.calls[1][1] == "first@example.com"
    assert smtp.calls[2][1] == "second@example.com"
    assert result.accepted == recipients
    assert result.failed == {}


def test_send_to_list_records_permanent_failure_per_recipient() -> None:
    smtp = scripted(
        batch_result={BAD: (550, b"no such user")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no such user")})},
    )("localhost", 25)
    result = maillist.send_to_list(smtp, "list@example.org", [OK, BAD], b"raw", "MSG")
    assert result.accepted == [OK]
    assert list(result.failed) == [BAD]
    assert "550" in result.failed[BAD]
    assert result.transient is False


def test_send_to_list_marks_temporary_failure_as_transient() -> None:
    smtp = scripted(batch_result=smtplib.SMTPServerDisconnected("gone"), single_errors={})("localhost", 25)
    smtp.single_errors = {
        OK: smtplib.SMTPServerDisconnected("gone"),
        BAD: smtplib.SMTPRecipientsRefused({BAD: (451, b"try later")}),
    }
    result = maillist.send_to_list(smtp, "list@example.org", [OK, BAD], b"raw", "MSG")
    assert result.accepted == []
    assert set(result.failed) == {OK, BAD}
    assert result.transient is True


def test_send_to_list_without_recipients_sends_nothing() -> None:
    smtp = FakeSMTP()
    result = maillist.send_to_list(smtp, "list@example.org", [], b"raw", "MSG")
    assert smtp.calls == []
    assert result == maillist.DeliveryResult(accepted=[], failed={})


def test_smtp_connection_refused_is_tempfail(run_main) -> None:
    class Refusing(RecordingSMTP):
        def __init__(self, *_args, **_kwargs):
            raise ConnectionRefusedError("refused")

    code, _, smtps = run_main(["list.example.org"], smtp=Refusing)
    assert code == maillist.EX_TEMPFAIL
    assert smtps == []


def test_starttls_failure_is_tempfail_and_nothing_is_sent_in_clear(run_main) -> None:
    class NoTls(RecordingSMTP):
        def starttls(self, context=None):
            raise smtplib.SMTPNotSupportedError("STARTTLS extension not supported")

    code, _, smtps = run_main(["list.example.org"], smtp=NoTls)
    assert code == maillist.EX_TEMPFAIL
    assert all(s.sent == [] and s.messages == [] for s in smtps)


def test_certificate_error_is_tempfail(run_main) -> None:
    class BadCert(RecordingSMTP):
        def starttls(self, context=None):
            raise ssl.SSLCertVerificationError("certificate verify failed")

    code, _, smtps = run_main(["list.example.org"], smtp=BadCert)
    assert code == maillist.EX_TEMPFAIL
    assert all(s.sent == [] for s in smtps)


def test_partial_refusal_reports_and_returns_ok(run_main) -> None:
    smtp = scripted(
        batch_result={BAD: (550, b"no")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no")})},
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == maillist.EX_OK
    reports = [m for s in smtps for m in s.messages]
    assert len(reports) == 1
    assert reports[0]["To"] == "admins@example.org"
    assert BAD in reports[0].get_content()
    assert OK not in reports[0].get_content()


def test_all_recipients_permanently_refused_reports_and_returns_ok(run_main) -> None:
    refused = {OK: (550, b"no"), BAD: (550, b"no")}
    smtp = scripted(
        batch_result=smtplib.SMTPRecipientsRefused(refused),
        single_errors={
            OK: smtplib.SMTPRecipientsRefused({OK: (550, b"no")}),
            BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no")}),
        },
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == maillist.EX_OK
    assert len([m for s in smtps for m in s.messages]) == 1


def test_batch_temporary_failure_before_acceptance_is_tempfail(run_main) -> None:
    gone = smtplib.SMTPServerDisconnected("gone")
    smtp = scripted(batch_result=gone, single_errors={OK: gone, BAD: gone})
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == maillist.EX_TEMPFAIL
    assert [m for s in smtps for m in s.messages] == []


def test_temporary_refusal_of_everyone_is_tempfail(run_main) -> None:
    later = {OK: (451, b"later"), BAD: (451, b"later")}
    smtp = scripted(
        batch_result=smtplib.SMTPRecipientsRefused(later),
        single_errors={
            OK: smtplib.SMTPRecipientsRefused({OK: (451, b"later")}),
            BAD: smtplib.SMTPRecipientsRefused({BAD: (451, b"later")}),
        },
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == maillist.EX_TEMPFAIL
    assert [m for s in smtps for m in s.messages] == []


def test_partial_delivery_with_temporary_failure_is_not_retried(run_main) -> None:
    # OK was accepted; retrying the whole message would deliver it twice.
    smtp = scripted(
        batch_result={BAD: (451, b"later")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (451, b"later")})},
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == maillist.EX_OK
    assert len([m for s in smtps for m in s.messages]) == 1


def test_no_valid_recipients_is_ok_without_connecting(run_main, caplog) -> None:
    rows = (("TAG", "list@example.org", "garbage-without-at"),)
    with caplog.at_level("WARNING", logger="maillist"):
        code, _, smtps = run_main(["list.example.org"], rows=rows)
    assert code == maillist.EX_OK
    assert smtps == []
    assert any("No valid recipients" in r.getMessage() for r in caplog.records)


def test_send_via_local_postfix_reports_failures_and_returns_ok(monkeypatch, settings) -> None:
    notified: dict[str, object] = {}

    def fake_send_to_list(_smtp, _from_addr, _recipients, _message, _msg_id):
        return maillist.DeliveryResult(accepted=[], failed={BAD: "501 bad syntax"})

    def fake_notify(_settings, msg_id, outbound_sender, recipients, failed) -> None:
        notified.update(msg_id=msg_id, outbound_sender=outbound_sender, recipients=recipients, failed=failed)

    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    monkeypatch.setattr(maillist, "send_to_list", fake_send_to_list)
    monkeypatch.setattr(maillist, "notify_delivery_issue", fake_notify)

    code = maillist.send_via_local_postfix(settings, "list@example.org", [BAD], b"raw message", "MSG-4")

    assert code == maillist.EX_OK
    assert notified == {
        "msg_id": "MSG-4",
        "outbound_sender": "list@example.org",
        "recipients": [BAD],
        "failed": {BAD: "501 bad syntax"},
    }


def _settings_with_smtp(settings, **smtp_changes) -> maillist.Settings:
    smtp = maillist.SmtpSettings(
        host=smtp_changes.get("host", settings.smtp.host),
        port=25,
        starttls=smtp_changes.get("starttls", True),
        ca_file=smtp_changes.get("ca_file", ""),
        tls_name=smtp_changes.get("tls_name", ""),
    )
    return maillist.Settings(
        database=settings.database,
        smtp=smtp,
        lists=settings.lists,
        log_file=settings.log_file,
        log_level=settings.log_level,
    )


def test_open_smtp_verifies_certificate_and_hostname(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    maillist.open_smtp(settings)
    smtp = RecordingSMTP.instances[0]
    assert smtp.starttls_called
    assert smtp.tls_context.check_hostname is True
    assert smtp.tls_context.verify_mode == ssl.CERT_REQUIRED
    assert smtp.tls_server_hostname == "localhost"
    assert smtp.timeout == maillist.SMTP_TIMEOUT


def test_open_smtp_checks_certificate_against_tls_name(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    maillist.open_smtp(_settings_with_smtp(settings, tls_name="mx.example.org"))
    smtp = RecordingSMTP.instances[0]
    assert smtp.tls_server_hostname == "mx.example.org"


def test_open_smtp_uses_ca_file(monkeypatch, settings, tmp_path) -> None:
    seen: dict[str, object] = {}
    real = ssl.create_default_context

    def spy(*args, **kwargs):
        seen.update(kwargs)
        return real()

    monkeypatch.setattr(maillist.ssl, "create_default_context", spy)
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    maillist.open_smtp(_settings_with_smtp(settings, ca_file="/etc/ssl/relay-ca.pem"))
    assert seen == {"cafile": "/etc/ssl/relay-ca.pem"}


def test_open_smtp_without_starttls_is_plain(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    maillist.open_smtp(_settings_with_smtp(settings, starttls=False))
    assert RecordingSMTP.instances[0].starttls_called is False


def test_open_smtp_closes_connection_when_starttls_fails(monkeypatch, settings) -> None:
    closed: list[bool] = []

    class Failing(RecordingSMTP):
        def starttls(self, context=None):
            raise smtplib.SMTPException("no tls")

        def close(self):
            closed.append(True)

    monkeypatch.setattr(maillist.smtplib, "SMTP", Failing)
    with pytest.raises(smtplib.SMTPException):
        maillist.open_smtp(settings)
    assert closed == [True]


def test_notify_delivery_issue_sends_email_report_over_starttls(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)

    maillist.notify_delivery_issue(
        settings, "MSG-5", "list@example.org", ["a@example.com", "b@example.com"], {"b@example.com": "550 no"}
    )

    smtp = RecordingSMTP.instances[0]
    message = smtp.messages[0]
    assert message["To"] == "admins@example.org"
    assert message["From"] == "maillist@example.org"
    assert "MSG-5" in message["Subject"]
    assert "b@example.com" in message.get_content()
    assert "550 no" in message.get_content()
    assert smtp.starttls_called
    assert smtp.tls_context.verify_mode == ssl.CERT_REQUIRED


def test_notify_unexpected_error_goes_through_starttls(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(maillist.smtplib, "SMTP", RecordingSMTP)
    maillist.notify_unexpected_error(settings, "MSG-8", RuntimeError("boom"))
    smtp = RecordingSMTP.instances[0]
    assert smtp.messages and smtp.starttls_called
    assert smtp.tls_context.check_hostname is True


def test_notify_failure_never_raises(monkeypatch, settings) -> None:
    class Refusing(RecordingSMTP):
        def __init__(self, *_args, **_kwargs):
            raise ConnectionRefusedError("refused")

    monkeypatch.setattr(maillist.smtplib, "SMTP", Refusing)
    maillist.notify_delivery_issue(settings, "MSG", "list@example.org", [BAD], {BAD: "x"})
    maillist.notify_unexpected_error(settings, "MSG", RuntimeError("boom"))


# --- no internals in bounces and no addresses in logs -----------------------


def _failing_everything(*_args, **_kwargs):
    raise RuntimeError("boom internal detail")


def test_success_prints_nothing(run_main, capsys) -> None:
    assert run_main(["list.example.org"])[0] == maillist.EX_OK
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_unknown_list_prints_nothing(run_main, capsys) -> None:
    assert run_main(["missing.example.org"], rows=())[0] == maillist.EX_NOUSER
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_unexpected_error_prints_nothing(run_main, monkeypatch, capsys) -> None:
    monkeypatch.setattr(maillist, "apply_list_headers", _failing_everything)
    assert run_main(["list.example.org"])[0] == maillist.EX_SOFTWARE
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_tempfail_on_database_error_prints_nothing(run_main, capsys) -> None:
    def refuse(**_):
        raise maillist.mysql.connector.errors.InterfaceError("Can't connect to db.example.test")

    assert run_main(["list.example.org"], connect=refuse)[0] == maillist.EX_TEMPFAIL
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_config_error_prints_one_fixed_line_without_path_or_detail(monkeypatch, tmp_path, capsys) -> None:
    secret_path = tmp_path / "very-secret-location.ini"
    monkeypatch.setenv("MAILLIST_CONFIG", str(secret_path))
    assert maillist.main(["list.example.org"]) == maillist.EX_TEMPFAIL
    captured = capsys.readouterr()
    assert captured.out == ""
    assert captured.err == "maillist: configuration unusable\n"


def test_invalid_arguments_print_only_a_fixed_line(capsys) -> None:
    with pytest.raises(SystemExit) as excinfo:
        maillist.main(["one", "two"])
    assert excinfo.value.code == maillist.EX_USAGE
    captured = capsys.readouterr()
    assert captured.out == ""
    assert captured.err == "maillist: invalid arguments\n"


def test_recipient_addresses_stay_out_of_logs_above_debug_through_all_paths(run_main, config_file, caplog) -> None:
    config_file.write_text(config_file.read_text() + "level = DEBUG\n")  # main() applies the configured level
    smtp = scripted(
        batch_result={BAD: (550, b"no such user")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no such user")})},
    )
    rows = (("TAG", "list@example.org", f"{OK}\n{BAD}\ninvalid-entry-xyz"),)
    with caplog.at_level("DEBUG", logger="maillist"):
        code, _, _ = run_main(["list.example.org"], rows=rows, smtp=smtp)
    assert code == maillist.EX_OK
    loud = " ".join(r.getMessage() for r in caplog.records if r.levelno > logging.DEBUG)
    quiet = " ".join(r.getMessage() for r in caplog.records if r.levelno == logging.DEBUG)
    for secret in (OK, BAD, "invalid-entry-xyz"):
        assert secret not in loud
    assert BAD in quiet
    assert "550" in loud


# --- main(): list key and exit codes ----------------------------------------


def test_argument_selects_list_and_wins_over_delivered_to(run_main) -> None:
    code, connection, smtps = run_main(["other.example.org"])
    assert code == maillist.EX_OK
    assert connection.calls[0][1] == ("other.example.org",)
    sent = [call for s in smtps for call in s.sent]
    assert sent and sent[0][1] == ["a@example.org", "b@example.org"]


def test_delivered_to_is_fallback_without_argument(run_main) -> None:
    code, connection, _ = run_main([])
    assert code == maillist.EX_OK
    assert connection.calls[0][1] == ("list.example.org",)


def test_without_argument_and_fallback_disabled_is_usage_error(run_main, config_file) -> None:
    config_file.write_text(config_file.read_text().replace("[list]", "[list]\ndelivered_to_fallback = no"))
    code, connection, smtps = run_main([])
    assert code == maillist.EX_USAGE
    assert connection.calls == []
    assert smtps == []


def test_without_argument_and_without_delivered_to_is_usage_error(run_main) -> None:
    raw = RAW_MAIL.replace(b"Delivered-To: list.example.org@maillist.invalid\r\n", b"")
    code, connection, _ = run_main([], raw=raw)
    assert code == maillist.EX_USAGE
    assert connection.calls == []


def test_too_many_arguments_is_usage_error(run_main) -> None:
    with pytest.raises(SystemExit) as excinfo:
        run_main(["one", "two"])
    assert excinfo.value.code == maillist.EX_USAGE


def test_unknown_list_is_nouser(run_main) -> None:
    code, _, smtps = run_main(["missing.example.org"], rows=())
    assert code == maillist.EX_NOUSER
    assert smtps == []


def test_database_unreachable_is_tempfail(run_main) -> None:
    def refuse(**_):
        raise maillist.mysql.connector.errors.InterfaceError("Can't connect")

    code, _, smtps = run_main(["list.example.org"], connect=refuse)
    assert code == maillist.EX_TEMPFAIL
    assert smtps == []


def test_database_error_during_query_is_tempfail(run_main) -> None:
    class BrokenCursor:
        def execute(self, *_args):
            raise maillist.mysql.connector.errors.OperationalError("gone away")

        def close(self):
            pass

    class BrokenConnection:
        def cursor(self):
            return BrokenCursor()

        def close(self):
            pass

    code, _, smtps = run_main(["list.example.org"], connect=lambda **_: BrokenConnection())
    assert code == maillist.EX_TEMPFAIL
    assert smtps == []


def test_missing_config_is_tempfail(monkeypatch, tmp_path, capsys) -> None:
    monkeypatch.setenv("MAILLIST_CONFIG", str(tmp_path / "absent.ini"))
    assert maillist.main(["list.example.org"]) == maillist.EX_TEMPFAIL
    assert capsys.readouterr().err == "maillist: configuration unusable\n"


def test_incomplete_config_is_tempfail(monkeypatch, tmp_path) -> None:
    path = tmp_path / "maillist.ini"
    path.write_text("[database]\nuser = u\n")
    monkeypatch.setenv("MAILLIST_CONFIG", str(path))
    assert maillist.main(["list.example.org"]) == maillist.EX_TEMPFAIL


def test_unexpected_error_is_software_and_reports(run_main, monkeypatch) -> None:
    def boom(*_args, **_kwargs):
        raise RuntimeError("boom")

    monkeypatch.setattr(maillist, "apply_list_headers", boom)
    code, _, smtps = run_main(["list.example.org"])
    assert code == maillist.EX_SOFTWARE
    reports = [m for s in smtps for m in s.messages]
    assert len(reports) == 1
    assert reports[0]["To"] == "admins@example.org"
    assert reports[0]["From"] == "maillist@example.org"


def test_unexpected_error_still_returns_software_when_report_fails(run_main, monkeypatch) -> None:
    def boom(*_args, **_kwargs):
        raise RuntimeError("boom")

    def no_smtp(*_args, **_kwargs):
        raise OSError("connection refused")

    monkeypatch.setattr(maillist, "apply_list_headers", boom)
    monkeypatch.setattr(maillist, "notify_unexpected_error", lambda *a, **k: no_smtp())
    code, _, _ = run_main(["list.example.org"])
    assert code == maillist.EX_SOFTWARE


def test_loop_is_dropped_with_ok(run_main) -> None:
    raw = RAW_MAIL.replace(
        b"Subject:", b"X-maillist: mailadmin-maillist\r\nX-Original-To: list@example.org\r\nSubject:"
    )
    code, connection, smtps = run_main(["list.example.org"], raw=raw)
    assert code == maillist.EX_OK
    assert connection.calls == []


def test_bounce_is_dropped_with_ok(run_main) -> None:
    raw = RAW_MAIL.replace(b"From: Author <author@example.net>", b"From: MAILER-DAEMON@mx.example.org")
    code, connection, smtps = run_main(["list.example.org"], raw=raw)
    assert code == maillist.EX_OK
    assert connection.calls == []
    assert smtps == []


def test_resolve_list_key_prefers_stripped_argument() -> None:
    msg = EmailMessage()
    msg["Delivered-To"] = "fallback.example.org@x.invalid"
    assert maillist.resolve_list_key("  arg.example.org ", msg, True) == "arg.example.org"
    assert maillist.resolve_list_key("   ", msg, True) == "fallback.example.org"
    assert maillist.resolve_list_key(None, msg, False) is None


def test_two_delivered_to_headers_are_usage_error_without_argument(run_main) -> None:
    raw = RAW_MAIL.replace(b"Subject:", b"Delivered-To: evil.example.org@maillist.invalid\r\nSubject:")
    code, connection, smtps = run_main([], raw=raw)
    assert code == maillist.EX_USAGE
    assert connection.calls == []
    assert smtps == []


def test_argument_wins_even_with_two_delivered_to_headers(run_main) -> None:
    raw = RAW_MAIL.replace(b"Subject:", b"Delivered-To: evil.example.org@maillist.invalid\r\nSubject:")
    code, connection, _ = run_main(["list.example.org"], raw=raw)
    assert code == maillist.EX_OK
    assert connection.calls[0][1] == ("list.example.org",)


def test_recipient_addresses_are_logged_only_at_debug(caplog) -> None:
    with caplog.at_level("DEBUG", logger="maillist"):
        maillist.normalize_recipients("alice@example.com", "MSG-9")
    info_text = " ".join(r.getMessage() for r in caplog.records if r.levelname == "INFO")
    debug_text = " ".join(r.getMessage() for r in caplog.records if r.levelname == "DEBUG")
    assert "alice@example.com" not in info_text
    assert "alice@example.com" in debug_text
