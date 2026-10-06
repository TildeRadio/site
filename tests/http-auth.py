#!/usr/bin/env python3
"""Exercise the real PHP routes over local test-only TLS with an isolated fake bridge.

Needs PHP 8.4+, Composer dependencies and openssl. No AzuraCast account or
production credential is used. PHP_BINARY/PHP_INI may override the test CLI.
"""
import contextlib
import http.client
import http.server
import json
import os
from pathlib import Path
import re
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[1]


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


@contextlib.contextmanager
def fixture(schedule=False):
    with tempfile.TemporaryDirectory(prefix="dj-http-") as temp:
        private = Path(temp)
        state = private / "state"
        state.mkdir(mode=0o700)
        (state / "sessions").mkdir(mode=0o700)
        (state / "rate-key").write_text(os.urandom(32).hex())
        bootstrap = private / "client.php"
        shutil.copyfile(ROOT / "tests/fixtures/client.php", bootstrap)
        client_config = private / "client.json"
        client_config.write_text(json.dumps({"mode": "active"}))
        backend_port, tls_port = free_port(), free_port()
        origin = f"https://127.0.0.1:{tls_port}"
        config = private / "site.json"
        settings = {
            "origin": origin, "state_dir": str(state),
            "client_bootstrap": str(bootstrap), "client_config": str(client_config),
            "trusted_proxy_ips": ["127.0.0.1"], "accounts": {"1:163": "deepend"},
            "administrators": ["1:163"],
        }
        if schedule:
            api_key = private / "api-key"
            api_key.write_text("test-private-schedule-api-key")
            api_key.chmod(0o600)
            settings["schedule_api"] = {
                "base_url": origin, "key_file": str(api_key),
                "station_ids": [1], "ca_file": str(private / "cert.pem"),
            }
            (private / "schedule.json").write_text(json.dumps({
                "items": [
                    {"id": 10, "start_time": 1200, "end_time": 1300, "days": [1],
                     "start_date": None, "end_date": None, "loop_once": False,
                     "prevent_requests": True, "reset_queue_at_start": False,
                     "reset_queue_recursive": False},
                    {"id": 11, "start_time": 2300, "end_time": 100, "days": [6],
                     "start_date": None, "end_date": None, "loop_once": False,
                     "prevent_requests": False, "reset_queue_at_start": True,
                     "reset_queue_recursive": True},
                ], "writes": [], "mode": "active",
                "directory": [
                    {"id": 163, "station_id": 1, "streamer_username": "deepend", "display_name": "deepend", "is_active": True, "streamer_password": "never-send-to-browser"},
                    {"id": 4, "station_id": 1, "streamer_username": "cat", "display_name": "<Cat>", "is_active": True, "streamer_password": "never-send-to-browser"},
                    {"id": 5, "station_id": 1, "streamer_username": "inactive", "display_name": "Inactive DJ", "is_active": False, "streamer_password": "never-send-to-browser"},
                ], "directory2": [
                    {"id": 99, "station_id": 2, "streamer_username": "cat_second", "display_name": "Cat in second station", "is_active": True},
                ], "list_mode": "wrapped", "requests": [],
            }))
        config.write_text(json.dumps(settings))
        cert, key = private / "cert.pem", private / "key.pem"
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
            "-keyout", str(key), "-out", str(cert),
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        args = [os.getenv("PHP_BINARY", "php")]
        if os.getenv("PHP_INI"):
            args += ["-c", os.environ["PHP_INI"]]
        if os.getenv("TILDERADIO_HTTP_PREPEND"):
            args += ["-d", "auto_prepend_file=" + os.environ["TILDERADIO_HTTP_PREPEND"]]
        args += ["-S", f"127.0.0.1:{backend_port}", "-t", str(ROOT)]
        episodes = private / "episodes.json"
        episodes.write_text(json.dumps({"version": 1, "generated_at": 1, "episodes": []}))
        environment = dict(os.environ, TILDERADIO_DJ_SITE_CONFIG=str(config), TILDERADIO_EPISODES_FILE=str(episodes))
        with (private / "server.log").open("wb") as log:
            process = subprocess.Popen(args, env=environment, stdout=log, stderr=log)

            class Proxy(http.server.BaseHTTPRequestHandler):
                def do_GET(self):
                    self.forward()

                def do_POST(self):
                    self.forward()

                def do_PUT(self):
                    self.forward()

                def forward(self):
                    if schedule and self.path.startswith("/api/station/"):
                        self.schedule_api()
                        return
                    connection = http.client.HTTPConnection("127.0.0.1", backend_port, timeout=10)
                    headers = {k: v for k, v in self.headers.items() if k.lower() not in {
                        "connection", "x-forwarded-proto", "x-real-ip", "x-test-ip",
                    }}
                    headers["X-Forwarded-Proto"] = "https"
                    headers["X-Real-IP"] = self.headers.get("X-Test-IP", "127.0.0.1")
                    body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
                    connection.request(self.command, self.path, body, headers)
                    response = connection.getresponse()
                    data = response.read()
                    self.send_response(response.status)
                    for name, value in response.getheaders():
                        if name.lower() not in {"connection", "transfer-encoding", "content-length"}:
                            self.send_header(name, value)
                    self.send_header("Content-Length", str(len(data)))
                    self.end_headers()
                    self.wfile.write(data)
                    connection.close()

                def schedule_api(self):
                    from urllib.parse import urlsplit, parse_qs
                    path = private / "schedule.json"
                    data = json.loads(path.read_text())
                    parsed = urlsplit(self.path)
                    data["requests"].append(self.path)
                    path.write_text(json.dumps(data))
                    status, response = 200, {}
                    if self.headers.get("Authorization") != "Bearer test-private-schedule-api-key":
                        status = 401
                    elif data["mode"] != "active":
                        status = 403 if data["mode"] == "denied" else 500
                    elif self.path == "/api/station/1" and self.command == "GET":
                        response = {"id": 1, "timezone": "America/Edmonton"}
                    elif parsed.path == "/api/station/1/streamer/4/broadcast/900/download":
                        payload = bytes.fromhex(data.get("recording_hex", ""))
                        self.send_response(data.get("recording_status", 200))
                        self.send_header("Content-Type", data.get("recording_type", "audio/wav"))
                        self.send_header("Content-Length", str(len(payload)))
                        self.send_header("Location", "https://attacker.invalid/must-not-follow")
                        self.end_headers()
                        self.wfile.write(payload)
                        return
                    elif parsed.path == "/api/station/1/streamers/broadcasts":
                        records = data.get("broadcasts", [])
                        per_page = int(parse_qs(parsed.query).get("rowCount", ["25"])[0])
                        page = int(parse_qs(parsed.query).get("current", ["1"])[0])
                        response = {"current": page, "rowCount": per_page, "total": len(records), "rows": records[(page - 1) * per_page:page * per_page]}
                    elif parsed.path in ["/api/station/1/streamers", "/api/station/2/streamers"]:
                        records = data["directory" if parsed.path.startswith("/api/station/1/") else "directory2"]
                        records = [row | {"schedule_items": row.get("schedule_items", data["items"] if row["id"] == 4 else [])} for row in records]
                        page = int(parse_qs(parsed.query).get("page", ["1"])[0])
                        # Default small pages exercise traversal; size tests honor the requested size.
                        requested = int(parse_qs(parsed.query).get("per_page", ["25"])[0])
                        cap = 1 if data["list_mode"] == "tiny" else 2
                        per_page = requested if data["list_mode"] == "large" else min(cap, requested)
                        response = {"page": page, "per_page": per_page, "total": len(records),
                                    "total_pages": (len(records) + per_page - 1) // per_page,
                                    "rows": records[(page - 1) * per_page:page * per_page],
                                    "links": {"next": "https://attacker.invalid/must-not-follow"}}
                        if data["list_mode"] == "flat":
                            response = records
                        elif data["list_mode"] == "malformed":
                            response["rows"] = [{"id": "not-an-id"}]
                        elif data["list_mode"] == "redirect":
                            status, response = 302, {}
                    elif re.fullmatch(r"/api/station/(1|2)/streamer/[0-9]+", self.path):
                        station = int(self.path.split("/")[3])
                        streamer = int(self.path.rsplit("/", 1)[1])
                        records = data["directory" if station == 1 else "directory2"]
                        record = next((row for row in records if row["id"] == streamer), None)
                        if record is None:
                            status = 404
                        elif self.command == "PUT":
                            payload = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                            assert set(payload) == {"schedule_items"}, "Only schedules may be written."
                            data["writes"].append(payload)
                            items = payload["schedule_items"]
                            for i, row in enumerate(items):
                                row.setdefault("id", 100 + i)
                            if streamer == 4:
                                data["items"] = items
                            else:
                                record["schedule_items"] = items
                            path.write_text(json.dumps(data))
                            response = {"success": True}
                        else:
                            response = record | {"enforce_schedule": True, "schedule_items": record.get("schedule_items", data["items"] if streamer == 4 else [])}
                    else:
                        status = 404
                    raw = json.dumps(response).encode()
                    self.send_response(status)
                    if status == 302:
                        self.send_header("Location", "https://attacker.invalid/must-not-follow")
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(raw)))
                    self.end_headers()
                    try:
                        self.wfile.write(raw)
                    except (BrokenPipeError, ConnectionResetError, ssl.SSLEOFError):
                        # The client deliberately closes oversized responses.
                        pass

                def log_message(self, *args):
                    pass

            server = http.server.ThreadingHTTPServer(("127.0.0.1", tls_port), Proxy)
            tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            tls.load_cert_chain(cert, key)
            server.socket = tls.wrap_socket(server.socket, server_side=True)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                for _ in range(100):
                    if process.poll() is not None:
                        raise RuntimeError("PHP test server did not start.")
                    try:
                        with socket.create_connection(("127.0.0.1", backend_port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.05)
                else:
                    raise RuntimeError("PHP test server startup timed out.")
                yield origin, state, client_config, backend_port
            finally:
                server.shutdown()
                server.server_close()
                process.terminate()
                process.wait(timeout=5)


class Browser:
    def __init__(self, origin, ip="127.0.0.1"):
        self.port = int(origin.rsplit(":", 1)[1])
        self.origin = origin
        self.cookie = None
        self.ip = ip

    def request(self, path, data=None, origin=None, method=None):
        from urllib.parse import urlencode
        headers = {"X-Test-IP": self.ip}
        if self.cookie:
            headers["Cookie"] = self.cookie
        body = None
        if data is not None:
            body = urlencode(data)
            headers["Content-Type"] = "application/x-www-form-urlencoded"
            headers["Origin"] = origin or self.origin
        connection = http.client.HTTPSConnection(
            "127.0.0.1", self.port, context=ssl._create_unverified_context(), timeout=10,
        )  # Only the generated one-day localhost test certificate is unverified.
        connection.request(method or ("POST" if data is not None else "GET"), path, body, headers)
        response = connection.getresponse()
        status, text, values = response.status, response.read().decode(), response.getheaders()
        result = {k.lower(): v for k, v in values}
        cookie = result.get("set-cookie")
        if cookie:
            self.cookie = cookie.split(";", 1)[0]
        connection.close()
        return status, text, result

    def form(self):
        status, html, headers = self.request("/dj/login.php")
        assert status == 200, (status, html)
        return re.search(r'name="csrf" value="([a-f0-9]{64})"', html)[1], headers

    def login(self, username="deepend"):
        token, _ = self.form()
        before = self.cookie
        status, _, headers = self.request("/dj/login.php", {
            "csrf": token, "username": username, "password": "test-correct-password",
            "station_id": 1, "streamer_id": 163, "role": "administrator",
        })
        assert status == 303 and headers["location"] == self.origin + "/dj/"
        assert self.cookie != before, "Session ID must rotate at login."
        return before


def age_session(browser, state, field, seconds):
    sid = browser.cookie.split("=", 1)[1]
    file = state / "sessions" / ("sess_" + sid)
    raw = file.read_text()
    updated, count = re.subn(rf"{field}\|i:[0-9]+;", f"{field}|i:{int(time.time()) - seconds};", raw)
    assert count == 1
    file.write_text(updated)


def run(origin, state, client_config, backend_port):
    browser = Browser(origin)
    status, _, headers = browser.request("/dj/")
    assert status == 303 and headers["location"] == origin + "/dj/login.php"
    token, headers = browser.form()
    # Inspect a fresh anonymous response's actual cookie attributes.
    fresh = Browser(origin)
    _, flags = fresh.form()
    flags = flags["set-cookie"].lower()
    assert all(flag in flags for flag in ["__secure-tilderadiodj=", "secure", "httponly", "samesite=lax", "path=/dj/"])
    assert "no-store" in headers["cache-control"]
    assert "frame-ancestors 'none'" in headers["content-security-policy"]
    fixation = Browser(origin)
    fixation.cookie = '__Secure-TildeRadioDJ=' + 'a' * 26
    fixation.form()
    assert fixation.cookie != '__Secure-TildeRadioDJ=' + 'a' * 26
    bad = {"csrf": "bad", "username": "deepend", "password": "test-correct-password"}
    assert browser.request("/dj/login.php", bad)[0] == 403
    bad["csrf"] = token
    assert browser.request("/dj/login.php", bad, origin="https://attacker.invalid")[0] == 403
    bad["password"] = "wrong"
    status, html, _ = browser.request("/dj/login.php", bad)
    assert status == 401 and "wrong" not in html and "Trace" not in html
    assert browser.request("/dj/login.php", {"csrf": token, "username[]": "cat", "password": "test"})[0] == 400
    assert browser.request("/dj/login.php", {"csrf": token, "username": "x" * 5000})[0] == 413
    before = browser.login()
    status, html, _ = browser.request("/dj/")
    assert status == 200 and "Administrator" in html and "Hello, deepend." in html
    stolen_old = Browser(origin)
    stolen_old.cookie = before
    assert stolen_old.request("/dj/")[0] == 303
    assert browser.request("/dj/logout.php")[0] == 405
    assert browser.request("/dj/logout.php", {"csrf": "bad"})[0] == 403
    assert browser.request("/dj/")[0] == 200
    csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', html)[1]
    assert browser.request("/dj/logout.php", {"csrf": csrf})[0] == 303
    assert browser.request("/dj/")[0] == 303
    browser.login("cat")
    status, html, _ = browser.request("/dj/")
    assert status == 200 and "Administrator" not in html and "Hello, &lt;Cat&gt;." in html
    assert "deepend" not in html and "Your login is ready" in html
    age_session(browser, state, "checked", 61)
    client_config.write_text(json.dumps({"mode": "unavailable"}))
    status, html, _ = browser.request("/dj/")
    assert status == 503 and "Hello," not in html
    client_config.write_text(json.dumps({"mode": "revoked"}))
    assert browser.request("/dj/")[0] == 303
    client_config.write_text(json.dumps({"mode": "active"}))
    browser.login()
    age_session(browser, state, "seen", 901)
    assert browser.request("/dj/")[0] == 303
    browser.login()
    age_session(browser, state, "started", 3601)
    assert browser.request("/dj/")[0] == 303
    limited = Browser(origin, "198.51.100.77")
    csrf, _ = limited.form()
    for _ in range(10):
        assert limited.request("/dj/login.php", {"csrf": csrf, "username": "unknown", "password": "wrong"})[0] == 401
    assert limited.request("/dj/login.php", {"csrf": csrf, "username": "unknown", "password": "wrong"})[0] == 429
    public = Browser(origin)
    status, _, headers = public.request("/copyright.php")
    assert status == 200 and "set-cookie" not in headers
    plain = http.client.HTTPConnection("127.0.0.1", backend_port)
    plain.request("POST", "/dj/login.php", "username=deepend")
    response = plain.getresponse()
    assert response.status == 403
    response.read()
    plain.close()
    for session in (state / "sessions").glob("sess_*"):
        assert session.stat().st_mode & 0o077 == 0
        assert "test-correct-password" not in session.read_text()
    for audit in state.glob("audit*.log"):
        text = audit.read_text()
        assert "test-correct-password" not in text and '"password"' not in text
    print("HTTP security checks passed: cookies, CSRF, session rotation, logout, roles, escaping, expiry, revocation, outage, throttling, public pages and HTTPS.")


if __name__ == "__main__":
    with fixture() as values:
        if "--serve" in sys.argv:
            print(json.dumps({"origin": values[0]}), flush=True)
            threading.Event().wait()
        else:
            run(*values)
