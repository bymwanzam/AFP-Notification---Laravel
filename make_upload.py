"""Build the file to upload to the cPanel server. Each zip holds one folder, surveillance_app/: upload it to the home
folder in File Manager and Extract it there.

python make_upload.py            dist/surveillance_app.zip: first install. The app with its libraries, the workbooks
                                 (from storage/app/data, or DATA_SOURCE) and .env (the server's settings).
python make_upload.py --update   dist/surveillance_app-update.zip: later updates. The app only: extracting it over the
                                 installed one never touches the server's .env, database or workbooks.

dist/.env is made once (a new app key and setup token) and reused by later builds: a new key would make the saved
SMS secret unreadable and sign everyone out. dist/SETUP LINK.txt has the setup page's link. Left out: this
computer's .env, database, workbooks, logs, sessions, tests and development libraries.
Needs PHP 8.3 and Composer on this computer (set PHP and COMPOSER to their paths if they are not found).
"""
import base64
import os
import secrets
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

BASE = Path(__file__).resolve().parent
DIST = BASE / "dist"
TOP = os.environ.get("APP_FOLDER", "surveillance")  # the app's folder in the server's home folder
DATA_SOURCE = Path(os.environ.get("DATA_SOURCE") or BASE / "storage" / "app" / "data")
SITE = os.environ.get("SITE", "surveillance.rhduer.online")
PHP = os.environ.get("PHP") or shutil.which("php") or str(
    Path(os.environ.get("LOCALAPPDATA", "")) / "Microsoft/WinGet/Packages/PHP.PHP.NTS.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe")
COMPOSER = os.environ.get("COMPOSER") or str(Path.home() / "bin" / "composer.phar")

SKIP_DIRS = {".git", "node_modules", "vendor", "tests", "dist", ".phpunit.cache", ".vscode", ".idea"}
SKIP_FILES = {".env", ".env.backup", ".env.production", ".phpunit.result.cache", "make_upload.py", "AGENTS.md", "CLAUDE.md"}
# Folders whose contents belong to this computer: kept empty (with their .gitignore) in the zip.
EMPTY = {"storage/app/data", "storage/app/private", "storage/app/public", "storage/logs", "storage/framework/cache",
         "storage/framework/sessions", "storage/framework/views", "storage/framework/testing", "bootstrap/cache"}


def app_files(root):
    for dirpath, dirs, files in os.walk(root):
        rel = Path(dirpath).relative_to(root).as_posix()
        rel = "" if rel == "." else rel
        dirs[:] = sorted(d for d in dirs if not (rel == "" and d in SKIP_DIRS))
        inside_empty = any(rel == e or rel.startswith(e + "/") for e in EMPTY)
        for name in sorted(files):
            arc = f"{rel}/{name}" if rel else name
            if (rel == "" and name in SKIP_FILES) or name.endswith(".sqlite") or (inside_empty and name != ".gitignore"):
                continue
            yield Path(dirpath) / name, arc


def stage_app(work):
    """The app and its production libraries, in the folder `work`."""
    for src, arc in app_files(BASE):
        dest = work / arc
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dest)
    for e in EMPTY:
        (work / e).mkdir(parents=True, exist_ok=True)
    print("Installing the production libraries…")
    subprocess.run([PHP, COMPOSER, "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-scripts",
                    "--quiet"], cwd=work, check=True)


def stage_data(work):
    n = 0
    for p in sorted(DATA_SOURCE.rglob("*")):
        if p.is_file() and not p.name.endswith(".bak.xlsx") and p.name not in (".write.lock", "support_account.json"):
            dest = work / "storage/app/data" / p.relative_to(DATA_SOURCE)
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(p, dest)
            n += 1
    print(f"  {n} workbooks and files from {DATA_SOURCE}")


def env_file():
    """dist/.env: made once with a new app key and setup token, then reused."""
    f = DIST / ".env"
    if f.exists():
        print(f"  reusing {f} (same app key and setup link)")
        return f
    key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
    lines = []
    for line in (BASE / ".env.example").read_text(encoding="utf-8").splitlines():
        name = line.split("=", 1)[0]
        lines.append({"APP_ENV": "APP_ENV=production", "APP_KEY": f"APP_KEY={key}", "APP_DEBUG": "APP_DEBUG=false",
                      "APP_URL": f"APP_URL=https://{SITE}", "LOG_LEVEL": "LOG_LEVEL=warning",
                      "LOG_STACK": "LOG_STACK=daily"}.get(name, line))
    token = secrets.token_urlsafe(24)
    lines += ["SESSION_SECURE_COOKIE=true", "", f"# The setup page: https://{SITE}/install?token=<this>", f"INSTALL_TOKEN={token}"]
    f.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    print(f"  new {f} (new app key and setup link)")
    return f


def write_zip(work, out):
    n = 0
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for dirpath, dirs, files in os.walk(work):
            dirs.sort()
            rel = Path(TOP) / Path(dirpath).relative_to(work)
            if not files and not dirs:
                z.writestr(rel.as_posix() + "/", "")
            for name in sorted(files):
                src, arc = Path(dirpath) / name, (rel / name).as_posix()
                if src.suffix == ".sh":  # bash on the server stops at Windows line endings
                    z.writestr(arc, src.read_bytes().replace(b"\r\n", b"\n"))
                else:
                    z.write(src, arc)
                n += 1
    print(f"{out}  ({n} files, {out.stat().st_size // 1024} KB)")


def setup_link():
    token = next((l.split("=", 1)[1] for l in (DIST / ".env").read_text(encoding="utf-8").splitlines()
                  if l.startswith("INSTALL_TOKEN=")), "")
    link = f"https://{SITE}/install?token={token}"
    (DIST / "SETUP LINK.txt").write_text(f"After extracting the zip and pointing the website at "
                                         f"{TOP}/public, open this link to finish the setup:\n\n{link}\n\n"
                                         f"Open it again after every update. Keep it private.\n", encoding="utf-8")
    print(f"Setup link in {DIST / 'SETUP LINK.txt'}")


if __name__ == "__main__":
    update = "--update" in sys.argv
    DIST.mkdir(exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="afp-build-"))
    try:
        stage_app(work)
        env = env_file()
        if not update:
            stage_data(work)
            shutil.copy2(env, work / ".env")
        write_zip(work, DIST / (f"{TOP}-update.zip" if update else f"{TOP}.zip"))
        setup_link()
    finally:
        shutil.rmtree(work, ignore_errors=True)
