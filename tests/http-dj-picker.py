#!/usr/bin/env python3
"""Exercise username selection, fresh verification, pagination and safe fallback."""
import importlib.util
import json
from pathlib import Path
import re
import sqlite3

spec = importlib.util.spec_from_file_location("auth_http", Path(__file__).with_name("http-auth.py"))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


def field(html, name):
    return re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)[1]


def run(origin, state, *_):
    root = auth.Browser(origin)
    root.login()
    path = state.parent / "schedule.json"
    database = sqlite3.connect(state / "admin.sqlite")

    def update(**changes):
        data = json.loads(path.read_text())
        data.update(changes)
        path.write_text(json.dumps(data))
        return data

    def page(url="/dj/admin/account.php"):
        status, html, _ = root.request(url)
        assert status == 200, (status, html)
        assert "never-send-to-browser" not in html and "test-private-schedule-api-key" not in html
        return html

    html = page()
    assert '<select id="admin-streamer_id"' in html
    assert "cat — &lt;Cat&gt;" in html and "<Cat>" not in html
    assert 'value="5" disabled' in html, "Inactive DJs on later pages must be visible but unselectable."
    assert len([url for url in json.loads(path.read_text())["requests"] if "/streamers?" in url]) == 2
    page()
    assert len([url for url in json.loads(path.read_text())["requests"] if "/streamers?" in url]) == 2, "Reuse safe names for at most 60 seconds."
    csrf = field(html, "csrf")
    post = {
        "csrf": csrf, "action": "save", "version": "0", "identity_mode": "picker",
        "station_id": "1", "streamer_id": "4", "label": "Website Cat",
        "enabled": "1", "role": "dj", "profile_slug": "", "stations[]": "1",
        "target_streamers[1]": "", "target_modes[1]": "picker",
    }
    assert root.request("/dj/admin/account.php", post | {"csrf": "bad"})[0] == 403
    assert root.request("/dj/admin/account.php", post | {"streamer_id": "999"})[0] == 404
    assert root.request("/dj/admin/account.php", post | {"streamer_id": "5"})[0] == 422
    assert root.request("/dj/admin/account.php", post | {"station_id": "2"})[0] == 403
    data = update()
    data["directory"][1]["streamer_username"] = "wrong_account"
    update(directory=data["directory"])
    assert root.request("/dj/admin/account.php", post)[0] == 422, "API name must match the signed bridge identity."
    data["directory"][1]["streamer_username"] = "cat"
    data["directory"][1]["is_active"] = False
    update(directory=data["directory"])
    assert root.request("/dj/admin/account.php", post)[0] == 422, "Fresh validation rejects cached active options after deactivation."
    data["directory"][1]["is_active"] = True
    update(directory=data["directory"])
    assert root.request("/dj/admin/account.php", post)[0] == 303
    assert database.execute("select username,role,enabled from accounts where station_id=1 and streamer_id=4").fetchone() == ("cat", "dj", 1)
    assert database.execute("select station_id,streamer_id from memberships where account_streamer_id=4").fetchone() == (1, 4)
    cat = auth.Browser(origin, "192.0.2.99")
    cat.login("cat")
    assert cat.request("/dj/admin/account.php")[0] == 403
    html = page("/dj/admin/account.php?station=1&streamer=4")
    assert 'value="4" selected' in html

    # A different station uses its actual station-specific DJ ID.
    assert root.request("/dj/admin/stations.php", {
        "csrf": field(html, "csrf"), "action": "save", "id": "2", "name": "Second",
        "timezone": "UTC", "enabled": "1", "version": "0",
    })[0] == 303
    config = state.parent / "site.json"
    settings = json.loads(config.read_text())
    settings["schedule_api"]["station_ids"] = [1, 2]
    config.write_text(json.dumps(settings))
    html = page("/dj/admin/account.php?station=1&streamer=4")
    assert "cat_second — Cat in second station" in html
    change = post | {
        "csrf": field(html, "csrf"), "version": field(html, "version"),
        "stations[0]": "1", "stations[1]": "2",
        "target_streamers[1]": "4", "target_streamers[2]": "99",
        "target_modes[2]": "picker",
    }
    change.pop("stations[]")
    assert root.request("/dj/admin/account.php?station=1&streamer=4", change | {"target_streamers[2]": "999"})[0] == 404
    assert root.request("/dj/admin/account.php?station=1&streamer=4", change)[0] == 303
    assert database.execute("select streamer_id from memberships where account_streamer_id=4 and station_id=2").fetchone() == (99,)

    # Production-sized records used to abort the entire directory at the 2 MiB cap.
    original = json.loads(path.read_text())["directory"]
    records = original + [
        {"id": 200 + i, "station_id": 1, "streamer_username": f"large_{i:02d}",
         "display_name": f"Large DJ {i}", "is_active": True}
        for i in range(37)
    ]
    for record in records:
        record["unused_history"] = "never-send-to-browser" + "x" * 220000
    update(directory=records, list_mode="large", requests=[])
    html = page("/dj/admin/account.php?refresh=1")
    assert '<select id="admin-streamer_id"' in html and "large_36" in html
    urls = [url for url in json.loads(path.read_text())["requests"] if "/station/1/streamers?" in url]
    assert urls[:3] == [
        "/api/station/1/streamers?per_page=25&page=1",
        "/api/station/1/streamers?per_page=12&page=1",
        "/api/station/1/streamers?per_page=6&page=1",
    ], urls
    assert urls[-1] == "/api/station/1/streamers?per_page=6&page=7"
    assert "x" * 1000 not in html

    # Overflow on a later page must restart traversal rather than mixing offsets.
    for record in records:
        record.pop("unused_history")
    records[26]["unused_history"] = "x" * 1200000
    records[27]["unused_history"] = "x" * 1200000
    update(directory=records, requests=[])
    html = page("/dj/admin/account.php?refresh=1")
    assert '<select id="admin-streamer_id"' in html and "large_36" in html
    urls = [url for url in json.loads(path.read_text())["requests"] if "/station/1/streamers?" in url]
    assert urls[:3] == [
        "/api/station/1/streamers?per_page=25&page=1",
        "/api/station/1/streamers?per_page=25&page=2",
        "/api/station/1/streamers?per_page=12&page=1",
    ], urls
    assert urls[-1] == "/api/station/1/streamers?per_page=3&page=14"
    dropdown = re.search(r'<select id="admin-streamer_id"[^>]*>(.*?)</select>', html, re.S)[1]
    for record in records[3:]:
        assert dropdown.count(f'value="{record["id"]}"') == 1

    # Smaller server pages can legitimately require more than the former 50-page limit.
    tiny = original + [
        {"id": 300 + i, "station_id": 1, "streamer_username": f"tiny_{i:02d}",
         "display_name": f"Tiny DJ {i}", "is_active": True}
        for i in range(57)
    ]
    update(directory=tiny, list_mode="tiny", requests=[])
    html = page("/dj/admin/account.php?refresh=1")
    assert '<select id="admin-streamer_id"' in html and "tiny_56" in html
    urls = [url for url in json.loads(path.read_text())["requests"] if "/station/1/streamers?" in url]
    assert len(urls) == 60 and urls[-1].endswith("page=60")

    # A single oversized record fails safely; authorization failures are not retried.
    oversized = original[0] | {"unused_history": "x" * 2097152}
    update(directory=[oversized], list_mode="large", requests=[])
    html = page("/dj/admin/account.php?refresh=1")
    assert "still exceeds the 2 MiB safety limit" in html
    assert 'name="identity_mode" value="manual"' in html
    urls = [url for url in json.loads(path.read_text())["requests"] if "/station/1/streamers?" in url]
    assert len(urls) == 5 and "per_page=1&page=1" in urls[-1], urls
    update(directory=original, list_mode="wrapped")

    for mode in ["flat", "malformed", "redirect"]:
        update(list_mode=mode)
        html = page("/dj/admin/account.php?refresh=1")
        if mode == "flat":
            assert '<select id="admin-streamer_id"' in html
        else:
            assert 'type="number"' in html and 'name="identity_mode" value="manual"' in html
    update(list_mode="wrapped", mode="denied", requests=[])
    html = page("/dj/admin/account.php?refresh=1")
    assert 'name="identity_mode" value="manual"' in html
    urls = [url for url in json.loads(path.read_text())["requests"] if "/station/1/streamers?" in url]
    assert len(urls) == 1, "Only confirmed size-limit failures may trigger page-size retries."
    assert root.request("/dj/admin/")[0] == 200, "Directory failures must not break administration."
    update(mode="active")
    manual = page("/dj/admin/account.php?station=1&streamer=4&manual=1")
    assert 'name="target_modes[1]" value="manual"' in manual
    assert 'name="target_streamers[1]" type="number"' in manual
    assert root.request("/dj/admin/account.php?station=1&streamer=4&manual=1", {
        "csrf": field(manual, "csrf"), "action": "save", "version": field(manual, "version"),
        "enabled": "1", "role": "dj", "label": "Manual fallback still works",
        "stations[]": "1", "target_streamers[1]": "4",
    })[0] == 303
    settings.pop("schedule_api")
    config.write_text(json.dumps(settings))
    html = page()
    assert "Manual entry remains available" in html and 'name="identity_mode" value="manual"' in html
    assert json.loads(path.read_text())["writes"] == [], "Directory/account setup never writes to AzuraCast."
    for session in (state / "sessions").glob("sess_*"):
        raw = session.read_text()
        assert "never-send-to-browser" not in raw and "test-private-schedule-api-key" not in raw
        assert "x" * 1000 not in raw, "Oversized metadata must never enter the safe directory cache."
    database.close()
    print("DJ picker checks passed: adaptive pagination, first/later-page overflow, single-record limit, names, safe cache, signed verification, inactive accounts, memberships, access checks, manual fallback and no credential leakage.")


if __name__ == "__main__":
    with auth.fixture(schedule=True) as values:
        run(*values)
