#!/usr/bin/env python3
"""Station conflict checks use fresh TLS responses and reject writes atomically locally."""
import fcntl
import importlib.util
import json
from pathlib import Path
import re

spec = importlib.util.spec_from_file_location("auth_http", Path(__file__).with_name("http-auth.py"))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


def field(html, name):
    return re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)[1]


def run(origin, state, *_):
    root = auth.Browser(origin)
    root.login()
    cat = auth.Browser(origin, "192.0.2.75")
    cat.login("cat")
    path = state.parent / "schedule.json"
    schedule = "/dj/schedule.php?source_station=1&source_streamer=4&station=1"
    admin_schedule = schedule.replace("/dj/schedule.php", "/dj/admin/schedule.php")

    def update(**changes):
        data = json.loads(path.read_text())
        data.update(changes)
        path.write_text(json.dumps(data))
        return data

    def post(browser=cat, url=schedule, **changes):
        status, html, _ = browser.request(url)
        assert status == 200, (status, html)
        result = {"csrf": field(html, "csrf"), "schedule_token": field(html, "schedule_token"),
                  "action": "add", "start_time": "14:00", "end_time": "15:00", "days[]": "2"}
        result.update(changes)
        return result

    def rejected(browser, url, data, status, message=None):
        before = update()["writes"]
        actual, html, _ = browser.request(url, data)
        assert actual == status, (actual, html)
        assert update()["writes"] == before, "Rejected requests must never call PUT."
        assert "never-send-to-browser" not in html and "test-private-schedule-api-key" not in html
        if message:
            assert message in html, html

    # Users cannot bypass the station lock by choosing a different streamer.
    pending = post()
    with (state / "schedule-station-1.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        rejected(cat, schedule, pending, 409, "Another request is updating this station")

    rejected(cat, schedule, post(start_time="12:30", end_time="14:00", **{"days[]": "1"}), 409, "overlaps cat")
    # A booking inserted after the form was loaded is still found. The API scan
    # includes DJs without website access and adapts to pages over the 2 MiB cap.
    pending = post()
    data = update()
    original = data["directory"]
    directory = original + [{"id": 200 + i, "station_id": 1, "streamer_username": "guest_" + str(i),
                              "display_name": "Guest", "is_active": True, "schedule_items": []}
                             for i in range(12)]
    for row in directory:
        row["unused_history"] = "never-send-to-browser" + "x" * 180000
    directory[-1]["streamer_username"] = "guest_<DJ>"
    directory[-1]["schedule_items"] = [{"id": 401, "start_time": 1430, "end_time": 1600,
                                         "days": [2], "start_date": None, "end_date": None}]
    update(directory=directory, list_mode="large", requests=[])
    rejected(cat, schedule, pending, 409, "overlaps guest_&lt;DJ&gt;")
    requests = update()["requests"]
    assert any("per_page=12" in url for url in requests), requests
    rejected(root, admin_schedule, post(root, admin_schedule), 409, "overlaps guest_&lt;DJ&gt;")

    # Adjacent times are allowed. A conflicting edit is rejected, and the
    # original entry itself is excluded when editing without changing its slot.
    directory[-1]["schedule_items"][0].update(start_time=1300, end_time=1400)
    for row in directory:
        row.pop("unused_history")
    update(directory=directory)
    assert cat.request(schedule, post())[0] == 303
    edit_url = schedule + "&item=102"
    rejected(cat, schedule, post(url=edit_url, action="edit", item_id="102", start_time="13:30"), 409)
    assert cat.request(schedule, post(url=edit_url, action="edit", item_id="102"))[0] == 303

    # Incomplete or malformed data fails closed instead of accepting a booking.
    directory[-1]["schedule_items"] = [{"id": 401, "start_time": "bad"}]
    update(directory=directory)
    rejected(cat, schedule, post(start_time="17:00", end_time="18:00"), 502)
    update(list_mode="malformed")
    rejected(cat, schedule, post(start_time="17:00", end_time="18:00"), 502)
    # Deletion must remain available to resolve old collisions or malformed
    # schedules belonging to another DJ; it does not require the station scan.
    assert cat.request(schedule, post(url=edit_url, action="delete", item_id="102", confirm="DELETE"))[0] == 303
    directory[-1]["schedule_items"] = []
    update(directory=directory, list_mode="large")
    assert len(update()["writes"]) == 3
    print("Schedule conflict HTTP checks passed: own/other DJ overlaps, fresh paginated lookup, escaped names, adaptive size limits, administrator rejection, station lock, adjacency, edit exclusion, fail-closed errors and deletion.")


if __name__ == "__main__":
    with auth.fixture(schedule=True) as values:
        run(*values)
