#!/usr/bin/env python3
"""Administrator route regression checks with isolated state and test-only TLS."""
import importlib.util
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess

spec = importlib.util.spec_from_file_location("auth_http", Path(__file__).with_name("http-auth.py"))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


def field(html, name):
    return re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)[1]


def form(browser, path):
    status, html, headers = browser.request(path)
    assert status == 200, (path, status, html)
    assert "no-store" in headers["cache-control"]
    return html, field(html, "csrf")


def run(origin, state, client_config, _port):
    root = auth.Browser(origin)
    root.login()
    cat = auth.Browser(origin, "192.0.2.20")
    cat.login("cat")
    routes = ["/dj/admin/", "/dj/admin/accounts.php", "/dj/admin/account.php",
              "/dj/admin/profiles.php", "/dj/admin/profile.php", "/dj/admin/stations.php",
              "/dj/admin/audit.php", "/dj/admin/export.php",
              "/dj/admin/schedule.php?source_station=1&source_streamer=4&station=1"]
    for path in routes:
        assert cat.request(path)[0] == 403, path
        assert auth.Browser(origin).request(path)[0] == 303, path
    for path in routes:
        assert root.request(path)[0] == 200, path
    db = sqlite3.connect(state / "admin.sqlite")
    account_path = "/dj/admin/account.php?station=1&streamer=4"
    profile_path = "/dj/admin/profile.php?slug=http-test"
    html, csrf = form(root, "/dj/admin/profile.php")
    profile = {
        "name": "<Test DJ>", "published": True, "bio": ["Safe text"],
        "show": {"title": "Kept", "timezone": "UTC",
                 "formats": [{"id": "late", "title": "Late show", "days": ["Saturday"]}]},
        "custom": {"kept": True},
    }
    save = {"csrf": csrf, "action": "save_json", "version": "0",
            "slug": "http-test", "profile_json": json.dumps(profile)}
    assert root.request("/dj/admin/profile.php", save | {"csrf": "bad"})[0] == 403
    assert root.request("/dj/admin/profile.php", save, origin="https://attacker.invalid")[0] == 403
    assert root.request("/dj/admin/profile.php", save)[0] == 303
    html, csrf = form(root, profile_path)
    assert "&lt;Test DJ&gt;" in html and "<Test DJ>" not in html
    version = field(html, "version")
    assert root.request(profile_path, save | {"csrf": csrf, "version": version})[0] == 303
    status, rejected, _ = root.request(profile_path, save | {"csrf": csrf, "version": version})
    assert status == 409 and field(rejected, "version") == version
    assert root.request(profile_path, save | {"csrf": csrf, "version": field(rejected, "version")})[0] == 409
    assert root.request("/dj/admin/profile.php", save | {"csrf": csrf, "slug": "../escape"})[0] == 422
    assert root.request("/dj/admin/profile.php", save | {"csrf": csrf, "slug": "bad", "profile_json": '{"links":{"Bad":"javascript:alert(1)"}}'})[0] == 422
    assert root.request("/dj/admin/account.php", {
        "csrf": csrf, "action": "save", "version": "0", "station_id": "2",
        "streamer_id": "4", "enabled": "1", "role": "admin",
    })[0] == 422
    assert root.request("/dj/admin/account.php", {
        "csrf": csrf, "action": "save", "version": "0", "station_id": "1",
        "streamer_id": "999", "enabled": "1", "role": "admin",
    })[0] == 422
    assert db.execute("select count(*) from accounts where streamer_id=999").fetchone()[0] == 0
    html, csrf = form(root, account_path)
    save_account = {
        "csrf": csrf, "action": "save", "version": field(html, "version"),
        "label": "Website Cat", "profile_slug": "http-test", "enabled": "1",
        "role": "admin", "stations[]": "1", "target_streamers[1]": "4",
    }
    assert root.request(account_path, save_account)[0] == 303
    assert cat.request("/dj/admin/")[0] == 200
    html, csrf = form(root, account_path)
    assert root.request(account_path, save_account | {
        "csrf": csrf, "version": field(html, "version"), "role": "dj",
    })[0] == 303
    assert cat.request("/dj/admin/")[0] == 403
    html, csrf = form(root, account_path)
    disabled = save_account | {"csrf": csrf, "version": field(html, "version"), "role": "dj"}
    disabled.pop("enabled")
    assert root.request(account_path, disabled)[0] == 303
    assert cat.request("/dj/")[0] == 403
    token, _ = cat.form()
    assert cat.request("/dj/login.php", {
        "csrf": token, "username": "cat", "password": "test-correct-password",
    })[0] == 403
    html, csrf = form(root, account_path)
    assert root.request(account_path, {
        "csrf": csrf, "action": "delete", "version": field(html, "version"), "confirm": "cat",
    })[0] == 303
    assert db.execute("select deleted from accounts where streamer_id=4").fetchone()[0] == 1
    html, csrf = form(root, account_path)
    assert root.request(account_path, save_account | {
        "csrf": csrf, "version": field(html, "version"), "role": "dj",
    })[0] == 303
    cat.login("cat")
    assert cat.request("/dj/")[0] == 200
    root_html, csrf = form(root, "/dj/admin/account.php?station=1&streamer=163")
    assert root.request("/dj/admin/account.php?station=1&streamer=163", {
        "csrf": csrf, "action": "delete", "version": field(root_html, "version"), "confirm": "deepend",
    })[0] == 409
    schedule_checks(root, state)
    status, exported, headers = root.request("/dj/admin/export.php")
    assert status == 200 and "attachment" in headers["content-disposition"]
    data = json.loads(exported)
    assert data["profiles"] and data["accounts"] and data["stations"]
    assert "test-correct-password" not in exported and "api-key" not in exported
    backup = state.parent / "backup.sqlite"
    args = [os.getenv("PHP_BINARY", "php")]
    if os.getenv("PHP_INI"):
        args += ["-c", os.environ["PHP_INI"]]
    args += [str(auth.ROOT / "bin/backup-dj-admin.php"), str(backup)]
    env = dict(os.environ, TILDERADIO_DJ_SITE_CONFIG=str(state.parent / "site.json"))
    subprocess.run(args, env=env, check=True, stdout=subprocess.DEVNULL)
    with sqlite3.connect(backup) as copied:
        assert copied.execute("select count(*) from admin_audit").fetchone() == db.execute("select count(*) from admin_audit").fetchone()
    assert backup.stat().st_mode & 0o077 == 0
    assert subprocess.run(args, env=env, capture_output=True).returncode == 1
    client_config.write_text('{"mode":"revoked"}')
    html, csrf = form(root, "/dj/admin/stations.php")
    assert root.request("/dj/admin/stations.php", {
        "csrf": csrf, "action": "save", "id": "5", "name": "Must fail",
        "timezone": "UTC", "enabled": "1", "version": "0",
    })[0] == 403
    assert db.execute("select count(*) from stations where id=5").fetchone()[0] == 0
    for file in state.glob("audit*.log"):
        assert "never-send-to-browser" not in file.read_text()
    db.close()
    print("Administrator HTTP checks passed: role enforcement, CSRF, verified IDs, protection, access revocation, CRUD, restore, stale forms, export and TLS schedule writes.")


def schedule_checks(root, state):
    path = "/dj/admin/schedule.php?source_station=1&source_streamer=4&station=1"
    html, csrf = form(root, path + "&item=10")
    assert "America/Edmonton" in html and "never-send-to-browser" not in html
    token = field(html, "schedule_token")
    original = json.loads((state.parent / "schedule.json").read_text())
    post = {
        "csrf": csrf, "schedule_token": token, "action": "edit", "item_id": "10",
        "start_time": "14:00", "end_time": "15:00", "days[]": "2",
    }
    assert root.request(path, post | {"item_id": "999"})[0] == 409
    assert root.request(path, post | {"start_time": "25:00"})[0] == 422
    assert root.request(path.replace("source_streamer=4", "source_streamer=163"), post)[0] == 409
    assert root.request(path, post)[0] == 303
    changed = json.loads((state.parent / "schedule.json").read_text())
    assert len(changed["writes"]) == 1
    assert changed["items"][1] == original["items"][1]
    assert changed["items"][0]["prevent_requests"] is True
    assert changed["items"][0]["start_time"] == 1400
    assert root.request(path, post)[0] == 409
    html, csrf = form(root, path + "&item=10")
    token = field(html, "schedule_token")
    changed["items"][0]["start_time"] = 1600
    (state.parent / "schedule.json").write_text(json.dumps(changed))
    assert root.request(path, post | {"csrf": csrf, "schedule_token": token})[0] == 409
    html, csrf = form(root, path + "&item=10")
    assert root.request(path, {
        "csrf": csrf, "schedule_token": field(html, "schedule_token"),
        "action": "delete", "item_id": "10", "confirm": "DELETE",
    })[0] == 303
    changed = json.loads((state.parent / "schedule.json").read_text())
    assert changed["items"] == [original["items"][1]]
    html, csrf = form(root, path)
    add = {
        "csrf": csrf, "schedule_token": field(html, "schedule_token"),
        "action": "add", "start_time": "23:00", "end_time": "01:00",
        "start_date": "", "end_date": "", "days[]": "6",
    }
    assert root.request(path, add)[0] == 409, "A duplicate Saturday overnight slot is rejected."
    assert root.request(path, add | {"days[]": "5"})[0] == 303
    changed = json.loads((state.parent / "schedule.json").read_text())
    assert len(changed["items"]) == 2 and changed["items"][1]["start_time"] == 2300
    changed["mode"] = "denied"
    (state.parent / "schedule.json").write_text(json.dumps(changed))
    assert root.request(path)[0] == 502
    changed["mode"] = "active"
    (state.parent / "schedule.json").write_text(json.dumps(changed))
    site = state.parent / "site.json"
    settings = json.loads(site.read_text())
    settings["schedule_api"].pop("ca_file")
    site.write_text(json.dumps(settings))
    assert root.request(path)[0] == 502, "TLS verification must reject the untrusted test certificate."
    settings["schedule_api"]["ca_file"] = str(state.parent / "cert.pem")
    settings["schedule_api"]["station_ids"] = [2]
    site.write_text(json.dumps(settings))
    assert root.request(path)[0] == 403, "The private station allowlist must be enforced."


if __name__ == "__main__":
    with auth.fixture(schedule=True) as values:
        run(*values)
