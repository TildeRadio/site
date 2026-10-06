#!/usr/bin/env python3
"""Cross-project protocol test: run with the path to the patched Carrier checkout.

Uses the actual PHP routes/client/synchronizer and Carrier SQLite engine, plus
test-only AzuraCast and local transport fixtures. Does not connect to production.
"""
import importlib.util
import json
import os
import re
import socket
import sqlite3
import sys
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

site = Path(__file__).resolve().parents[1]
bot = Path(sys.argv[1]).resolve()
sys.path.insert(0, str(bot))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


auth = load('auth_contract', site / 'tests/http-auth.py')
tests = load('bot_contract', bot / 'tests/test_integration.py')
engine = tests.IntegrationTests()
engine.setUp()
endpoint = socket.socket()
endpoint.bind(('127.0.0.1', 0))
endpoint.listen(5)
endpoint.settimeout(.1)
os.environ['TILDERADIO_CARRIER_TEST_PORT'] = str(endpoint.getsockname()[1])
os.environ['TILDERADIO_HTTP_PREPEND'] = str(site / 'tests/fixtures/carrier-transport.php')


def execute(call):
    result = []
    error = []

    def run():
        try:
            result.append(call())
        except BaseException as exc:
            error.append(exc)

    worker = threading.Thread(target=run)
    worker.start()
    deadline = time.monotonic() + 20
    while worker.is_alive() and time.monotonic() < deadline:
        try:
            connection, _ = endpoint.accept()
        except socket.timeout:
            continue
        with connection:
            request = json.loads(connection.makefile('rb').readline(1048577))
            assert request.pop('key') == 'c' * 64
            assert int(time.time()) <= request['expires'] <= int(time.time()) + 15
            try:
                response = {'ok': True, 'data': engine.integration.rpc(request)}
            except (PermissionError, ValueError) as exc:
                response = {'ok': False, 'error': str(exc)}
            connection.sendall((json.dumps(response) + '\n').encode())
    worker.join(1)
    assert not worker.is_alive(), 'PHP protocol test timed out'
    if error:
        raise error[0]
    return result[0]


try:
    with auth.fixture(schedule=True) as (origin, state, *_):
        cat = auth.Browser(origin)
        cat.login('cat')
        db = sqlite3.connect(state / 'admin.sqlite')
        db.execute("UPDATE accounts SET profile_slug='cat' WHERE streamer_id=4")
        db.execute("INSERT OR IGNORE INTO profiles(slug,json,updated_at) VALUES('cat',?,1)", (json.dumps({'name': 'Cat', 'published': True}),))
        db.commit()
        db.close()
        config = state.parent / 'site.json'
        settings = json.loads(config.read_text())
        key = state.parent / 'carrier-key'
        key.write_text('c' * 64)
        key.chmod(0o600)
        sock = state.parent / 'carrier.sock'
        sock.touch()
        settings['carrier'] = {'enabled': True, 'station_id': 1, 'key_file': str(key), 'socket_path': str(sock)}
        config.write_text(json.dumps(settings))
        schedule = state.parent / 'schedule.json'
        upstream = json.loads(schedule.read_text())
        upstream['broadcasts'] = [{'id': 900, 'streamer': {'id': 4, 'streamer_username': 'cat', 'display_name': 'Cat'},
                                   'timestampStart': datetime.fromtimestamp(engine.clock[0], timezone.utc).isoformat(), 'timestampEnd': None}]
        schedule.write_text(json.dumps(upstream))
        status, html, _ = cat.request('/dj/plan.php')
        assert status == 200
        token = re.search(r'name="csrf" value="([^"]+)"', html)[1]
        stamp = lambda n: datetime.fromtimestamp(n, timezone.utc).strftime('%Y-%m-%dT%H:%M')
        assert cat.request('/dj/plan.php', {'csrf': token, 'version': '0', 'station_id': '1', 'starts_at': stamp(engine.clock[0]),
                    'ends_at': stamp(engine.clock[0] + 3600), 'episode': 'Real contract show', 'playlist': 'Artist | One\nArtist | Two'})[0] == 303
        # Generating a linking code runs the real paged website snapshot first.
        _, html, _ = execute(lambda: cat.request('/dj/carrier.php'))
        form = next(f for f in re.findall(r'<form\b.*?</form>', html, re.S) if 'Generate a linking code' in f)
        post = {name: re.search(r'name="' + name + r'" value="([^"]+)"', form)[1] for name in ['csrf', 'action_id']}
        assert execute(lambda: cat.request('/dj/carrier.php', post))[0] == 200
        assert engine.integration.live_actor_ids() == {'1:4'}
        engine.integration.begin(1, 'cat', engine.clock[0])
        assert engine.integration.prepared()['plan_id'] == 1
        _, html, _ = execute(lambda: cat.request('/dj/carrier.php'))
        form = next(f for f in re.findall(r'<form\b.*?</form>', html, re.S) if 'Start next song' in f)
        post = {name: re.search(r'name="' + name + r'" value="([^"]+)"', form)[1] for name in ['csrf', 'action_id']}
        post['value'] = 'next'
        assert execute(lambda: cat.request('/dj/carrier.php', post))[0] == 303
        assert engine.integration.prepared()['position'] == 1
        assert execute(lambda: cat.request('/dj/carrier.php', post))[0] == 303
        assert engine.integration.prepared()['position'] == 1
        assert engine.integration.activity(False)['current_track']['title'] == 'One'
        print('Cross-project contract passed: real PHP plan, paged sync, private upstream ID, bot attachment, live action and duplicate POST protection.')
finally:
    endpoint.close()
    engine.tearDown()
