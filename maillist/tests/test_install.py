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
# The modules maillist.py imports from its own directory; a release is incomplete without any of them.
MODULES = ("config.py", "delivery.py", "headers.py", "members.py")


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

    def __init__(self, root=True, users=(), deps=True, check_ok=True, trusted=None):
        self.root, self.users, self.deps, self.check_ok = root, set(users), deps, check_ok
        self.trusted = trusted if trusted is not None else {os.geteuid(), 0}
        self.calls: list[tuple] = []

    def trusted_uids(self):
        return self.trusted

    def fchown(self, fd, user, group):
        self.calls.append(("fchown", os.fstat(fd).st_ino, user, group))

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
    for name in MODULES:
        (src / name).write_text(f"# {name}\n")
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
    assert not (env["prefix"] / "releases").exists()  # nothing orphaned before the config is filled in
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
    for name in ("maillist.py", *MODULES, "maillist.ini.example", "VERSION", "README.md"):
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
    # pipe(8) passes no environment, so a non-default config path has to travel in argv
    expected = (
        f"argv=/usr/bin/env MAILLIST_CONFIG={env['config']} /usr/bin/python3x {env['prefix']}/current/maillist.py"
    )
    assert f"{expected} -- ${{user}}" in out
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
    fill_config(env)
    run(env, "--source", str(src))
    (name,) = release_names(env)
    expected = hashlib.sha256()
    for code in ("maillist.py", *MODULES):
        data = (src / code).read_bytes()
        expected.update(f"{code}\0{len(data)}\0".encode() + data)
    digest = expected.hexdigest()[:8]
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
    parent_ino = env["config"].parent.stat().st_ino
    assert ("fchown", parent_ino, "root", "listuser") in runner.calls
    assert ("fchown", env["config"].stat().st_ino, "root", "listuser") in runner.calls
    fill_config(env)
    run(env, "--source", str(src), "--user", "listuser", user=True, runner=runner)
    assert ("fchown", env["log"].stat().st_ino, "listuser", "listuser") in runner.calls
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


def build_archive(tag: str, members=None, version=None, omit=()) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        files = {
            f"maillist-{tag}/maillist.py": b"print('release')\n",
            **{f"maillist-{tag}/{name}": f"# {name}\n".encode() for name in MODULES},
            f"maillist-{tag}/maillist.ini.example": (ROOT / "maillist.ini.example").read_bytes(),
            f"maillist-{tag}/VERSION": (version or tag).encode() + b"\n",
            f"maillist-{tag}/README.md": b"readme\n",
        }
        for name, data in files.items():
            if name.split("/", 1)[1] in omit:
                continue
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


# --- review round: security hardening ------------------------------------------------------


def configured(env, version="1.0.0"):
    """First run creates the config, then fill it in. Returns the source directory."""
    src = make_source(env["tmp"], version)
    assert run(env, "--source", str(src)) == install.EXIT_CONFIG
    fill_config(env)
    return src


def snapshot_modes(path):
    return mode(path), path.stat().st_ino


# 1. log file


def test_log_symlink_is_refused_and_target_untouched(env) -> None:
    src = configured(env)
    victim = env["tmp"] / "victim"
    victim.write_text("precious")
    victim.chmod(0o600)
    env["log"].parent.mkdir(parents=True)
    env["log"].symlink_to(victim)
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert victim.read_text() == "precious" and mode(victim) == 0o600
    assert not (env["prefix"] / "current").exists()


def test_dangling_log_symlink_is_refused(env) -> None:
    src = configured(env)
    target = env["tmp"] / "does-not-exist"
    env["log"].parent.mkdir(parents=True)
    env["log"].symlink_to(target)
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert not target.exists()


def test_log_hardlink_is_refused_and_target_untouched(env) -> None:
    src = configured(env)
    victim = env["tmp"] / "victim"
    victim.write_text("precious")
    victim.chmod(0o666)
    env["log"].parent.mkdir(parents=True)
    os.link(victim, env["log"])
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert mode(victim) == 0o666 and victim.read_text() == "precious"


def test_log_parent_writable_by_others_is_refused(env) -> None:
    src = configured(env)
    env["log"].parent.mkdir(parents=True)
    env["log"].parent.chmod(0o777)
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert not env["log"].exists()


def test_log_parent_not_owned_by_trusted_user_is_refused(env) -> None:
    src = configured(env)
    env["log"].parent.mkdir(parents=True)
    assert run(env, "--source", str(src), runner=FakeRunner(trusted={0})) == install.EXIT_FAIL
    assert not env["log"].exists()


def test_log_parent_created_by_installer_is_0755(env) -> None:
    src = configured(env)
    assert run(env, "--source", str(src)) == 0
    assert mode(env["log"].parent) == 0o755
    assert mode(env["log"]) == 0o640


def test_existing_log_file_gets_mode_corrected_in_place(env) -> None:
    src = configured(env)
    env["log"].parent.mkdir(parents=True)
    env["log"].write_text("old line\n")
    env["log"].chmod(0o666)
    inode = env["log"].stat().st_ino
    assert run(env, "--source", str(src)) == 0
    assert mode(env["log"]) == 0o640 and env["log"].stat().st_ino == inode
    assert env["log"].read_text() == "old line\n"


# 2. config and its parent directory


def test_config_in_existing_shared_directory_leaves_directory_alone(env) -> None:
    shared = env["tmp"] / "shared"
    shared.mkdir()
    shared.chmod(0o755)
    env["config"] = shared / "maillist.ini"
    runner = FakeRunner()
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), "--user", "listuser", user=True, runner=runner) == install.EXIT_CONFIG
    assert mode(shared) == 0o755
    assert ("chown", str(shared), "root", "listuser") not in runner.calls
    assert mode(env["config"]) == 0o640


def test_config_in_group_writable_shared_directory_is_refused(env) -> None:
    shared = env["tmp"] / "shared"
    shared.mkdir()
    shared.chmod(0o775)
    env["config"] = shared / "maillist.ini"
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert not env["config"].exists()
    assert mode(shared) == 0o775


def test_config_directory_with_untrusted_owner_is_refused(env) -> None:
    shared = env["tmp"] / "shared"
    shared.mkdir()
    env["config"] = shared / "maillist.ini"
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), runner=FakeRunner(trusted={0})) == install.EXIT_FAIL


def test_config_directory_created_by_installer_is_0750(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    run(env, "--source", str(src))
    assert mode(env["config"].parent) == 0o750


def test_symlinked_config_is_refused_and_target_untouched(env) -> None:
    real = env["tmp"] / "real.ini"
    real.write_text("[log]\nfile = " + str(env["log"]) + "\n")
    real.chmod(0o666)
    env["config"].parent.mkdir(parents=True)
    env["config"].symlink_to(real)
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert mode(real) == 0o666


def test_config_creation_does_not_follow_a_planted_symlink(env) -> None:
    victim = env["tmp"] / "victim"
    victim.write_text("precious")
    env["config"].parent.mkdir(parents=True)
    env["config"].symlink_to(victim)  # dangling-or-not, creation must refuse
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert victim.read_text() == "precious"


# 3. master.cf and the config path


def test_default_config_path_needs_no_env_wrapper(env, monkeypatch, capsys) -> None:
    monkeypatch.setattr(install, "DEFAULT_CONFIG", str(env["config"]))
    src = configured(env)
    capsys.readouterr()
    assert run(env, "--source", str(src)) == 0
    out = capsys.readouterr().out
    assert "/usr/bin/env" not in out and "MAILLIST_CONFIG" not in out
    assert f"argv=/usr/bin/python3 {env['prefix']}/current/maillist.py -- ${{user}}" in out


def test_maillist_script_honours_config_environment_variable() -> None:
    assert 'CONFIG_ENV_VAR = "MAILLIST_CONFIG"' in (ROOT / "config.py").read_text()


@pytest.mark.parametrize("flag", ["--prefix", "--config", "--python"])
def test_paths_with_whitespace_are_refused(env, flag) -> None:
    src = make_source(env["tmp"], "1.0.0")
    extra = [flag, str(env["tmp"] / "with space" / "x")]
    assert run(env, "--source", str(src), *extra) == install.EXIT_FAIL


def test_relative_python_is_refused(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), "--python", "python3") == install.EXIT_FAIL


# 4. --check environment


def test_check_runs_with_minimal_environment_and_timeout(monkeypatch, tmp_path) -> None:
    seen = {}

    def fake_run(cmd, **kwargs):
        seen.update(cmd=cmd, **kwargs)
        return install.subprocess.CompletedProcess(cmd, 0, stdout="ok", stderr="")

    monkeypatch.setattr(install.subprocess, "run", fake_run)
    ok, _ = install.Runner().run_check("/usr/bin/python3", tmp_path / "maillist.py", tmp_path / "c.ini", None)
    assert ok
    assert seen["env"] == {"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "MAILLIST_CONFIG": str(tmp_path / "c.ini")}
    assert seen["timeout"] == 60


def test_check_timeout_counts_as_failure(monkeypatch, tmp_path) -> None:
    def fake_run(cmd, **kwargs):
        raise install.subprocess.TimeoutExpired(cmd, 60)

    monkeypatch.setattr(install.subprocess, "run", fake_run)
    ok, output = install.Runner().run_check("/usr/bin/python3", tmp_path / "m.py", tmp_path / "c.ini", None)
    assert ok is False and "timed out" in output


def test_check_really_gets_only_the_minimal_environment(monkeypatch, tmp_path) -> None:
    script = tmp_path / "probe.py"
    script.write_text(
        "import os, sys\nprint(sorted(k for k in os.environ if k in ('HOME','SECRET','MAILLIST_CONFIG','PATH')))\n"
    )
    monkeypatch.setenv("SECRET", "x")
    ok, output = install.Runner().run_check(sys.executable, script, tmp_path / "c.ini", None)
    assert ok and "SECRET" not in output and "HOME" not in output and "MAILLIST_CONFIG" in output


# 5. umask


def test_main_uses_a_sane_umask_and_restores_the_callers(env) -> None:
    deep = env["tmp"] / "etc" / "a" / "b" / "maillist.ini"
    env["config"] = deep
    src = make_source(env["tmp"], "1.0.0")
    old = os.umask(0o077)
    try:
        assert run(env, "--source", str(src)) == install.EXIT_CONFIG
        assert os.umask(0o077) == 0o077  # restored to what the caller had
    finally:
        os.umask(old)
    assert mode(deep.parent.parent) == 0o755  # intermediate directory created under umask 022


# 6. redirects and latest


def test_redirect_handler_rejects_non_https_targets() -> None:
    import urllib.request

    handler = install._HttpsOnlyRedirect()
    request = urllib.request.Request("https://github.com/x")
    with pytest.raises(install.InstallError):
        handler.redirect_request(request, None, 302, "Found", {}, "http://evil.example/x")
    handler.redirect_request(request, None, 302, "Found", {}, "https://objects.example.org/x")


@pytest.mark.parametrize(
    "final",
    [
        "https://evil.example/r00tat/cyrus-mailadmin/releases/tag/v1.0.0",
        "https://github.com/other/repo/releases/tag/v1.0.0",
        "https://github.com/r00tat/cyrus-mailadmin/releases/latest",
        "https://github.com/r00tat/cyrus-mailadmin/releases/tag/v1;ls",
        "https://github.com/r00tat/cyrus-mailadmin/releases/tag/",
        "http://github.com/r00tat/cyrus-mailadmin/releases/tag/v1.0.0",
    ],
)
def test_latest_with_unexpected_final_url_is_refused(env, final) -> None:
    fetch, requested = fake_network("v1.0.0", build_archive("v1.0.0"))
    assert run(env, "--release", "latest", fetch=fetch, final_url=lambda _u: final) == install.EXIT_FAIL
    assert requested == []


# 7. size caps


def test_oversized_archive_is_refused(env, monkeypatch) -> None:
    archive = build_archive("v1.0.0")
    monkeypatch.setattr(install, "MAX_ARCHIVE_BYTES", len(archive) - 1)
    fetch, _ = fake_network("v1.0.0", archive)
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL


def test_oversized_checksum_file_is_refused(env, monkeypatch) -> None:
    monkeypatch.setattr(install, "MAX_SUMS_BYTES", 10)
    fetch, _ = fake_network("v1.0.0", build_archive("v1.0.0"))
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL


def test_default_fetch_reads_at_most_the_cap_plus_one(monkeypatch) -> None:
    reads = []

    class Response:
        def __enter__(self):
            return self

        def __exit__(self, *_):
            return False

        def read(self, n=-1):
            reads.append(n)
            return b"x" * (n if n >= 0 else 10)

    class Opener:
        def open(self, url, timeout=None):
            return Response()

    monkeypatch.setattr(install, "_opener", lambda: Opener())
    monkeypatch.setattr(install, "MAX_ARCHIVE_BYTES", 5)
    with pytest.raises(install.InstallError, match="too large"):
        install.default_fetch("https://github.com/x")
    assert reads == [6]


# 8. prefix ownership and prune


def test_existing_prefix_with_untrusted_owner_is_refused(env) -> None:
    env["prefix"].mkdir()
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), runner=FakeRunner(trusted={0})) == install.EXIT_FAIL


def test_world_writable_prefix_is_refused(env) -> None:
    env["prefix"].mkdir()
    env["prefix"].chmod(0o777)
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert mode(env["prefix"]) == 0o777


def test_group_writable_releases_directory_is_refused(env) -> None:
    src = configured(env)
    assert run(env, "--source", str(src)) == 0
    (env["prefix"] / "releases").chmod(0o775)
    assert run(env, "--source", str(src)) == install.EXIT_FAIL


def test_prune_skips_symlinks_in_releases(env) -> None:
    src = configured(env)
    outside = env["tmp"] / "outside"
    outside.mkdir()
    (outside / "keep.txt").write_text("x")
    run(env, "--source", str(src))
    (env["prefix"] / "releases" / "evil").symlink_to(outside)
    for version in ("1.1.0", "1.2.0", "1.3.0"):
        run(env, "--source", str(make_source(env["tmp"] / "s", version)), "--keep-releases", "1")
    assert (outside / "keep.txt").read_text() == "x"
    assert (env["prefix"] / "releases" / "evil").is_symlink()


# 9. orphans


def test_failed_verification_removes_the_release_created_in_this_run(env) -> None:
    src = configured(env)
    assert run(env, "--source", str(src), verify=True, runner=FakeRunner(check_ok=False)) == install.EXIT_FAIL
    assert release_names(env) == []


def test_failed_verification_keeps_a_release_that_already_existed(env) -> None:
    src = configured(env)
    assert run(env, "--source", str(src)) == 0
    assert run(env, "--source", str(src), verify=True, runner=FakeRunner(check_ok=False)) == install.EXIT_FAIL
    assert release_names(env) == ["1.0.0"]
    assert os.readlink(env["prefix"] / "current") == "releases/1.0.0"


# 10. module header


def test_installer_declares_future_annotations_and_checks_python_at_import() -> None:
    text = INSTALL.read_text()
    assert "from __future__ import annotations" in text
    assert text.index("sys.version_info") < text.index("class InstallError")


# 11. dry-run prune and the config note


def test_dry_run_prune_matches_what_a_real_run_removes(env, capsys) -> None:
    src = configured(env)
    run(env, "--source", str(src))
    for version in ("1.1.0", "1.2.0"):
        assert run(env, "--source", str(make_source(env["tmp"] / "s", version)), "--keep-releases", "9") == 0
    nxt = make_source(env["tmp"] / "s", "1.3.0")
    capsys.readouterr()
    run(env, "--source", str(nxt), "--keep-releases", "2", "--dry-run")
    dry = capsys.readouterr().out
    would_remove = sorted(re.findall(r"remove old release (\S+)", dry))
    before = set(release_names(env))
    run(env, "--source", str(nxt), "--keep-releases", "2")
    removed = sorted(before - set(release_names(env)))
    assert would_remove == removed and removed


@pytest.mark.skipif(os.geteuid() == 0, reason="root can read any file")
def test_dry_run_with_unreadable_config_notes_the_log_path_may_be_the_default(env, capsys) -> None:
    src = configured(env)
    env["config"].chmod(0o000)
    try:
        capsys.readouterr()
        run(env, "--source", str(src), "--dry-run")
        out = capsys.readouterr().out.lower()
    finally:
        env["config"].chmod(0o640)
    assert "note" in out and "default" in out


# --- second review round ----------------------------------------------------------------


def test_run_check_resolves_runuser_to_an_absolute_path(monkeypatch, tmp_path) -> None:
    seen = {}

    def fake_run(cmd, **kwargs):
        seen["cmd"] = cmd
        return install.subprocess.CompletedProcess(cmd, 0, stdout="", stderr="")

    searched = {}

    def fake_which(name, path=None):
        searched["path"] = path
        return f"/usr/sbin/{name}" if name == "runuser" else None

    monkeypatch.setattr(install.subprocess, "run", fake_run)
    monkeypatch.setattr(install.shutil, "which", fake_which)
    runner = install.Runner()
    monkeypatch.setattr(runner, "is_root", lambda: True)
    ok, _ = runner.run_check("/usr/bin/python3", tmp_path / "m.py", tmp_path / "c.ini", "listuser")
    assert ok
    assert seen["cmd"][0] == "/usr/sbin/runuser"
    assert os.path.isabs(seen["cmd"][0])
    assert "/usr/sbin" in searched["path"]


def test_run_check_falls_back_to_setpriv_and_fails_clearly_without_either(monkeypatch, tmp_path) -> None:
    import pwd

    monkeypatch.setattr(
        install.shutil, "which", lambda name, path=None: "/usr/bin/setpriv" if name == "setpriv" else None
    )
    monkeypatch.setattr(
        install.subprocess,
        "run",
        lambda cmd, **kw: seen.update(cmd=cmd) or install.subprocess.CompletedProcess(cmd, 0, stdout="", stderr=""),
    )
    seen = {}
    monkeypatch.setattr(pwd, "getpwnam", lambda n: pwd.getpwuid(os.getuid()))
    runner = install.Runner()
    monkeypatch.setattr(runner, "is_root", lambda: True)
    runner.run_check("/usr/bin/python3", tmp_path / "m.py", tmp_path / "c.ini", "listuser")
    assert seen["cmd"][0] == "/usr/bin/setpriv"
    monkeypatch.setattr(install.shutil, "which", lambda name, path=None: None)
    with pytest.raises(install.InstallError, match="runuser"):
        runner.run_check("/usr/bin/python3", tmp_path / "m.py", tmp_path / "c.ini", "listuser")


@pytest.mark.parametrize("which", ["config", "log", "prefix"])
def test_new_directories_under_a_writable_ancestor_are_refused(env, which) -> None:
    open_dir = env["tmp"] / "open"
    open_dir.mkdir()
    open_dir.chmod(0o777)
    src = configured(env)
    if which == "config":
        env["config"].unlink()
        env["config"] = open_dir / "new" / "maillist.ini"
    elif which == "log":
        text = env["config"].read_text()
        env["config"].write_text(re.sub(r"(?m)^file = .*$", f"file = {open_dir}/new/maillist.log", text))
    else:
        env["prefix"] = open_dir / "new" / "prefix"
    try:
        assert run(env, "--source", str(src)) == install.EXIT_FAIL
        assert not (open_dir / "new").exists()
    finally:
        open_dir.chmod(0o755)


def test_created_directories_get_their_mode_through_a_descriptor(env) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_CONFIG
    assert mode(env["config"].parent) == 0o750
    fill_config(env)
    assert run(env, "--source", str(src)) == 0
    assert mode(env["log"].parent) == 0o755
    assert mode(env["prefix"]) == 0o755 and mode(env["prefix"] / "releases") == 0o755


@pytest.mark.parametrize("flag", ["--prefix", "--config", "--python"])
def test_paths_with_dollar_sign_are_refused(env, flag, capsys) -> None:
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src), flag, str(env["tmp"] / "a$b" / "x")) == install.EXIT_FAIL
    assert "$" in capsys.readouterr().err


def test_failed_config_write_leaves_no_half_written_file(env) -> None:
    class Failing(FakeRunner):
        def fchown(self, fd, user, group):
            raise OSError("boom")

    src = make_source(env["tmp"], "1.0.0")
    runner = Failing()
    assert run(env, "--source", str(src), "--user", "listuser", user=True, runner=runner) == install.EXIT_FAIL
    assert not env["config"].exists()


def test_checksum_file_naming_the_archive_twice_is_refused(env) -> None:
    archive = build_archive("v1.2.3")
    name = "maillist-v1.2.3.tar.gz"
    good = hashlib.sha256(archive).hexdigest()
    sums = f"{'0' * 64}  {name}\n{good}  {name}\n"
    fetch, _ = fake_network("v1.2.3", archive, sums=sums)
    assert run(env, "--release", "v1.2.3", fetch=fetch) == install.EXIT_FAIL


# 5. Isolated interpreter


def test_dependency_check_runs_isolated_from_root_directory(monkeypatch) -> None:
    seen = {}

    def fake_run(cmd, **kwargs):
        seen.update(cmd=cmd, **kwargs)
        return install.subprocess.CompletedProcess(cmd, 0, stdout="", stderr="")

    monkeypatch.setattr(install.subprocess, "run", fake_run)
    assert install.Runner().check_dependency("/usr/bin/python3")
    assert seen["cmd"] == ["/usr/bin/python3", "-I", "-c", "import mysql.connector"]
    assert seen["cwd"] == "/"


def test_check_runs_isolated_from_root_directory(monkeypatch, tmp_path) -> None:
    seen = {}

    def fake_run(cmd, **kwargs):
        seen.update(cmd=cmd, **kwargs)
        return install.subprocess.CompletedProcess(cmd, 0, stdout="ok", stderr="")

    monkeypatch.setattr(install.subprocess, "run", fake_run)
    install.Runner().run_check("/usr/bin/python3", tmp_path / "maillist.py", tmp_path / "c.ini", None)
    assert seen["cmd"] == ["/usr/bin/python3", "-I", str(tmp_path / "maillist.py"), "--check"]
    assert seen["cwd"] == "/"


def test_isolation_warning_only_without_isolated_flag(monkeypatch) -> None:
    class Flags:
        isolated = 0

    monkeypatch.setattr(install.sys, "flags", Flags)
    assert "python3 -I" in install.isolation_warning()
    Flags.isolated = 1
    assert install.isolation_warning() is None


# --- sibling modules ----------------------------------------------------------------------


def test_code_files_are_the_script_and_every_module_beside_it() -> None:
    assert ("maillist.py", *MODULES) == install.CODE_FILES
    assert set(install.CODE_FILES) <= set(install.RELEASE_FILES)
    assert not set(install.CODE_FILES) & set(install.OPTIONAL_FILES)
    assert sorted(p.name for p in ROOT.glob("*.py")) == sorted(install.CODE_FILES)


def test_packaging_and_project_list_every_code_file() -> None:
    package = (ROOT.parent / "bin" / "package-release").read_text()
    (line,) = [line for line in package.splitlines() if line.startswith("readonly MAILLIST_FILES=")]
    pyproject = (ROOT / "pyproject.toml").read_text()
    for name in install.CODE_FILES:
        assert name in line.split("(", 1)[1].split()
        assert f'"{name.removesuffix(".py")}"' in pyproject


def configured_source(env) -> Path:
    """Install release 1.0.0 from a source directory, configuration included; return that directory."""
    src = make_source(env["tmp"], "1.0.0")
    assert run(env, "--source", str(src)) == install.EXIT_CONFIG
    fill_config(env)
    assert run(env, "--source", str(src)) == 0
    return src


def test_install_copies_every_module_byte_for_byte(env) -> None:
    src = configured_source(env)
    current = env["prefix"] / "current"
    for name in install.CODE_FILES:
        assert (current / name).read_bytes() == (src / name).read_bytes()
        assert not (current / name).is_symlink()
        assert mode(current / name) == 0o644


@pytest.mark.parametrize("module", MODULES)
def test_source_without_a_module_is_refused(env, capsys, module) -> None:
    src = make_source(env["tmp"], "1.0.0")
    (src / module).unlink()
    assert run(env, "--source", str(src)) == install.EXIT_FAIL
    assert f"no {module} in" in capsys.readouterr().err
    assert not env["prefix"].exists()
    assert not env["config"].exists()


@pytest.mark.parametrize("module", MODULES)
def test_release_archive_without_a_module_is_refused(env, module) -> None:
    fetch, _ = fake_network("v1.0.0", build_archive("v1.0.0", omit=(module,)))
    run(env, "--release", "v1.0.0", fetch=fetch)
    fill_config(env)
    assert run(env, "--release", "v1.0.0", fetch=fetch) == install.EXIT_FAIL
    assert not (env["prefix"] / "current").exists()
    assert release_names(env) == []


@pytest.mark.parametrize("module", MODULES)
def test_same_version_with_a_different_module_is_refused(env, module) -> None:
    first = configured_source(env)
    other = make_source(env["tmp"] / "other", "1.0.0")
    (other / module).write_text("# changed\n")
    assert run(env, "--source", str(other)) == install.EXIT_FAIL
    assert (env["prefix"] / "current" / module).read_bytes() == (first / module).read_bytes()


@pytest.mark.parametrize("module", MODULES)
def test_dev_release_id_changes_with_every_module(env, module) -> None:
    src = make_source(env["tmp"], "dev")
    before = install.code_digest(src)
    (src / module).write_text("# changed\n")
    assert install.code_digest(src) != before


@pytest.mark.parametrize("module", MODULES)
def test_existing_release_missing_a_module_is_not_reused(env, module) -> None:
    # The comparison reads every code file of an existing release; a missing one means different content.
    src = configured_source(env)
    (env["prefix"] / "releases" / "1.0.0" / module).unlink()
    assert run(env, "--source", str(src)) == install.EXIT_FAIL


# --- the installed script really runs its --check -----------------------------------------


class RealCheckRunner(FakeRunner):
    """FakeRunner, but the dependency check and --check run for real, as the installer starts them.

    No database is reachable in the tests, so the check counts as passed once configuration and log file
    were checked: that only happens after maillist.py imported all its modules.
    """

    def __init__(self):
        super().__init__()
        self.outputs: list[str] = []

    def check_dependency(self, python):
        return install.Runner().check_dependency(python)

    def run_check(self, python, script, config, user):
        ok, output = install.Runner().run_check(python, script, config, user)
        self.outputs.append(output)
        return ("config ok\nlog ok" in output and "Traceback" not in output), output


def write_check_config(env) -> None:
    # Port 1 on the loopback refuses at once, so the database step fails fast instead of timing out.
    env["config"].write_text(
        "[database]\nhost = 127.0.0.1\nport = 1\nuser = u\npassword = p\n"
        "[list]\nowner = o@example.org\nerror_report_to = a@example.org\nerror_report_from = m@example.org\n"
        f"[log]\nfile = {env['log']}\n"
    )


def assert_check_runs(env, runner) -> None:
    (output,) = runner.outputs
    assert "config ok\nlog ok\ndatabase FAILED" in output, output
    assert "ModuleNotFoundError" not in output and "ImportError" not in output
    script = env["prefix"] / "current" / "maillist.py"
    environment = {"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "MAILLIST_CONFIG": str(env["config"])}
    # As the installer starts it (-I leaves the script directory off sys.path) and as Postfix does (no -I).
    for flags in (["-I"], []):
        result = install.subprocess.run(  # noqa: S603
            [sys.executable, *flags, str(script), "--check"],
            capture_output=True,
            text=True,
            env=environment,
            cwd="/",
            timeout=60,
            check=False,
        )
        assert result.returncode == 75, result.stderr
        assert result.stdout.startswith("config ok\nlog ok\n"), result.stdout + result.stderr
        assert "database FAILED" in result.stderr


def test_install_from_source_yields_a_runnable_check(env) -> None:
    run(env, "--source", str(ROOT))
    write_check_config(env)
    runner = RealCheckRunner()
    assert run(env, "--source", str(ROOT), "--python", sys.executable, verify=True, runner=runner) == 0
    assert_check_runs(env, runner)


def test_install_from_release_archive_yields_a_runnable_check(env) -> None:
    tag = "v9.9.9"
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for name in install.RELEASE_FILES:
            data = tag.encode() + b"\n" if name == "VERSION" else (ROOT / name).read_bytes()
            info = tarfile.TarInfo(f"maillist-{tag}/{name}")
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))
    fetch, _ = fake_network(tag, buffer.getvalue())
    run(env, "--release", tag, fetch=fetch)
    write_check_config(env)
    runner = RealCheckRunner()
    assert run(env, "--release", tag, "--python", sys.executable, verify=True, runner=runner, fetch=fetch) == 0
    assert os.readlink(env["prefix"] / "current") == f"releases/{tag}"
    assert_check_runs(env, runner)
