<?php
function http_get(string $url, int $timeout = 4, bool $insecure_ssl = false): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'tilderadio-index/1.1',
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
}

function http_get_with_headers(string $url, array $headers = [], int $timeout = 4, bool $insecure_ssl = false): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'tilderadio-index/1.1',
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
}

function azuracast_nowplaying(string $url): array {
    $json = http_get($url);
    if (!$json) return ['title' => null, 'listeners' => null];
    $data = json_decode($json, true);
    if (!is_array($data)) return ['title' => null, 'listeners' => null];

    if (isset($data['icestats'])) {
        $src = $data['icestats']['source'] ?? [];
        $mounts = (is_array($src) && array_keys($src) === range(0, count($src) - 1)) ? $src : [$src];
        $total = 0;
        $preferred = null;
        $fallback  = null;
        foreach ($mounts as $m) {
            if (!is_array($m)) continue;
            if (isset($m['listeners']) && is_numeric($m['listeners'])) $total += (int)$m['listeners'];
            $title = isset($m['title']) && is_string($m['title']) ? trim($m['title']) : '';
            if ($title !== '') {
                if (($m['server_type'] ?? '') === 'audio/mpeg' && $preferred === null) $preferred = $title;
                if ($fallback === null) $fallback = $title;
            } elseif (!empty($m['yp_currently_playing'])) {
                $yp = trim((string)$m['yp_currently_playing']);
                if ($yp !== '' && $fallback === null) $fallback = $yp;
            }
        }
        $title = $preferred ?? $fallback;
        return ['title' => $title, 'listeners' => $total > 0 ? $total : null];
    }

    $np        = $data['now_playing'] ?? null;
    $listeners = $data['listeners'] ?? null;

    $title = null;
    if (is_array($np)) {
        $song = $np['song'] ?? null;
        if (is_array($song)) {
            $artist = trim((string)($song['artist'] ?? ''));
            $track  = trim((string)($song['title']  ?? ''));
            if ($artist !== '' || $track !== '') $title = trim($artist . ' - ' . $track, ' -');
            else {
                $text = trim((string)($song['text'] ?? ''));
                if ($text !== '') $title = $text;
            }
        }
    }

    $listenersVal = null;
    if (is_array($listeners)) {
        foreach (['current', 'total', 'unique'] as $k) {
            if (isset($listeners[$k]) && is_numeric($listeners[$k])) { $listenersVal = (int)$listeners[$k]; break; }
        }
    } elseif (is_numeric($listeners)) {
        $listenersVal = (int)$listeners;
    }

    return ['title' => $title, 'listeners' => $listenersVal];
}

function azuracast_live_dj(string $base, string $shortcode, ?string $apiKey = null): array {
    $base = rtrim($base, '/');
    $url  = $base . '/api/nowplaying/' . rawurlencode($shortcode);

    $headers = ['Accept' => 'application/json'];
    if ($apiKey && trim($apiKey) !== '') {
        $headers['X-API-Key']     = $apiKey;
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $json = http_get_with_headers($url, $headers);
    if ($json === null && $apiKey && trim($apiKey) !== '') {
        $sep  = (strpos($url, '?') !== false) ? '&' : '?';
        $json = http_get($url . $sep . 'api_key=' . rawurlencode($apiKey));
    }
    if ($json === null) return ['is_live' => false, 'streamer_name' => null];

    $data = json_decode($json, true);
    if (!is_array($data)) return ['is_live' => false, 'streamer_name' => null];

    if (array_keys($data) === range(0, count($data) - 1)) {
        foreach ($data as $row) {
            if (is_array($row) && isset($row['station']['shortcode']) &&
                strcasecmp((string)$row['station']['shortcode'], $shortcode) === 0) {
                $data = $row;
                break;
            }
        }
        if (array_keys($data) === range(0, count($data) - 1)) return ['is_live' => false, 'streamer_name' => null];
    }

    $live = $data['live'] ?? null;
    if (!is_array($live)) return ['is_live' => false, 'streamer_name' => null];

    $isLive = !empty($live['is_live']);
    $name   = isset($live['streamer_name']) && is_string($live['streamer_name']) ? trim($live['streamer_name']) : null;

    return ['is_live' => (bool)$isLive, 'streamer_name' => ($name !== '' ? $name : null)];
}

function parse_ics_schedule(string $url): array {
    $ics = http_get($url);
    if (!$ics) return ['current' => null, 'next' => null];
    $raw = preg_split("/\r\n|\n|\r/", $ics);
    $lines = [];
    foreach ($raw as $ln) {
        if ($ln !== '' && (isset($ln[0]) && ($ln[0] === ' ' || $ln[0] === "\t")) && !empty($lines)) $lines[count($lines) - 1] .= substr($ln, 1);
        else $lines[] = rtrim($ln, "\r\n");
    }
    $events = [];
    $in = false;
    $cur = ['start' => null, 'end' => null, 'summary' => null];
    foreach ($lines as $ln) {
        if ($ln === 'BEGIN:VEVENT') { $in = true; $cur = ['start' => null, 'end' => null, 'summary' => null]; continue; }
        if ($ln === 'END:VEVENT') { if ($cur['start'] && $cur['end'] && $cur['summary']) $events[] = $cur; $in = false; $cur = ['start' => null, 'end' => null, 'summary' => null]; continue; }
        if (!$in || strpos($ln, ':') === false) continue;
        [$keyPart, $value] = explode(':', $ln, 2);
        $key = strtoupper(strtok($keyPart, ';'));
        if ($key === 'DTSTART') $cur['start'] = ics_to_epoch($value);
        elseif ($key === 'DTEND') $cur['end'] = ics_to_epoch($value);
        elseif ($key === 'SUMMARY') $cur['summary'] = ics_unescape($value);
    }
    usort($events, fn($a, $b) => $a['start'] <=> $b['start']);
    $now = time();
    $current = null; $next = null;
    foreach ($events as $e) {
        if ($e['start'] <= $now && $now < $e['end']) $current = $e;
        elseif ($e['start'] > $now) { $next = $e; break; }
    }
    return ['current' => $current, 'next' => $next];
}

function ics_to_epoch(string $v): ?int {
    $v = trim($v);
    if (preg_match('/^\d{8}T\d{6}Z$/', $v)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $v, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }
    if (preg_match('/^\d{8}T\d{6}$/', $v)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $v, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }
    return null;
}

function ics_unescape(string $s): string {
    return trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], [' ', ' ', ',', ';', '\\'], $s));
}

$AZURACAST_BASE      = 'https://azuracast.tilderadio.org';
$AZURACAST_SHORTCODE = 'tilderadio';
$NOWPLAY_URL         = 'https://azuracast.tilderadio.org/radio/8000/status-json.xsl';
$ICS_URL             = 'https://tilderadio.org/schedule/ics.php';

$apikey = null;
@include __DIR__ . '/schedule/apikey.php';

if (isset($_GET['json']) && $_GET['json'] === 'np') {
    $np = azuracast_nowplaying($NOWPLAY_URL);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'title'     => $np['title'] ?? null,
        'listeners' => $np['listeners'] ?? null,
        'ts'        => time(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$np        = azuracast_nowplaying($NOWPLAY_URL);
$sched     = parse_ics_schedule($ICS_URL);
$liveInfo  = azuracast_live_dj($AZURACAST_BASE, $AZURACAST_SHORTCODE, $apikey ?? null);

$currentSong = $np['title'] ?? null;
$listenerCt  = $np['listeners'] ?? null;
$currentDJ   = (!empty($liveInfo['is_live']) && !empty($liveInfo['streamer_name'])) ? $liveInfo['streamer_name'] : null;

$slogan_raw = http_get('https://bot.tildegit.org/api/slogan', 4, true);
$slogan_txt = $slogan_raw !== null ? json_decode($slogan_raw, true) : null;
?>
<?php include 'header.php'; ?>

<style>
:root { --tr-bg:#000; --tr-fg:#0f0; --tr-dim:rgba(0,255,0,.6); --tr-muted:rgba(0,255,0,.75); --tr-line:rgba(0,255,0,.25); }
.tr-wrap{max-width:980px;margin:0 auto;padding:0 12px;}
.tr-section{border:1px solid var(--tr-line);background:var(--tr-bg);border-radius:8px;padding:12px 14px;margin:14px 0;}
.tr-title{display:flex;align-items:center;gap:10px;margin:0 0 8px 0}
.tr-badge{border:1px solid var(--tr-fg);color:var(--tr-fg);padding:2px 8px;border-radius:999px;font-size:12px;letter-spacing:.06em}
.tr-grid{display:grid;grid-template-columns:1fr;gap:14px}
@media (min-width: 820px){ .tr-grid{grid-template-columns:1fr 1fr} }
.tr-kv{display:grid;grid-template-columns:auto 1fr;gap:8px 10px;align-items:center}
.tr-kv strong{color:var(--tr-fg)}
.tr-divider{height:1px;background:var(--tr-line);margin:10px 0}
.tr-statbar{display:flex;gap:10px;flex-wrap:wrap}
.tr-pill{border:1px solid var(--tr-fg);color:var(--tr-fg);padding:2px 8px;border-radius:999px;font-size:12px;white-space:nowrap}
.tr-links{display:grid;grid-template-columns:1fr;gap:8px;margin:10px 0 0}
@media (min-width:720px){ .tr-links{grid-template-columns:repeat(2,minmax(0,1fr))} }

.tr-player{border:1px solid var(--tr-fg);background:#000;padding:12px;border-radius:8px;width:100%;}
.tr-player .row{width:100%}
.tr-player .row.top{
  display:grid;
  grid-template-columns:auto auto 1fr auto;
  gap:10px;
  align-items:center;
}
.tr-player .controls{display:inline-flex;align-items:center;gap:8px}
.tr-player .status{display:inline-flex;align-items:center;gap:6px}
.tr-player .source{display:inline-flex;align-items:center;gap:6px; justify-self:end}
.tr-player .row.meta{
  display:grid;
  grid-template-columns:1fr auto;
  gap:10px;
  align-items:baseline;
  margin-top:8px;
}

.tr-player button,
.tr-player select{
  background:#000;color:var(--tr-fg);border:1px solid var(--tr-fg);
  padding:0 10px;cursor:pointer;font:inherit;border-radius:6px;
  height:32px; line-height:30px;
}
.tr-player button:hover,.tr-player select:hover{background:var(--tr-fg);color:#000}
.tr-player input[type="range"]{width:170px; height:32px; vertical-align:middle}
.tr-player .pill{border:1px solid var(--tr-fg);color:var(--tr-fg);padding:2px 8px;border-radius:999px;font-size:12px}
.tr-player .np{font-style:italic;color:var(--tr-fg)}
.tr-player .listeners{font-size:12px;opacity:.9;color:var(--tr-fg)}
.tr-player .srconly{font-size:12px;opacity:.8}
.tr-hidden{display:none !important}
.tr-eq{display:inline-flex;gap:2px;height:10px}
.tr-eq i{display:block;width:2px;background:var(--tr-fg);opacity:.9;height:6px}
.tr-player.is-playing .tr-eq i:nth-child(1){animation:tr-eq 600ms linear infinite}
.tr-player.is-playing .tr-eq i:nth-child(2){animation:tr-eq 600ms linear infinite 100ms}
.tr-player.is-playing .tr-eq i:nth-child(3){animation:tr-eq 600ms linear infinite 200ms}
@keyframes tr-eq{0%{height:4px}50%{height:10px}100%{height:4px}}
@media (prefers-reduced-motion:reduce){ .tr-player.is-playing .tr-eq i{animation:none} }

blockquote{border-left:2px solid var(--tr-line);padding-left:10px;color:var(--tr-muted)}
hr{border:0;border-top:1px solid var(--tr-line);margin:16px 0}
</style>

<div class="tr-wrap">

  <section class="tr-section">
    <div class="tr-title">
      <span class="tr-badge">NOW PLAYING</span>
      <span class="tr-pill">LIVE</span>
    </div>

    <div class="tr-grid">
      <div>
        <div class="tr-kv" role="status" aria-live="polite">
          <strong>DJ</strong>
          <div>
            <?php if ($currentDJ): ?>
              <?= htmlspecialchars($currentDJ, ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
              <em>no live DJ right now</em>
            <?php endif; ?>
          </div>

          <strong>Song</strong>
          <div class="np">
            <span id="tr-np-text">
              <?php if ($currentSong): ?>
                <?= htmlspecialchars($currentSong, ENT_QUOTES, 'UTF-8') ?>
              <?php else: ?>
                <em>unknown</em>
              <?php endif; ?>
            </span>
          </div>

          <strong>Listeners</strong>
          <div>
            <span id="tr-listeners-val">
              <?php if (is_int($listenerCt)): ?>
                <?= (int)$listenerCt ?>
              <?php else: ?>
                n/a
              <?php endif; ?>
            </span>
          </div>
        </div>

        <div class="tr-divider"></div>
        <div class="tr-statbar">
          <span class="tr-pill">Join IRC: <a href="https://tilde.chat/kiwi/#tilderadio" target="_blank" style="color:var(--tr-fg)">#tilderadio</a></span>
          <span class="tr-pill">Mastodon: <a href="https://tilde.zone/@tilderadio" target="_blank" style="color:var(--tr-fg)">@tilderadio</a></span>
        </div>
      </div>

      <div>
        <div class="tr-player" id="tr-player" aria-label="tilderadio player" role="group">
          <div class="row top">
            <div class="controls">
              <button id="tr-play" type="button">▶ play</button>
              <button id="tr-mute" type="button">🔈 mute</button>
              <label class="srconly" for="tr-vol">vol</label>
              <input id="tr-vol" type="range" min="0" max="1" step="0.01" value="1" aria-label="volume">
            </div>
            <div class="status">
              <span class="pill">LIVE</span>
              <span class="tr-eq" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>
            <div class="source">
              <label class="srconly" for="tr-src">stream</label>
              <select id="tr-src" aria-label="stream">
                <option value="https://azuracast.tilderadio.org/radio/8000/radio.ogg" data-type="audio/ogg">ogg 192k</option>
                <option value="https://azuracast.tilderadio.org/radio/8000/320k.ogg" data-type="audio/ogg">ogg 320k</option>
                <option value="https://azuracast.tilderadio.org/radio/8000/radio.mp3" data-type="audio/mpeg">mp3 192k</option>
                <option value="https://azuracast.tilderadio.org/radio/8000/320k.mp3" data-type="audio/mpeg">mp3 320k</option>
              </select>
            </div>
          </div>

          <div class="row meta">
            <span class="np">now playing: <span id="tr-np-text-clone">
              <?php if ($currentSong): ?>
                <?= htmlspecialchars($currentSong, ENT_QUOTES, 'UTF-8') ?>
              <?php else: ?>
                <em>unknown</em>
              <?php endif; ?>
            </span></span>
            <span class="listeners">listeners: <span id="tr-listeners-val-clone">
              <?php if (is_int($listenerCt)): ?>
                <?= (int)$listenerCt ?>
              <?php else: ?>
                n/a
              <?php endif; ?>
            </span></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="tr-section">
    <h2 class="tr-title"><span class="tr-badge">UP NEXT</span></h2>
    <p><em><?php include 'schedule/nextdj.php'; ?></em></p>
  </section>

  <section class="tr-section">
    <h2 class="tr-title"><span class="tr-badge">ABOUT</span></h2>
    <blockquote><?= $slogan_txt ? htmlspecialchars((string)$slogan_txt, ENT_QUOTES, 'UTF-8') : '' ?></blockquote>
    <p>tilderadio is internet radio streamed by / for users of the <a href="https://tildeverse.org/">tildeverse</a>.</p>
  </section>

  <section class="tr-section">
    <h2 class="tr-title"><span class="tr-badge">HOW TO LISTEN</span></h2>
    <p>use the player above, or one of the following links:</p>
    <ul class="tr-links">
      <li><a href="https://tilderadio.org/listen">https://tilderadio.org/listen</a></li>
      <li><a href="https://azuracast.tilderadio.org/radio/8000/radio.ogg">https://azuracast.tilderadio.org/radio/8000/radio.ogg</a> (ogg 192k)</li>
      <li><a href="https://azuracast.tilderadio.org/radio/8000/320k.ogg">https://azuracast.tilderadio.org/radio/8000/320k.ogg</a> (ogg 320k)</li>
      <li><a href="https://azuracast.tilderadio.org/radio/8000/radio.mp3">https://azuracast.tilderadio.org/radio/8000/radio.mp3</a> (mp3 192k)</li>
      <li><a href="https://azuracast.tilderadio.org/radio/8000/320k.mp3">https://azuracast.tilderadio.org/radio/8000/320k.mp3</a> (mp3 320k)</li>
    </ul>
  </section>

</div>

<div style="width: 100%; border: 0; height:0; overflow:hidden">
  <audio id="tr-audio" controls preload="none" style="width:100%">
    <source src="https://azuracast.tilderadio.org/radio/8000/radio.ogg" type="audio/ogg">
    <source src="https://azuracast.tilderadio.org/radio/8000/radio.mp3" type="audio/mpeg">
  </audio>
</div>

<script>
(function () {
  var audio = document.getElementById('tr-audio');
  var ui    = document.getElementById('tr-player');
  if (!audio || !ui) return;
  audio.classList.add('tr-hidden');

  var play    = document.getElementById('tr-play');
  var mute    = document.getElementById('tr-mute');
  var vol     = document.getElementById('tr-vol');
  var src     = document.getElementById('tr-src');
  var npEl    = document.getElementById('tr-np-text');
  var npClone = document.getElementById('tr-np-text-clone');
  var lsEl    = document.getElementById('tr-listeners-val');
  var lsClone = document.getElementById('tr-listeners-val-clone');

  function syncPlayingClass() {
    if (audio.paused) ui.classList.remove('is-playing');
    else ui.classList.add('is-playing');
  }

  function setPlayLabel() { play.textContent = audio.paused ? '▶ play' : '⏸ pause'; }
  function setMuteLabel() { mute.textContent = audio.muted ? '🔇 unmute' : '🔈 mute'; }

  play.addEventListener('click', function () {
    if (audio.paused) { audio.play().catch(function(){}); } else { audio.pause(); }
    setPlayLabel(); syncPlayingClass();
  });

  audio.addEventListener('play', function(){ setPlayLabel(); syncPlayingClass(); });
  audio.addEventListener('pause', function(){ setPlayLabel(); syncPlayingClass(); });

  mute.addEventListener('click', function () { audio.muted = !audio.muted; setMuteLabel(); });

  vol.addEventListener('input', function () {
    audio.volume = parseFloat(this.value);
    if (audio.volume === 0 && !audio.muted) { audio.muted = true; setMuteLabel(); }
    if (audio.volume > 0 && audio.muted) { audio.muted = false; setMuteLabel(); }
  });

  src.addEventListener('change', function () {
    var url = this.value;
    var wasPlaying = !audio.paused;
    audio.src = url;
    audio.load();
    if (wasPlaying) { audio.play().catch(function(){}); }
  });

  function updateNP() {
    var url = '?json=np&_=' + Date.now();
    fetch(url, {cache: 'no-store'})
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (j) {
        var title = (j && typeof j.title === 'string' && j.title.trim() !== '') ? j.title : null;
        if (npEl)     { npEl.textContent = title || 'unknown'; if (!title) npEl.innerHTML = '<em>unknown</em>'; }
        if (npClone)  { npClone.textContent = title || 'unknown'; if (!title) npClone.innerHTML = '<em>unknown</em>'; }
        var val = (j && typeof j.listeners === 'number') ? String(j.listeners) : 'n/a';
        if (lsEl)    lsEl.textContent = val;
        if (lsClone) lsClone.textContent = val;
      })
      .catch(function () {});
  }

  setPlayLabel(); setMuteLabel(); syncPlayingClass();
  updateNP();
  setInterval(updateNP, 15000);
})();
</script>

<?php include 'footer.php'; ?>
