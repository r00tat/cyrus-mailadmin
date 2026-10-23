#!/usr/bin/env python3
"""Deliver a message to the members of a mailing list.

Postfix hands the message over on stdin; the list is looked up in the database
and the message is relayed to every member through the local MTA.

Entry point of the script. The rest lives in sibling modules next to this file: config (settings,
logging, exit codes), members (reading the member field), headers (loop/bounce checks, list headers)
and delivery (SMTP relay, admin reports).

Originally written by Erich Malzer for the maillist-py project, Apache License 2.0;
maintained as part of mailadmin.
"""

import argparse
import logging
import os
import sys
from contextlib import closing
from dataclasses import dataclass
from email import policy
from email.message import EmailMessage
from email.parser import BytesParser

import mysql.connector
from mysql.connector import errorcode
from mysql.connector.abstracts import MySQLConnectionAbstract
from mysql.connector.pooling import PooledMySQLConnection

# The sibling modules are imported from the directory this file really lives in, the release directory.
# Postfix runs the script without -I, so Python already puts the script's directory first on sys.path; the
# installer's --check runs it with -I, which leaves it out. Putting the resolved directory first makes both
# invocations import the same files, and resolving the `current` symlink keeps the imports in one release
# directory instead of following `current` while an update switches it.
_HERE = os.path.dirname(os.path.realpath(__file__))
if sys.path[:1] != [_HERE]:
    sys.path.insert(0, _HERE)

from config import (  # noqa: E402
    CONFIG_ENV_VAR,
    DEFAULT_CONFIG_PATH,
    EX_NOUSER,
    EX_OK,
    EX_SOFTWARE,
    EX_TEMPFAIL,
    EX_USAGE,
    ConfigError,
    Settings,
    configure_logging,
    load_settings,
)
from delivery import notify_ambiguous_list, notify_unexpected_error, send_via_local_postfix  # noqa: E402
from headers import (  # noqa: E402
    apply_list_headers,
    extract_delivered_to_local_part,
    list_sender_address,
    sanitize_text,
    should_drop_for_bounce,
    should_drop_for_loop,
)
from members import normalize_recipients  # noqa: E402

DBConnection = MySQLConnectionAbstract | PooledMySQLConnection

logger = logging.getLogger("maillist")


class ListDataError(Exception):
    """A row in the lists table has an unusable shape. Maps to EX_SOFTWARE, never to "list not found"."""


class AmbiguousListError(Exception):
    """More than one list matches the key. The message must not go to a guessed list."""

    def __init__(self, count: int) -> None:
        super().__init__(f"{count} lists match the key")
        self.count = count


@dataclass(frozen=True)
class ListInfo:
    """Mailing list metadata loaded from the database."""

    tag: str | None
    address: str
    members: str


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
