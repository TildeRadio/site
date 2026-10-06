#!/usr/bin/env python3
"""Help routes work publicly without private configuration, and escape search input."""
import importlib.util
from pathlib import Path
import json
import http.client
from urllib.parse import quote

spec = importlib.util.spec_from_file_location('auth_http', Path(__file__).with_name('http-auth.py'))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)

with auth.fixture() as (origin, state, client, port):
    # A missing private authentication configuration must not take help offline.
    config = state.parent / 'site.json'
    config.unlink()
    public = auth.Browser(origin)
    for path, required in [
        ('/help/', ['<h1 id="help-title">Help</h1>', '29 guides', 'data-tr-help-search']),
        ('/help/?q=%21songs', ['Song announcements', 'Jump to:']),
        ('/help/?category=archive&audience=dj', ['2 guides', 'recordings']),
        ('/help/?topic=commands', ['!track next', '!songs on', '!carrier announce']),
        ('/djinfo/', ['id="testing"', 'id="going-live"']),
        ('/community/carrier/', ['id="how-it-works"', 'id="set-commands"']),
    ]:
        status, html, headers = public.request(path)
        assert status == 200, (path, status)
        for value in required:
            assert value in html, (path, value)
        assert 'set-cookie' not in headers
        assert headers['x-content-type-options'] == 'nosniff'
        assert "form-action 'self'" in headers['content-security-policy']
        assert "object-src 'none'" in headers['content-security-policy']
        assert "default-src" not in headers['content-security-policy']
        assert 'aria-current="page">help</a>' in html
    payload = '<svg/onload=alert(1)>'
    status, html, _ = public.request('/help/?q=' + quote(payload))
    assert status == 200 and payload not in html and '&lt;svg/' in html
    for query in ['q[]=bad', 'q='+('a'*161), 'q=%FF', 'q=%00', 'category=secret', 'audience=root']:
        status, html, _ = public.request('/help/?' + query)
        assert status == 400 and 'Fatal error' not in html, (query, status)
    status, html, _ = public.request('/help/?topic=../../etc/passwd')
    assert status == 404 and 'root:' not in html
    status, _, headers = public.request('/help/', {'q': 'test'})
    assert status == 405 and headers['allow'] == 'GET, HEAD'
    catalog = json.loads((auth.ROOT / 'data/help.json').read_text())
    for article in catalog['articles']:
        status, html, _ = public.request('/help/?topic=' + article['id'])
        assert status == 200
        for section in article['sections']:
            assert f'id="{section["id"]}"' in html
    status, body, headers = public.request('/api/')
    assert status == 200 and json.loads(body)['endpoints']['now'] == '/api/now/'
    head = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
    head.request('HEAD', '/help/?q=%21songs')
    response = head.getresponse()
    assert response.status == 200 and response.read() == b''
    head.close()
    assert not list((state / 'sessions').glob('sess_*'))
    assert not list(state.glob('admin.sqlite*'))
print('Help HTTP checks passed: public/offline access, all guides, legacy anchors, literal escaped search, invalid-input status codes, unchanged API directory, and no DJ session/state writes.')
