import logging
import re
import smtplib
import ssl
import sys
from email.message import EmailMessage
from pathlib import Path

import pytest

import config
import delivery
import headers
import maillist
import members
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


def test_load_settings_reads_values_and_defaults(settings: config.Settings) -> None:
    assert settings.database.host == "db.example.test"
    assert settings.database.port == 3306
    assert settings.database.name == "mail"
    assert settings.database.password == "secret%with%percent"
    assert settings.smtp == config.SmtpSettings(host="localhost", port=25, starttls=True)
    assert settings.lists.loop_marker == "mailadmin-maillist"
    assert settings.lists.delivered_to_fallback is True
    assert settings.log_level == "INFO"


def test_load_settings_missing_file(tmp_path) -> None:
    with pytest.raises(config.ConfigError, match="cannot read"):
        config.load_settings(str(tmp_path / "absent.ini"))


def test_load_settings_missing_required_key_names_key_not_value(tmp_path) -> None:
    path = tmp_path / "maillist.ini"
    path.write_text("[database]\nuser = u\npassword = topsecret\n[list]\nowner = o@example.org\n")
    with pytest.raises(config.ConfigError) as excinfo:
        config.load_settings(str(path))
    assert "error_report_to" in str(excinfo.value)
    assert "topsecret" not in str(excinfo.value)


def test_load_settings_rejects_non_numeric_port(config_file) -> None:
    config_file.write_text(config_file.read_text().replace("[database]", "[database]\nport = abc"))
    with pytest.raises(config.ConfigError, match="port"):
        config.load_settings(str(config_file))


def test_load_settings_rejects_unknown_log_level(config_file) -> None:
    config_file.write_text(config_file.read_text() + "level = LOUD\n")
    with pytest.raises(config.ConfigError, match="level"):
        config.load_settings(str(config_file))


def test_load_settings_parses_booleans(config_file) -> None:
    text = config_file.read_text()
    text = text.replace("[list]", "[list]\ndelivered_to_fallback = no")
    config_file.write_text("[smtp]\nstarttls = no\n" + text)
    loaded = config.load_settings(str(config_file))
    assert loaded.smtp.starttls is False
    assert loaded.lists.delivered_to_fallback is False


def _settings_with_log_file(settings, log_file: str) -> config.Settings:
    return config.Settings(
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

    monkeypatch.setattr(config.logging.handlers, "SysLogHandler", FakeSysLog)
    config.configure_logging(_settings_with_log_file(settings, str(tmp_path / "no-dir" / "x.log")))
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

    monkeypatch.setattr(config.logging.handlers, "SysLogHandler", no_syslog)
    config.configure_logging(_settings_with_log_file(settings, str(tmp_path / "no-dir" / "x.log")))
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
    loaded = config.load_settings(str(config_file))
    assert loaded.smtp.ca_file == "/etc/ssl/relay-ca.pem"
    assert loaded.smtp.tls_name == "mx.example.org"


# --- recipients, headers, loop and bounce ---------------------------------


def test_normalize_recipients_deduplicates_and_skips_invalid() -> None:
    stored = "Alice <alice@example.com><br />invalid-entry<br >ALICE@example.com\nBob <bob@example.com><BR/>"
    recipients = members.normalize_recipients(stored, "MSG-1")
    assert recipients == ["alice@example.com", "bob@example.com"]


def test_loop_detected_by_marker_and_own_list_id(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = settings.lists.loop_marker
    msg["List-Id"] = "<list@example.org>"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is True


def test_loop_detection_needs_no_to_or_original_to(settings) -> None:
    # pipe(8) with flags=Rq: no X-Original-To, and a forged mail may have no To at all.
    msg = EmailMessage()
    msg["X-maillist"] = settings.lists.loop_marker
    msg["List-Id"] = "<LIST@example.org>"
    assert "To" not in msg and "X-Original-To" not in msg
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is True


def test_marker_with_other_list_id_is_not_a_loop(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = settings.lists.loop_marker
    msg["List-Id"] = "<outer@example.org>"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is False


def test_foreign_marker_is_not_a_loop(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = "someone-else"
    msg["List-Id"] = "<list@example.org>"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is False


def test_old_x_original_to_rule_is_gone(settings) -> None:
    msg = EmailMessage()
    msg["X-maillist"] = settings.lists.loop_marker
    msg["X-Original-To"] = "list@example.org"
    msg["To"] = "list@example.org"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is False


def test_should_drop_for_bounce_from_mailer_daemon() -> None:
    msg = EmailMessage()
    msg["From"] = "Mail Delivery System <MAILER-DAEMON@mx.example.org>"
    assert headers.should_drop_for_bounce(msg) is True


def test_should_drop_for_bounce_daemon_match_ignores_case() -> None:
    msg = EmailMessage()
    msg["From"] = "mailer-daemon@mx.example.org"
    assert headers.should_drop_for_bounce(msg) is True


def test_should_not_drop_ordinary_sender() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    assert headers.should_drop_for_bounce(msg) is False


def test_auto_submitted_alone_is_not_dropped() -> None:
    msg = EmailMessage()
    msg["Auto-Submitted"] = "auto-generated"
    assert headers.should_drop_for_bounce(msg) is False


def test_should_drop_for_bounce_autoreply_header() -> None:
    msg = EmailMessage()
    msg["X-Autoreply"] = "yes"
    assert headers.should_drop_for_bounce(msg) is True


def test_should_drop_for_bounce_null_return_path_with_report() -> None:
    msg = EmailMessage()
    msg["Return-Path"] = "<>"
    msg["Content-Type"] = "multipart/report; report-type=delivery-status"
    assert headers.should_drop_for_bounce(msg) is True


def test_ensure_sender_header_sets_sender_for_list_mediation() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    headers.ensure_sender_header(msg, "list@example.org")
    assert msg["Sender"] == "list@example.org"


def test_ensure_sender_header_does_not_override_existing_sender() -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Sender"] = "already@example.org"
    headers.ensure_sender_header(msg, "list@example.org")
    assert msg["Sender"] == "already@example.org"


def test_apply_list_headers_sets_headers_and_subject_tag(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Subject"] = "Hello"

    headers.apply_list_headers(msg, "TAG", "list@example.org", "list@example.org", settings.lists)

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
    assert headers.extract_delivered_to_local_part(msg) == "my.list"


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

    result = delivery.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-2")

    # First call is batched, second call retries the refused recipient, which now succeeds.
    assert len(smtp.calls) == 2
    assert smtp.calls[0][1] == recipients
    assert smtp.calls[1][1] == "bad@example.com"
    assert sorted(result.accepted) == sorted(recipients)
    assert result.failed == {}


def test_send_to_list_fallbacks_to_individual_on_batch_exception() -> None:
    smtp = FakeSMTP(raise_on_batch=True)
    recipients = ["first@example.com", "second@example.com"]

    result = delivery.send_to_list(smtp, "list@example.org", recipients, b"raw message", "MSG-3")

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
    result = delivery.send_to_list(smtp, "list@example.org", [OK, BAD], b"raw", "MSG")
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
    result = delivery.send_to_list(smtp, "list@example.org", [OK, BAD], b"raw", "MSG")
    assert result.accepted == []
    assert set(result.failed) == {OK, BAD}
    assert result.transient is True


def test_smtp_connection_refused_is_tempfail(run_main) -> None:
    class Refusing(RecordingSMTP):
        def __init__(self, *_args, **_kwargs):
            raise ConnectionRefusedError("refused")

    code, _, smtps = run_main(["list.example.org"], smtp=Refusing)
    assert code == config.EX_TEMPFAIL
    assert smtps == []


def test_starttls_failure_is_tempfail_and_nothing_is_sent_in_clear(run_main) -> None:
    class NoTls(RecordingSMTP):
        def starttls(self, context=None):
            raise smtplib.SMTPNotSupportedError("STARTTLS extension not supported")

    code, _, smtps = run_main(["list.example.org"], smtp=NoTls)
    assert code == config.EX_TEMPFAIL
    assert all(s.sent == [] and s.messages == [] for s in smtps)


def test_certificate_error_is_tempfail(run_main) -> None:
    class BadCert(RecordingSMTP):
        def starttls(self, context=None):
            raise ssl.SSLCertVerificationError("certificate verify failed")

    code, _, smtps = run_main(["list.example.org"], smtp=BadCert)
    assert code == config.EX_TEMPFAIL
    assert all(s.sent == [] for s in smtps)


def test_partial_refusal_reports_and_returns_ok(run_main) -> None:
    smtp = scripted(
        batch_result={BAD: (550, b"no")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no")})},
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == config.EX_OK
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
    assert code == config.EX_OK
    assert len([m for s in smtps for m in s.messages]) == 1


def test_batch_temporary_failure_before_acceptance_is_tempfail(run_main) -> None:
    gone = smtplib.SMTPServerDisconnected("gone")
    smtp = scripted(batch_result=gone, single_errors={OK: gone, BAD: gone})
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == config.EX_TEMPFAIL
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
    assert code == config.EX_TEMPFAIL
    assert [m for s in smtps for m in s.messages] == []


def test_partial_delivery_with_temporary_failure_is_not_retried(run_main) -> None:
    # OK was accepted; retrying the whole message would deliver it twice.
    smtp = scripted(
        batch_result={BAD: (451, b"later")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (451, b"later")})},
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=smtp)
    assert code == config.EX_OK
    assert len([m for s in smtps for m in s.messages]) == 1


def test_no_usable_recipients_is_nouser_without_connecting(run_main, caplog) -> None:
    rows = (("TAG", "list@example.org", "garbage-without-at"),)
    with caplog.at_level("WARNING", logger="maillist"):
        code, _, smtps = run_main(["list.example.org"], rows=rows)
    assert code == config.EX_NOUSER
    assert smtps == []
    assert any("No valid recipients" in r.getMessage() for r in caplog.records)


def test_empty_member_list_is_nouser(run_main) -> None:
    code, _, smtps = run_main(["list.example.org"], rows=(("TAG", "list@example.org", ""),))
    assert code == config.EX_NOUSER
    assert smtps == []


def test_send_via_local_postfix_reports_failures_and_returns_ok(monkeypatch, settings) -> None:
    notified: dict[str, object] = {}

    def fake_send_to_list(_smtp, _from_addr, _recipients, _message, _msg_id):
        return delivery.DeliveryResult(accepted=[], failed={BAD: "501 bad syntax"})

    def fake_notify(_settings, msg_id, outbound_sender, recipients, failed) -> None:
        notified.update(msg_id=msg_id, outbound_sender=outbound_sender, recipients=recipients, failed=failed)

    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    monkeypatch.setattr(delivery, "send_to_list", fake_send_to_list)
    monkeypatch.setattr(delivery, "notify_delivery_issue", fake_notify)

    code = delivery.send_via_local_postfix(settings, "list@example.org", [BAD], b"raw message", "MSG-4")

    assert code == config.EX_OK
    assert notified == {
        "msg_id": "MSG-4",
        "outbound_sender": "list@example.org",
        "recipients": [BAD],
        "failed": {BAD: "501 bad syntax"},
    }


def _settings_with_smtp(settings, **smtp_changes) -> config.Settings:
    smtp = config.SmtpSettings(
        host=smtp_changes.get("host", settings.smtp.host),
        port=25,
        starttls=smtp_changes.get("starttls", True),
        ca_file=smtp_changes.get("ca_file", ""),
        tls_name=smtp_changes.get("tls_name", ""),
    )
    return config.Settings(
        database=settings.database,
        smtp=smtp,
        lists=settings.lists,
        log_file=settings.log_file,
        log_level=settings.log_level,
    )


def test_open_smtp_verifies_certificate_and_hostname(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.open_smtp(settings)
    smtp = RecordingSMTP.instances[0]
    assert smtp.starttls_called
    assert smtp.tls_context.check_hostname is True
    assert smtp.tls_context.verify_mode == ssl.CERT_REQUIRED
    assert smtp.tls_server_hostname == "localhost"
    assert smtp.timeout == delivery.SMTP_TIMEOUT


def test_open_smtp_checks_certificate_against_tls_name(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.open_smtp(_settings_with_smtp(settings, tls_name="mx.example.org"))
    smtp = RecordingSMTP.instances[0]
    assert smtp.tls_server_hostname == "mx.example.org"


def test_open_smtp_uses_ca_file(monkeypatch, settings, tmp_path) -> None:
    seen: dict[str, object] = {}
    real = ssl.create_default_context

    def spy(*args, **kwargs):
        seen.update(kwargs)
        return real()

    monkeypatch.setattr(delivery.ssl, "create_default_context", spy)
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.open_smtp(_settings_with_smtp(settings, ca_file="/etc/ssl/relay-ca.pem"))
    assert seen == {"cafile": "/etc/ssl/relay-ca.pem"}


def test_open_smtp_without_starttls_is_plain(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.open_smtp(_settings_with_smtp(settings, starttls=False))
    assert RecordingSMTP.instances[0].starttls_called is False


def test_open_smtp_closes_connection_when_starttls_fails(monkeypatch, settings) -> None:
    closed: list[bool] = []

    class Failing(RecordingSMTP):
        def starttls(self, context=None):
            raise smtplib.SMTPException("no tls")

        def close(self):
            closed.append(True)

    monkeypatch.setattr(delivery.smtplib, "SMTP", Failing)
    with pytest.raises(smtplib.SMTPException):
        delivery.open_smtp(settings)
    assert closed == [True]


def test_notify_delivery_issue_sends_email_report_over_starttls(monkeypatch, settings) -> None:
    RecordingSMTP.instances.clear()
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)

    delivery.notify_delivery_issue(
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
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.notify_unexpected_error(settings, "MSG-8", RuntimeError("boom"))
    smtp = RecordingSMTP.instances[0]
    assert smtp.messages and smtp.starttls_called
    assert smtp.tls_context.check_hostname is True


def test_notify_failure_never_raises(monkeypatch, settings) -> None:
    class Refusing(RecordingSMTP):
        def __init__(self, *_args, **_kwargs):
            raise ConnectionRefusedError("refused")

    monkeypatch.setattr(delivery.smtplib, "SMTP", Refusing)
    delivery.notify_delivery_issue(settings, "MSG", "list@example.org", [BAD], {BAD: "x"})
    delivery.notify_unexpected_error(settings, "MSG", RuntimeError("boom"))


# --- no internals in bounces and no addresses in logs -----------------------


def _failing_everything(*_args, **_kwargs):
    raise RuntimeError("boom internal detail")


def test_success_prints_nothing(run_main, capsys) -> None:
    assert run_main(["list.example.org"])[0] == config.EX_OK
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_unknown_list_prints_nothing(run_main, capsys) -> None:
    assert run_main(["missing.example.org"], rows=())[0] == config.EX_NOUSER
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_unexpected_error_prints_nothing(run_main, monkeypatch, capsys) -> None:
    monkeypatch.setattr(maillist, "apply_list_headers", _failing_everything)
    assert run_main(["list.example.org"])[0] == config.EX_SOFTWARE
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_tempfail_on_database_error_prints_nothing(run_main, capsys) -> None:
    def refuse(**_):
        raise maillist.mysql.connector.errors.InterfaceError("Can't connect to db.example.test")

    assert run_main(["list.example.org"], connect=refuse)[0] == config.EX_TEMPFAIL
    captured = capsys.readouterr()
    assert captured.out == "" and captured.err == ""


def test_config_error_prints_one_fixed_line_without_path_or_detail(monkeypatch, tmp_path, capsys) -> None:
    secret_path = tmp_path / "very-secret-location.ini"
    monkeypatch.setenv("MAILLIST_CONFIG", str(secret_path))
    assert maillist.main(["list.example.org"]) == config.EX_TEMPFAIL
    captured = capsys.readouterr()
    assert captured.out == ""
    assert captured.err == "maillist: configuration unusable\n"


def test_invalid_arguments_print_only_a_fixed_line(capsys) -> None:
    with pytest.raises(SystemExit) as excinfo:
        maillist.main(["one", "two"])
    assert excinfo.value.code == config.EX_USAGE
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
    assert code == config.EX_OK
    loud = " ".join(r.getMessage() for r in caplog.records if r.levelno > logging.DEBUG)
    quiet = " ".join(r.getMessage() for r in caplog.records if r.levelno == logging.DEBUG)
    for secret in (OK, BAD, "invalid-entry-xyz"):
        assert secret not in loud
    assert BAD in quiet
    assert "550" in loud


# --- main(): list key and exit codes ----------------------------------------


def test_argument_selects_list_and_wins_over_delivered_to(run_main) -> None:
    code, connection, smtps = run_main(["other.example.org"])
    assert code == config.EX_OK
    assert connection.calls[0][1] == ("other.example.org",)
    sent = [call for s in smtps for call in s.sent]
    assert sent and sent[0][1] == ["a@example.org", "b@example.org"]


def test_delivered_to_is_fallback_without_argument(run_main) -> None:
    code, connection, _ = run_main([])
    assert code == config.EX_OK
    assert connection.calls[0][1] == ("list.example.org",)


def test_without_argument_and_fallback_disabled_is_usage_error(run_main, config_file) -> None:
    config_file.write_text(config_file.read_text().replace("[list]", "[list]\ndelivered_to_fallback = no"))
    code, connection, smtps = run_main([])
    assert code == config.EX_USAGE
    assert connection.calls == []
    assert smtps == []


def test_without_argument_and_without_delivered_to_is_usage_error(run_main) -> None:
    raw = RAW_MAIL.replace(b"Delivered-To: list.example.org@maillist.invalid\r\n", b"")
    code, connection, _ = run_main([], raw=raw)
    assert code == config.EX_USAGE
    assert connection.calls == []


def test_too_many_arguments_is_usage_error(run_main) -> None:
    with pytest.raises(SystemExit) as excinfo:
        run_main(["one", "two"])
    assert excinfo.value.code == config.EX_USAGE


def test_unknown_list_is_nouser(run_main) -> None:
    code, _, smtps = run_main(["missing.example.org"], rows=())
    assert code == config.EX_NOUSER
    assert smtps == []


def test_database_unreachable_is_tempfail(run_main) -> None:
    def refuse(**_):
        raise maillist.mysql.connector.errors.InterfaceError("Can't connect")

    code, _, smtps = run_main(["list.example.org"], connect=refuse)
    assert code == config.EX_TEMPFAIL
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
    assert code == config.EX_TEMPFAIL
    assert smtps == []


def test_missing_config_is_tempfail(monkeypatch, tmp_path, capsys) -> None:
    monkeypatch.setenv("MAILLIST_CONFIG", str(tmp_path / "absent.ini"))
    assert maillist.main(["list.example.org"]) == config.EX_TEMPFAIL
    assert capsys.readouterr().err == "maillist: configuration unusable\n"


def test_incomplete_config_is_tempfail(monkeypatch, tmp_path) -> None:
    path = tmp_path / "maillist.ini"
    path.write_text("[database]\nuser = u\n")
    monkeypatch.setenv("MAILLIST_CONFIG", str(path))
    assert maillist.main(["list.example.org"]) == config.EX_TEMPFAIL


def test_unexpected_error_is_software_and_reports(run_main, monkeypatch) -> None:
    def boom(*_args, **_kwargs):
        raise RuntimeError("boom")

    monkeypatch.setattr(maillist, "apply_list_headers", boom)
    code, _, smtps = run_main(["list.example.org"])
    assert code == config.EX_SOFTWARE
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
    assert code == config.EX_SOFTWARE


def test_loop_is_dropped_with_ok(run_main) -> None:
    raw = RAW_MAIL.replace(b"Subject:", b"X-maillist: mailadmin-maillist\r\nList-Id: <list@example.org>\r\nSubject:")
    code, connection, smtps = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
    assert len(connection.calls) == 1  # looked up, then dropped
    assert smtps == []


def test_nested_list_with_marker_but_other_list_id_is_delivered(run_main) -> None:
    raw = RAW_MAIL.replace(b"Subject:", b"X-maillist: mailadmin-maillist\r\nList-Id: <outer@example.org>\r\nSubject:")
    code, _, smtps = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
    assert [s for s in smtps if s.sent]


def test_postfix_pipe_invocation_with_double_dash(run_main) -> None:
    code, connection, _ = run_main(["--", "list.example.org"])
    assert code == config.EX_OK
    assert connection.calls[0][1] == ("list.example.org",)


def test_bounce_is_dropped_with_ok(run_main) -> None:
    raw = RAW_MAIL.replace(b"From: Author <author@example.net>", b"From: MAILER-DAEMON@mx.example.org")
    code, connection, smtps = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
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
    assert code == config.EX_USAGE
    assert connection.calls == []
    assert smtps == []


def test_argument_wins_even_with_two_delivered_to_headers(run_main) -> None:
    raw = RAW_MAIL.replace(b"Subject:", b"Delivered-To: evil.example.org@maillist.invalid\r\nSubject:")
    code, connection, _ = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
    assert connection.calls[0][1] == ("list.example.org",)


def test_recipient_addresses_are_logged_only_at_debug(caplog) -> None:
    with caplog.at_level("DEBUG", logger="maillist"):
        members.normalize_recipients("alice@example.com", "MSG-9")
    info_text = " ".join(r.getMessage() for r in caplog.records if r.levelname == "INFO")
    debug_text = " ".join(r.getMessage() for r in caplog.records if r.levelname == "DEBUG")
    assert "alice@example.com" not in info_text
    assert "alice@example.com" in debug_text


# --- --check -------------------------------------------------------------------


class ExplodingStdin:
    @property
    def buffer(self):
        raise AssertionError("--check must not read stdin")

    def read(self, *_args):
        raise AssertionError("--check must not read stdin")


def test_check_reports_ok(run_main, capsys) -> None:
    code, connection, smtps = run_main(["--check"], rows=[(3,)])
    out = capsys.readouterr()
    assert code == config.EX_OK
    assert out.out.splitlines() == ["config ok", "log ok", "database ok (3 lists)"]
    assert out.err == ""
    assert "COUNT(*)" in connection.calls[0][0]
    assert smtps == []


def test_check_does_not_read_stdin(run_main, monkeypatch) -> None:
    code, _, _ = run_main(["--check"], rows=[(0,)])
    assert code == config.EX_OK
    monkeypatch.setattr(sys, "stdin", ExplodingStdin())
    monkeypatch.setattr(maillist.mysql.connector, "connect", lambda **_: _count_connection(1))
    assert maillist.main(["--check"]) == config.EX_OK


def _count_connection(count):
    from tests.conftest import FakeConnection

    return FakeConnection([(count,)])


def test_check_with_unreachable_database_fails(run_main, capsys) -> None:
    def refuse(**_):
        raise maillist.mysql.connector.errors.InterfaceError("Can't connect")

    code, _, _ = run_main(["--check"], connect=refuse)
    out = capsys.readouterr()
    assert code == config.EX_TEMPFAIL
    assert "config ok" in out.out and "log ok" in out.out
    assert "database ok" not in out.out
    assert "database" in out.err.lower()


def test_check_with_missing_config_names_the_problem(monkeypatch, tmp_path, capsys) -> None:
    path = tmp_path / "absent.ini"
    monkeypatch.setenv("MAILLIST_CONFIG", str(path))
    assert maillist.main(["--check"]) == config.EX_TEMPFAIL
    out = capsys.readouterr()
    assert out.out == ""
    assert str(path) in out.err


def test_check_with_unwritable_log_fails(run_main, config_file, tmp_path, capsys) -> None:
    broken = config_file.read_text().replace(str(tmp_path / "maillist.log"), str(tmp_path / "no-dir" / "x.log"))
    config_file.write_text(broken)
    code, _, _ = run_main(["--check"], rows=[(1,)])
    out = capsys.readouterr()
    assert code == config.EX_TEMPFAIL
    assert out.out.splitlines() == ["config ok"]
    assert "log" in out.err.lower()


def test_check_with_unexpected_error_is_software(run_main, capsys) -> None:
    def boom(**_):
        raise RuntimeError("boom")

    code, _, _ = run_main(["--check"], connect=boom)
    assert code == config.EX_SOFTWARE
    assert "database ok" not in capsys.readouterr().out


EXAMPLE = Path(__file__).resolve().parents[1] / "maillist.ini.example"
REQUIRED_KEYS = ("user", "password", "owner", "error_report_to", "error_report_from")


def test_example_config_requires_the_required_keys_to_be_filled(tmp_path) -> None:
    copy = tmp_path / "example.ini"
    copy.write_text(EXAMPLE.read_text())
    with pytest.raises(config.ConfigError):
        config.load_settings(str(copy))


def test_example_config_is_loadable_after_filling_required_keys(tmp_path) -> None:
    text = EXAMPLE.read_text()
    for key in REQUIRED_KEYS:
        text, count = re.subn(rf"(?m)^{key} =[ \t]*$", f"{key} = value@example.org", text)
        assert count == 1, f"{key} must appear once with an empty value"
    filled = tmp_path / "filled.ini"
    filled.write_text(text)
    loaded = config.load_settings(str(filled))
    assert loaded.lists.loop_marker == "mailadmin-maillist"
    assert loaded.smtp.starttls is True


def test_example_config_documents_every_supported_key() -> None:
    import configparser

    parser = configparser.ConfigParser(interpolation=None)
    parser.read_string(EXAMPLE.read_text())
    keys = {(section, key) for section in parser.sections() for key in parser[section]}
    assert keys == {
        ("database", "host"),
        ("database", "port"),
        ("database", "user"),
        ("database", "password"),
        ("database", "name"),
        ("smtp", "host"),
        ("smtp", "port"),
        ("smtp", "starttls"),
        ("smtp", "ca_file"),
        ("smtp", "tls_name"),
        ("list", "owner"),
        ("list", "error_report_to"),
        ("list", "error_report_from"),
        ("list", "loop_marker"),
        ("list", "delivered_to_fallback"),
        ("log", "file"),
        ("log", "level"),
    }


# --- review round: hardening ----------------------------------------------------


def test_non_ascii_and_dotless_domain_addresses_are_skipped(caplog) -> None:
    stored = "j\u00f6rg@example.org\nuser@localhost\nok@example.org\n@example.org\nuser@"
    with caplog.at_level("DEBUG", logger="maillist"):
        recipients = members.normalize_recipients(stored, "MSG")
    assert recipients == ["ok@example.org"]
    loud = " ".join(r.getMessage() for r in caplog.records if r.levelno > logging.DEBUG)
    quiet = " ".join(r.getMessage() for r in caplog.records if r.levelno == logging.DEBUG)
    assert "example.org" not in loud and "localhost" not in loud
    assert "j\u00f6rg@example.org" in quiet
    # four per-entry warnings plus one aggregate
    assert len([r for r in caplog.records if r.levelname == "WARNING"]) == 5


def test_unencodable_recipient_is_a_permanent_failure_not_a_crash(run_main) -> None:
    class Strict(ScriptedSMTP):
        def sendmail(self, from_addr, to_addrs, message):
            if isinstance(to_addrs, list):
                raise UnicodeEncodeError("ascii", "x", 0, 1, "ordinal not in range")
            if to_addrs == BAD:
                raise UnicodeEncodeError("ascii", "x", 0, 1, "ordinal not in range")
            return super().sendmail(from_addr, to_addrs, message)

    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, smtp=Strict)
    assert code == config.EX_OK
    reports = [m for s in smtps for m in s.messages]
    assert len(reports) == 1 and BAD in reports[0].get_content()


def test_value_error_in_batch_falls_back_to_individual_sends() -> None:
    smtp = FakeSMTP()

    def batch_fails(from_addr, to_addrs, message):
        smtp.calls.append((from_addr, to_addrs, message))
        if isinstance(to_addrs, list):
            raise ValueError("bad header")
        return {}

    smtp.sendmail = batch_fails
    result = delivery.send_to_list(smtp, "list@example.org", [OK, BAD], b"raw", "MSG")
    assert result.accepted == [OK, BAD]


def test_message_id_with_encoded_crlf_never_breaks_the_report(run_main, monkeypatch) -> None:
    raw = RAW_MAIL.replace(b"Message-Id: <m1@example.net>", b"Message-Id: =?utf-8?q?<a=0D=0ABcc:_evil@example.net>?=")
    smtp = scripted(
        batch_result={BAD: (550, b"no")},
        single_errors={BAD: smtplib.SMTPRecipientsRefused({BAD: (550, b"no")})},
    )
    code, _, smtps = run_main(["list.example.org"], rows=MEMBERS_OK_BAD, raw=raw, smtp=smtp)
    assert code == config.EX_OK
    reports = [m for s in smtps for m in s.messages]
    assert len(reports) == 1
    assert "\r" not in reports[0]["Subject"] and "\n" not in reports[0]["Subject"]
    assert reports[0]["Bcc"] is None


def test_sanitize_text_replaces_control_characters_and_caps_length() -> None:
    assert headers.sanitize_text("a\r\nb\x00c\x7fd") == "a  b c d"
    assert len(headers.sanitize_text("x" * 500)) == 200


def test_notify_functions_never_raise_on_bad_input(monkeypatch, settings) -> None:
    monkeypatch.setattr(delivery.smtplib, "SMTP", RecordingSMTP)
    delivery.notify_delivery_issue(settings, "a\r\nb", "list@example.org", [BAD], {BAD: "x\r\ny"})
    delivery.notify_unexpected_error(settings, "a\r\nb", RuntimeError("x"))

    def explode(*_a, **_k):
        raise ValueError("cannot build")

    monkeypatch.setattr(delivery, "EmailMessage", explode)
    delivery.notify_delivery_issue(settings, "MSG", "list@example.org", [BAD], {BAD: "x"})
    delivery.notify_unexpected_error(settings, "MSG", RuntimeError("x"))


def test_ambiguous_list_key_is_tempfail_with_admin_report(run_main, caplog) -> None:
    rows = (
        ("A", "list@example.org", "a@example.net"),
        ("B", "list.example@org", "b@example.net"),
    )
    with caplog.at_level("DEBUG", logger="maillist"):
        code, _, smtps = run_main(["list.example.org"], rows=rows)
    assert code == config.EX_TEMPFAIL
    assert [s for s in smtps if s.sent] == []
    reports = [m for s in smtps for m in s.messages]
    assert len(reports) == 1 and reports[0]["To"] == "admins@example.org"
    loud = " ".join(r.getMessage() for r in caplog.records if r.levelno > logging.DEBUG)
    assert "a@example.net" not in loud and "b@example.net" not in loud
    assert any(r.levelname == "ERROR" for r in caplog.records)


def test_bad_row_type_is_software_error_not_not_found(run_main) -> None:
    code, _, smtps = run_main(["list.example.org"], rows=(("TAG", "list@example.org", None),))
    assert code == config.EX_SOFTWARE
    assert [s for s in smtps if s.sent] == []


def test_database_error_is_logged_once(run_main, caplog) -> None:
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

    with caplog.at_level("ERROR", logger="maillist"):
        run_main(["list.example.org"], connect=lambda **_: BrokenConnection())
    assert len([r for r in caplog.records if "gone away" in r.getMessage()]) == 1


def test_subject_tag_is_not_prepended_twice(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Subject"] = "Re: [TAG] Hello"
    headers.apply_list_headers(msg, "TAG", "list@example.org", "list@example.org", settings.lists)
    assert msg["Subject"] == "Re: [TAG] Hello"


def test_subject_tag_with_control_characters_is_sanitised(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Subject"] = "Hello"
    headers.apply_list_headers(msg, "T\r\nBcc: x@example.net", "list@example.org", "list@example.org", settings.lists)
    assert "\n" not in msg["Subject"] and "\r" not in msg["Subject"]
    assert msg["Bcc"] is None


def test_connect_does_not_raise_on_warnings(run_main, capsys) -> None:
    seen: dict = {}

    def connect(**kwargs):
        from tests.conftest import FakeConnection

        seen.update(kwargs)
        return FakeConnection([("TAG", "list@example.org", "a@example.org")])

    run_main(["list.example.org"], connect=connect)
    assert "raise_on_warnings" not in seen
    seen.clear()
    run_main(["--check"], connect=lambda **kw: (seen.update(kw), _count_connection(1))[1])
    assert "raise_on_warnings" not in seen


def test_configure_logging_silences_logging_errors(settings, monkeypatch) -> None:
    monkeypatch.setattr(logging, "raiseExceptions", True)
    config.configure_logging(settings)
    try:
        assert logging.raiseExceptions is False
    finally:
        maillist.logger.handlers.clear()


def test_member_count_logged_is_the_normalised_count(run_main, caplog) -> None:
    rows = (("TAG", "list@example.org", "a@example.org\ninvalid\na@example.org"),)
    with caplog.at_level("INFO", logger="maillist"):
        run_main(["list.example.org"], rows=rows)
    assert any("with 1 members" in r.getMessage() for r in caplog.records)


# --- review round 2 --------------------------------------------------------------


def _outgoing(smtps) -> bytes:
    batches = [call[2] for s in smtps for call in s.sent if isinstance(call[1], list)]
    assert batches, "nothing was delivered"
    return batches[0]


def _rows(address: str, stored: str = "member@example.net"):
    return (("TAG", address, stored),)


def test_two_lists_containing_each_other_do_not_loop(run_main) -> None:
    a_rows = _rows("a@example.org", "b@example.org")
    code, _, smtps = run_main(["a.example.org"], rows=a_rows)
    assert code == config.EX_OK
    to_b = _outgoing(smtps)

    code, _, smtps = run_main(["b.example.org"], rows=_rows("b@example.org", "a@example.org"), raw=to_b)
    assert code == config.EX_OK
    back_to_a = _outgoing(smtps)

    code, connection, smtps = run_main(["a.example.org"], rows=a_rows, raw=back_to_a)
    assert code == config.EX_OK
    assert [s for s in smtps if s.sent] == []  # second pass through A is dropped


def test_chain_of_distinct_lists_delivers_and_records_both_hops(run_main) -> None:
    from email import policy
    from email.parser import BytesParser

    _, _, smtps = run_main(["a.example.org"], rows=_rows("a@example.org", "b@example.org"))
    code, _, smtps = run_main(["b.example.org"], rows=_rows("b@example.org"), raw=_outgoing(smtps))
    assert code == config.EX_OK
    out = BytesParser(policy=policy.SMTP).parsebytes(_outgoing(smtps))
    assert out.get_all("X-Loop") == ["a@example.org", "b@example.org"]
    assert out["List-Id"] == "<b@example.org>"


def test_x_loop_is_appended_never_replaced(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["X-Loop"] = "other@example.org"
    headers.apply_list_headers(msg, None, "list@example.org", "list@example.org", settings.lists)
    assert msg.get_all("X-Loop") == ["other@example.org", "list@example.org"]


def test_loop_detected_by_own_address_in_any_x_loop_header(settings) -> None:
    msg = EmailMessage()
    msg["X-Loop"] = "first@example.org"
    msg["X-Loop"] = " LIST@example.org "
    msg["List-Id"] = "<unrelated@example.org>"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is True


def test_other_lists_in_x_loop_are_not_a_loop(settings) -> None:
    msg = EmailMessage()
    msg["X-Loop"] = "first@example.org"
    assert headers.should_drop_for_loop(msg, settings.lists.loop_marker, "list@example.org") is False


def test_hop_limit_drops_regardless_of_list(settings) -> None:
    def with_hops(count: int) -> EmailMessage:
        msg = EmailMessage()
        for i in range(count):
            msg["X-Loop"] = f"hop{i}@example.org"
        return msg

    assert headers.MAX_LOOP_HOPS == 10
    assert headers.should_drop_for_loop(with_hops(10), settings.lists.loop_marker, "list@example.org") is True
    assert headers.should_drop_for_loop(with_hops(9), settings.lists.loop_marker, "list@example.org") is False


def test_hop_limit_is_enforced_by_main(run_main) -> None:
    hops = b"".join(b"X-Loop: hop%d@example.org\r\n" % i for i in range(10))
    raw = RAW_MAIL.replace(b"Subject:", hops + b"Subject:")
    code, _, smtps = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
    assert [s for s in smtps if s.sent] == []


def test_list_key_from_header_is_sanitised_in_logs(run_main, config_file, caplog) -> None:
    config_file.write_text(config_file.read_text() + "level = DEBUG\n")
    raw = RAW_MAIL.replace(
        b"Delivered-To: list.example.org@maillist.invalid", b"Delivered-To: =?utf-8?q?x=0D=0Ay?=@maillist.invalid"
    )
    with caplog.at_level("DEBUG", logger="maillist"):
        code, _, _ = run_main([], rows=(), raw=raw)
    assert code == config.EX_NOUSER
    assert any("x  y" in r.getMessage() for r in caplog.records)
    assert not any("\r" in r.getMessage() or "\n" in r.getMessage() for r in caplog.records)


@pytest.mark.parametrize(
    "address",
    [
        "a b@example.org",
        "a@exa mple.org",
        "a<b@example.org",
        "a>@example.org",
        'a"b@example.org',
        "a(b)@example.org",
        "a,b@example.org",
        "a;b@example.org",
        "a:b@example.org",
        "a\\b@example.org",
        "a[b]@example.org",
        "@example.org",
        "a@",
        "a@example..org",
        "a@.example.org",
        "a@example.org.",
        "a@b@example.org",
        "j\u00f6rg@example.org",
        "user@localhost",
    ],
)
def test_is_plain_address_rejects(address: str) -> None:
    assert members._is_plain_address(address) is False


@pytest.mark.parametrize("address", ["a@example.org", "first.last+tag@mail.example.org", "a_b-c@x.example.co"])
def test_is_plain_address_accepts(address: str) -> None:
    assert members._is_plain_address(address) is True


def test_skipped_members_are_summarised_in_one_warning(caplog) -> None:
    stored = "bad one\nok@example.org\nuser@localhost\nother@example.org"
    with caplog.at_level("WARNING", logger="maillist"):
        members.normalize_recipients(stored, "MSG")
    summaries = [r.getMessage() for r in caplog.records if "skipped" in r.getMessage().lower()]
    assert len(summaries) == 1
    assert "2" in summaries[0] and "1" in summaries[0] and "3" in summaries[0]  # count and line numbers
    assert "example.org" not in summaries[0] and "localhost" not in summaries[0]


def test_no_summary_warning_when_nothing_is_skipped(caplog) -> None:
    with caplog.at_level("WARNING", logger="maillist"):
        members.normalize_recipients("ok@example.org", "MSG")
    assert caplog.records == []


def test_decoded_subject_with_crlf_cannot_inject_headers(run_main) -> None:
    from email import policy
    from email.parser import BytesParser

    raw = RAW_MAIL.replace(b"Subject: Hello", b"Subject: =?utf-8?q?Hello=0D=0ABcc:_x@example.net?=")
    code, _, smtps = run_main(["list.example.org"], raw=raw)
    assert code == config.EX_OK
    out = BytesParser(policy=policy.SMTP).parsebytes(_outgoing(smtps))
    assert out["Bcc"] is None
    assert "\r" not in out["Subject"] and "\n" not in out["Subject"]
    assert out["Subject"].startswith("[TAG] Hello")


def test_subject_is_capped_when_tag_is_prepended(settings) -> None:
    msg = EmailMessage()
    msg["From"] = "Author <author@example.net>"
    msg["Subject"] = "x" * 3000
    headers.apply_list_headers(msg, "TAG", "list@example.org", "list@example.org", settings.lists)
    assert len(msg["Subject"]) <= 998 + len("[TAG] ")
