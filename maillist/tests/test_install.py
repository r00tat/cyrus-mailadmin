import hashlib
import importlib.util
import io
import os
import re
import stat
import sys
import tarfile
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
INSTALL = ROOT / "install"


def _load():
    spec = importlib.util.spec_from_loader(
        "maillist_install", importlib.machinery.SourceFileLoader("maillist_install", str(INSTALL))
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["maillist_install"] = module
    spec.loader.exec_module(module)
    return module


import importlib.machinery  # noqa: E402

install = _load()


class FakeRunner:
    """Replaces every system command: no root, no useradd, no real verification."""

    def __init__(self, root=True, users=(), deps=True, check_ok=True):
        self.root, self.users, self.deps, self.check_ok = root, set(users), deps, check_ok
        self.calls: list[tuple] = []

    def is_root(self):
        return self.root

    def user_exists(self, name):
        return name in self.users

    def create_user(self, name):
        self.calls.append(("useradd", name))
        self.users.add(name)

    def chown(self, path, user, group):
        self.calls.append(("chown", str(path), user, group))

    def check_dependency(self, python):
        self.calls.append(("dep", python))
        return self.deps

    def run_check(self, python, script, config, user):
        self.calls.append(("check", python, str(script), str(config), user))
        return self.check_ok, "fake check output"


def make_source(base: Path, version: str, body: str = "print('maillist')\n") -> Path:
    src = base / f"src-{version}"
    src.mkdir(parents=True)
    (src / "maillist.py").write_text(body)
    (src / "maillist.ini.example").write_text((ROOT / "maillist.ini.example").read_text())
    (src / "VERSION").write_text(version + "\n")
    (src / "README.md").write_text("readme\n")
    return src


@pytest.fixture
def env(tmp_path):
    return {
        "tmp": tmp_path,
        "prefix": tmp_path / "prefix",
        "config": tmp_path / "etc" / "maillist.ini",
        "log": tmp_path / "log" / "maillist.log",
    }


def run(env, *extra, runner=None, verify=False, user=False, fetch=None, final_url=None):
    argv = ["--prefix", str(env["prefix"]), "--config", str(env["config"])]
    if not user:
        argv.append("--no-user")
    if not verify:
        argv.append("--no-verify")
    return install.main(argv + list(extra), runner=runner or FakeRunner(), fetch=fetch, final_url=final_url)


def fill_config(env):
    text = env["config"].read_text()
    text = re.sub(r"(?m)^file = .*$", f"file = {env['log']}", text)
    env["config"].write_text(text)


def installed(env, version, base=None):
    src = make_source(base or env["tmp"], version)
    assert run(env, "--source", str(src)) == 0
    return src


def release_names(env):
    return sorted(p.name for p in (env["prefix"] / "releases").iterdir())


def mode(path) -> int:
    return stat.S_IMODE(path.stat().st_mode)


# --- first and second install ----------------------------------------------------


def test_first_install_creates_config_and_stops_with_2(env, capsys) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_CONFIG
    assert env["config"].read_text() == (ROOT / "maillist.ini.example").read_text()
    assert mode(env["config"]) == 0o640
    assert not (env["prefix"] / "current").exists()
    assert "fill" in capsys.readouterr().out.lower()


def test_second_install_activates_release_and_writes_marker(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == 2
    fill_config(env)
    assert run(env, "--source", str(src)) == 0
    current = env["prefix"] / "current"
    assert current.is_symlink()
    assert not os.path.isabs(os.readlink(current))
    assert os.readlink(current) == "releases/1.0.0"
    assert (env["prefix"] / ".mailadmin-maillist").is_file()
    release = env["prefix"] / "releases" / "1.0.0"
    assert (current / "maillist.py").read_text() == "print('maillist')\n"
    assert mode(release) == 0o755
    for name in ("maillist.py", "maillist.ini.example", "VERSION", "README.md"):
        assert mode(release / name) == 0o644
    assert env["log"].is_file() and mode(env["log"]) == 0o640


def test_success_output_shows_master_cf_line_and_recipient_limit(env, capsys) -> None:
    src = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(src))
    fill_config(env)
    capsys.readouterr()
    assert run(env, "--source", str(src), "--user", "listuser", "--python", "/usr/bin/python3x", user=True) == 0
    out = capsys.readouterr().out
    assert "flags=Rq" in out
    assert "user=listuser" in out
    assert f"argv=/usr/bin/python3x {env['prefix']}/current/maillist.py -- ${{user}}" in out
    assert "maillist_destination_recipient_limit = 1" in out


def test_update_switches_current_and_keeps_previous(env) -> None:
    installed_first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(installed_first))
    fill_config(env)
    run(env, "--source", str(installed_first))
    second = make_source(env["tmp"], "1.1.0", body="print('new')\n")
    assert run(env, "--source", str(second)) == 0
    assert os.readlink(env["prefix"] / "current") == "releases/1.1.0"
    assert os.readlink(env["prefix"] / "previous") == "releases/1.0.0"


def test_same_release_twice_is_reused(env) -> None:
    src = installed_first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(installed_first))
    fill_config(env)
    assert run(env, "--source", str(src)) == 0
    assert run(env, "--source", str(src)) == 0
    assert release_names(env) == ["1.0.0"]
    assert not (env["prefix"] / "previous").exists()


def test_same_version_with_different_content_is_refused(env) -> None:
    first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(first))
    fill_config(env)
    run(env, "--source", str(first))
    other = make_source(env["tmp"] / "other", "1.0.0", body="print('different')\n")
    assert run(env, "--source", str(other)) == install.EXIT_FAIL
    assert (env["prefix"] / "releases" / "1.0.0" / "maillist.py").read_text() == "print('maillist')\n"


def test_dev_checkout_gets_timestamped_id(env) -> None:
    src = make_source(env["tmp"], "dev")
    run(env, "--source", str(src))
    (name,) = release_names(env)
    digest = hashlib.sha256(b"print('maillist')\n").hexdigest()[:8]
    assert re.fullmatch(rf"dev-\d{{8}}T\d{{6}}Z-{digest}", name)


def test_version_with_path_characters_is_refused(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    (src / "VERSION").write_text("../../evil\n")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert not (env["tmp"] / "evil").exists()


# --- rollback and status -------------------------------------------------------------


def two_releases(env):
    first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(first))
    fill_config(env)
    run(env, "--source", str(first))
    run(env, "--source", str(make_source(env["tmp"], "1.1.0", body="print('new')\n")))


def test_rollback_swaps_current_and_previous(env) -> None:
    two_releases(env)
    assert run(env, "--rollback") == 0
    assert os.readlink(env["prefix"] / "current") == "releases/1.0.0"
    assert os.readlink(env["prefix"] / "previous") == "releases/1.1.0"
    assert run(env, "--rollback") == 0
    assert os.readlink(env["prefix"] / "current") == "releases/1.1.0"


def test_rollback_without_previous_fails(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(src))
    fill_config(env)
    assert run(env, "--source", str(src)) == 0
    assert run(env, "--rollback") == install.EXIT_FAIL


def test_rollback_on_foreign_prefix_is_refused(env) -> None:
    env["prefix"].mkdir()
    (env["prefix"] / "foo").write_text("x")
    assert run(env, "--rollback") == install.EXIT_FAIL


def test_status_lists_current_previous_and_releases(env, capsys) -> None:
    two_releases(env)
    capsys.readouterr()
    assert run(env, "--status") == 0
    out = capsys.readouterr().out
    assert "current" in out and "releases/1.1.0" in out
    assert "previous" in out and "releases/1.0.0" in out


# --- safety --------------------------------------------------------------------------


def test_existing_foreign_directory_is_refused(env) -> None:
    env["prefix"].mkdir()
    (env["prefix"] / "foo").write_text("x")
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert sorted(p.name for p in env["prefix"].iterdir()) == ["foo"]
    assert not env["config"].exists()


def test_empty_existing_prefix_is_accepted(env) -> None:
    env["prefix"].mkdir()
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_CONFIG


def test_existing_config_is_not_overwritten(env) -> None:
    env["config"].parent.mkdir(parents=True)
    env["config"].write_text("[log]\nfile = " + str(env["log"]) + "\n# mine\n")
    env["config"].chmod(0o666)
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == 0
    assert "# mine" in env["config"].read_text()
    assert mode(env["config"]) == 0o640


def test_prune_keeps_current_and_previous(env) -> None:
    first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(first))
    fill_config(env)
    for version in ("1.0.0", "1.1.0", "1.2.0", "1.3.0"):
        assert run(env, "--source", str(make_source(env["tmp"] / "s", version)), "--keep-releases", "2") == 0
    assert release_names(env) == ["1.2.0", "1.3.0"]
    assert os.readlink(env["prefix"] / "current") == "releases/1.3.0"
    assert os.readlink(env["prefix"] / "previous") == "releases/1.2.0"


def test_prune_never_removes_current_or_previous_even_with_keep_one(env) -> None:
    first = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(first))
    fill_config(env)
    for version in ("1.0.0", "1.1.0", "1.2.0"):
        run(env, "--source", str(make_source(env["tmp"] / "s", version)), "--keep-releases", "1")
    assert release_names(env) == ["1.1.0", "1.2.0"]


def test_invalid_keep_releases_is_refused(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), "--keep-releases", "0") == install.EXIT_FAIL


def test_verify_failure_does_not_activate(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(src))
    fill_config(env)
    runner = FakeRunner(check_ok=False)
    assert run(env, "--source", str(src), verify=True, runner=runner) == install.EXIT_FAIL
    assert not (env["prefix"] / "current").exists()
    assert any(call[0] == "check" for call in runner.calls)


def test_verify_runs_check_with_config_as_list_user(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    runner = FakeRunner(users={"listuser"})
    run(env, "--source", str(src), "--user", "listuser", verify=True, user=True, runner=runner)
    fill_config(env)
    assert run(env, "--source", str(src), "--user", "listuser", verify=True, user=True, runner=runner) == 0
    check = [c for c in runner.calls if c[0] == "check"][0]
    assert check[3] == str(env["config"]) and check[4] == "listuser"
    assert check[2].endswith("releases/1.0.0/maillist.py")
    assert (env["prefix"] / "current").is_symlink()


def test_missing_mysql_connector_aborts_with_hint(env, capsys) -> None:
    src = make_source(env["tmp"], "1.0.0")
    code = run(env, "--source", str(src), verify=True, runner=FakeRunner(deps=False))
    assert code == install.EXIT_FAIL
    assert "python3-mysql.connector" in capsys.readouterr().err
    assert not env["prefix"].exists()


def test_user_is_created_and_files_are_chowned(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    runner = FakeRunner()
    assert run(env, "--source", str(src), "--user", "listuser", user=True, runner=runner) == install.EXIT_CONFIG
    assert ("useradd", "listuser") in runner.calls
    assert ("chown", str(env["config"]), "root", "listuser") in runner.calls
    fill_config(env)
    run(env, "--source", str(src), "--user", "listuser", user=True, runner=runner)
    assert ("chown", str(env["log"]), "listuser", "listuser") in runner.calls
    assert [c for c in runner.calls if c[0] == "useradd"] == [("useradd", "listuser")]


def test_not_root_without_no_user_is_refused(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), user=True, runner=FakeRunner(root=False)) == install.EXIT_FAIL
    assert not env["prefix"].exists()


def test_dry_run_changes_nothing(env, capsys) -> None:
    src = make_source(env["tmp"], "1.0.0")
    runner = FakeRunner()
    assert run(env, "--source", str(src), "--dry-run", runner=runner) in (0, 2)
    assert not env["prefix"].exists()
    assert not env["config"].exists()
    assert not env["log"].exists()
    assert [c for c in runner.calls if c[0] in ("useradd", "chown")] == []
    assert "would" in capsys.readouterr().out.lower()


def snapshot(env):
    items = {}
    for key in ("prefix", "config", "log"):
        root = env[key]
        paths = [root, *root.rglob("*")] if root.is_dir() else [root]
        for path in paths:
            if path.is_symlink():
                items[str(path)] = ("link", os.readlink(path))
            elif path.is_file():
                items[str(path)] = ("file", path.read_bytes(), mode(path))
            elif path.is_dir():
                items[str(path)] = ("dir", mode(path))
    return items


def test_dry_run_on_installed_prefix_changes_nothing(env) -> None:
    two_releases(env)
    before = snapshot(env)
    new = make_source(env["tmp"] / "s", "1.2.0")
    assert run(env, "--source", str(new), "--dry-run") == 0
    assert run(env, "--rollback", "--dry-run") == 0
    assert snapshot(env) == before


# --- release download ------------------------------------------------------------------


def build_archive(tag: str, members=None, version=None) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        files = {
            f"maillist-{tag}/maillist.py": b"print('release')\n",
            f"maillist-{tag}/maillist.ini.example": (ROOT / "maillist.ini.example").read_bytes(),
            f"maillist-{tag}/VERSION": (version or tag).encode() + b"\n",
            f"maillist-{tag}/README.md": b"readme\n",
        }
        for name, data in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))
        for info, data in members or []:
            tar.addfile(info, io.BytesIO(data) if data is not None else None)
    return buffer.getvalue()


def fake_network(tag: str, archive: bytes, sums: str | None = None, repo="r00tat/cyrus-mailadmin"):
    name = f"maillist-{tag}.tar.gz"
    sums = sums if sums is not None else f"{hashlib.sha256(archive).hexdigest()}  {name}\n"
    base = f"https://github.com/{repo}/releases/download/{tag}"
    urls = {f"{base}/{name}": archive, f"{base}/SHA256SUMS": sums.encode()}
    requested: list[str] = []

    def fetch(url):
        requested.append(url)
        if url not in urls:
            raise OSError(f"unexpected url {url}")
        return urls[url]

    return fetch, requested


def test_release_install_downloads_verifies_and_installs(env) -> None:
    fetch, requested = fake_network("v1.2.3", build_archive("v1.2.3"))
    run(env, "--release", "v1.2.3", fetch=fetch)
    fill_config(env)
    assert run(env, "--release", "v1.2.3", fetch=fetch) == 0
    assert (env["prefix"] / "current" / "maillist.py").read_text() == "print('release')\n"
    assert os.readlink(env["prefix"] / "current") == "releases/v1.2.3"
    assert all(
        url.startswith("https://github.com/r00tat/cyrus-mailadmin/releases/download/v1.2.3/") for url in requested
    )


def test_release_latest_resolves_tag_from_redirect(env) -> None:
    fetch, requested = fake_network("v2.0.0", build_archive("v2.0.0"))
    seen: list[str] = []

    def final_url(url):
        seen.append(url)
        return "https://github.com/r00tat/cyrus-mailadmin/releases/tag/v2.0.0"

    run(env, "--release", "latest", fetch=fetch, final_url=final_url)
    fill_config(env)
    assert run(env, "--release", "latest", fetch=fetch, final_url=final_url) == 0
    assert seen[0] == "https://github.com/r00tat/cyrus-mailadmin/releases/latest"
    assert os.readlink(env["prefix"] / "current") == "releases/v2.0.0"


def test_release_uses_given_repo(env) -> None:
    fetch, requested = fake_network("v1.0.0", build_archive("v1.0.0"), repo="example/other")
    run(env, "--release", "v1.0.0", "--repo", "example/other", fetch=fetch)
    assert requested and all("example/other" in url for url in requested)


def test_release_archive_with_bad_checksum_is_refused(env) -> None:
    fetch, _ = fake_network("v1.0.0", build_archive("v1.0.0"), sums=f"{'0' * 64}  maillist-v1.0.0.tar.gz\n")
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL
    assert not env["prefix"].exists()


def test_release_without_checksum_entry_is_refused(env) -> None:
    fetch, _ = fake_network("v1.0.0", build_archive("v1.0.0"), sums=f"{'0' * 64}  something-else.tar.gz\n")
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL


def _traversal_cases():
    def info(name, kind=tarfile.REGTYPE, linkname=""):
        member = tarfile.TarInfo(name)
        member.type = kind
        member.linkname = linkname
        if kind == tarfile.REGTYPE:
            member.size = 4
        return member

    return {
        "dotdot": (info("../evil.txt"), b"evil"),
        "nested-dotdot": (info("maillist-v1.0.0/../../evil.txt"), b"evil"),
        "absolute": (info("/maillist-test-absolute-evil.txt"), b"evil"),
        "symlink-outside": (info("maillist-v1.0.0/link", tarfile.SYMTYPE, "/etc/passwd"), None),
        "symlink-relative-escape": (info("maillist-v1.0.0/link", tarfile.SYMTYPE, "../../x"), None),
        "hardlink": (info("maillist-v1.0.0/hard", tarfile.LNKTYPE, "maillist-v1.0.0/maillist.py"), None),
        "device": (info("maillist-v1.0.0/dev", tarfile.CHRTYPE), None),
        "fifo": (info("maillist-v1.0.0/fifo", tarfile.FIFOTYPE), None),
    }


@pytest.mark.parametrize("case", sorted(_traversal_cases()))
def test_release_archive_with_unsafe_member_is_refused(env, case) -> None:
    member = _traversal_cases()[case]
    fetch, _ = fake_network("v1.0.0", build_archive("v1.0.0", members=[member]))
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL
    assert not env["prefix"].exists()
    assert not (env["tmp"] / "evil.txt").exists()
    assert not Path("/maillist-test-absolute-evil.txt").exists()


@pytest.mark.parametrize("tag", ["../x", "v1/../../x", "v1 0", "-rf", "v1;ls"])
def test_release_tag_with_unsafe_characters_is_refused(env, tag) -> None:
    fetch, requested = fake_network("v1.0.0", build_archive("v1.0.0"))
    assert run(env, f"--release={tag}", fetch=fetch) == install.EXIT_FAIL
    assert requested == []


@pytest.mark.parametrize("repo", ["nonsense", "a/b/c", "a b/c", "../x"])
def test_repo_with_unsafe_characters_is_refused(env, repo) -> None:
    fetch, requested = fake_network("v1.0.0", build_archive("v1.0.0"))
    assert run(env, "--release", "v1.0.0", "--repo", repo, fetch=fetch) == install.EXIT_FAIL
    assert requested == []


def test_source_and_release_are_mutually_exclusive(env, tmp_path) -> None:
    with pytest.raises(SystemExit) as excinfo:
        run(env, "--source", str(tmp_path), "--release", "v1.0.0")
    assert excinfo.value.code != 0
