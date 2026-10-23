"""Configuration, logging and the exit codes of the list delivery script.

The base module: it imports none of the others, so headers, delivery and maillist can all import from here
without a cycle. That is also why the exit codes live here and not in the entry point: delivery returns them
too, and importing them from maillist.py would load the script a second time under its module name.
"""

import configparser
import logging
import logging.handlers
from dataclasses import dataclass

DEFAULT_CONFIG_PATH = "/etc/mailadmin-maillist/maillist.ini"
CONFIG_ENV_VAR = "MAILLIST_CONFIG"

# Exit codes from sysexits.h; Postfix pipe(8) maps them to deliver/bounce/defer.
EX_OK = 0
EX_USAGE = 64
EX_NOUSER = 67  # list not found -> bounce
EX_SOFTWARE = 70  # unexpected error -> bounce, admins notified
EX_TEMPFAIL = 75  # database/SMTP/config temporarily unusable -> Postfix retries

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
