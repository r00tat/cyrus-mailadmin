import textwrap
from pathlib import Path

import pytest

import maillist

CONFIG_TEXT = textwrap.dedent(
    """\
    [database]
    host = db.example.test
    user = listreader
    password = secret%with%percent

    [list]
    owner = owner@example.org
    error_report_to = admins@example.org
    error_report_from = maillist@example.org

    [log]
    file = {log_file}
    """
)


@pytest.fixture
def config_file(tmp_path: Path) -> Path:
    path = tmp_path / "maillist.ini"
    path.write_text(CONFIG_TEXT.format(log_file=tmp_path / "maillist.log"))
    return path


@pytest.fixture
def settings(config_file: Path) -> maillist.Settings:
    return maillist.load_settings(str(config_file))
