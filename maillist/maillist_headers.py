"""Header handling of the list delivery script: loop and bounce detection, list headers, safe header text."""

import re
from email.message import EmailMessage
from email.utils import parseaddr

from maillist_config import ListSettings

MAX_LOOP_HOPS = 10  # safety net: this many X-Loop headers means the message circulates

_CONTROL_CHARS = re.compile(r"[\x00-\x1f\x7f]")


def sanitize_text(value: str, limit: int = 200) -> str:
    """Replace control characters (CR/LF included) with a space and cap the length."""
    return _CONTROL_CHARS.sub(" ", value)[:limit]


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
