#!/usr/bin/env python3
"""Exercise private preview, approval, byte ranges, podcast MIME and tombstones."""
import hashlib
import http.client
import importlib.util
import json
import os
import re
import sqlite3
import ssl
import subprocess
import time
import wave
from pathlib import Path

spec = importlib.util.spec_from_file_location('auth', Path(__file__).with_name('http-auth.py'))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


def run(origin, state, *_):
    admin = auth.Browser(origin)
    admin.login()
    cat = auth.Browser(origin, '192.0.2.71')
    cat.login('cat')
    config = state.parent / 'site.json'
    settings = json.loads(config.read_text())
    recordings = state.parent / 'recordings'
    recordings.mkdir(mode=0o700)
    settings['carrier'] = {'enabled': True, 'recordings': {'enabled': True, 'directory': str(recordings)}}
    config.write_text(json.dumps(settings))
    token = 'e' * 64
    audio = recordings / (token + '.audio')
    with wave.open(str(audio), 'wb') as output:
        output.setparams((1, 2, 8000, 0, 'NONE', 'not compressed'))
        output.writeframes(b'\0\0' * 800)
    audio.chmod(0o600)
    original = audio.read_bytes()
    upstream_file = state.parent / 'schedule.json'
    upstream = json.loads(upstream_file.read_text())
    upstream['recording_hex'] = original.hex()
    upstream_file.write_text(json.dumps(upstream))
    staging = state.parent / 'download.audio'
    php = [os.getenv('PHP_BINARY', 'php')]
    if os.getenv('PHP_INI'):
        php += ['-c', os.environ['PHP_INI']]
    program = '''require $argv[1].'/vendor/autoload.php';
    $settings=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    try { $api=new \\TildeRadio\\Site\\Admin\\ScheduleApi($settings['schedule_api']);
    echo $api->downloadRecording(1,4,900,$argv[3],(int)$argv[4]); }
    catch (\\Throwable) { exit(1); }'''
    def download(cap):
        return subprocess.run(php + ['-r', program, str(auth.ROOT), str(config), str(staging), str(cap)], capture_output=True)
    assert download(len(original)).returncode == 0 and staging.read_bytes() == original
    staging.unlink()
    assert download(len(original) - 1).returncode == 1 and not staging.exists()
    for change in [{'recording_status': 302}, {'recording_type': 'text/html'}, {'mode': 'denied'}]:
        upstream_file.write_text(json.dumps(upstream | change))
        assert download(len(original)).returncode == 1 and not staging.exists()
    upstream_file.write_text(json.dumps(upstream))
    episode = {'id': 31, 'dj': 'cat', 'dj_slug': 'cat', 'started_at': int(time.time()) - 7200, 'ended_at': int(time.time()) - 3600,
               'show': {'episode': 'Recorded set'}, 'tracks': [{'text': 'Original capture'}], 'is_live': False}
    (state.parent / 'episodes.json').write_text(json.dumps({'version': 1, 'generated_at': 1, 'episodes': [episode]}))
    url = origin + '/recordings/?id=' + token
    candidate = hashlib.sha256(('31:' + url).encode()).hexdigest()
    db = sqlite3.connect(state / 'admin.sqlite')
    db.execute("UPDATE accounts SET profile_slug='cat' WHERE streamer_id=4")
    db.execute("INSERT OR IGNORE INTO profiles(slug,json,updated_at) VALUES('cat',?,1)", (json.dumps({'name': 'Cat', 'published': True}),))
    db.execute('INSERT INTO recording_candidates(id,broadcast_id,url,bytes,created_at) VALUES(?,31,?,?,1)', (candidate, url, len(original)))
    db.commit()

    def request(path, cookie=None, range_=None):
        conn = http.client.HTTPSConnection('127.0.0.1', int(origin.rsplit(':', 1)[1]), context=ssl._create_unverified_context())
        headers = {}
        if cookie:
            headers['Cookie'] = cookie
        if range_:
            headers['Range'] = range_
        conn.request('GET', path, headers=headers)
        result = conn.getresponse()
        answer = result.status, result.read(), dict((k.lower(), v) for k, v in result.getheaders())
        conn.close()
        return answer

    try:
        path = '/recordings/?id=' + token
        assert request(path)[0] == 404
        assert request('/dj/recording.php?id=' + token)[0] == 303
        status, html, _ = cat.request('/dj/recordings.php')
        assert status == 200 and 'Approve recording' in html
        assert request('/dj/recording.php?id=' + token, cat.cookie)[1] == original
        post = {'csrf': re.search(r'name="csrf" value="([^"]+)"', html)[1], 'recording_id': candidate, 'action': 'approve'}
        assert cat.request('/dj/recordings.php', post)[0] == 303
        status, body, headers = request(path, range_='bytes=10-19')
        assert status == 206 and body == original[10:20]
        assert headers['content-range'] == 'bytes 10-19/' + str(len(original))
        assert request(path, range_='bytes=-10')[1] == original[-10:]
        assert request(path, range_='bytes=999999-')[0] == 416
        assert request(path, range_='bytes=0-1,3-4')[0] == 416
        assert request(path)[1] == original
        status, feed, _ = request('/api/podcast/?dj=cat')
        assert status == 200 and b'<enclosure ' in feed and (b'audio/x-wav' in feed or b'audio/wav' in feed)
        db.execute('UPDATE broadcast_edits SET deleted=1 WHERE id=31')
        db.commit()
        assert request(path)[0] == 404
        assert b'<enclosure ' not in request('/api/podcast/?dj=cat')[1]
        assert audio.read_bytes() == original
        assert json.loads((state.parent / 'episodes.json').read_text())['episodes'][0] == episode
        print('Recording HTTP checks passed: authenticated TLS download, size/type/redirect rejection, private preview, approval, byte ranges, WAV podcast MIME, tombstones and untouched audio/capture.')
    finally:
        db.close()


if __name__ == '__main__':
    with auth.fixture(schedule=True) as args:
        run(*args)
