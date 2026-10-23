"""Read the stored `lists.members` field into the addresses a message is delivered to.

Counterpart of src/Domain/MailingList/MemberList.php. Both sides must stay equivalent: mailadmin writes and
checks the field with MemberList, this module reads it for delivery, and any difference lets mailadmin show
one address while mail goes to another. The shared golden fixtures live in tests/Fixtures/MemberFormat/ and
are checked against both sides; a change here needs the matching change there, and a fixture for it.
"""

import html
import html.entities
import logging
import re
from email.utils import getaddresses

logger = logging.getLogger("maillist")

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
