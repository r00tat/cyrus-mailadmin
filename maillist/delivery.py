"""Relaying a list message through the MTA, and the reports that tell the admins what went wrong."""

import logging
import smtplib
import ssl
from collections.abc import Callable
from dataclasses import dataclass
from email.message import EmailMessage

from config import EX_NOUSER, EX_OK, EX_TEMPFAIL, Settings
from headers import sanitize_text

SMTP_TIMEOUT = 30

logger = logging.getLogger("maillist")


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
