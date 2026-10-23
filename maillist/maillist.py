#!/usr/bin/env python3
"""Deliver a message to the members of a mailing list.

Postfix hands the message over on stdin; the list is looked up in the database
and the message is relayed to every member through the local MTA.

Originally written by Erich Malzer for the maillist-py project, Apache License 2.0;
maintained as part of mailadmin.
"""

import argparse
import configparser
import html
import logging
import logging.handlers
import os
import re
import smtplib
import ssl
import sys
from contextlib import closing
from dataclasses import dataclass
from email import policy
from email.message import EmailMessage
from email.parser import BytesParser
from email.utils import getaddresses, parseaddr

import mysql.connector
from mysql.connector import errorcode
from mysql.connector.abstracts import MySQLConnectionAbstract
from mysql.connector.pooling import PooledMySQLConnection

DEFAULT_CONFIG_PATH = "/etc/mailadmin-maillist/maillist.ini"
CONFIG_ENV_VAR = "MAILLIST_CONFIG"
SMTP_TIMEOUT = 30

# Exit codes from sysexits.h; Postfix pipe(8) maps them to deliver/bounce/defer.
EX_OK = 0
EX_USAGE = 64
EX_NOUSER = 67  # list not found -> bounce
EX_SOFTWARE = 70  # unexpected error -> bounce, admins notified
EX_TEMPFAIL = 75  # database/SMTP/config temporarily unusable -> Postfix retries

DBConnection = MySQLConnectionAbstract | PooledMySQLConnection

logger = logging.getLogger("maillist")


@dataclass(frozen=True)
class DatabaseSettings:
    host: str
    port: int
    user: str
    password: str
    name: str


@dataclass(frozen=True)
class SmtpSettings:
    host: str
    port: int
    starttls: bool
    ca_file: str = ""  # empty: system CAs
    tls_name: str = ""  # empty: verify the certificate against host


@dataclass(frozen=True)
class ListSettings:
    owner: str
    error_report_to: str
    error_report_from: str
    loop_marker: str
    delivered_to_fallback: bool


@dataclass(frozen=True)
class Settings:
    database: DatabaseSettings
    smtp: SmtpSettings
    lists: ListSettings
    log_file: str
    log_level: str


class ConfigError(Exception):
    """The configuration file is missing, unreadable or incomplete."""


@dataclass(frozen=True)
class ListInfo:
    """Mailing list metadata loaded from the database."""

    tag: str | None
    address: str
    members: str


def load_settings(path: str) -> Settings:
    """Read the INI configuration. Error messages name the file and the key, never a value."""
    parser = configparser.ConfigParser(interpolation=None)
    try:
        with open(path, encoding="utf-8") as handle:
            parser.read_file(handle)
    except (OSError, configparser.Error, UnicodeDecodeError) as exc:
        raise ConfigError(f"cannot read configuration file {path}: {type(exc).__name__}") from exc

    def text(section: str, key: str, default: str | None = None) -> str:
        value = parser.get(section, key, fallback="").strip()
        if value:
            return value
        if default is None:
            raise ConfigError(f"{path}: [{section}] {key} is required")
        return default

    def number(section: str, key: str, default: int) -> int:
        try:
            return int(text(section, key, str(default)))
        except ValueError:
            raise ConfigError(f"{path}: [{section}] {key} must be a number") from None

    def flag(section: str, key: str, default: bool) -> bool:
        try:
            return parser.getboolean(section, key, fallback=default)
        except ValueError:
            raise ConfigError(f"{path}: [{section}] {key} must be yes or no") from None

    level = text("log", "level", "INFO").upper()
    if not isinstance(logging.getLevelName(level), int):
        raise ConfigError(f"{path}: [log] level is not a valid logging level")

    return Settings(
        database=DatabaseSettings(
            host=text("database", "host", "localhost"),
            port=number("database", "port", 3306),
            user=text("database", "user"),
            password=text("database", "password"),
            name=text("database", "name", "mail"),
        ),
        smtp=SmtpSettings(
            host=text("smtp", "host", "localhost"),
            port=number("smtp", "port", 25),
            starttls=flag("smtp", "starttls", True),
            ca_file=text("smtp", "ca_file", ""),
            tls_name=text("smtp", "tls_name", ""),
        ),
        lists=ListSettings(
            owner=text("list", "owner"),
            error_report_to=text("list", "error_report_to"),
            error_report_from=text("list", "error_report_from"),
            loop_marker=text("list", "loop_marker", "mailadmin-maillist"),
            delivered_to_fallback=flag("list", "delivered_to_fallback", True),
        ),
        log_file=text("log", "file", "/var/log/mail/maillist.log"),
        log_level=level,
    )


def configure_logging(settings: Settings) -> None:
    """Log to the configured file (logrotate rotates it).

    Postfix copies the script's stdout and stderr into bounce messages, so logging never falls back to
    stderr: an unwritable file means syslog, and without syslog nothing is logged.
    """
    handler: logging.Handler
    fallback_reason: str | None = None
    try:
        handler = logging.handlers.WatchedFileHandler(settings.log_file, encoding="utf-8")
    except OSError as exc:
        fallback_reason = f"cannot open log file ({exc.strerror}); logging to syslog"
        try:
            handler = logging.handlers.SysLogHandler(address="/dev/log")
        except OSError:
            handler = logging.NullHandler()
    handler.setFormatter(logging.Formatter("maillist: %(asctime)s %(levelname)s %(message)s"))
    logger.handlers.clear()
    logger.addHandler(handler)
    logger.setLevel(settings.log_level)
    if fallback_reason:
        logger.warning(fallback_reason)


def get_list_info(cnx: DBConnection, msg_id: str, delivered_to: str) -> ListInfo | None:
    """Query database for mailing list information.

    Args:
        cnx: MySQL database connection
        msg_id: Message ID for logging
        delivered_to: List key (address with "@" replaced by ".")

    Returns:
        ListInfo object or None if not found
    """
    try:
        query = "SELECT tag, address, members FROM lists WHERE REPLACE(address, '@', '.') = %s"
        logger.debug(f"{msg_id} - Query: {query} with param: {delivered_to}")
        with closing(cnx.cursor()) as cursor:
            cursor.execute(query, (delivered_to,))
            result = cursor.fetchone()
        if not result:
            return None

        raw_tag, raw_address, raw_members = result

        tag: str | None
        if raw_tag is None:
            tag = None
        elif isinstance(raw_tag, str):
            tag = raw_tag
        else:
            tag = str(raw_tag)

        if not isinstance(raw_address, str) or not isinstance(raw_members, str):
            logger.error(
                f"{msg_id} - Invalid list row types for {delivered_to}: "
                f"address={type(raw_address).__name__}, members={type(raw_members).__name__}"
            )
            return None

        return ListInfo(tag=tag, address=raw_address, members=raw_members)
    except mysql.connector.Error as e:
        logger.error(f"{msg_id} - Database error: {e}")
        raise


_BR = re.compile(r"<br\s*/?>", re.IGNORECASE)


def normalize_recipients(members: str, msg_id: str) -> list[str]:
    """Parse the stored member field into unique mailbox addresses, in order.

    Entries are separated by line breaks, commas, semicolons and <br> tags; HTML entities written by the
    legacy application are decoded first. Addresses are personal data and are only logged at DEBUG.
    """
    text = _BR.sub("\n", html.unescape(members))
    recipients: list[str] = []
    seen: set[str] = set()
    for line_no, line in enumerate(text.splitlines(), start=1):
        line = line.strip()
        if not line:
            continue
        # getaddresses honours quotes, so a comma inside "Doe, John" is not a separator.
        for _name, address in getaddresses([line.replace(";", ",")]):
            address = address.strip()
            if not address or "@" not in address:
                logger.warning(f"{msg_id} - Skipping invalid recipient entry in line {line_no}")
                logger.debug(f"{msg_id} - Invalid recipient entry in line {line_no}: {line!r}")
                continue
            key = address.lower()
            if key in seen:
                continue
            seen.add(key)
            recipients.append(address)

    logger.info(f"{msg_id} - Normalized {len(recipients)} unique recipients")
    logger.debug(f"{msg_id} - Recipients: {', '.join(recipients)}")
    return recipients


@dataclass(frozen=True)
class DeliveryResult:
    """Outcome of relaying one message. `transient` is True when a failure may succeed on retry."""

    accepted: list[str]
    failed: dict[str, str]
    transient: bool = False


def _describe_smtp_failure(exc: Exception) -> tuple[str, bool, str]:
    """Return (report text, permanent, log label) for a failed send. The log label never names a recipient."""
    if isinstance(exc, smtplib.SMTPRecipientsRefused):
        codes = [code for code, _msg in exc.recipients.values()]
        code = max(codes) if codes else 0
        detail = next((msg for _code, msg in exc.recipients.values()), b"")
        return f"{code} {detail.decode(errors='replace')}".strip(), bool(codes) and min(codes) >= 500, f"SMTP {code}"
    if isinstance(exc, smtplib.SMTPResponseException):
        detail = exc.smtp_error.decode(errors="replace") if isinstance(exc.smtp_error, bytes) else str(exc.smtp_error)
        return f"{exc.smtp_code} {detail}".strip(), exc.smtp_code >= 500, f"SMTP {exc.smtp_code}"
    return f"{type(exc).__name__}: {exc}", False, type(exc).__name__


def send_to_list(
    smtp: smtplib.SMTP, from_addr: str, recipients: list[str], message: bytes, msg_id: str
) -> DeliveryResult:
    """Send message to all recipients and report who was accepted and who failed.

    One batch transaction first; recipients it refused (or all of them, if the batch failed) are retried
    one by one. Individual failures are recorded, not raised, so the caller can tell a total failure
    (retry later) from a partial one (retrying would deliver twice). Addresses are only logged at DEBUG.
    """
    if not recipients:
        logger.warning(f"{msg_id} - No valid recipients found after normalization")
        return DeliveryResult(accepted=[], failed={})

    accepted: list[str] = []
    pending: list[str]
    try:
        logger.info(f"{msg_id} - Sending mail in batch to {len(recipients)} recipients")
        refused = smtp.sendmail(from_addr, recipients, message)
        pending = list(refused.keys())
        accepted = [r for r in recipients if r not in refused]
        if pending:
            logger.warning(f"{msg_id} - Batch send refused {len(pending)} recipients; retrying individually")
            for address, (code, _detail) in refused.items():
                logger.debug(f"{msg_id} - Batch refused {address}: SMTP {code}")
    except smtplib.SMTPException as e:
        _text, _permanent, label = _describe_smtp_failure(e)
        logger.warning(f"{msg_id} - Batch send failed ({label}); retrying recipients individually")
        pending = list(recipients)

    failed: dict[str, str] = {}
    transient = False
    for index, address in enumerate(pending, start=1):
        try:
            smtp.sendmail(from_addr, address, message)
            accepted.append(address)
        except (smtplib.SMTPException, OSError) as e:
            text, permanent, label = _describe_smtp_failure(e)
            failed[address] = text
            transient = transient or not permanent
            logger.error(f"{msg_id} - Retry {index}/{len(pending)} failed ({label})")
            logger.debug(f"{msg_id} - Retry {index}/{len(pending)} failed for {address}")
    return DeliveryResult(accepted=accepted, failed=failed, transient=transient)


def set_or_replace_header(msg: EmailMessage, header: str, value: str) -> None:
    """Set header if missing, otherwise replace existing value."""
    if header in msg:
        msg.replace_header(header, value)
    else:
        msg[header] = value


def list_sender_address(list_address: str) -> str:
    """Get normalized mailbox for use as envelope sender and Sender header."""
    return parseaddr(list_address)[1] or list_address


def ensure_sender_header(msg: EmailMessage, list_address: str) -> None:
    """Add Sender header for list mediation when From differs from list identity."""
    from_addr = parseaddr(msg.get("From", ""))[1]
    sender_addr = parseaddr(msg.get("Sender", ""))[1]
    if not from_addr or sender_addr:
        return

    normalized_list_sender = list_sender_address(list_address)
    if from_addr.lower() != normalized_list_sender.lower():
        msg["Sender"] = normalized_list_sender


def parse_incoming_message() -> EmailMessage:
    """Parse stdin as bytes and return an SMTP-policy email message."""
    # Message-handling policy for auth compatibility:
    # - Parse input from raw bytes to avoid lossy Unicode conversion.
    # - Keep body unchanged unless list-specific header changes are required.
    # - Serialize once as SMTP bytes immediately before relaying.
    incoming_bytes = sys.stdin.buffer.read()
    return BytesParser(policy=policy.SMTP).parsebytes(incoming_bytes)


def should_drop_for_loop(incoming: EmailMessage, loop_marker: str) -> bool:
    """Return True when known loop patterns are detected."""
    return incoming.get("X-maillist") == loop_marker and incoming.get("X-Original-To") == incoming.get("To")


def _local_part(address_header: str) -> str:
    return parseaddr(address_header)[1].split("@", 1)[0].strip().lower()


def should_drop_for_bounce(incoming: EmailMessage) -> bool:
    """Return True when bounce/autoreply patterns are detected."""
    # Auto-Submitted alone is deliberately not a reason to drop: many legitimate
    # senders set it.
    if _local_part(incoming.get("From", "")) == "mailer-daemon" or (
        _local_part(incoming.get("Reply-To", "")) == "mailer-daemon"
        and incoming.get("Subject") == "Undelivered Mail Returned to Sender"
    ):
        return True

    if incoming.get("X-Autoreply") or incoming.get("X-Autorespond"):
        return True

    return_path = incoming.get("Return-Path", "").strip()
    content_type = incoming.get("Content-Type", "").lower()
    subject = incoming.get("Subject", "").lower()
    if return_path == "<>":
        if "multipart/report" in content_type or "delivery-status" in content_type:
            return True
        if "undelivered" in subject or "delivery status notification" in subject:
            return True

    return False


def extract_delivered_to_local_part(incoming: EmailMessage) -> str | None:
    """Return local-part from Delivered-To header, or None if missing/invalid."""
    delivered_to = incoming.get("Delivered-To", "").strip()
    if not delivered_to:
        return None
    local_part = delivered_to.split("@", 1)[0].strip()
    return local_part or None


def apply_list_headers(
    incoming: EmailMessage, tag: str | None, address: str, outbound_sender: str, lists: ListSettings
) -> None:
    """Apply list-specific headers to the outgoing message."""
    if tag:
        set_or_replace_header(incoming, "Subject", f"[{tag}] {incoming.get('Subject', '')}")

    set_or_replace_header(incoming, "X-maillist", lists.loop_marker)
    set_or_replace_header(incoming, "List-Id", f"<{address}>")
    set_or_replace_header(incoming, "List-Post", f"<mailto:{address}>")
    set_or_replace_header(incoming, "List-Owner", lists.owner)
    set_or_replace_header(incoming, "List-Unsubscribe", f"<mailto:{address}?subject=unsubscribe>")
    ensure_sender_header(incoming, outbound_sender)


def open_smtp(settings: Settings) -> smtplib.SMTP:
    """Connect to the relay and, unless disabled, upgrade to TLS with certificate verification.

    Every SMTP path (list delivery and error reports) goes through here so none can skip STARTTLS.
    Verification cannot be switched off; `starttls = no` is the explicit plaintext choice.
    """
    cfg = settings.smtp
    smtp = smtplib.SMTP(cfg.host, cfg.port, timeout=SMTP_TIMEOUT)
    try:
        if cfg.starttls:
            context = ssl.create_default_context(cafile=cfg.ca_file or None)
            # smtplib passes self._host as server_hostname to wrap_socket, so overriding it is the only
            # way to verify the certificate against a name other than the connect address.
            smtp._host = cfg.tls_name or cfg.host
            smtp.starttls(context=context)
    except BaseException:
        smtp.close()
        raise
    return smtp


def send_via_local_postfix(
    settings: Settings, outbound_sender: str, recipients: list[str], outgoing_bytes: bytes, msg_id: str
) -> int:
    """Relay the message to the MTA and return the exit code.

    EX_TEMPFAIL only when nobody was served, so Postfix can retry without duplicates. Once at least one
    recipient was accepted, failures go to the admins and the message counts as delivered.
    """
    if not recipients:
        logger.warning(f"{msg_id} - No valid recipients found after normalization")
        return EX_OK

    result: DeliveryResult | None = None
    try:
        with open_smtp(settings) as smtp:
            result = send_to_list(smtp, outbound_sender, recipients, outgoing_bytes, msg_id)
    except (smtplib.SMTPException, OSError) as e:
        if result is None:
            logger.error(f"{msg_id} - SMTP relay unavailable ({type(e).__name__}); message will be retried")
            return EX_TEMPFAIL
        logger.warning(f"{msg_id} - Error closing the SMTP session after delivery ({type(e).__name__})")

    if result.failed:
        if not result.accepted and result.transient:
            logger.error(f"{msg_id} - No recipient accepted and failures are temporary; message will be retried")
            return EX_TEMPFAIL
        logger.error(f"{msg_id} - {len(result.failed)} of {len(recipients)} recipients failed; notifying admins")
        notify_delivery_issue(settings, msg_id, outbound_sender, recipients, result.failed)
    return EX_OK


def _send_report(settings: Settings, report: EmailMessage, msg_id: str) -> None:
    try:
        with open_smtp(settings) as smtp:
            smtp.send_message(report)
    except (smtplib.SMTPException, OSError) as notify_error:
        logger.error(f"{msg_id} - Failed to send error report ({type(notify_error).__name__})")


def notify_delivery_issue(
    settings: Settings, msg_id: str, outbound_sender: str, recipients: list[str], failed: dict[str, str]
) -> None:
    """Tell the admins which recipients could not be served (a report, not a log: it may name them)."""
    report = EmailMessage()
    report["From"] = settings.lists.error_report_from
    report["To"] = settings.lists.error_report_to
    report["Subject"] = f"[maillist] delivery error for {msg_id}"
    failures = "\n".join(f"  {address}: {reason}" for address, reason in failed.items())
    report.set_content(
        "The maillist relay could not deliver to some recipients.\n\n"
        f"Message-Id: {msg_id}\n"
        f"Envelope sender: {outbound_sender}\n"
        f"Recipient count: {len(recipients)}\n"
        f"Failed recipients ({len(failed)}):\n{failures}\n"
    )
    _send_report(settings, report, msg_id)


def notify_unexpected_error(settings: Settings, msg_id: str, error: Exception) -> None:
    """Best-effort report of an unexpected failure to the configured report mailbox."""
    report = EmailMessage()
    report["From"] = settings.lists.error_report_from
    report["To"] = settings.lists.error_report_to
    report["Subject"] = f"[maillist] unexpected error for {msg_id}"
    report.set_content(
        f"The maillist script failed with an unexpected error.\n\nMessage-Id: {msg_id}\nError: {error!r}\n"
    )
    _send_report(settings, report, msg_id)


def resolve_list_key(argument: str | None, incoming: EmailMessage, fallback: bool) -> str | None:
    """Return the list key: the pipe argument, else (during migration) the Delivered-To local part."""
    if argument and argument.strip():
        return argument.strip()
    if fallback:
        # Only trust an unambiguous header: a sender-supplied extra Delivered-To must not pick the list.
        if len(incoming.get_all("Delivered-To") or []) != 1:
            logger.warning("Ignoring Delivered-To fallback: expected exactly one header")
            return None
        return extract_delivered_to_local_part(incoming)
    return None


class _Parser(argparse.ArgumentParser):
    def error(self, message: str) -> None:
        # Postfix copies stderr into bounces: say as little as possible.
        self.exit(EX_USAGE, "maillist: invalid arguments\n")


def build_parser() -> argparse.ArgumentParser:
    parser = _Parser(description="Read a message from stdin and dispatch it to list members.")
    parser.add_argument(
        "list_key",
        nargs="?",
        help="list key: the list address with '@' replaced by '.' (Postfix passes ${user})",
    )
    return parser


def deliver(settings: Settings, args: argparse.Namespace, incoming: EmailMessage, msg_id: str) -> int:
    """Look up the list and relay the message. Database errors propagate to the caller."""
    list_key = resolve_list_key(args.list_key, incoming, settings.lists.delivered_to_fallback)
    if not list_key:
        logger.error(f"{msg_id} - No list key: no argument and no usable Delivered-To header")
        return EX_USAGE

    db = settings.database
    cnx = mysql.connector.connect(
        user=db.user,
        password=db.password,
        host=db.host,
        port=db.port,
        database=db.name,
        raise_on_warnings=True,
        connection_timeout=10,
    )
    with closing(cnx):
        list_info = get_list_info(cnx, msg_id, list_key)

    if not list_info:
        logger.warning(f"{msg_id} - No mailing list found for {list_key}")
        return EX_NOUSER

    logger.info(f"{msg_id} - Found Maillist: {list_info.address} with {len(list_info.members.splitlines())} members")
    outbound_sender = list_sender_address(list_info.address)
    recipients = normalize_recipients(list_info.members, msg_id)

    apply_list_headers(incoming, list_info.tag, list_info.address, outbound_sender, settings.lists)

    outgoing_bytes = incoming.as_bytes(policy=policy.SMTP)

    logger.debug(f"{msg_id} - Modified E-Mail headers: {dict(incoming.items())}")
    return send_via_local_postfix(settings, outbound_sender, recipients, outgoing_bytes, msg_id)


def main(argv: list[str] | None = None) -> int:
    """Read a message from stdin and dispatch it to list members. Returns the exit code."""
    args = build_parser().parse_args(argv)

    config_path = os.environ.get(CONFIG_ENV_VAR, DEFAULT_CONFIG_PATH)
    try:
        settings = load_settings(config_path)
    except ConfigError:
        # Operator error: let the message wait in the queue instead of bouncing it.
        # Postfix copies stderr into bounces: no path, no detail. `--check` shows the details.
        print("maillist: configuration unusable", file=sys.stderr)
        return EX_TEMPFAIL
    configure_logging(settings)

    msg_id = "UNKNOWN"
    try:
        incoming = parse_incoming_message()
        msg_id = incoming.get("Message-Id", "UNKNOWN")
        logger.info(f"{msg_id} - starting maillist processing")

        if should_drop_for_loop(incoming, settings.lists.loop_marker):
            logger.error(f"{msg_id} - Mail loop detected, dropping")
            return EX_OK

        if should_drop_for_bounce(incoming):
            logger.error(f"{msg_id} - Possible bounce / undeliverable mail")
            return EX_OK

        logger.debug(f"{msg_id} - Original E-Mail headers: {dict(incoming.items())}")
        return deliver(settings, args, incoming, msg_id)
    except mysql.connector.Error as err:
        if err.errno == errorcode.ER_ACCESS_DENIED_ERROR:
            logger.error(f"{msg_id} - Database access denied (check credentials)")
        elif err.errno == errorcode.ER_BAD_DB_ERROR:
            logger.error(f"{msg_id} - Database does not exist")
        else:
            logger.error(f"{msg_id} - Database error: {err}")
        return EX_TEMPFAIL
    except Exception as exc:
        logger.exception(f"{msg_id} - Unexpected error")
        try:
            notify_unexpected_error(settings, msg_id, exc)
        except Exception:
            logger.exception(f"{msg_id} - Could not report the unexpected error")
        return EX_SOFTWARE


if __name__ == "__main__":
    sys.exit(main())
