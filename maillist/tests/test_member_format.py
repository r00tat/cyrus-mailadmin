from pathlib import Path

import pytest

import members

FIXTURES = Path(__file__).resolve().parents[2] / "tests" / "Fixtures" / "MemberFormat"
CASES = sorted(p.stem for p in FIXTURES.glob("*.members"))


def test_fixture_directory_is_found() -> None:
    assert CASES, f"no golden files in {FIXTURES}"


@pytest.mark.parametrize("case", CASES)
def test_members_yield_expected_recipients(case: str) -> None:
    stored = (FIXTURES / f"{case}.members").read_bytes().decode("utf-8")
    expected = (FIXTURES / f"{case}.recipients").read_text().splitlines()
    assert members.normalize_recipients(stored, "MSG") == expected


def test_comma_inside_quoted_display_name_is_not_a_separator() -> None:
    stored = '"Doe, John" <john@example.org>\nalice@example.org'
    assert members.normalize_recipients(stored, "MSG") == ["john@example.org", "alice@example.org"]


def test_entry_without_at_sign_is_skipped_not_raised() -> None:
    assert members.normalize_recipients("not-an-address\nok@example.org", "MSG") == ["ok@example.org"]


def test_entities_are_decoded_before_br_handling() -> None:
    # An entity-encoded <br> from the legacy application separates entries like a plain one.
    stored = "a@example.org&lt;br&gt;b@example.org"
    assert members.normalize_recipients(stored, "MSG") == ["a@example.org", "b@example.org"]


def test_invalid_entry_log_does_not_contain_the_entry(caplog) -> None:
    with caplog.at_level("DEBUG", logger="maillist"):
        members.normalize_recipients("secret-entry-no-at\nok@example.org", "MSG")
    above_debug = " ".join(r.getMessage() for r in caplog.records if r.levelname != "DEBUG")
    debug = " ".join(r.getMessage() for r in caplog.records if r.levelname == "DEBUG")
    assert "secret-entry-no-at" not in above_debug
    assert "secret-entry-no-at" in debug
    assert any(r.levelname == "WARNING" for r in caplog.records)


def test_entities_without_semicolon_are_left_alone() -> None:
    # html.unescape() would read `&#64` as "@"; mailadmin's PHP side does not, so neither does the script.
    assert members.normalize_recipients("evil&#64example.org\nok&#64;example.org", "MSG") == ["ok@example.org"]


def test_an_unknown_named_entity_is_left_alone() -> None:
    # html.unescape("&notit;") decodes the prefix "&not"; PHP leaves the whole entity untouched.
    assert members._decode_entities("&notit; &amp; &#x40; &#64;") == "&notit; & @ @"


def test_lines_break_only_at_cr_and_lf() -> None:
    # str.splitlines() would also break at \x0b, \x0c, \x1c-\x1e and \x85, and so read
    # `"evil@attacker.test\x0b" <victim@example.org>` as mail to evil@attacker.test.
    stored = '"evil@attacker.test\x0b" <victim@example.org>\r\nok@example.org\rnext@example.org'
    assert members.normalize_recipients(stored, "MSG") == ["ok@example.org", "next@example.org"]


def test_numeric_entities_php_leaves_alone_are_left_alone() -> None:
    # html_entity_decode(..., ENT_HTML5) decodes neither C0/C1 controls (except tab, LF and FF), nor CR,
    # nor surrogates or noncharacters; html.unescape() maps &#128; to the euro sign and drops &#11;.
    assert members._decode_entities("&#11;&#13;&#128;&#x9F;&#xD800;&#xFDD0;&#xFFFE;&#99999999999;") == (
        "&#11;&#13;&#128;&#x9F;&#xD800;&#xFDD0;&#xFFFE;&#99999999999;"
    )
    assert members._decode_entities("&#9;&#10;&#12;&#0064;&#X40;&#xA0;&#xFFFD;") == "\t\n\x0c@@\xa0\ufffd"
