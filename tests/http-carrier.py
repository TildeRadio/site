#!/usr/bin/env python3
"""Real PHP/TLS route checks with a deterministic local RPC transport fixture."""
import importlib.util
import json
import os
import re
import socket
import sqlite3
import threading
from datetime import datetime, timezone
from pathlib import Path

spec = importlib.util.spec_from_file_location('auth', Path(__file__).with_name('http-auth.py'))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


def field(html, name):
    return re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', html)[1]


def run(origin, state, *_):
    admin = auth.Browser(origin)
    admin.login()
    cat = auth.Browser(origin, '192.0.2.70')
    cat.login('cat')
    public = auth.Browser(origin, '192.0.2.80')
    db = sqlite3.connect(state / 'admin.sqlite')
    for slug in ('cat', 'deepend'):
        db.execute("INSERT OR IGNORE INTO profiles(slug,json,updated_at) VALUES(?,?,?)", (slug, json.dumps({'name': slug, 'published': True}), 1))
    db.execute("UPDATE accounts SET profile_slug='cat' WHERE streamer_id=4")
    db.commit()
    config = state.parent / 'site.json'
    settings = json.loads(config.read_text())
    key = state.parent / 'carrier-key'
    key.write_text('c' * 64)
    key.chmod(0o600)
    sockpath = state.parent / 'carrier.sock'
    settings['carrier'] = {'enabled': True, 'socket_path': str(sockpath), 'key_file': str(key), 'station_id': 1}
    config.write_text(json.dumps(settings))
    requests = []
    tcp = os.getenv('TILDERADIO_CARRIER_TEST_PORT')
    endpoint = socket.socket(socket.AF_INET if tcp else socket.AF_UNIX)
    if tcp:
        sockpath.touch()
        endpoint.bind(('127.0.0.1', int(tcp)))
    else:
        endpoint.bind(str(sockpath))
    endpoint.listen(5)
    endpoint.settimeout(.2)
    stop = threading.Event()

    def serve():
        while not stop.is_set():
            try:
                conn, _ = endpoint.accept()
            except socket.timeout:
                continue
            except OSError:
                return
            with conn:
                raw = conn.makefile('rb').readline(1048577)
                request = json.loads(raw)
                assert request.pop('key') == 'c' * 64
                requests.append(request)
                op = request['op']
                if op == 'view':
                    result = {'is_live': True, 'session_id': 55, 'dj': '<Cat>', 'listeners': 5, 'show': {'episode': 'Live title'}, 'requests_enabled': False, 'tracking': 'planned', 'position': 1,
                              'playlist': [{'artist': 'Artist', 'title': 'First song'}], 'current_track': {'artist': 'Artist', 'title': 'First song'}, 'can_control': request['actor'] in ['1:4', '1:163'],
                              'links': [], 'all_links': [], 'questions': [{'id': 9, 'nick': 'Listener', 'text': '<private question>'}], 'requests': [], 'poll': None}
                elif op == 'public':
                    result = {'is_live': True, 'session_id': 55, 'dj': '<Cat>', 'listeners': 5, 'show': {'episode': 'Live title'}, 'requests_enabled': False, 'current_track': None, 'poll': None, 'reactions': {}, 'answered_questions': []}
                elif op == 'health':
                    result = {'last_sync_at': 1, 'accounts': 2, 'plans': 1, 'corrections': 0, 'session_id': 55, 'radio_age_seconds': 1, 'actions': [], 'plan_uses': []}
                elif op == 'link_code':
                    result = {'code': 'd' * 32, 'expires': 9999999999}
                else:
                    result = {'message': 'Action accepted.', 'records': 2}
                conn.sendall((json.dumps({'ok': True, 'data': result}) + '\n').encode())

    thread = threading.Thread(target=serve, daemon=True)
    thread.start()
    try:
        def page(browser, path):
            status, html, _ = browser.request(path)
            assert status == 200, (status, html)
            return html

        html = page(cat, '/dj/plan.php')
        start = datetime.now(timezone.utc).replace(second=0, microsecond=0)
        from datetime import timedelta
        post = {'csrf': field(html, 'csrf'), 'version': '0', 'station_id': '1', 'starts_at': start.strftime('%Y-%m-%dT%H:%M'), 'ends_at': (start + timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),
                'episode': '<Prepared show>', 'playlist': 'Secret artist | Secret song', 'public': '1', 'owner': '1:163', 'owner_slug': 'deepend'}
        assert cat.request('/dj/plan.php', post | {'csrf': 'bad'})[0] == 403
        assert cat.request('/dj/plan.php', post)[0] == 303
        row = db.execute('SELECT id,owner_streamer FROM show_plans').fetchone()
        assert row[1] == 4, row
        planpath = '/dj/plan.php?id=' + str(row[0])
        assert 'Secret artist | Secret song' in page(cat, planpath)
        assert cat.request('/dj/plan.php?id[]=1')[0] == 422
        assert cat.request('/dj/admin/carrier.php')[0] == 403
        preview = page(public, '/community/upcoming.php')
        assert '&lt;Prepared show&gt;' in preview and 'Secret song' not in preview
        feed = page(public, '/api/planned/')
        assert 'Secret song' not in feed and 'owner_streamer' not in feed
        db.execute('UPDATE show_plans SET owner_streamer=163,slug=\'deepend\' WHERE id=?', (row[0],))
        db.commit()
        assert cat.request(planpath)[0] == 403
        assert admin.request(planpath)[0] == 200
        db.execute('UPDATE show_plans SET owner_streamer=4,slug=\'cat\' WHERE id=?', (row[0],))
        db.commit()
        html = page(cat, '/dj/carrier.php')
        assert '&lt;Cat&gt;' in html and '<private question>' not in html
        assert 'Start next song' in html
        forms = re.findall(r'<form\b.*?</form>', html, re.S)
        linkform = next(f for f in forms if 'Generate a linking code' in f)
        assert cat.request('/dj/carrier.php', {'csrf': field(linkform, 'csrf'), 'action_id': field(linkform, 'action_id')})[0] == 200
        assert any(r['op'] == 'link_code' for r in requests)
        html = page(cat, '/dj/carrier.php')
        forms = re.findall(r'<form\b.*?</form>', html, re.S)
        nextform = next(f for f in forms if 'Start next song' in f)
        post = {'csrf': field(nextform, 'csrf'), 'action_id': field(nextform, 'action_id'), 'session_id': '999', 'actor': '1:163', 'action': 'goal', 'value': 'next'}
        assert cat.request('/dj/carrier.php', post)[0] == 303
        live = next(r for r in reversed(requests) if r['op'] == 'live')
        assert live['actor'] == '1:4' and live['session_id'] == 55 and live['action'] == 'track', live
        assert cat.request('/dj/carrier.php', post | {'action_id': '0' * 32})[0] == 409
        assert admin.request('/dj/admin/carrier.php')[0] == 200
        activity = page(public, '/community/live.php')
        assert 'private question' not in activity and 'Secret song' not in activity
        form = next(f for f in re.findall(r'<form\b.*?</form>', activity, re.S) if 'Ask the DJ' in f)
        assert public.request('/community/live.php', {'csrf': 'bad', 'action_id': field(form, 'action_id'), 'action': 'question', 'value': 'Question'})[0] == 403
        assert public.request('/community/live.php', {'csrf': field(form, 'csrf'), 'action_id': field(form, 'action_id'), 'action': 'question', 'value': 'Question', 'actor': '1:163', 'session_id': 999})[0] == 303
        listener = requests[-1]
        assert listener['actor'].startswith('listener:') and listener['session_id'] == 55
        endpoint.close()
        sockpath.unlink()
        assert cat.request('/dj/carrier.php')[0] == 503
        assert cat.request('/dj/profile.php')[0] == 200
        assert cat.request('/dj/broadcasts.php')[0] == 200
        print('Carrier website HTTP checks passed: planned shows, ownership, CSRF, private playlists, bound live actions, account linking, public activity and offline isolation.')
    finally:
        stop.set()
        endpoint.close()
        thread.join(1)
        db.close()


if __name__ == '__main__':
    try:
        socket.socket(socket.AF_UNIX).close()
    except PermissionError:
        os.environ['TILDERADIO_CARRIER_TEST_PORT'] = str(auth.free_port())
        os.environ['TILDERADIO_HTTP_PREPEND'] = str(auth.ROOT / 'tests/fixtures/carrier-transport.php')
        print('AF_UNIX unavailable: using the test-only local TCP transport adapter.')
    with auth.fixture(schedule=True) as args:
        run(*args)
