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
import html.entities
import logging
import logging.handlers
import os
import re
import smtplib
import ssl
import sys
from collections.abc import Callable
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
MAX_LOOP_HOPS = 10  # safety net: this many X-Loop headers means the message circulates

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


class ListDataError(Exception):
    """A row in the lists table has an unusable shape. Maps to EX_SOFTWARE, never to "list not found"."""


class AmbiguousListError(Exception):
    """More than one list matches the key. The message must not go to a guessed list."""

    def __init__(self, count: int) -> None:
        super().__init__(f"{count} lists match the key")
        self.count = count


_CONTROL_CHARS = re.compile(r"[\x00-\x1f\x7f]")


def sanitize_text(value: str, limit: int = 200) -> str:
    """Replace control characters (CR/LF included) with a space and cap the length."""
    return _CONTROL_CHARS.sub(" ", value)[:limit]


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
    # A failing log handler must never print a traceback to stderr: Postfix would put it into a bounce.
    logging.raiseExceptions = False
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

    Raises:
        AmbiguousListError: more than one list matches the key.
        ListDataError: the row has unusable column types.
        mysql.connector.Error: propagates; the caller logs it once.
    """
    query = "SELECT tag, address, members FROM lists WHERE REPLACE(address, '@', '.') = %s"
    logger.debug(f"{msg_id} - Query: {query} with param: {sanitize_text(delivered_to)}")
    with closing(cnx.cursor()) as cursor:
        cursor.execute(query, (delivered_to,))
        rows = cursor.fetchall()
    if not rows:
        return None
    if len(rows) > 1:
        raise AmbiguousListError(len(rows))

    raw_tag, raw_address, raw_members = rows[0]

    tag: str | None
    if raw_tag is None:
        tag = None
    elif isinstance(raw_tag, str):
        tag = raw_tag
    else:
        tag = str(raw_tag)

    if not isinstance(raw_address, str) or not isinstance(raw_members, str):
        raise ListDataError(
            f"invalid list row types: address={type(raw_address).__name__}, members={type(raw_members).__name__}"
        )

    return ListInfo(tag=tag, address=raw_address, members=raw_members)


_BR = re.compile(r"<br\s*/?>", re.IGNORECASE)

_ENTITY = re.compile(r"&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);")


def _php_decodes(code: int) -> bool:
    """Whether html_entity_decode(..., ENT_HTML5) decodes this numeric entity, or leaves it as written.

    PHP refuses C0 controls except tab, LF and FF, CR, DEL and C1, surrogates and noncharacters.
    html.unescape() maps C1 to Windows-1252 and drops others; both would let the two sides read different text.
    """
    if code in (0x09, 0x0A, 0x0C) or 0x20 <= code <= 0x7E or 0xA0 <= code <= 0xD7FF:
        return True
    return 0xE000 <= code <= 0x10FFFF and (code & 0xFFFF) < 0xFFFE and not 0xFDD0 <= code <= 0xFDEF


def _decode_entities(text: str) -> str:
    """Decode HTML entities the way mailadmin's PHP side does: only complete ones, ending in `;`.

    html.unescape() also reads `&#64` or `&lt` without a semicolon and decodes the known prefix of an
    unknown name (`&notit;` becomes "¬it;"). mailadmin would then show one address and the script deliver
    to another, so anything but a complete entity is left as it is, and a numeric one only becomes the
    character PHP would make of it (see _php_decodes).
    """

    def decode(match: re.Match[str]) -> str:
        entity = match.group(0)
        if entity.startswith("&#"):
            body = entity[2:-1]
            code = int(body[1:], 16) if body[:1] in "xX" else int(body)
            return chr(code) if _php_decodes(code) else entity
        return html.entities.html5.get(entity[1:], entity)

    return _ENTITY.sub(decode, text)


_ADDRESS_FORBIDDEN = re.compile(r'[\s<>"(),;:\\\[\]]')

# Lines break at CR and LF only, as in mailadmin. str.splitlines() also breaks at \x0b, \x0c, \x1c-\x1e and
# \x85, which turned `"evil@attacker.test\x0b" <victim@example.org>` into mail to evil@attacker.test.
_LINE_BREAK = re.compile(r"\r\n|\r|\n")

# An entry holding one of these is skipped; mailadmin does the same. Tab is ordinary whitespace.
_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")

# What PHP's trim() removes; the control check runs after exactly this, so both sides agree on the edges.
_PHP_TRIM = " \t\n\r\0\x0b"


def _is_plain_address(address: str) -> bool:
    """ASCII local@domain with a dotted domain. Anything else cannot be sent without SMTPUTF8.

    Deliberately stricter than RFC 5322: quoted local parts, comments and similar are rejected.
    """
    if not address.isascii() or _ADDRESS_FORBIDDEN.search(address):
        return False
    local, at, domain = address.rpartition("@")
    if not (at and local and domain) or "@" in local:
        return False
    return "." in domain and ".." not in domain and not domain.startswith(".") and not domain.endswith(".")


def _split_entries(line: str) -> list[tuple[str, bool]]:
    """Split one member line at commas and semicolons outside quotes and angle brackets.

    Each entry comes with a flag that says whether it uses group syntax: a colon outside quotes and angle
    brackets. getaddresses reads `user:x@example.org` as the group "user" with the member x@example.org, so
    such an entry would be delivered to an address nobody wrote down. Those entries are skipped instead.
    """
    entries: list[tuple[str, bool]] = []
    current: list[str] = []
    quoted = escaped = group = False
    angle = 0
    for char in line:
        if quoted:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                quoted = False
        elif char == '"':
            quoted = True
        elif char == "<":
            angle += 1
        elif char == ">" and angle:
            angle -= 1
        elif char in ",;" and not angle:
            entries.append(("".join(current), group))
            current, group = [], False
            continue
        elif char == ":" and not angle:
            group = True
        current.append(char)
    entries.append(("".join(current), group))
    return entries


def normalize_recipients(members: str, msg_id: str) -> list[str]:
    """Parse the stored member field into unique mailbox addresses, in order.

    Entries are separated by line breaks, commas, semicolons and <br> tags; HTML entities written by the
    legacy application are decoded first. Addresses are personal data and are only logged at DEBUG.
    """
    text = _BR.sub("\n", _decode_entities(members))
    recipients: list[str] = []
    seen: set[str] = set()
    skipped_lines: list[int] = []
    for line_no, line in enumerate(_LINE_BREAK.split(text), start=1):
        if not line.strip():
            continue
        # Split outside quotes, so a comma inside "Doe, John" is not a separator.
        addresses: list[str] = []
        for entry, group in _split_entries(line):
            if not entry.strip():
                continue
            if group or _CONTROL.search(entry.strip(_PHP_TRIM)):
                addresses.append("")
                continue
            addresses.extend(address for _name, address in getaddresses([entry]))
        for address in addresses:
            address = address.strip()
            if not _is_plain_address(address):
                logger.warning(f"{msg_id} - Skipping invalid recipient entry in line {line_no}")
                logger.debug(f"{msg_id} - Invalid recipient entry in line {line_no}: {line!r}")
                skipped_lines.append(line_no)
                continue
            key = address.lower()
            if key in seen:
                continue
            seen.add(key)
            recipients.append(address)

    if skipped_lines:
        # Only counts and line numbers; the entries themselves are personal data.
        lines = ", ".join(str(n) for n in dict.fromkeys(skipped_lines))
        logger.warning(f"{msg_id} - Skipped {len(skipped_lines)} invalid member entries (lines {lines})")
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
    if isinstance(exc, ValueError):  # includes UnicodeEncodeError: the address cannot be sent as is
        return f"{type(exc).__name__}: {exc}", True, type(exc).__name__
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
    accepted: list[str] = []
    pending: list[str]
    try:
        # A timeout after the final "." of DATA can leave the relay with the mail queued while we see an
        # error; a retry then delivers twice. That is inherent to SMTP and accepted.
        logger.info(f"{msg_id} - Sending mail in batch to {len(recipients)} recipients")
        refused = smtp.sendmail(from_addr, recipients, message)
        pending = list(refused.keys())
        accepted = [r for r in recipients if r not in refused]
        if pending:
            logger.warning(f"{msg_id} - Batch send refused {len(pending)} recipients; retrying individually")
            for address, (code, _detail) in refused.items():
                logger.debug(f"{msg_id} - Batch refused {address}: SMTP {code}")
    except (smtplib.SMTPException, ValueError) as e:
        _text, _permanent, label = _describe_smtp_failure(e)
        logger.warning(f"{msg_id} - Batch send failed ({label}); retrying recipients individually")
        pending = list(recipients)

    failed: dict[str, str] = {}
    transient = False
    for index, address in enumerate(pending, start=1):
        try:
            smtp.sendmail(from_addr, address, message)
            accepted.append(address)
        except (smtplib.SMTPException, OSError, ValueError) as e:
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
    """Parse stdin as bytes and return an SMTP-policy email message.

    Known limitation, same as upstream: raw 8-bit headers are re-encoded by policy.SMTP when the
    message is serialised again.
    """
    # Message-handling policy for auth compatibility:
    # - Parse input from raw bytes to avoid lossy Unicode conversion.
    # - Keep body unchanged unless list-specific header changes are required.
    # - Serialize once as SMTP bytes immediately before relaying.
    incoming_bytes = sys.stdin.buffer.read()
    return BytesParser(policy=policy.SMTP).parsebytes(incoming_bytes)


def should_drop_for_loop(incoming: EmailMessage, loop_marker: str, list_address: str) -> bool:
    """Return True when the message already passed this list or has circulated too long.

    Every list appends its own address as an X-Loop header (never replacing earlier ones), so a cycle
    A -> B -> A is caught on the second pass through A, however many lists are in between. Postfix runs
    the transport with flags=Rq, so X-Original-To is not available. The marker plus the list's own List-Id
    is kept as an additional check; List-Id alone is not enough because it is overwritten on each hop.
    """
    hops = [str(value).strip().lower() for value in incoming.get_all("X-Loop") or []]
    if len(hops) >= MAX_LOOP_HOPS or list_address.strip().lower() in hops:
        return True
    if incoming.get("X-maillist") != loop_marker:
        return False
    return str(incoming.get("List-Id", "")).strip().lower() == f"<{list_address}>".lower()


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
    tag = sanitize_text(tag).strip() if tag else None
    subject = str(incoming.get("Subject", ""))
    if tag and f"[{tag}]" not in subject:
        # A decoded subject can contain CR/LF; rebuild it from a sanitised copy.
        set_or_replace_header(incoming, "Subject", f"[{tag}] {sanitize_text(subject, limit=998)}")

    incoming["X-Loop"] = address  # appended, never replaced: the trace of lists this message passed
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
        return EX_NOUSER

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
        # Deliberate: when every recipient was refused permanently the result is still EX_OK plus an admin
        # report. A bounce to the sender would only leak the member list's state to them.
        logger.error(f"{msg_id} - {len(result.failed)} of {len(recipients)} recipients failed; notifying admins")
        notify_delivery_issue(settings, msg_id, outbound_sender, recipients, result.failed)
    return EX_OK


def _send_report(settings: Settings, build: Callable[[], EmailMessage], msg_id: str) -> None:
    """Build and send an admin report. Never raises: a broken report must not change the exit code."""
    try:
        report = build()
        with open_smtp(settings) as smtp:
            smtp.send_message(report)
    except Exception as notify_error:
        logger.error(f"{msg_id} - Failed to send error report ({type(notify_error).__name__})")


def _new_report(settings: Settings, subject: str) -> EmailMessage:
    report = EmailMessage()
    report["From"] = settings.lists.error_report_from
    report["To"] = settings.lists.error_report_to
    report["Subject"] = sanitize_text(subject)
    return report


def notify_delivery_issue(
    settings: Settings, msg_id: str, outbound_sender: str, recipients: list[str], failed: dict[str, str]
) -> None:
    """Tell the admins which recipients could not be served (a report, not a log: it may name them)."""

    def build() -> EmailMessage:
        mid = sanitize_text(msg_id)
        report = _new_report(settings, f"[maillist] delivery error for {mid}")
        failures = "\n".join(f"  {address}: {sanitize_text(reason, 500)}" for address, reason in failed.items())
        report.set_content(
            "The maillist relay could not deliver to some recipients.\n\n"
            f"Message-Id: {mid}\n"
            f"Envelope sender: {sanitize_text(outbound_sender)}\n"
            f"Recipient count: {len(recipients)}\n"
            f"Failed recipients ({len(failed)}):\n{failures}\n"
        )
        return report

    _send_report(settings, build, msg_id)


def notify_unexpected_error(settings: Settings, msg_id: str, error: Exception) -> None:
    """Best-effort report of an unexpected failure to the configured report mailbox."""

    def build() -> EmailMessage:
        mid = sanitize_text(msg_id)
        report = _new_report(settings, f"[maillist] unexpected error for {mid}")
        report.set_content(
            f"The maillist script failed with an unexpected error.\n\nMessage-Id: {mid}\nError: {error!r}\n"
        )
        return report

    _send_report(settings, build, msg_id)


def notify_ambiguous_list(settings: Settings, msg_id: str, count: int) -> None:
    """Tell the admins that a list key matched several lists, so the message was held back."""

    def build() -> EmailMessage:
        mid = sanitize_text(msg_id)
        report = _new_report(settings, f"[maillist] ambiguous list key for {mid}")
        report.set_content(
            f"The list key matched {count} lists, so the message was not delivered and stays queued.\n"
            "Fix the duplicate list addresses; Postfix will retry.\n\n"
            f"Message-Id: {mid}\n"
        )
        return report

    _send_report(settings, build, msg_id)


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
        # ${user} is the local part without the address extension; ${mailbox} would keep it in the key.
        help="list key: the list address with '@' replaced by '.' (Postfix passes ${user})",
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="check configuration, log file and database, then exit (does not read stdin)",
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
        connection_timeout=10,
    )
    try:
        with closing(cnx):
            list_info = get_list_info(cnx, msg_id, list_key)
    except AmbiguousListError as exc:
        logger.error(f"{msg_id} - The list key matches {exc.count} lists; refusing to guess, message stays queued")
        # Every Postfix retry sends another report. Accepted on purpose: mailadmin prevents duplicate list
        # addresses on create, so this state needs manual database edits and deserves the noise.
        notify_ambiguous_list(settings, msg_id, exc.count)
        return EX_TEMPFAIL

    if not list_info:
        logger.warning(f"{msg_id} - No mailing list found for {sanitize_text(list_key)}")
        return EX_NOUSER

    if should_drop_for_loop(incoming, settings.lists.loop_marker, list_info.address):
        logger.error(f"{msg_id} - Mail loop detected, dropping")
        return EX_OK

    outbound_sender = list_sender_address(list_info.address)
    recipients = normalize_recipients(list_info.members, msg_id)
    logger.info(f"{msg_id} - Found Maillist: {list_info.address} with {len(recipients)} members")

    apply_list_headers(incoming, list_info.tag, list_info.address, outbound_sender, settings.lists)

    outgoing_bytes = incoming.as_bytes(policy=policy.SMTP)

    logger.debug(f"{msg_id} - Modified E-Mail headers: {dict(incoming.items())}")
    return send_via_local_postfix(settings, outbound_sender, recipients, outgoing_bytes, msg_id)


def run_check(config_path: str) -> int:
    """Verify an installation: configuration, log file, database. Not for pipe use, so it may talk."""
    try:
        settings = load_settings(config_path)
    except ConfigError as exc:
        print(f"config FAILED: {exc}", file=sys.stderr)
        return EX_TEMPFAIL
    print("config ok")

    try:
        with open(settings.log_file, "a", encoding="utf-8"):
            pass
    except OSError as exc:
        print(f"log FAILED: cannot append to {settings.log_file}: {exc.strerror}", file=sys.stderr)
        return EX_TEMPFAIL
    print("log ok")

    db = settings.database
    try:
        cnx = mysql.connector.connect(
            user=db.user,
            password=db.password,
            host=db.host,
            port=db.port,
            database=db.name,
            connection_timeout=10,
        )
        with closing(cnx), closing(cnx.cursor()) as cursor:
            cursor.execute("SELECT COUNT(*) FROM lists")
            row = cursor.fetchone()
        count = int(row[0]) if row else 0
    except mysql.connector.Error as exc:
        print(f"database FAILED: {exc}", file=sys.stderr)
        return EX_TEMPFAIL
    except Exception as exc:
        print(f"database FAILED unexpectedly: {type(exc).__name__}", file=sys.stderr)
        return EX_SOFTWARE
    print(f"database ok ({count} lists)")
    return EX_OK


def _report_software_error(settings: Settings, msg_id: str, exc: Exception) -> None:
    try:
        notify_unexpected_error(settings, msg_id, exc)
    except Exception:
        logger.exception(f"{msg_id} - Could not report the unexpected error")


def main(argv: list[str] | None = None) -> int:
    """Read a message from stdin and dispatch it to list members. Returns the exit code."""
    args = build_parser().parse_args(argv)

    config_path = os.environ.get(CONFIG_ENV_VAR, DEFAULT_CONFIG_PATH)
    if args.check:
        return run_check(config_path)
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
        # Decoded headers can carry CR/LF; sanitise once so logs and reports can use the value safely.
        msg_id = sanitize_text(str(incoming.get("Message-Id") or "UNKNOWN"))
        logger.info(f"{msg_id} - starting maillist processing")

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
    except ListDataError as exc:
        logger.error(f"{msg_id} - Unusable list row: {exc}")
        _report_software_error(settings, msg_id, exc)
        return EX_SOFTWARE
    except Exception as exc:
        logger.exception(f"{msg_id} - Unexpected error")
        _report_software_error(settings, msg_id, exc)
        return EX_SOFTWARE


if __name__ == "__main__":
    sys.exit(main())
