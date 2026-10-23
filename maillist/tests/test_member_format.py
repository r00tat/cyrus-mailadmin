from pathlib import Path

import pytest

import maillist

FIXTURES = Path(__file__).resolve().parents[2] / "tests" / "Fixtures" / "MemberFormat"
CASES = sorted(p.stem for p in FIXTURES.glob("*.members"))


def test_fixture_directory_is_found() -> None:
    assert CASES, f"no golden files in {FIXTURES}"


@pytest.mark.parametrize("case", CASES)
def test_members_yield_expected_recipients(case: str) -> None:
    stored = (FIXTURES / f"{case}.members").read_bytes().decode("utf-8")
    expected = (FIXTURES / f"{case}.recipients").read_text().splitlines()
    assert maillist.normalize_recipients(stored, "MSG") == expected


def test_comma_inside_quoted_display_name_is_not_a_separator() -> None:
    stored = '"Doe, John" <john@example.org>\nalice@example.org'
    assert maillist.normalize_recipients(stored, "MSG") == ["john@example.org", "alice@example.org"]


def test_entry_without_at_sign_is_skipped_not_raised() -> None:
    assert maillist.normalize_recipients("not-an-address\nok@example.org", "MSG") == ["ok@example.org"]


def test_entities_are_decoded_before_br_handling() -> None:
    # An entity-encoded <br> from the legacy application separates entries like a plain one.
    stored = "a@example.org&lt;br&gt;b@example.org"
    assert maillist.normalize_recipients(stored, "MSG") == ["a@example.org", "b@example.org"]


def test_invalid_entry_log_does_not_contain_the_entry(caplog) -> None:
    with caplog.at_level("DEBUG", logger="maillist"):
        maillist.normalize_recipients("secret-entry-no-at\nok@example.org", "MSG")
    above_debug = " ".join(r.getMessage() for r in caplog.records if r.levelname != "DEBUG")
    debug = " ".join(r.getMessage() for r in caplog.records if r.levelname == "DEBUG")
    assert "secret-entry-no-at" not in above_debug
    assert "secret-entry-no-at" in debug
    assert any(r.levelname == "WARNING" for r in caplog.records)
