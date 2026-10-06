#!/usr/bin/env python3
"""Exercise scoped DJ writes and public archive corrections over real PHP/TLS."""
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
    cat = auth.Browser(origin, "192.0.2.70")
    cat.login("cat")
    public = auth.Browser(origin)
    db = sqlite3.connect(state / "admin.sqlite")
    export = state.parent / "episodes.json"
    start = 1791200007
    source = [
        {"id": 1, "dj": "Cat", "dj_slug": "cat", "started_at": start, "ended_at": start + 3600,
         "duration": 3600, "is_live": False, "track_count": 1, "peak_listeners": 7,
         "show": {"title": "Original show", "episode": "Missing title", "format": {"id": "kept", "genres": ["Jazz"]}},
         "tracks": [{"text": "Captured track", "played_at": start + 30}]},
        {"id": 2, "dj": "deepend", "dj_slug": "deepend", "started_at": start, "ended_at": start + 3600,
         "is_live": False, "show": {"episode": "Other DJ"}, "tracks": [], "track_count": 0},
    ]
    export.write_text(json.dumps({"version": 1, "generated_at": 1, "episodes": source}))

    def page(browser, path):
        status, html, _ = browser.request(path)
        assert status == 200, (status, html)
        return html

    def profile(slug):
        existing = db.execute("select version from profiles where slug=?", (slug,)).fetchone()
        path = "/dj/admin/profile.php" + ("?slug=" + slug if existing else "")
        html = page(root, path)
        result = root.request(path, {
            "csrf": field(html, "csrf"), "action": "save_json", "version": field(html, "version"), "slug": slug,
            "profile_json": json.dumps({"name": slug, "published": True, "custom": {"preserved": True}}),
        })
        assert result[0] == 303, result[:2]

    for slug in ["cat", "deepend"]:
        profile(slug)
    html = page(root, "/dj/admin/account.php?station=1&streamer=4")
    assert root.request("/dj/admin/account.php?station=1&streamer=4", {
        "csrf": field(html, "csrf"), "action": "save", "version": field(html, "version"),
        "role": "dj", "enabled": "1", "profile_slug": "cat", "stations[]": "1",
        "target_streamers[1]": "4", "target_modes[1]": "manual",
    })[0] == 303
    assert cat.request("/dj/admin/")[0] == 403
    html = page(cat, "/dj/")
    assert 'href="/dj/profile.php"' in html and "schedule.php?" in html
    html = page(cat, "/dj/profile.php?slug=deepend")
    assert 'Permanent URL slug: <strong>cat</strong>' in html
    version = field(html, "version")
    save_profile = {
        "csrf": field(html, "csrf"), "action": "save_json", "version": version,
        "profile_json": json.dumps({"name": "<Cat>", "published": True, "bio": ["Updated bio"],
                                    "show": {"title": "DJ self-service", "timezone": "UTC"},
                                    "custom": {"preserved": True}}),
    }
    assert cat.request("/dj/profile.php", save_profile | {"csrf": "bad"})[0] == 403
    assert cat.request("/dj/profile.php", save_profile)[0] == 303
    assert cat.request("/dj/profile.php", save_profile)[0] == 409
    assert json.loads(db.execute("select json from profiles where slug='cat'").fetchone()[0])["name"] == "<Cat>"
    assert json.loads(db.execute("select json from profiles where slug='deepend'").fetchone()[0])["name"] == "deepend"
    assert cat.request("/dj/profile.php", save_profile | {"action": "delete", "confirm": "cat"})[0] == 422

    # Live station schedule CRUD reuses the existing concurrency-protected writer.
    schedule = "/dj/schedule.php?source_station=1&source_streamer=4&station=1"
    assert cat.request(schedule.replace("source_streamer=4", "source_streamer=163"))[0] == 403
    html = page(cat, schedule)
    add = {"csrf": field(html, "csrf"), "schedule_token": field(html, "schedule_token"),
           "action": "add", "start_time": "14:00", "end_time": "15:00", "days[]": "2"}
    assert cat.request(schedule, add)[0] == 303
    assert cat.request(schedule, add)[0] == 409
    html = page(cat, schedule + "&item=102")
    change = add | {"csrf": field(html, "csrf"), "schedule_token": field(html, "schedule_token"),
                    "action": "edit", "item_id": "102", "start_time": "15:00", "end_time": "16:00"}
    assert cat.request(schedule, change)[0] == 303
    html = page(cat, schedule + "&item=102")
    assert cat.request(schedule, {"csrf": field(html, "csrf"), "schedule_token": field(html, "schedule_token"),
                                 "action": "delete", "item_id": "102", "confirm": "DELETE"})[0] == 303

    assert public.request("/dj/broadcasts.php")[0] == 303
    assert cat.request("/dj/broadcast.php?id=2")[0] == 403
    html = page(cat, "/dj/broadcasts.php")
    assert "Missing title" in html and "Other DJ" not in html
    assert "Other DJ" in page(root, "/dj/broadcasts.php")
    forged_list = page(cat, "/dj/broadcasts.php?owner_slug=deepend&all=1&admin=1&deleted=1")
    assert "Other DJ" not in forged_list and "Missing title" in forged_list
    assert "Owning DJ profile" not in page(cat, "/dj/broadcast.php?id=1")
    assert "Owning DJ profile" in page(root, "/dj/broadcast.php?id=2")

    def broadcast_post(browser, path="/dj/broadcast.php?id=1", **overrides):
        html = page(browser, path)
        data = {
            "csrf": field(html, "csrf"), "broadcast_token": field(html, "broadcast_token"),
            "action": "save", "dj": "Cat", "started_at": "2026-10-05T11:33", "ended_at": "2026-10-05T12:33",
            "status": "recorded", "show_title": "Original show", "show_episode": "Fixed <set> title",
            "show_note": "Corrected notes", "peak_listeners": "7", "owner_slug": "cat",
        }
        data.update(overrides)
        return data

    # Match the UTC controls exactly while retaining the captured seconds.
    from datetime import datetime, timezone
    dates = {"started_at": datetime.fromtimestamp(start, timezone.utc).strftime("%Y-%m-%dT%H:%M"),
             "ended_at": datetime.fromtimestamp(start + 3600, timezone.utc).strftime("%Y-%m-%dT%H:%M")}
    post = broadcast_post(cat, **dates)
    assert cat.request("/dj/broadcast.php?id=1", post | {"csrf": "bad"})[0] == 403
    assert cat.request("/dj/broadcast.php?id=1", post, origin="https://attacker.invalid")[0] == 403
    assert cat.request("/dj/broadcast.php?id=2", post)[0] == 403
    assert cat.request("/dj/broadcast.php?id=2&admin=1", post | {
        "action": "delete", "confirm": "DELETE", "owner_slug": "cat",
    })[0] == 403
    assert db.execute("select count(*) from broadcast_edits where id=2").fetchone()[0] == 0
    assert source[1] == json.loads(export.read_text())["episodes"][1]
    assert cat.request("/dj/broadcast.php?id=1", post | {"show_link": "javascript:alert(1)"})[0] == 422
    assert cat.request("/dj/broadcast.php?id=1", post | {"edit_tracks": "1", "tracks_json": '[{"text":"x","password":"secret"}]'})[0] == 422
    assert cat.request("/dj/broadcast.php?id=1", post | {"owner_slug": "deepend"})[0] == 303
    assert db.execute("select owner_slug from broadcast_edits where id=1").fetchone()[0] == "cat"
    assert cat.request("/dj/broadcast.php?id=1", post)[0] == 409
    status, body, headers = public.request("/api/episodes/")
    assert status == 200 and "set-cookie" not in headers
    edited = next(row for row in json.loads(body)["episodes"] if row["id"] == 1)
    assert edited["show"]["episode"] == "Fixed <set> title"
    assert edited["started_at"] == start and edited["ended_at"] == start + 3600
    assert edited["show"]["format"] == source[0]["show"]["format"]
    html = page(public, "/episodes/?id=1")
    assert "Fixed &lt;set&gt; title" in html and "Corrected notes" in html

    # Carrier rewrites must not erase title corrections or freeze unedited tracks/stats.
    source[0]["tracks"].append({"text": "New captured track", "played_at": start + 90})
    source[0]["track_count"] = 2
    source[0]["peak_listeners"] = 20
    export.write_text(json.dumps({"version": 1, "generated_at": 2, "episodes": source}))
    edited = next(row for row in json.loads(public.request("/api/episodes/")[1])["episodes"] if row["id"] == 1)
    assert edited["show"]["episode"] == "Fixed <set> title" and edited["track_count"] == 2 and edited["peak_listeners"] == 20
    post = broadcast_post(cat, **dates, show_episode="Tracks corrected", peak_listeners="20",
                          edit_tracks="1", tracks_json='[{"artist":"Artist","title":"Correct title"}]')
    assert cat.request("/dj/broadcast.php?id=1", post)[0] == 303
    edited = next(row for row in json.loads(public.request("/api/episodes/")[1])["episodes"] if row["id"] == 1)
    assert edited["tracks"][0]["text"] == "Artist - Correct title" and edited["track_count"] == 1

    # A changed Carrier snapshot invalidates the old form.
    stale = broadcast_post(cat, **dates, peak_listeners="20")
    source[0]["peak_listeners"] = 21
    export.write_text(json.dumps({"version": 1, "generated_at": 3, "episodes": source}))
    assert cat.request("/dj/broadcast.php?id=1", stale)[0] == 409
    html = page(cat, "/dj/broadcast.php?id=1")
    delete = {"csrf": field(html, "csrf"), "broadcast_token": field(html, "broadcast_token"),
              "action": "delete", "confirm": "DELETE"}
    assert cat.request("/dj/broadcast.php?id=1", delete | {"confirm": "wrong"})[0] == 422
    assert cat.request("/dj/broadcast.php?id=1", delete)[0] == 303
    assert public.request("/episodes/?id=1")[0] == 404
    assert all(row["id"] != 1 for row in json.loads(public.request("/api/episodes/")[1])["episodes"])
    assert cat.request("/dj/broadcast.php?id=1")[0] == 403
    assert source[0] in json.loads(export.read_text())["episodes"]
    html = page(root, "/dj/broadcast.php?id=1")
    assert root.request("/dj/broadcast.php?id=1", {
        "csrf": field(html, "csrf"), "broadcast_token": field(html, "broadcast_token"),
        "action": "restore", "confirm": "RESTORE",
    })[0] == 303
    assert public.request("/episodes/?id=1")[0] == 200

    # Manually recovered sets have stable IDs and work without a Carrier export.
    post = broadcast_post(cat, "/dj/broadcast.php", **dates, show_episode="Recovered set", show_title="", owner_slug="deepend")
    assert cat.request("/dj/broadcast.php", post)[0] == 303
    manual_id = db.execute("select id from broadcast_edits where manual=1").fetchone()[0]
    assert manual_id >= 1000000000
    assert db.execute("select owner_slug from broadcast_edits where id=?", (manual_id,)).fetchone()[0] == "cat"
    assert cat.request("/dj/broadcast.php", post)[0] == 409
    assert public.request("/episodes/?id=" + str(manual_id))[0] == 200
    manual = next(row for row in json.loads(public.request("/api/episodes/")[1])["episodes"] if row["id"] == manual_id)
    for key in ["peak_listeners", "max_couch", "props", "questions", "requests", "reactions", "tildes"]:
        assert isinstance(manual[key], int), "Recovered listings retain the existing API statistics shape."
    manual_path = "/dj/broadcast.php?id=" + str(manual_id)
    manual_post = broadcast_post(cat, manual_path, **dates, show_episode="Recovered and edited", show_title="")
    assert cat.request(manual_path, manual_post)[0] == 303
    html = page(cat, manual_path)
    assert cat.request(manual_path, {
        "csrf": field(html, "csrf"), "broadcast_token": field(html, "broadcast_token"),
        "action": "delete", "confirm": "DELETE",
    })[0] == 303
    assert public.request("/episodes/?id=" + str(manual_id))[0] == 404
    assert cat.request(manual_path)[0] == 403
    html = page(root, manual_path)
    assert root.request(manual_path, {
        "csrf": field(html, "csrf"), "broadcast_token": field(html, "broadcast_token"),
        "action": "restore", "confirm": "RESTORE",
    })[0] == 303
    export.write_text(json.dumps({"version": 1, "generated_at": 4, "episodes": []}))
    assert public.request("/episodes/?id=1")[0] == 200, "Corrected source snapshots survive export rotation."
    assert public.request("/episodes/?id=" + str(manual_id))[0] == 200

    # Reassigning a profile or revoking access invalidates DJ authorization immediately.
    post = broadcast_post(cat, **dates, peak_listeners="21")
    db.execute("update accounts set profile_slug='deepend',version=version+1 where streamer_id=4")
    db.commit()
    assert cat.request("/dj/broadcast.php?id=1", post)[0] == 403
    db.execute("update accounts set profile_slug='cat',enabled=0,version=version+1 where streamer_id=4")
    db.commit()
    assert cat.request("/dj/broadcast.php?id=1", post)[0] == 403
    assert db.execute("select count(*) from admin_audit where action like 'broadcast.%'").fetchone()[0] >= 5
    assert len(json.loads((state.parent / "schedule.json").read_text())["writes"]) == 3
    db.close()
    print("DJ self-service checks passed: profiles, own schedules, scoped broadcast CRUD, CSRF, stale forms, revocation, source retention, public HTML/API, export rewrites and metadata preservation.")


if __name__ == "__main__":
    with auth.fixture(schedule=True) as values:
        run(*values)
